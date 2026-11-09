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
use Omeka\Api\Adapter\ItemSetAdapter;
use Omeka\Api\Adapter\SiteAdapter;
use Omeka\Api\Adapter\SitePageAdapter;
use Omeka\Entity\SitePage;
use Omeka\Form\SettingForm;
use Omeka\Form\SiteSettingsForm;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Vikus\Job\GenerateData;
use Vikus\Stdlib\CacheStorage;
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

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.95')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.95'
            );
            throw new ModuleCannotInstallException((string) $message);
        }

        $errors = [];

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
            ItemSetAdapter::class,
            'api.delete.pre',
            [$this, 'handleItemSetDelete']
        );
        $sharedEventManager->attach(
            SiteAdapter::class,
            'api.delete.pre',
            [$this, 'handleSiteDelete']
        );
        $sharedEventManager->attach(
            SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
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

        $keys = [];
        $blocks = [];
        foreach ($page->getBlocks() as $block) {
            if ($block->getLayout() !== 'vikus' || !$block->getId()) {
                continue;
            }
            $keys[] = CacheStorage::blockKey($block->getId());
            $blocks[$block->getId()] = [$block, ($block->getData() ?? []) + $defaults];
        }

        $layout = $services->get('Omeka\BlockLayoutManager')->get('vikus');
        foreach ($blocks as $blockId => [$block, $data]) {
            $key = CacheStorage::blockKey($blockId);
            $hash = DataBuilder::hash($data);
            if ($layout->isRegenerationForced($block)
                || ($storage->readMeta($key)['hash'] ?? null) !== $hash
            ) {
                // Delete the current cache before dispatching: the block may
                // have just inherited the id (and so the cache) of a different
                // vikus block through a reorder or an insertion, and must not
                // serve its dataset while the job is running.
                $this->deleteCache($key);
                $services->get('Omeka\Job\Dispatcher')->dispatch(GenerateData::class, ['key' => $key]);
            }
        }

        $this->purge($page, $keys);
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
     * Delete the caches of a deleted item set in any site.
     */
    public function handleItemSetDelete(Event $event): void
    {
        $id = $this->requestId($event);
        if ($id) {
            $this->purgeKeys('~^s\d+-r' . $id . '$~');
        }
    }

    /**
     * Delete the caches of the item sets of a deleted site.
     */
    public function handleSiteDelete(Event $event): void
    {
        $id = $this->requestId($event);
        if ($id) {
            $this->purgeKeys('~^s' . $id . '-r\d+$~');
        }
    }

    protected function requestId(Event $event): int
    {
        $id = $event->getParam('request')->getId();
        return (int) (is_array($id) ? ($id['id'] ?? 0) : $id);
    }

    protected function purgeKeys(string $pattern): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        foreach ($storage->listKeys() as $key) {
            if (preg_match($pattern, $key)) {
                $this->deleteCache($key);
            }
        }
    }

    /**
     * Delete the cache of the blocks of a page, except the ones to keep.
     */
    protected function purge(SitePage $page, array $keepKeys): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        foreach (array_diff($storage->listKeys(), $keepKeys) as $key) {
            if (($storage->readMeta($key)['page_id'] ?? null) === $page->getId()) {
                $this->deleteCache($key);
            }
        }
    }

    /**
     * Delete a cache without breaking the save of the page when a file cannot
     * be removed: the error is logged and the job replaces the cache anyway.
     */
    protected function deleteCache(string $key): void
    {
        $services = $this->getServiceLocator();
        try {
            $services->get('Vikus\CacheStorage')->delete($key);
        } catch (\RuntimeException $e) {
            $services->get('Omeka\Logger')->err(
                'Vikus cache "{key}": {message}', // @translate
                ['key' => $key, 'message' => $e->getMessage()]
            );
        }
    }
}
