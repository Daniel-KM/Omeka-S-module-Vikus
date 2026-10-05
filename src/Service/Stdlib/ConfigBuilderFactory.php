<?php declare(strict_types=1);

namespace Vikus\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Vikus\Stdlib\ConfigBuilder;

class ConfigBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ConfigBuilder(
            $services->get('Common\EasyMeta'),
            $services->get('MvcTranslator')
        );
    }
}
