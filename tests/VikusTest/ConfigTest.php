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

    public function testBlockLayoutIsRegistered(): void
    {
        $blockLayoutManager = $this->getServiceLocator()->get('Omeka\BlockLayoutManager');
        $this->assertTrue($blockLayoutManager->has('vikus'));
    }

    public function testSiteSettingsFieldsetIsRegistered(): void
    {
        $fieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(\Vikus\Form\SiteSettingsFieldset::class);
        $this->assertTrue($fieldset->has('vikus_property_year'));
        $this->assertTrue($fieldset->has('vikus_style_fontColor'));
        $this->assertTrue($fieldset->has('vikus_filter_type'));
        $this->assertTrue($fieldset->has('vikus_properties_group'));
        $this->assertTrue($fieldset->has('vikus_map_outliers'));
        $this->assertFalse($fieldset->has('vikus_timeline_events'));
        $this->assertFalse($fieldset->has('vikus_query'));
        $this->assertFalse($fieldset->has('vikus_title'));
        $this->assertFalse($fieldset->has('vikus_info'));
    }

    public function testSiteSettingsFieldsetHasOneElementGroup(): void
    {
        $fieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(\Vikus\Form\SiteSettingsFieldset::class);

        $this->assertSame(['vikus' => 'Vikus Viewer'], $fieldset->getOption('element_groups'));
        foreach ($fieldset->getElements() as $element) {
            $this->assertSame('vikus', $element->getOption('element_group'));
        }
    }

    public function testSiteSettingsDefaults(): void
    {
        $defaults = $this->getServiceLocator()->get('Config')['vikus']['site_settings'];
        $this->assertSame('dcterms:date', $defaults['vikus_property_year']);
        $this->assertSame('#eae2cb', $defaults['vikus_style_canvasBackground']);
        $this->assertSame('tags', $defaults['vikus_filter_type']);
        $this->assertSame([], $defaults['vikus_properties_group']);
        $this->assertSame('clamp', $defaults['vikus_map_outliers']);
        $this->assertArrayNotHasKey('vikus_query', $defaults);
    }

    public function testBlockDefaults(): void
    {
        $defaults = $this->getServiceLocator()->get('Config')['vikus']['block_settings']['vikus'];
        $this->assertSame('dcterms:date', $defaults['property_year']);
        $this->assertSame(['dcterms:subject'], $defaults['property_keywords']);
        $this->assertSame('auto', $defaults['image_source']);
        $this->assertSame(6, $defaults['columns']);
        $this->assertSame('#eae2cb', $defaults['style_canvasBackground']);
        $this->assertSame('tags', $defaults['filter_type']);
        $this->assertSame([], $defaults['properties_group']);
        $this->assertSame('clamp', $defaults['map_outliers']);
        $this->assertSame([], $defaults['timeline_events']);
    }
}
