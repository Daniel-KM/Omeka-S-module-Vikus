<?php declare(strict_types=1);

namespace VikusTest\Form;

use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Form\VikusFieldset;
use VikusTest\VikusTestTrait;

class VikusFieldsetTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected function fieldset(): VikusFieldset
    {
        return $this->getServiceLocator()->get('FormElementManager')->get(VikusFieldset::class);
    }

    public function testLayoutTitlesUsesThePairsEditor(): void
    {
        $element = $this->fieldset()
            ->get('o:block[__blockIndex__][o:data][layout_titles]');

        $this->assertStringContainsString('common-pairs-textarea', (string) $element->getAttribute('class'));
        $this->assertSame('lines', $element->getAttribute('data-pairs-format'));
        $this->assertSame('=', $element->getAttribute('data-pairs-separator'));
        // The keys are the properties chosen for the group layouts, so the
        // picker of the editor lists them with their label.
        $this->assertSame('#vikus-properties-group', $element->getAttribute('data-pairs-key-source'));
    }

    public function testTimelineEventsUsesThePairsEditor(): void
    {
        $element = $this->fieldset()
            ->get('o:block[__blockIndex__][o:data][timeline_events]');

        $this->assertStringContainsString('common-pairs-textarea', (string) $element->getAttribute('class'));
        $this->assertSame('lines', $element->getAttribute('data-pairs-format'));
        $this->assertSame('=', $element->getAttribute('data-pairs-separator'));
    }

    public function testSpriteSizeIsARadio(): void
    {
        $element = $this->fieldset()->get('o:block[__blockIndex__][o:data][sprite_size]');
        $this->assertInstanceOf(\Laminas\Form\Element\Radio::class, $element);
        $this->assertSame([256, 128], array_keys($element->getValueOptions()));
    }

    public function testElementGroupsListsTheSixSectionsInOrder(): void
    {
        $elementGroups = $this->fieldset()->getOption('element_groups');

        $this->assertSame([
            'vikus_items',
            'vikus_alignment',
            'vikus_layouts',
            'vikus_map',
            'vikus_images',
            'vikus_style',
        ], array_keys($elementGroups));
    }

    public function testEveryElementBelongsToAnElementGroup(): void
    {
        $fieldset = $this->fieldset();
        $groups = array_keys($fieldset->getOption('element_groups'));

        foreach ($fieldset->getElements() as $element) {
            $this->assertContains(
                $element->getOption('element_group'),
                $groups,
                $element->getName() . ' has no valid element group.'
            );
        }
    }
}
