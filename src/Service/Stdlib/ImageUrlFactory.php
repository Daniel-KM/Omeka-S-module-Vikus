<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Interop\Container\ContainerInterface;
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
            // '3' is the version of the IIIF Image API.
            $iiifMediaUrl = fn ($media): string => (string) $helper($media, 'imageserver/id', '3');
        }
        return new ImageUrl($iiifMediaUrl);
    }
}
