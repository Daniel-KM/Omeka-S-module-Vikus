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
     * The titles of the group layouts are read live from the options: they
     * are a presentation only and do not require to rebuild the cache.
     *
     * @param array $meta The meta data of the cache: "map" (whether it
     * contains a map.csv, only built when the module Mapping is active and an
     * item has a point), "sprite_size" (size of the sprites of the published
     * atlases, that may differ from the option when it changed since the last
     * generation) and the counts "years" and "groups" of DataBuilder.
     */
    public function build(array $options, string $title, string $cacheUrl, array $meta = []): array
    {
        $style = [];
        foreach ($options as $key => $value) {
            if (str_starts_with((string) $key, 'style_')) {
                $style[substr($key, 6)] = (string) $value;
            }
        }
        $style['textShadow'] = '1px 1px 0px ' . ($style['canvasBackground'] ?? '#ffffff');

        $columns = (int) ($options['columns'] ?? 6) ?: 6;
        $years = (int) ($meta['years'] ?? 0);
        $groupCounts = (array) ($meta['groups'] ?? []);

        // The first layout is the default one and the viewer hides the
        // navigation when there is only one.
        $layouts = [['title' => 'Time']];
        $titles = ['Time' => true];
        $layoutTitles = (array) ($options['layout_titles'] ?? []);
        foreach ((array) ($options['properties_group'] ?? []) as $term) {
            $layouts[] = [
                'title' => $this->uniqueTitle($layoutTitles[$term] ?? $this->label($term), $titles, $term),
                'type' => 'group',
                'groupKey' => DataBuilder::columnGroup($term),
                'columns' => $this->groupColumns($columns, $years, (int) ($groupCounts[$term] ?? 0)),
            ];
        }

        // The map uses the csv mechanism of the similarity layouts of the
        // viewer, that rescales the columns "x" and "y" on their extent.
        $map = [];
        if (!empty($options['layout_map']) && !empty($meta['map'])) {
            $mapTitle = $this->uniqueTitle($this->translator->translate('Map'), $titles, 'map.csv'); // @translate
            $layouts[] = [
                'title' => $mapTitle,
                'url' => $cacheUrl . '/map.csv',
                'scale' => 0.5,
            ];
            $map = $this->map($options, $mapTitle, $style, (array) ($meta['map_bounds'] ?? []));
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
                    'medium' => ['size' => (int) ($meta['sprite_size'] ?? 0) ?: SpriteBuilder::SIZE, 'url' => $cacheUrl . '/sprites/sprites.json'],
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
        if ($map) {
            // The viewer ignores the keys it does not know, so the settings of
            // the base map travel with its own config.
            $config['vikus'] = ['map' => $map];
        }

        return $config;
    }

    /**
     * Build the settings of the base map displayed behind the map layout.
     *
     * The bounds are the geographic ones of the window of map.csv, that the
     * script of the module inverts to follow the zoom of the canvas. Without
     * them, there is nothing to align the map on, so no base map is declared.
     *
     * @return array Empty when the cache has no bounds (generated before).
     */
    protected function map(array $options, string $title, array $style, array $bounds): array
    {
        if (count($bounds) !== 4) {
            return [];
        }
        return [
            'title' => $title,
            'bounds' => array_map('floatval', $bounds),
            'tiles' => (string) ($options['map_tiles'] ?? OptionsNormalizer::MAP_TILES),
            'attribution' => (string) ($options['map_attribution'] ?? ''),
            'opacity' => (float) ($options['map_opacity'] ?? 0.6),
            'hideMissing' => !empty($options['map_hide_missing']),
            // The canvas of the viewer becomes transparent to let the map
            // through, so the page carries its background itself.
            'background' => (string) ($style['canvasBackground'] ?? '#ffffff'),
        ];
    }

    /**
     * Get the width of a group layout, so that its images stay readable.
     *
     * The viewer nests the items of a key in a fixed number of columns, so a
     * property with many values spreads the canvas over hundreds of columns
     * and its images are a few pixels wide. The total width of the layout is
     * aligned on the one of the time layout, that the option describes: the
     * columns of a group are the columns of a year multiplied by the ratio of
     * the count of the years to the count of the values. The option is kept
     * when a count is missing, for instance with a cache generated before
     * they were recorded.
     */
    protected function groupColumns(int $columns, int $years, int $groups): int
    {
        return $years > 0 && $groups > 0
            ? max(1, (int) round($columns * $years / $groups))
            : $columns;
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
