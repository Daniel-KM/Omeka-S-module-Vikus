<?php declare(strict_types=1);

namespace Vikus\Service\Controller;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Controller\PlayerController;

class PlayerControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new PlayerController(
            $services->get('Omeka\EntityManager'),
            $services->get('Vikus\CacheStorage'),
            $services->get('Vikus\ConfigBuilder'),
            $services->get('Config')['vikus']['block_settings']['vikus'],
            dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer'
        );
    }
}
