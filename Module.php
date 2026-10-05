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
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;

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
}
