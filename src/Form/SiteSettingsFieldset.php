<?php declare(strict_types=1);

namespace Vikus\Form;

use Laminas\Form\Fieldset;

class SiteSettingsFieldset extends Fieldset
{
    /**
     * Options set by the block only: the resource page block builds them from
     * the item set itself.
     */
    const SKIP = ['query', 'title', 'info', 'regenerate', 'timeline_events'];

    protected $label = 'Vikus Viewer'; // @translate

    protected $elementGroups = [
        'vikus' => 'Vikus Viewer', // @translate
    ];

    public function init(): void
    {
        $name = fn (string $key): string => 'vikus_' . $key;

        $this
            ->setAttribute('id', 'vikus')
            ->setOption('element_groups', $this->elementGroups);

        foreach (VikusFieldset::elementSpecs($name, $name) as $key => $spec) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $spec['options']['element_group'] = 'vikus';
            $this->add($spec);
        }
    }
}
