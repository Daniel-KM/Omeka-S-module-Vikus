<?php declare(strict_types=1);

namespace Vikus\Service\BlockLayout;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Site\BlockLayout\Vikus;

class VikusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Vikus($services->get('Config')['vikus']['block_settings']['vikus']);
    }
}
