<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Stdlib\DataBuilder;

class DataBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DataBuilder(
            $services->get('Omeka\ApiManager'),
            $services->get('Vikus\ImageUrl'),
            $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files'
        );
    }
}
