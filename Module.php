<?php declare(strict_types=1);

namespace Vikus;

// Common may be installed but not registered in autoloader, in particular
// during upgrade. So dynamically register all classes of the module.
if (!defined('COMMON_PSR4_FALLBACK')) {
    foreach ([
        OMEKA_PATH . '/modules/Common/src',
        OMEKA_PATH . '/composer-addons/modules/Common/src',
        dirname(__DIR__) . '/Common/src',
    ] as $commonSrc) {
        if (file_exists($commonSrc . '/TraitModule.php')) {
            define('COMMON_PSR4_FALLBACK', $commonSrc);
            spl_autoload_register(static function ($class): void {
                if (str_starts_with($class, 'Common\\')) {
                    $file = COMMON_PSR4_FALLBACK . '/' . strtr(substr($class, 7), '\\', '/') . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            break;
        }
    }
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Adapter\SitePageAdapter;
use Omeka\Entity\SitePage;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Vikus\Job\GenerateData;
use Vikus\Stdlib\DataBuilder;

/**
 * Vikus.
 *
 * @copyright Daniel Berthereau, 2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    use TraitModule;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, ['Vikus\Controller\Player']);
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.91')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.91'
            );
            throw new ModuleCannotInstallException((string) $message);
        }

        $errors = [];

        if (!file_exists(__DIR__ . '/asset/vendor/vikus-viewer/index.html')) {
            $message = new PsrMessage(
                'The library "{library}" should be installed. See module’s installation documentation.', // @translate
                ['library' => 'VIKUS Viewer']
            );
            $errors[] = (string) $message->setTranslator($translator);
        }

        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            $message = new PsrMessage(
                'The php extension "imagick" or "gd" is required to build the sprites.' // @translate
            );
            $errors[] = (string) $message->setTranslator($translator);
        }

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        if (!$this->checkDestinationDir($basePath . '/vikus')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/vikus']
            );
            $errors[] = (string) $message->setTranslator($translator);
        }

        if ($errors) {
            throw new ModuleCannotInstallException(implode("\n", $errors));
        }
    }

    protected function postUninstall(): void
    {
        $this->getServiceLocator()->get('Vikus\CacheStorage')->deleteAll();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            SitePageAdapter::class,
            'api.create.post',
            [$this, 'handleSitePageSave']
        );
        $sharedEventManager->attach(
            SitePageAdapter::class,
            'api.update.post',
            [$this, 'handleSitePageSave']
        );
        $sharedEventManager->attach(
            SitePageAdapter::class,
            'api.delete.pre',
            [$this, 'handleSitePageDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
    }

    /**
     * Generate the cache of updated blocks and purge the one of removed ones.
     */
    public function handleSitePageSave(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if ($request->getOption('validateOnly')) {
            return;
        }

        $services = $this->getServiceLocator();
        $storage = $services->get('Vikus\CacheStorage');
        $defaults = $services->get('Config')['vikus']['block_settings']['vikus'];
        /** @var \Omeka\Entity\SitePage $page */
        $page = $event->getParam('response')->getContent();

        $blockIds = [];
        $blocks = [];
        foreach ($page->getBlocks() as $block) {
            if ($block->getLayout() !== 'vikus' || !$block->getId()) {
                continue;
            }
            $blockIds[] = $block->getId();
            $blocks[$block->getId()] = ($block->getData() ?? []) + $defaults;
        }

        foreach ($blocks as $blockId => $data) {
            $hash = DataBuilder::hash($data);
            if (($storage->readMeta($blockId)['hash'] ?? null) !== $hash) {
                $this->dispatchGenerateData($blockId);
            }
        }

        // A job dispatched synchronously (used by the tests of this module)
        // clears the entity manager once done, detaching $page: re-attach it so
        // the api layer can still build its response representation.
        $entityManager = $services->get('Omeka\EntityManager');
        if (!$entityManager->contains($page)) {
            $page = $entityManager->find(get_class($page), $page->getId());
            $event->getParam('response')->setContent($page);
        }

        $this->purge($page, $blockIds);
    }

    /**
     * Dispatch the generation job for a block.
     *
     * The authenticated user carried by the job as its owner may have been
     * detached from the entity manager by an earlier clear() (for example after
     * a previous api call in the same request), so it is re-merged before and
     * after dispatching, since the synchronous strategy used by the tests of
     * this module clears the entity manager once the job is done.
     */
    protected function dispatchGenerateData(int $blockId): void
    {
        $services = $this->getServiceLocator();
        $this->reattachIdentity($services);
        $services->get('Omeka\Job\Dispatcher')->dispatch(GenerateData::class, ['block_id' => $blockId]);
        $this->reattachIdentity($services);
    }

    /**
     * Re-merge the authenticated user into the current entity manager.
     */
    protected function reattachIdentity(ServiceLocatorInterface $services): void
    {
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');
        $user = $auth->getIdentity();
        if ($user && !$entityManager->contains($user)) {
            $auth->getStorage()->write($entityManager->merge($user));
        }
    }

    public function handleSitePageDelete(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $adapter = $this->getServiceLocator()->get('Omeka\ApiAdapterManager')->get('site_pages');
        $id = $request->getId();
        try {
            $page = $adapter->findEntity(is_array($id) ? $id : ['id' => $id]);
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return;
        }
        $this->purge($page, []);
    }

    /**
     * Delete the cache of the blocks of a page, except the ones to keep.
     */
    protected function purge(SitePage $page, array $keepBlockIds): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        foreach (array_diff($storage->listBlockIds(), $keepBlockIds) as $blockId) {
            if (($storage->readMeta($blockId)['page_id'] ?? null) === $page->getId()) {
                $storage->delete($blockId);
            }
        }
    }
}
