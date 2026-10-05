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
     * Options that require to rebuild the cache when they are updated.
     */
    const DATA_OPTIONS = [
        'query',
        'property_year',
        'property_year_annotation',
        'property_keywords',
        'properties_detail',
        'timeline_events',
        'filter_type',
        'properties_group',
        'image_source',
        'link_to_item',
        'layout_map',
        'info',
    ];

    protected ApiManager $api;

    protected EntityManager $entityManager;

    protected ImageUrl $imageUrl;

    protected TranslatorInterface $translator;

    protected string $filesBasePath;

    protected bool $hasMapping;

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
                $row = [
                    $item->id(),
                    implode(',', $this->keywords($item, $termsKeywords)),
                    $this->year($item, $options),
                ];
                foreach ($termsDetail as $term) {
                    $row[] = implode(' ; ', array_map([$this, 'label'], $item->value($term, ['all' => true])));
                }
                foreach ($termsFilter as $term) {
                    $row[] = $this->firstLabel($item, $term);
                }
                foreach ($termsGroup as $term) {
                    $row[] = $this->firstLabel($item, $term) ?: $unknown;
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

        $this->buildMap($options, array_keys($sources), $dir, $shouldStop);

        return $sources;
    }

    /**
     * Write map.csv, whose rows place the items with a point on a map.
     *
     * The viewer rescales the columns "x" and "y" of a csv layout on the
     * extent of the data, so the raw degrees of an equirectangular projection
     * are written as is. It negates "y" to place a row and a negative y is
     * upward, so the latitude gives the north up. The items without point are
     * simply absent: the viewer hides them in that layout.
     *
     * The file is skipped when no item has a point, so that the layout is not
     * appended to a config pointing to a missing file.
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
                $points[$itemId] = [$coordinates[0], $coordinates[1]];
            }
            $this->entityManager->clear();
        }
        if (!$points) {
            return;
        }

        $handle = fopen($dir . '/map.csv', 'w');
        fputcsv($handle, ['id', 'x', 'y'], ',', '"', '');
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
     * its annotation and not in the item itself.
     */
    protected function year(ItemRepresentation $item, array $options): string
    {
        $term = (string) ($options['property_year'] ?? '');
        if ($term === '') {
            return '';
        }
        $termAnnotation = (string) ($options['property_year_annotation'] ?? '');
        if ($termAnnotation === '') {
            return self::extractYear((string) $item->value($term));
        }
        foreach ($item->value($term, ['all' => true]) as $value) {
            $annotation = $value->valueAnnotation();
            if (!$annotation) {
                continue;
            }
            foreach ($annotation->value($termAnnotation, ['all' => true]) as $annotationValue) {
                $year = self::extractYear((string) $annotationValue);
                if ($year !== '') {
                    return $year;
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
