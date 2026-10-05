<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Stdlib\DataBuilder;

class DataBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // The module DigitalObject is optional: its view helper is the only
        // access to the digital objects linked to an item, so none of its
        // classes is referenced.
        $helpers = $services->get('ViewHelperManager');
        $digitalObjectInline = $helpers->has('digitalObjectInline')
            ? fn ($item): array => (array) $helpers->get('digitalObjectInline')($item)
            : null;

        return new DataBuilder(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\EntityManager'),
            $services->get('Vikus\ImageUrl'),
            $services->get('MvcTranslator'),
            $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files',
            // The module Mapping is optional: its api resource is the marker
            // of an active module, so none of its classes is referenced.
            $services->get('Omeka\ApiAdapterManager')->has('mapping_features'),
            $digitalObjectInline
        );
    }
}
