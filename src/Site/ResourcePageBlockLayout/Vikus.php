<?php declare(strict_types=1);

namespace Vikus\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;
use Vikus\Stdlib\CacheStorage;
use Vikus\Stdlib\OptionsNormalizer;

/**
 * Display the items of an item set in the VIKUS Viewer.
 */
class Vikus implements ResourcePageBlockLayoutInterface
{
    const PARTIAL_NAME = 'common/resource-page-block-layout/vikus';

    public function getLabel(): string
    {
        return 'Vikus Viewer'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return [
            'item_sets',
        ];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        $services = $resource->getServiceLocator();
        $site = $view->currentSite();

        $options = OptionsNormalizer::fromSettings(
            $services->get('Omeka\Settings\Site'),
            $services->get('Config')['vikus']['site_settings']
        );
        $options['query'] = ['item_set_id' => $resource->id()];
        $key = CacheStorage::itemSetKey($site->id(), $resource->id());

        return $view->partial(self::PARTIAL_NAME, [
            'resource' => $resource,
            'site' => $site,
            'key' => $key,
            'height' => $options['height'],
        ]);
    }
}
