<?php declare(strict_types=1);

namespace VikusTest;

use Omeka\Test\AbstractHttpControllerTestCase;

class ConfigTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    public function testModuleIsActive(): void
    {
        $module = $this->getServiceLocator()->get('Omeka\ModuleManager')->getModule('Vikus');
        $this->assertSame('active', $module->getState());
    }

    public function testBlockDefaults(): void
    {
        $defaults = $this->getServiceLocator()->get('Config')['vikus']['block_settings']['vikus'];
        $this->assertSame('dcterms:date', $defaults['property_year']);
        $this->assertSame(['dcterms:subject'], $defaults['property_keywords']);
        $this->assertSame('auto', $defaults['image_source']);
        $this->assertSame(6, $defaults['columns']);
        $this->assertSame('#eae2cb', $defaults['style_canvasBackground']);
    }
}
