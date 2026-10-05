<?php declare(strict_types=1);

namespace Vikus\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;
use Vikus\Stdlib\OptionsNormalizer;

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

    /**
     * Groups of the elements, keyed by the option key of their first member.
     */
    const ELEMENT_GROUPS = [
        'vikus_items' => [
            'label' => 'Items', // @translate
            'keys' => ['query', 'max_items', 'title', 'info', 'regenerate'],
        ],
        'vikus_alignment' => [
            'label' => 'Alignment', // @translate
            'keys' => [
                'property_year',
                'property_year_annotation',
                'year_precision',
                'property_keywords',
                'properties_detail',
                'link_to_item',
            ],
        ],
        'vikus_layouts' => [
            'label' => 'Layouts', // @translate
            'keys' => [
                'columns',
                'properties_group',
                'layout_titles',
                'filter_type',
                'timeline_events',
            ],
        ],
        'vikus_map' => [
            'label' => 'Map', // @translate
            'keys' => [
                'layout_map',
                'map_outliers',
                'map_hide_missing',
                'map_tiles',
                'map_attribution',
                'map_opacity',
            ],
        ],
        'vikus_images' => [
            'label' => 'Images', // @translate
            'keys' => ['image_source', 'sprite_size', 'height'],
        ],
        'vikus_style' => [
            'label' => 'Style', // @translate
            'keys' => null,
        ],
    ];

    public function init(): void
    {
        $name = fn (string $key): string => 'o:block[__blockIndex__][o:data][' . $key . ']';
        $id = fn (string $key): string => 'vikus-' . strtolower(strtr($key, '_', '-'));

        $elementGroups = [];
        foreach (self::ELEMENT_GROUPS as $group => $data) {
            $elementGroups[$group] = $data['label'];
        }
        $this->setOption('element_groups', $elementGroups);

        $groupByKey = [];
        foreach (self::ELEMENT_GROUPS as $group => $data) {
            foreach ($data['keys'] ?? [] as $key) {
                $groupByKey[$key] = $group;
            }
        }

        foreach (self::elementSpecs($name, $id) as $key => $spec) {
            $spec['options']['element_group'] = $groupByKey[$key] ?? 'vikus_style';
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
            'max_items' => [
                'name' => $name('max_items'),
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Maximum number of items', // @translate
                    'info' => 'Leave empty to keep all the items of the query. The viewer stays smooth up to about 2000 items on laptops and phones.', // @translate
                ],
                'attributes' => [
                    'id' => $id('max_items'),
                    'min' => 1,
                    'step' => 1,
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
            'regenerate' => [
                'name' => $name('regenerate'),
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Regenerate on save', // @translate
                    'info' => 'The data and the sprites are rebuilt only when an option changes. Check to include the items added or modified since the last generation. The box is not kept.', // @translate
                ],
                'attributes' => [
                    'id' => $id('regenerate'),
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
                    // The editor of pairs has no column mode, so the year is
                    // the key of a row and the three other fields stay in its
                    // value, separated by "=" as in the stored line.
                    'pairs_editor' => [
                        'format' => 'lines',
                        'key_label' => 'Year', // @translate
                        'value_label' => 'Title = text = extra note', // @translate
                    ],
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
                    'label' => 'Titles of the layouts', // @translate
                    'info' => 'Write one title by line like "dcterms:subject = Themes". The label of the property is used when it is not set. The two keys "time" and "map", to be typed by hand, rename the time layout and the map layout. A short title is advised: the navigation buttons of the viewer are narrow.', // @translate
                    // Important: OptionsNormalizer rebuilds this element in the
                    // same mode to parse the value stored as a string.
                    'as_key_value' => true,
                    // The known keys are read from the select of the group
                    // layouts, so the picker of the editor lists the chosen
                    // properties only, each one with its label as the title.
                    // The keys stay free ("free_keys" of the pairs editor
                    // defaults to true), so "time" and "map" can be typed.
                    'pairs_editor' => [
                        'key_label' => 'Property', // @translate
                        'value_label' => 'Title', // @translate
                        'key_source' => '#' . $id('properties_group'),
                    ],
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
            'map_outliers' => [
                'name' => $name('map_outliers'),
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Isolated points of the map', // @translate
                    'info' => 'The map fills the canvas, so a few distant points reduce the area where most of the items are to a few pixels. The isolated points are moved to the border of that area, or hidden on the map only, or kept where they are. Below fifty points, nothing is done.', // @translate
                    'value_options' => [
                        'clamp' => 'Move them to the border of the dense area', // @translate
                        'hide' => 'Hide them on the map', // @translate
                        'none' => 'Keep them where they are', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => $id('map_outliers'),
                ],
            ],
            'map_tiles' => [
                'name' => $name('map_tiles'),
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Tiles of the base map', // @translate
                    'info' => 'Url template of a tile server, with the placeholders {z}, {x} and {y}. The tiles of OpenStreetMap are not meant for a public site: use a self-hosted or a licensed tile server.', // @translate
                ],
                'attributes' => [
                    'id' => $id('map_tiles'),
                    'placeholder' => OptionsNormalizer::MAP_TILES,
                ],
            ],
            'map_attribution' => [
                'name' => $name('map_attribution'),
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Attribution of the base map', // @translate
                    'info' => 'Displayed in the corner of the map, as the licence of the tiles requires it.', // @translate
                ],
                'attributes' => [
                    'id' => $id('map_attribution'),
                    'placeholder' => OptionsNormalizer::MAP_ATTRIBUTION,
                ],
            ],
            'map_opacity' => [
                'name' => $name('map_opacity'),
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Opacity of the base map', // @translate
                    'info' => 'From 0 (invisible) to 1 (full): a faded map keeps the images readable.', // @translate
                ],
                'attributes' => [
                    'id' => $id('map_opacity'),
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.1,
                ],
            ],
            'map_hide_missing' => [
                'name' => $name('map_hide_missing'),
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Hide the items without coordinates', // @translate
                    'info' => 'The viewer keeps the items absent from the file of the layout in the canvas, dimmed and laid out on a ring around the map. Check this option to make them fully transparent on the map layout, where they mean nothing. The items excluded by the current filter are hidden the same way on this layout.', // @translate
                ],
                'attributes' => [
                    'id' => $id('map_hide_missing'),
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
