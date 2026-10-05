<?php declare(strict_types=1);

namespace Vikus\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class VikusFieldset extends Fieldset
{
    const STYLES = [
        'fontColor' => 'Font color', // @translate
        'fontColorActive' => 'Font color of the active keyword', // @translate
        'fontBackground' => 'Background of the active keyword', // @translate
        'canvasBackground' => 'Background of the canvas', // @translate
        'timelineBackground' => 'Background of the timeline', // @translate
        'timelineFontColor' => 'Font color of the timeline', // @translate
        'backgroundHeader' => 'Background of the header', // @translate
        'fontColorHeader' => 'Font color of the header', // @translate
        'detailBackground' => 'Background of the detail panel', // @translate
        'infoBackground' => 'Background of the info panel', // @translate
        'infoFontColor' => 'Font color of the info panel', // @translate
        'searchbarBackground' => 'Background of the search bar', // @translate
    ];

    public function init(): void
    {
        $name = fn (string $key): string => 'o:block[__blockIndex__][o:data][' . $key . ']';

        $this
            ->add([
                'name' => $name('query'),
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Items to display', // @translate
                    'info' => 'Only items of the site with an image are displayed.', // @translate
                    'query_resource_type' => 'items',
                    'query_partial_excludelist' => [
                        'common/advanced-search/site',
                    ],
                ],
                'attributes' => [
                    'id' => 'vikus-query',
                ],
            ])
            ->add([
                'name' => $name('title'),
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Title', // @translate
                    'info' => 'The title of the page is used when empty.', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus-title',
                ],
            ])
            ->add([
                'name' => $name('info'),
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Presentation (markdown)', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus-info',
                    'rows' => 6,
                ],
            ])
            ->add([
                'name' => $name('property_year'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Property for the year', // @translate
                    'info' => 'The first number of four digits of the first value is used.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'vikus-property-year',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])
            ->add([
                'name' => $name('property_keywords'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Properties for the keywords', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'vikus-property-keywords',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])
            ->add([
                'name' => $name('properties_detail'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Properties of the detail panel', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'vikus-properties-detail',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])
            ->add([
                'name' => $name('image_source'),
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Source of the detailed images', // @translate
                    'value_options' => [
                        'auto' => 'Automatic (iiif when available)', // @translate
                        'omeka' => 'Omeka (large and original)', // @translate
                        'iiif' => 'IIIF image server', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'vikus-image-source',
                ],
            ])
            ->add([
                'name' => $name('link_to_item'),
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Add a link to the item in the detail panel', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus-link-to-item',
                ],
            ])
            ->add([
                'name' => $name('columns'),
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of columns by year', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus-columns',
                    'min' => 1,
                    'max' => 50,
                ],
            ])
            ->add([
                'name' => $name('height'),
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Height of the viewer (css)', // @translate
                ],
                'attributes' => [
                    'id' => 'vikus-height',
                    'placeholder' => '80vh',
                ],
            ]);

        foreach (self::STYLES as $key => $label) {
            $this->add([
                'name' => $name('style_' . $key),
                'type' => Element\Color::class,
                'options' => [
                    'label' => $label,
                ],
                'attributes' => [
                    'id' => 'vikus-style-' . strtolower($key),
                ],
            ]);
        }
    }
}
