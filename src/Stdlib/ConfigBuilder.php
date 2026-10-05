<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Common\Stdlib\EasyMeta;
use Laminas\I18n\Translator\TranslatorInterface;

class ConfigBuilder
{
    protected EasyMeta $easyMeta;

    protected TranslatorInterface $translator;

    public function __construct(EasyMeta $easyMeta, TranslatorInterface $translator)
    {
        $this->easyMeta = $easyMeta;
        $this->translator = $translator;
    }

    /**
     * Build the config of the viewer from the options of a block.
     *
     * @param bool $hasMap Whether the cache contains a map.csv, that is only
     * built when the module Mapping is active and an item has a point.
     */
    public function build(array $options, string $title, string $cacheUrl, bool $hasMap = false): array
    {
        $style = [];
        foreach ($options as $key => $value) {
            if (str_starts_with((string) $key, 'style_')) {
                $style[substr($key, 6)] = (string) $value;
            }
        }
        $style['textShadow'] = '1px 1px 0px ' . ($style['canvasBackground'] ?? '#ffffff');

        $columns = (int) ($options['columns'] ?? 6) ?: 6;

        // The first layout is the default one and the viewer hides the
        // navigation when there is only one.
        $layouts = [['title' => 'Time']];
        $titles = ['Time' => true];
        foreach ((array) ($options['properties_group'] ?? []) as $term) {
            $layouts[] = [
                'title' => $this->uniqueTitle($this->label($term), $titles, $term),
                'type' => 'group',
                'groupKey' => DataBuilder::columnGroup($term),
                'columns' => $columns,
            ];
        }

        // The map uses the csv mechanism of the similarity layouts of the
        // viewer, that rescales the columns "x" and "y" on their extent.
        if (!empty($options['layout_map']) && $hasMap) {
            $mapTitle = $this->translator->translate('Map'); // @translate
            $layouts[] = [
                'title' => $this->uniqueTitle($mapTitle, $titles, 'map.csv'),
                'url' => $cacheUrl . '/map.csv',
                'scale' => 0.5,
            ];
        }

        $structure = [];
        foreach ((array) ($options['properties_detail'] ?? []) as $term) {
            $structure[] = [
                'name' => $this->label($term),
                'source' => DataBuilder::column($term),
                'display' => 'column',
                'type' => 'text',
            ];
        }
        if (!empty($options['link_to_item'])) {
            $structure[] = [
                'name' => $this->translator->translate('View the record'), // @translate
                'source' => '_url',
                'display' => 'column',
                'type' => 'link',
            ];
        }

        $config = [
            'project' => [
                'name' => trim((string) ($options['title'] ?? '')) ?: $title,
                'quality' => 1,
            ],
            'loader' => [
                'info' => $cacheUrl . '/info.md',
                'items' => $cacheUrl . '/data.csv',
                'timeline' => $cacheUrl . '/timeline.csv',
                'layouts' => $layouts,
                'textures' => [
                    'medium' => ['size' => 256, 'url' => $cacheUrl . '/sprites/sprites.json'],
                    'detail' => ['size' => 1024, 'csv' => '_detail'],
                    'big' => ['size' => 4096, 'csv' => '_big'],
                ],
            ],
            'style' => $style,
            'projection' => [
                'columns' => $columns,
            ],
            'detail' => [
                'structure' => $structure,
            ],
        ];

        $filter = $this->filter($options);
        if ($filter) {
            $config['filter'] = $filter;
        }

        return $config;
    }

    /**
     * Build the filter of the viewer, empty for the default keyword tags.
     *
     * In hierarchical mode, the viewer splits each keyword on ":" itself. In
     * crossfilter mode, each property of the keywords is a dimension, read in
     * its own column of data.csv.
     */
    protected function filter(array $options): array
    {
        $type = (string) ($options['filter_type'] ?? 'tags');
        if ($type === 'hierarchical') {
            return ['type' => 'hierarchical'];
        }
        if ($type !== 'crossfilter') {
            return [];
        }
        $dimensions = [];
        foreach ((array) ($options['property_keywords'] ?? []) as $term) {
            $dimensions[] = [
                'source' => DataBuilder::columnFilter($term),
                'label' => $this->label($term),
            ];
        }
        return ['type' => 'crossfilter', 'dimensions' => $dimensions];
    }

    /**
     * Get a title not yet used by a layout, suffixed when it is taken.
     *
     * The viewer identifies a layout by its title, so two layouts sharing one
     * would override each other.
     */
    protected function uniqueTitle(string $title, array &$titles, string $suffix): string
    {
        if (isset($titles[$title])) {
            $title .= ' (' . $suffix . ')';
        }
        $titles[$title] = true;
        return $title;
    }

    /**
     * Get the translated label of a property, or the term when unknown.
     */
    protected function label(string $term): string
    {
        $label = $this->easyMeta->propertyLabel($term);
        return $label ? $this->translator->translate($label) : $term;
    }
}
