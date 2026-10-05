<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Stdlib\CacheStorage;

class CacheStorageFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config')['file_store']['local'];
        $basePath = $config['base_path'] ?: OMEKA_PATH . '/files';
        $baseUrl = $config['base_uri'];
        if (!$baseUrl) {
            $helpers = $services->get('ViewHelperManager');
            $baseUrl = $helpers->get('ServerUrl')->__invoke($helpers->get('BasePath')->__invoke('files'));
        }
        return new CacheStorage($basePath, $baseUrl);
    }
}
