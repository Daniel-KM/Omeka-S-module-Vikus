<?php declare(strict_types=1);

namespace Vikus\Job;

use Omeka\Entity\SitePageBlock;
use Omeka\Job\AbstractJob;
use Vikus\Stdlib\CacheStorage;
use Vikus\Stdlib\DataBuilder;
use Vikus\Stdlib\OptionsNormalizer;
use Vikus\Stdlib\SpriteBuilder;

class GenerateData extends AbstractJob
{
    /**
     * Get the number of items to generate: the option of the viewer, capped by
     * the main setting.
     */
    public static function maxItems(array $options, int $settingMax): int
    {
        $optionMax = (int) ($options['max_items'] ?? 0);
        return $optionMax > 0 ? min($optionMax, $settingMax) : $settingMax;
    }

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $key = (string) $this->getArg('key');

        $target = $this->target($key);
        if (!$target) {
            $logger->err(
                'The cache "{key}" is not a Vikus one.', // @translate
                ['key' => $key]
            );
            return;
        }
        [$options, $site, $meta] = $target;

        $hash = DataBuilder::hash($options);
        $maxItems = self::maxItems(
            $options,
            (int) $services->get('Omeka\Settings')->get('vikus_max_items', 10000)
        );

        $storage = $services->get('Vikus\CacheStorage');
        $dir = $storage->prepareTemp($key);
        $shouldStop = fn (): bool => $this->shouldStop();
        try {
            $dataBuilder = $services->get('Vikus\DataBuilder');
            $sources = $dataBuilder->build($options, $site, $dir, $maxItems, $shouldStop);
            $stats = $dataBuilder->stats();
            if ($this->shouldStop()) {
                $storage->discardTemp($dir);
                $logger->notice(
                    'Vikus cache "{key}": the generation was stopped.', // @translate
                    ['key' => $key]
                );
                return;
            }
            $spriteSize = (int) ($options['sprite_size'] ?? SpriteBuilder::SIZE) ?: SpriteBuilder::SIZE;
            $failed = $services->get('Vikus\SpriteBuilder')->build($sources, $dir, $shouldStop, $spriteSize);
            if ($this->shouldStop()) {
                $storage->discardTemp($dir);
                $logger->notice(
                    'Vikus cache "{key}": the generation was stopped.', // @translate
                    ['key' => $key]
                );
                return;
            }
            if (@file_put_contents($dir . '/info.md', (string) ($options['info'] ?? '')) === false) {
                throw new \RuntimeException(sprintf('Unable to write file "%s/info.md".', $dir));
            }
            $meta += [
                'key' => $key,
                // The layout of the map is only added to the config when the
                // file exists, so that the viewer never loads a missing url.
                'map' => is_file($dir . '/map.csv'),
                // The config of the viewer declares the size of the sprites of
                // the published atlases, not the one of the current options.
                'sprite_size' => $spriteSize,
                'hash' => $hash,
                'total' => count($sources),
                // The config sizes the group layouts on these counts, so that
                // their images stay as readable as the ones of the time one.
                'years' => $stats['years'],
                'groups' => $stats['groups'],
                // Geographic bounds of the window of map.csv, that the base map
                // of the layout follows.
                'map_bounds' => $stats['map_bounds'],
                'generated' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
            if (@file_put_contents($dir . '/meta.json', json_encode($meta)) === false) {
                throw new \RuntimeException(sprintf('Unable to write file "%s/meta.json".', $dir));
            }

            // Reload the target to guard against a concurrent update: if its
            // options changed (or it was removed) while this job was building
            // the cache, another job was dispatched for the same key, and its
            // result, not this one, must win. The target read above is
            // detached, since the builder clears the entity manager, so it is
            // fetched again instead of being refreshed.
            $current = $this->target($key);
            if (!$current || DataBuilder::hash($current[0]) !== $hash) {
                $storage->discardTemp($dir);
                $logger->notice(
                    'Vikus cache "{key}": the options changed during the generation, the result was discarded.', // @translate
                    ['key' => $key]
                );
                return;
            }

            $storage->publish($key, $dir);
        } catch (\Throwable $e) {
            $storage->discardTemp($dir);
            throw $e;
        }

        if (!$sources) {
            $logger->warn(
                'Vikus cache "{key}": no item with an image.', // @translate
                ['key' => $key]
            );
        }
        if ($failed) {
            $logger->warn(
                'Vikus cache "{key}": the thumbnail of {count} items is not readable: {item_ids}.', // @translate
                ['key' => $key, 'count' => count($failed), 'item_ids' => implode(', ', $failed)]
            );
        }
        $logger->notice(
            'Vikus cache "{key}": {total} items generated.', // @translate
            ['key' => $key, 'total' => count($sources)]
        );
    }

    /**
     * Resolve a cache key into its options, its site and its own meta data.
     *
     * @return array|null [options, site, meta]
     */
    protected function target(string $key): ?array
    {
        $services = $this->getServiceLocator();
        $ids = CacheStorage::parseKey($key);
        if (!$ids) {
            return null;
        }
        if (!isset($ids['block_id'])) {
            return $this->itemSetTarget($ids['site_id'], $ids['item_set_id']);
        }

        $defaults = $services->get('Config')['vikus']['block_settings']['vikus'];
        $block = $services->get('Omeka\EntityManager')->find(SitePageBlock::class, $ids['block_id']);
        if (!$block || $block->getLayout() !== 'vikus') {
            return null;
        }
        $page = $block->getPage();
        $site = $services->get('Omeka\ApiManager')->read('sites', $page->getSite()->getId())->getContent();

        return [
            ($block->getData() ?? []) + $defaults,
            $site,
            ['page_id' => $page->getId()],
        ];
    }

    /**
     * Resolve an item set of a site into the options of its viewer.
     *
     * @return array|null [options, site, meta]
     */
    protected function itemSetTarget(int $siteId, int $itemSetId): ?array
    {
        $services = $this->getServiceLocator();
        try {
            $site = $services->get('Omeka\ApiManager')->read('sites', $siteId)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return null;
        }

        // The settings of the site are a shared service without an accessor to
        // its current target, so a clone is targeted instead of it.
        $siteSettings = clone $services->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($siteId);
        $options = OptionsNormalizer::fromSettings($siteSettings, $services->get('Config')['vikus']['site_settings']);
        $options['query'] = ['item_set_id' => $itemSetId];

        return [
            $options,
            $site,
            ['site_id' => $siteId, 'item_set_id' => $itemSetId],
        ];
    }
}
