<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Common\Form\Element\ArrayTextarea;
use Common\Form\Element\DataTextarea;
use Omeka\Settings\AbstractSettings;
use Vikus\Form\VikusFieldset;

/**
 * Normalize and validate the options of a viewer.
 *
 * The same options are set by a page block and by the site settings, so they
 * are checked at a single place: a block is checked when it is hydrated, the
 * site settings when they are read.
 */
class OptionsNormalizer
{
    const IMAGE_SOURCES = ['auto', 'omeka', 'iiif'];

    const FILTER_TYPES = ['tags', 'hierarchical', 'crossfilter'];

    const SPRITE_SIZES = [256, 128];

    const YEAR_PRECISIONS = ['year', 'decade', 'century'];

    const MAP_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    const MAP_ATTRIBUTION = '© OpenStreetMap contributors';

    /**
     * Read the normalized options of a viewer from the settings of a site.
     *
     * @param array $defaults The config "vikus.site_settings", whose keys are
     * prefixed by "vikus_".
     */
    public static function fromSettings(AbstractSettings $settings, array $defaults): array
    {
        $data = [];
        $unprefixed = [];
        foreach ($defaults as $name => $default) {
            $key = substr($name, 6);
            $unprefixed[$key] = $default;
            $data[$key] = $settings->get($name, $default);
        }
        return self::normalize($data, $unprefixed);
    }

    public static function normalize(array $data, array $defaults): array
    {
        if (empty($data['query'])) {
            $data['query'] = [];
        } elseif (!is_array($data['query'])) {
            $query = [];
            parse_str(ltrim((string) $data['query'], "? \t\n\r\0\x0B"), $query);
            $data['query'] = $query;
        }

        $data['max_items'] = max(0, (int) ($data['max_items'] ?? 0));

        foreach (['property_keywords', 'properties_detail', 'properties_group'] as $key) {
            $data[$key] = array_values(array_filter((array) ($data[$key] ?? [])));
        }
        foreach (['property_year', 'property_year_annotation'] as $key) {
            $value = $data[$key] ?? null;
            $data[$key] = is_string($value) ? trim($value) : '';
        }
        $data['layout_titles'] = self::layoutTitles($data['layout_titles'] ?? []);
        if (array_key_exists('timeline_events', $data)) {
            $data['timeline_events'] = self::timelineEvents($data['timeline_events']);
        }
        $data['link_to_item'] = !empty($data['link_to_item']);
        $data['layout_map'] = !empty($data['layout_map']);
        $outliers = $data['map_outliers'] ?? 'clamp';
        // The option was a checkbox before, where checked meant the clamping.
        if (in_array($outliers, [true, false, 0, 1, '0', '1'], true)) {
            $outliers = $outliers ? 'clamp' : 'none';
        }
        $data['map_outliers'] = in_array($outliers, DataBuilder::MAP_OUTLIERS, true)
            ? $outliers
            : 'clamp';
        $tiles = trim((string) ($data['map_tiles'] ?? ''));
        $data['map_tiles'] = self::isTileUrl($tiles)
            ? $tiles
            : (string) ($defaults['map_tiles'] ?? self::MAP_TILES);
        // Leaflet writes the attribution as html in its control, so the tags
        // are removed here and the script escapes the text a second time: the
        // option is a plain text, not a markup.
        $data['map_attribution'] = trim(strip_tags((string) ($data['map_attribution'] ?? '')));
        $opacity = $data['map_opacity'] ?? null;
        $data['map_opacity'] = is_numeric($opacity)
            ? max(0.0, min(1.0, (float) $opacity))
            : (float) ($defaults['map_opacity'] ?? 0.6);
        $data['map_hide_missing'] = !empty($data['map_hide_missing']);
        $data['columns'] = max(1, min(50, (int) ($data['columns'] ?? 6)));
        $data['height'] = preg_match('~^\d+(\.\d+)?(px|vh|em|rem|%)$~', trim((string) ($data['height'] ?? '')))
            ? trim((string) $data['height'])
            : '80vh';
        $data['sprite_size'] = in_array((int) ($data['sprite_size'] ?? 0), self::SPRITE_SIZES, true)
            ? (int) $data['sprite_size']
            : 256;
        $data['image_source'] = in_array($data['image_source'] ?? null, self::IMAGE_SOURCES, true)
            ? $data['image_source']
            : 'auto';
        $data['filter_type'] = in_array($data['filter_type'] ?? null, self::FILTER_TYPES, true)
            ? $data['filter_type']
            : 'tags';
        $data['year_precision'] = in_array($data['year_precision'] ?? null, self::YEAR_PRECISIONS, true)
            ? $data['year_precision']
            : 'year';

        foreach (array_keys(VikusFieldset::STYLES) as $key) {
            $key = 'style_' . $key;
            $value = $data[$key] ?? null;
            $data[$key] = is_string($value) && preg_match('~^#[0-9a-fA-F]{6}$~', $value)
                ? $value
                : ($defaults[$key] ?? '#000000');
        }

        return $data;
    }

    /**
     * Check an url template of a tile server, as Leaflet expects it.
     *
     * Only http and https are allowed, and the three placeholders must be
     * present: a template without them would make the map request the same tile
     * everywhere.
     */
    protected static function isTileUrl(string $url): bool
    {
        return (bool) preg_match('~^https?://[^\s<>"\']+$~', $url)
            && str_contains($url, '{z}')
            && str_contains($url, '{x}')
            && str_contains($url, '{y}');
    }

    /**
     * Normalize the titles of the layouts, set as a string by the form.
     *
     * Each line is a pair "term = Title", so the value is an array of titles
     * by term. The keys are kept as they are typed: besides the terms of the
     * group layouts, ConfigBuilder reads the two reserved keys "time" and
     * "map". A line without title is skipped: it would hide the label of the
     * property behind an empty navigation button.
     */
    protected static function layoutTitles($titles): array
    {
        if (is_string($titles)) {
            $element = new ArrayTextarea();
            $element->setAsKeyValue(true);
            $titles = $element->stringToArray($titles);
        }
        $result = [];
        foreach ((array) $titles as $term => $title) {
            $title = trim((string) $title);
            if ($title !== '') {
                $result[(string) $term] = $title;
            }
        }
        return $result;
    }

    /**
     * Normalize the events of the timeline, stored as a string by the form.
     *
     * Each event is a row with the keys of VikusFieldset::TIMELINE_KEYS. The
     * events without year are skipped: the viewer places a row of the timeline
     * in the column of its year.
     */
    protected static function timelineEvents($events): array
    {
        if (is_string($events)) {
            $element = new DataTextarea();
            $element->setDataOptions(VikusFieldset::TIMELINE_KEYS);
            $events = $element->stringToArray($events);
        }
        $result = [];
        foreach ((array) $events as $event) {
            $event = array_replace(VikusFieldset::TIMELINE_KEYS, array_intersect_key((array) $event, VikusFieldset::TIMELINE_KEYS));
            $event = array_map(fn ($value): string => trim((string) $value), $event);
            if ($event['year'] !== '') {
                $result[] = $event;
            }
        }
        return $result;
    }
}
