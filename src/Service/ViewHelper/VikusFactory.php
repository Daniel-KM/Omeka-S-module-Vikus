<?php declare(strict_types=1);

namespace Vikus\Service\ViewHelper;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\View\Helper\Vikus;

class VikusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Vikus($services->get('Vikus\CacheStorage'));
    }
}
