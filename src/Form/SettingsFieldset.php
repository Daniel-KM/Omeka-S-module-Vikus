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
                    'label' => 'Hard limit of items by visualization', // @translate
                    'info' => 'Safety limit of the background job, that the option of a block or of a site cannot exceed.', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus_max_items',
                    'min' => 1,
                ],
            ]);
    }
}
