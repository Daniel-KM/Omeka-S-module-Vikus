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
        return new ImageUrl($iiifMediaUrl, $isIiifMedia);
    }
}
