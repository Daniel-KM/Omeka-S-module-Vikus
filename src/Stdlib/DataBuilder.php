<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
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
        'property_keywords',
        'properties_detail',
        'image_source',
        'link_to_item',
        'info',
    ];

    protected ApiManager $api;

    protected ImageUrl $imageUrl;

    protected string $filesBasePath;

    public function __construct(ApiManager $api, ImageUrl $imageUrl, string $filesBasePath)
    {
        $this->api = $api;
        $this->imageUrl = $imageUrl;
        $this->filesBasePath = rtrim($filesBasePath, '/');
    }

    public static function hash(array $options): string
    {
        $data = array_intersect_key($options, array_flip(self::DATA_OPTIONS));
        ksort($data);
        return sha1(json_encode($data));
    }

    /**
     * Extract a year from a date, preferring a number of four digits.
     */
    public static function extractYear(string $value): string
    {
        $matches = [];
        return preg_match('~-?\d{4}~', $value, $matches) || preg_match('~-?\d{1,3}~', $value, $matches)
            ? (string) (int) $matches[0]
            : '';
    }

    public static function column(string $term): string
    {
        return '_' . strtr($term, [':' => '_']);
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
        $termYear = (string) ($options['property_year'] ?? '');
        $termsKeywords = (array) ($options['property_keywords'] ?? []);
        $termsDetail = (array) ($options['properties_detail'] ?? []);
        $imageSource = (string) ($options['image_source'] ?? 'auto');
        $linkToItem = !empty($options['link_to_item']);

        $headers = array_merge(
            ['id', 'keywords', 'year'],
            array_map([self::class, 'column'], $termsDetail),
            $linkToItem ? ['_url'] : [],
            ['_detail', '_big']
        );

        $handle = fopen($dir . '/data.csv', 'w');
        fputcsv($handle, $headers, ',', '"', '\\');

        // The viewer always loads the timeline file, so it is written even
        // when it has no row: a missing url makes it request "<base>/undefined"
        // and log a loading error in the console.
        $timeline = fopen($dir . '/timeline.csv', 'w');
        fputcsv($timeline, ['year', 'title', 'description'], ',', '"', '\\');
        fclose($timeline);

        $query = ['site_id' => $site->id(), 'has_media' => 1] + (array) ($options['query'] ?? []);
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
                $media = $this->imageMedia($item);
                if (!$media) {
                    continue;
                }
                $urls = $this->imageUrl->urls($media, $imageSource);
                $row = [
                    $item->id(),
                    implode(',', $this->keywords($item, $termsKeywords)),
                    $termYear ? self::extractYear((string) $item->value($termYear)) : '',
                ];
                foreach ($termsDetail as $term) {
                    $row[] = implode(' ; ', array_map([$this, 'label'], $item->value($term, ['all' => true])));
                }
                if ($linkToItem) {
                    $row[] = $item->siteUrl($site->slug(), true);
                }
                $row[] = $urls['detail'];
                $row[] = $urls['big'];
                fputcsv($handle, $row, ',', '"', '\\');

                $sources[$item->id()] = $this->thumbnailSource($media);
                if (count($sources) >= $maxItems) {
                    break;
                }
            }
        }

        fclose($handle);
        return $sources;
    }

    protected function imageMedia(ItemRepresentation $item): ?MediaRepresentation
    {
        $media = $item->primaryMedia();
        return $media && $media->hasThumbnails() ? $media : null;
    }

    /**
     * Get the source of the thumbnail to pack in a sprite.
     *
     * The large derivative is preferred: the sprites are 256 px, but the
     * medium one may be configured below that size, and an upscale is never
     * done, so it would make the canvas blurry.
     */
    protected function thumbnailSource(MediaRepresentation $media): string
    {
        foreach (['large', 'medium'] as $type) {
            $path = $this->filesBasePath . '/' . $type . '/' . $media->storageId() . '.jpg';
            if (is_file($path)) {
                return $path;
            }
        }
        return (string) ($media->thumbnailUrl('large') ?: $media->thumbnailUrl('medium'));
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

    protected function label(ValueRepresentation $value): string
    {
        $resource = $value->valueResource();
        return $resource
            ? (string) $resource->displayTitle()
            : (string) ($value->value() ?: $value->uri());
    }
}
