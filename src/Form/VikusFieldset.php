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
                    // Important: OptionsNormalizer rebuilds this element with
                    // the same keys to parse the value stored as a string.
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
            'property_year_annotation' => [
                'name' => $name('property_year_annotation'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Property of the annotation carrying the year', // @translate
                    'info' => 'When set, the year is read in this property of the value annotations of the values of "Property for the year". When empty, the value itself is used.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => $id('property_year_annotation'),
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ],
            'year_precision' => [
                'name' => $name('year_precision'),
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Precision of the year', // @translate
                    'info' => 'The year is rounded down to the first year of its decade or of its century, so the columns of the time layout are wider and the images are bigger at the first display.', // @translate
                    'value_options' => [
                        'year' => 'Year', // @translate
                        'decade' => 'Decade', // @translate
                        'century' => 'Century', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => $id('year_precision'),
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
            'properties_group' => [
                'name' => $name('properties_group'),
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Properties for the group layouts', // @translate
                    'info' => 'A layout is added to the navigation for each property: the items are grouped by their first value.', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => $id('properties_group'),
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ],
            'layout_titles' => [
                'name' => $name('layout_titles'),
                'type' => CommonElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Titles of the group layouts', // @translate
                    'info' => 'Write one title by line like "dcterms:subject = Themes". The label of the property is used when it is not set. A short title is advised: the navigation buttons of the viewer are narrow.', // @translate
                    // Important: OptionsNormalizer rebuilds this element in
                    // the same mode to parse the value stored as a string.
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => $id('layout_titles'),
                    'placeholder' => 'dcterms:subject = Themes',
                    'rows' => 3,
                ],
            ],
            'layout_map' => [
                'name' => $name('layout_map'),
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Add a map layout (module Mapping)', // @translate
                    'info' => 'A layout is added to the navigation with the items having a point. The other items are hidden in it.', // @translate
                ],
                'attributes' => [
                    'id' => $id('layout_map'),
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
            'sprite_size' => [
                'name' => $name('sprite_size'),
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Size of the sprites (px)', // @translate
                    'info' => 'The thumbnails are packed in atlases of 2048 px. A smaller size divides the number of atlases by four, so the memory of the graphic card too, but the images are blurry when the visitor zooms in.', // @translate
                    'value_options' => [
                        256 => '256 px',
                        128 => '128 px',
                    ],
                ],
                'attributes' => [
                    'id' => $id('sprite_size'),
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
