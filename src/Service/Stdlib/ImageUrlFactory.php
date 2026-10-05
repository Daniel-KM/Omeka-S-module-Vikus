<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Stdlib\ImageUrl;

class ImageUrlFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $helpers = $services->get('ViewHelperManager');
        $iiifMediaUrl = null;
        if ($helpers->has('iiifMediaUrl')) {
            $helper = $helpers->get('iiifMediaUrl');
            // The version is managed by the helper itself, according to the
            // settings of the module IiifServer (default version, appended or
            // not, external image server).
            $iiifMediaUrl = fn ($media): string => (string) $helper($media, 'imageserver/id', null);
        }
        $isIiifMedia = $helpers->has('isIiifMedia')
            ? fn ($media, ?string $type = null): bool => (bool) $helpers->get('isIiifMedia')($media, $type)
            : null;
        // The version of the api served for an url without version segment.
        $version = (string) $services->get('Omeka\Settings')->get('iiifserver_media_api_default_version', '2');
        $mediaDimension = null;
        if ($helpers->has('mediaDimension')) {
            $dimensionHelper = $helpers->get('mediaDimension');
            // The helper reads the dimensions stored in the media data, else
            // the file itself, and throws for a file that cannot be read.
            $mediaDimension = function ($media) use ($dimensionHelper): ?array {
                try {
                    return $dimensionHelper($media, 'original');
                } catch (\Throwable $e) {
                    return null;
                }
            };
        }
        return new ImageUrl($iiifMediaUrl, $isIiifMedia, version_compare($version, '3', '>='), $mediaDimension);
    }
}
