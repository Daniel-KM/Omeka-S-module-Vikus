<?php declare(strict_types=1);

namespace Vikus\Stdlib;

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

        foreach (['property_keywords', 'properties_detail'] as $key) {
            $data[$key] = array_values(array_filter((array) ($data[$key] ?? [])));
        }
        if (array_key_exists('timeline_events', $data)) {
            $data['timeline_events'] = self::timelineEvents($data['timeline_events']);
        }
        $data['link_to_item'] = !empty($data['link_to_item']);
        $data['columns'] = max(1, min(50, (int) ($data['columns'] ?? 6)));
        $data['height'] = preg_match('~^\d+(\.\d+)?(px|vh|em|rem|%)$~', trim((string) ($data['height'] ?? '')))
            ? trim((string) $data['height'])
            : '80vh';
        $data['image_source'] = in_array($data['image_source'] ?? null, self::IMAGE_SOURCES, true)
            ? $data['image_source']
            : 'auto';
        $data['filter_type'] = in_array($data['filter_type'] ?? null, self::FILTER_TYPES, true)
            ? $data['filter_type']
            : 'tags';

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
