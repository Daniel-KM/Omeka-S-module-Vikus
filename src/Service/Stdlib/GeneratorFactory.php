<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Vikus\Stdlib\Generator;

class GeneratorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Generator(
            $services->get('Vikus\CacheStorage'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Job\Dispatcher'),
            $services->get('Omeka\Logger')
        );
    }
}
