<?php declare(strict_types=1);

namespace Vikus\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Vikus Viewer'; // @translate

    protected $elementGroups = [
        'vikus' => 'Vikus Viewer', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'vikus')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'vikus_max_items',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'vikus',
                    'label' => 'Maximum number of items by visualization', // @translate
                    'info' => 'This is a safety limit for the background job, not a target: the browser downloads about one atlas of thumbnails by 49 items, so a few thousand items by visualization is a reasonable maximum.', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus_max_items',
                    'min' => 1,
                ],
            ]);
    }
}
