<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Doctrine\ORM\EntityManager;
use Laminas\I18n\Translator\TranslatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

class DataBuilder
{
    const BATCH = 100;

    /**
     * Minimal count of points required to process the isolated ones.
     */
    const MAP_OUTLIERS_MIN = 50;

    /**
     * Ways to handle the isolated points of the map.
     */
    const MAP_OUTLIERS = ['clamp', 'hide', 'none'];

    /**
     * Highest latitude of the Web Mercator projection, in degrees.
     */
    const MAP_LATITUDE_MAX = 85.05112878;

    /**
     * Smallest half side of the window of the map, in projected units.
     *
     * A unit is a radian of longitude, so this is about six kilometres: it
     * keeps a window around a collection whose points are all the same.
     */
    const MAP_HALF_MIN = 0.001;

    /**
     * Ids of the two rows anchoring the window of the map, never an item.
     */
    const MAP_ANCHORS = [0, -1];

    /**
     * Options that require to rebuild the cache when they are updated.
     */
    const DATA_OPTIONS = [
        'query',
        'property_year',
        'property_year_annotation',
        'year_precision',
        'property_keywords',
        'properties_detail',
        'timeline_events',
        'filter_type',
        'properties_group',
        'image_source',
        'sprite_size',
        'link_to_item',
        'layout_map',
        'map_outliers',
        'info',
    ];

    protected ApiManager $api;

    protected EntityManager $entityManager;

    protected ImageUrl $imageUrl;

    protected TranslatorInterface $translator;

    protected string $filesBasePath;

    protected bool $hasMapping;

    /**
     * Counts of the distinct keys of the layouts of the last build.
     */
    protected array $stats = ['years' => 0, 'groups' => [], 'map_bounds' => []];

    /**
     * @var callable|null
     */
    protected $digitalObjectInline;

    public function __construct(
        ApiManager $api,
        EntityManager $entityManager,
        ImageUrl $imageUrl,
        TranslatorInterface $translator,
        string $filesBasePath,
        bool $hasMapping,
        ?callable $digitalObjectInline = null
    ) {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->imageUrl = $imageUrl;
        $this->translator = $translator;
        $this->filesBasePath = rtrim($filesBasePath, '/');
        $this->hasMapping = $hasMapping;
        $this->digitalObjectInline = $digitalObjectInline;
    }

    public static function hash(array $options): string
    {
        $data = array_intersect_key($options, array_flip(self::DATA_OPTIONS));
        ksort($data);
        return sha1(json_encode($data));
    }

    /**
     * Extract a year from a date, as the first number of four digits.
     *
     * Four digits are required: a date with unknown digits ("18XX~") or a
     * truncated one would else give a wrong year. So the years before 1000
     * must be written with four digits ("0476", "-0050"), as EDTF does. A
     * longer run of digits is read from its first four ones ("01889" gives
     * 188).
     */
    public static function extractYear(string $value): string
    {
        $matches = [];
        return preg_match('~-?\d{4}~', $value, $matches)
            ? (string) (int) $matches[0]
            : '';
    }

    /**
     * Round a year down to the first year of its decade or of its century.
     *
     * The division is a floor one, so a negative year is rounded away from
     * zero like a positive one is rounded toward the past: the decade of
     * "-45" begins at "-50" and its century at "-100". An empty year stays
     * empty and an unknown precision keeps the year as is.
     */
    public static function roundYear(string $year, string $precision): string
    {
        $step = ['decade' => 10, 'century' => 100][$precision] ?? 1;
        if ($year === '' || $step === 1) {
            return $year;
        }
        return (string) ((int) floor((int) $year / $step) * $step);
    }

    /**
     * Clamp the coordinates of the points to their dense area.
     *
     * The viewer rescales the columns "x" and "y" of a csv layout on the
     * extent of the data, so a handful of distant points shrink the area
     * where most of the collection lies to a few pixels. The coordinates are
     * clamped to the range of the second and of the ninety-eighth percentiles
     * of each axis: the isolated points are pinned to the border of the dense
     * area instead of stretching it. A small collection is kept as is, where
     * a distant point is a part of the subject and not an outlier.
     *
     * @param array $points Pairs [x, y] by item id.
     * @return array The same pairs, in the same order.
     */
    public static function clampOutliers(array $points): array
    {
        if (count($points) < self::MAP_OUTLIERS_MIN) {
            return $points;
        }
        [$minX, $maxX] = self::percentiles(array_column($points, 0));
        [$minY, $maxY] = self::percentiles(array_column($points, 1));
        foreach ($points as $id => $point) {
            $points[$id] = [
                min($maxX, max($minX, $point[0])),
                min($maxY, max($minY, $point[1])),
            ];
        }
        return $points;
    }

    /**
     * Drop the points outside of the dense area instead of clamping them.
     *
     * The same range as clampOutliers() is used, but an isolated point is
     * removed from the layout: the viewer hides an item absent from the csv, so
     * it disappears from the map and stays on the other layouts. It is the
     * choice to make when a point on the border of the map would be read as a
     * real location. A small collection is kept as is, as when clamping.
     *
     * @param array $points Pairs [x, y] by item id.
     * @return array The kept pairs, in the same order.
     */
    public static function hideOutliers(array $points): array
    {
        if (count($points) < self::MAP_OUTLIERS_MIN) {
            return $points;
        }
        [$minX, $maxX] = self::percentiles(array_column($points, 0));
        [$minY, $maxY] = self::percentiles(array_column($points, 1));
        return array_filter($points, fn (array $point): bool => $point[0] >= $minX
            && $point[0] <= $maxX
            && $point[1] >= $minY
            && $point[1] <= $maxY);
    }

    /**
     * Project a latitude on the ordinate of the Web Mercator projection.
     *
     * The unit of the result is the radian of longitude, so a window as wide as
     * it is tall in these units is a square on a Web Mercator map, which is
     * what a base map behind the layout requires. The latitude is clamped to
     * the limit of the projection, where the ordinate diverges.
     */
    public static function mercator(float $latitude): float
    {
        $latitude = min(self::MAP_LATITUDE_MAX, max(-self::MAP_LATITUDE_MAX, $latitude));
        return log(tan(M_PI / 4 + deg2rad($latitude) / 2));
    }

    /**
     * Get the latitude of an ordinate of the Web Mercator projection.
     */
    public static function latitude(float $mercator): float
    {
        return rad2deg(2 * atan(exp($mercator)) - M_PI / 2);
    }

    /**
     * Get the square window of the map containing every projected point.
     *
     * The viewer rescales the columns "x" and "y" of a csv layout on their own
     * extent, so it stretches the collection on a square canvas and the two
     * axes get different scales: a base map cannot follow. The window is
     * therefore squared here, in projected units, and written as two anchor
     * rows that set the extent of the file. The collection is then inscribed in
     * a square, undistorted, and the whole window is clamped to the world.
     *
     * @param array $points Pairs [longitude, ordinate] by item id.
     * @return array [longitude min, ordinate min, longitude max, ordinate max]
     */
    public static function mapWindow(array $points): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $half = max(
            deg2rad(max($xs) - min($xs)) / 2,
            (max($ys) - min($ys)) / 2,
            self::MAP_HALF_MIN
        );
        $half = min($half, M_PI);
        $halfDegrees = rad2deg($half);
        $centerX = min(180 - $halfDegrees, max(-180 + $halfDegrees, (min($xs) + max($xs)) / 2));
        $centerY = min(M_PI - $half, max(-M_PI + $half, (min($ys) + max($ys)) / 2));
        return [
            $centerX - $halfDegrees,
            $centerY - $half,
            $centerX + $halfDegrees,
            $centerY + $half,
        ];
    }

    /**
     * Get the second and the ninety-eighth percentiles of a list of values.
     */
    protected static function percentiles(array $values): array
    {
        sort($values);
        $last = count($values) - 1;
        return [
            $values[(int) round($last * 0.02)],
            $values[(int) round($last * 0.98)],
        ];
    }

    public static function column(string $term): string
    {
        return '_' . strtr($term, [':' => '_']);
    }

    /**
     * Name of the column of a dimension of the crossfilter.
     */
    public static function columnFilter(string $term): string
    {
        return '_f' . self::column($term);
    }

    /**
     * Name of the column used as the key of a group layout.
     */
    public static function columnGroup(string $term): string
    {
        return '_g' . self::column($term);
    }

    /**
     * Counts of the distinct keys of the layouts of the last build.
     *
     * The key "years" is the number of distinct non-empty years, that is the
     * number of columns of the time layout, and the key "groups" the number
     * of distinct values by property of the group layouts. They allow the
     * config to give a group layout a width comparable to the time one.
     *
     * @return array Keys "years" (int) and "groups" (int by term).
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Write data.csv in a directory and list the thumbnails to pack.
     *
     * @return array Sources (path or url) of medium thumbnails by item id.
     */
    public function build(
        array $options,
        SiteRepresentation $site,
        string $dir,
        int $maxItems,
        ?callable $shouldStop = null
    ): array {
        $termsKeywords = (array) ($options['property_keywords'] ?? []);
        $termsDetail = (array) ($options['properties_detail'] ?? []);
        $imageSource = (string) ($options['image_source'] ?? 'auto');
        $linkToItem = !empty($options['link_to_item']);
        // A dimension of the crossfilter is a single column of data.csv, so one
        // is written by property of the keywords, but only in that mode.
        $termsFilter = ($options['filter_type'] ?? 'tags') === 'crossfilter'
            ? $termsKeywords
            : [];
        $termsGroup = (array) ($options['properties_group'] ?? []);
        // The viewer removes the empty keys from the domain of a group layout
        // and places an item with an unknown key outside of the canvas, so the
        // items without value are grouped under a label.
        $unknown = $this->translator->translate('Unknown'); // @translate

        $this->stats = ['years' => 0, 'groups' => [], 'map_bounds' => []];
        $years = [];
        $groups = array_fill_keys($termsGroup, []);

        $headers = array_merge(
            ['id', 'keywords', 'year'],
            array_map([self::class, 'column'], $termsDetail),
            array_map([self::class, 'columnFilter'], $termsFilter),
            array_map([self::class, 'columnGroup'], $termsGroup),
            $linkToItem ? ['_url'] : [],
            ['_detail', '_big']
        );

        // The escape character is disabled: with the default backslash, a value
        // containing one before a quote is written verbatim and the parser of
        // the viewer (d3, RFC 4180) shifts the columns of the row.
        $handle = fopen($dir . '/data.csv', 'w');
        fputcsv($handle, $headers, ',', '"', '');

        $this->buildTimeline($options, $dir);

        // The items are filtered on the presence of a media, that is the only
        // image of an item in a standard install. When the instance has
        // digital objects, an item without media may have one, so the filter
        // is skipped and the items without image are simply not written.
        $query = ['site_id' => $site->id()]
            + ($this->hasDigitalObjects() ? [] : ['has_media' => 1])
            + (array) ($options['query'] ?? []);
        $query['sort_by'] = 'id';
        $query['sort_order'] = 'asc';
        // The items are read by batches, so the limit of the query cannot be
        // passed to the api: it caps the generation instead, like the main
        // setting does, and the smaller of the two wins.
        $limit = (int) ($query['limit'] ?? 0);
        if ($limit > 0) {
            $maxItems = min($limit, $maxItems);
        }
        $query['limit'] = self::BATCH;
        unset($query['page'], $query['per_page']);

        $sources = [];
        for ($offset = 0; count($sources) < $maxItems; $offset += self::BATCH) {
            if ($shouldStop && $shouldStop()) {
                break;
            }
            $items = $this->api->search('items', ['offset' => $offset] + $query)->getContent();
            if (!$items) {
                break;
            }
            foreach ($items as $item) {
                $image = $this->imageResource($item);
                if (!$image) {
                    continue;
                }
                $urls = $this->imageUrl->urls($image, $imageSource);
                $year = $this->year($item, $options);
                if ($year !== '') {
                    $years[$year] = true;
                }
                $row = [
                    $item->id(),
                    implode(',', $this->keywords($item, $termsKeywords)),
                    $year,
                ];
                foreach ($termsDetail as $term) {
                    $row[] = implode(' ; ', array_map([$this, 'label'], $item->value($term, ['all' => true])));
                }
                foreach ($termsFilter as $term) {
                    $row[] = $this->firstLabel($item, $term);
                }
                foreach ($termsGroup as $term) {
                    $group = $this->firstLabel($item, $term) ?: $unknown;
                    $groups[$term][$group] = true;
                    $row[] = $group;
                }
                if ($linkToItem) {
                    $row[] = $item->siteUrl($site->slug(), true);
                }
                $row[] = $urls['detail'];
                $row[] = $urls['big'];
                fputcsv($handle, $row, ',', '"', '');

                $sources[$item->id()] = $this->thumbnailSource($image);
                if (count($sources) >= $maxItems) {
                    break;
                }
            }
            // The identity map keeps every item and value of the batch, so it
            // must be cleared to build a collection of thousands of items
            // without exhausting the memory.
            $this->entityManager->clear();
        }

        fclose($handle);

        $this->stats = [
            'years' => count($years),
            'groups' => array_map('count', $groups),
            'map_bounds' => [],
        ];

        $this->buildMap($options, array_keys($sources), $dir, $shouldStop);

        return $sources;
    }

    /**
     * Write map.csv, whose rows place the items with a point on a map.
     *
     * The column "x" is the longitude in degrees and the column "y" the Web
     * Mercator ordinate of the latitude, so that a base map can be displayed
     * behind the layout. The viewer negates "y" to place a row and a negative y
     * is upward, so the ordinate gives the north up. The viewer rescales both
     * columns on the extent of the file, so two anchor rows write the square
     * window of mapWindow() and keep the map undistorted. The items without
     * point are simply absent: the viewer hides them in that layout.
     *
     * The file is skipped when no item has a point, so that the layout is not
     * appended to a config pointing to a missing file. The geographic bounds of
     * the window are recorded in the stats, for the config of the base map.
     *
     * @param int[] $itemIds Ids of the items written in data.csv, in order.
     */
    protected function buildMap(
        array $options,
        array $itemIds,
        string $dir,
        ?callable $shouldStop = null
    ): void {
        if (empty($options['layout_map']) || !$this->hasMapping || !$itemIds) {
            return;
        }

        // The module Mapping may be absent, so none of its classes can be
        // referenced here: its api resource and its json keys are used only.
        $points = [];
        foreach (array_chunk($itemIds, self::BATCH) as $ids) {
            if ($shouldStop && $shouldStop()) {
                return;
            }
            // The features of an item are sorted, so the point kept for it is
            // the same from one generation to the next.
            $features = $this->api->search('mapping_features', [
                'item_id' => $ids,
                'sort_by' => 'id',
                'sort_order' => 'asc',
            ])->getContent();
            foreach ($features as $feature) {
                $itemId = $feature->item()->id();
                if (isset($points[$itemId]) || $feature->geographyType() !== 'Point') {
                    continue;
                }
                $coordinates = $feature->geographyCoordinates();
                $points[$itemId] = [$coordinates[0], self::mercator((float) $coordinates[1])];
            }
            $this->entityManager->clear();
        }
        if (!$points) {
            return;
        }
        // The isolated points are handled on the projected coordinates, so
        // the percentiles describe the map that the visitor sees.
        $outliers = (string) ($options['map_outliers'] ?? 'clamp');
        if ($outliers === 'clamp') {
            $points = self::clampOutliers($points);
        } elseif ($outliers === 'hide') {
            $points = self::hideOutliers($points);
        }
        if (!$points) {
            return;
        }

        [$minX, $minY, $maxX, $maxY] = self::mapWindow($points);
        $this->stats['map_bounds'] = [
            $minX,
            self::latitude($minY),
            $maxX,
            self::latitude($maxY),
        ];

        $handle = fopen($dir . '/map.csv', 'w');
        fputcsv($handle, ['id', 'x', 'y'], ',', '"', '');
        // The two anchors set the extent of the file, hence the window that
        // the viewer maps on the canvas. Their ids are never those of an item,
        // so they place no image.
        fputcsv($handle, [self::MAP_ANCHORS[0], $minX, $minY], ',', '"', '');
        fputcsv($handle, [self::MAP_ANCHORS[1], $maxX, $maxY], ',', '"', '');
        foreach ($itemIds as $itemId) {
            if (isset($points[$itemId])) {
                fputcsv($handle, [$itemId, $points[$itemId][0], $points[$itemId][1]], ',', '"', '');
            }
        }
        fclose($handle);
    }

    /**
     * Write timeline.csv, whose rows are events displayed under the years.
     *
     * The viewer reads the columns "titel" (german spelling), "text" and
     * "extra", and it always loads the file, so it is written even when it has
     * no row: a missing url makes it request "<base>/undefined" and log a
     * loading error in the console.
     */
    protected function buildTimeline(array $options, string $dir): void
    {
        $handle = fopen($dir . '/timeline.csv', 'w');
        fputcsv($handle, ['year', 'titel', 'text', 'extra'], ',', '"', '');
        foreach ((array) ($options['timeline_events'] ?? []) as $event) {
            $year = self::extractYear((string) ($event['year'] ?? ''));
            if ($year === '') {
                continue;
            }
            fputcsv($handle, [
                $year,
                (string) ($event['title'] ?? ''),
                (string) ($event['text'] ?? ''),
                (string) ($event['extra'] ?? ''),
            ], ',', '"', '');
        }
        fclose($handle);
    }

    /**
     * Check if the instance has at least one digital object.
     *
     * The filter on the media of the item query can only be dropped when they
     * exist: without that check, enabling the module DigitalObject alone would
     * make every generation read all the items of the site.
     */
    protected function hasDigitalObjects(): bool
    {
        return $this->digitalObjectInline
            && $this->api->search('digital_objects', ['limit' => 0])->getTotalResults() > 0;
    }

    /**
     * Get the resource used as the image of an item.
     *
     * The primary media is preferred. When the item has none, the first
     * digital object linked to it through the properties configured in the
     * module DigitalObject is used: the module may be absent, so it is only
     * reached through its optional view helper.
     */
    protected function imageResource(ItemRepresentation $item): ?AbstractResourceEntityRepresentation
    {
        $media = $item->primaryMedia();
        if ($media && $media->hasThumbnails()) {
            return $media;
        }
        if (!$this->digitalObjectInline) {
            return null;
        }
        foreach (($this->digitalObjectInline)($item) as $digitalObject) {
            if ($digitalObject->hasThumbnails()) {
                return $digitalObject;
            }
        }
        return null;
    }

    /**
     * Get the source of the thumbnail to pack in a sprite.
     *
     * The large derivative is preferred: the sprites are 256 px, but the
     * medium one may be configured below that size, and an upscale is never
     * done, so it would make the canvas blurry.
     */
    protected function thumbnailSource(AbstractResourceEntityRepresentation $media): string
    {
        foreach (['large', 'medium'] as $type) {
            $path = $this->filesBasePath . '/' . $type . '/' . $media->storageId() . '.jpg';
            if (is_file($path)) {
                return $path;
            }
        }
        return (string) $media->thumbnailUrl('large');
    }

    /**
     * Get the year of an item, read in a value or in a value annotation.
     *
     * When a property of the annotation is set, the year is the first one
     * found in the annotations of the values of the property of the year:
     * many collections carry the dating of a value (an author, an event) in
     * its annotation and not in the item itself. The year is then rounded
     * according to the precision.
     */
    protected function year(ItemRepresentation $item, array $options): string
    {
        $term = (string) ($options['property_year'] ?? '');
        if ($term === '') {
            return '';
        }
        $precision = (string) ($options['year_precision'] ?? 'year');
        $termAnnotation = (string) ($options['property_year_annotation'] ?? '');
        if ($termAnnotation === '') {
            return self::roundYear(self::extractYear((string) $item->value($term)), $precision);
        }
        foreach ($item->value($term, ['all' => true]) as $value) {
            $annotation = $value->valueAnnotation();
            if (!$annotation) {
                continue;
            }
            foreach ($annotation->value($termAnnotation, ['all' => true]) as $annotationValue) {
                $year = self::extractYear((string) $annotationValue);
                if ($year !== '') {
                    return self::roundYear($year, $precision);
                }
            }
        }
        return '';
    }

    protected function keywords(ItemRepresentation $item, array $terms): array
    {
        $keywords = [];
        foreach ($terms as $term) {
            foreach ($item->value($term, ['all' => true]) as $value) {
                $keyword = trim((string) preg_replace('~[\s,]+~u', ' ', $this->label($value)));
                if ($keyword !== '') {
                    $keywords[$keyword] = $keyword;
                }
            }
        }
        return array_values($keywords);
    }

    /**
     * Get the label of the first value of a property, if any.
     *
     * The crossfilter and the group layouts of the viewer take a single value
     * by item: an array is only supported for the column "keywords".
     */
    protected function firstLabel(ItemRepresentation $item, string $term): string
    {
        $value = $item->value($term);
        return $value ? $this->label($value) : '';
    }

    /**
     * Get the label of a value, trimmed.
     *
     * A value made of spaces only must count as empty: it would be an
     * invisible key of a group layout and an invisible keyword.
     */
    protected function label(ValueRepresentation $value): string
    {
        $resource = $value->valueResource();
        return trim($resource
            ? (string) $resource->displayTitle()
            : (string) ($value->value() ?: $value->uri()));
    }
}
