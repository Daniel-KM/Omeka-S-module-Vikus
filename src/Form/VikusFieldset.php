<?php declare(strict_types=1);

namespace Vikus\Form;

use Common\Form\Element as CommonElement;
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

    /**
     * Keys of an event of the timeline, in the order of timeline.csv.
     */
    const TIMELINE_KEYS = [
        'year' => null,
        'title' => null,
        'text' => null,
        'extra' => null,
    ];

    public function init(): void
    {
        $name = fn (string $key): string => 'o:block[__blockIndex__][o:data][' . $key . ']';
        $id = fn (string $key): string => 'vikus-' . strtolower(strtr($key, '_', '-'));

        foreach (self::elementSpecs($name, $id) as $spec) {
            $this->add($spec);
        }
    }

    /**
     * Build the specs of the elements of a viewer, by option key.
     *
     * The same options are set by a page block and by the site settings, only
     * their names and their ids differ, so they are declared once here.
     *
     * @param callable $name Build the name of an element from its option key.
     * @param callable $id Build the html id of an element from its option key.
     */
    public static function elementSpecs(callable $name, callable $id): array
    {
        $specs = [
            'query' => [
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
                    'id' => $id('query'),
                ],
            ],
            'title' => [
                'name' => $name('title'),
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Title', // @translate
                    'info' => 'The title of the page is used when empty.', // @translate
                ],
                'attributes' => [
                    'id' => $id('title'),
                ],
            ],
            'info' => [
                'name' => $name('info'),
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Presentation (markdown)', // @translate
                ],
                'attributes' => [
                    'id' => $id('info'),
                    'rows' => 6,
                ],
            ],
            'timeline_events' => [
                'name' => $name('timeline_events'),
                'type' => CommonElement\DataTextarea::class,
                'options' => [
                    'label' => 'Events of the timeline', // @translate
                    'info' => 'Write one event by line like "1789 = Night of the 4th August = Abolition of feudalism = Extra note". Only the year is required.', // @translate
                    // Important: these options should be set in the block
                    // layout too.
                    'data_options' => self::TIMELINE_KEYS,
                ],
                'attributes' => [
                    'id' => $id('timeline_events'),
                    'placeholder' => '',
                    'rows' => 5,
                ],
            ],
            'property_year' => [
                'name' => $name('property_year'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Property for the year', // @translate
                    'info' => 'The first number of four digits of the first value is used.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => $id('property_year'),
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ],
            'property_keywords' => [
                'name' => $name('property_keywords'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Properties for the keywords', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => $id('property_keywords'),
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ],
            'filter_type' => [
                'name' => $name('filter_type'),
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Mode of the filter', // @translate
                    'info' => 'With the hierarchical mode, the keywords are split on ":" to build levels. With the crossfilter, each property of the keywords is a facet.', // @translate
                    'value_options' => [
                        'tags' => 'Keywords (tag cloud)', // @translate
                        'hierarchical' => 'Hierarchical keywords', // @translate
                        'crossfilter' => 'Crossfilter (facets by property)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => $id('filter_type'),
                ],
            ],
            'properties_detail' => [
                'name' => $name('properties_detail'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Properties of the detail panel', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => $id('properties_detail'),
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ],
            'image_source' => [
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
                    'id' => $id('image_source'),
                ],
            ],
            'link_to_item' => [
                'name' => $name('link_to_item'),
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Add a link to the item in the detail panel', // @translate
                ],
                'attributes' => [
                    'id' => $id('link_to_item'),
                ],
            ],
            'columns' => [
                'name' => $name('columns'),
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of columns by year', // @translate
                ],
                'attributes' => [
                    'id' => $id('columns'),
                    'min' => 1,
                    'max' => 50,
                ],
            ],
            'height' => [
                'name' => $name('height'),
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Height of the viewer (css)', // @translate
                ],
                'attributes' => [
                    'id' => $id('height'),
                    'placeholder' => '80vh',
                ],
            ],
        ];

        foreach (self::STYLES as $key => $label) {
            $key = 'style_' . $key;
            $specs[$key] = [
                'name' => $name($key),
                'type' => Element\Color::class,
                'options' => [
                    'label' => $label,
                ],
                'attributes' => [
                    'id' => $id($key),
                ],
            ];
        }

        return $specs;
    }
}
