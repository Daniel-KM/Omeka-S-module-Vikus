<?php declare(strict_types=1);

namespace Vikus\Job;

use Omeka\Entity\SitePageBlock;
use Omeka\Job\AbstractJob;
use Vikus\Stdlib\DataBuilder;

class GenerateData extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $blockId = (int) $this->getArg('block_id');

        $block = $services->get('Omeka\EntityManager')->find(SitePageBlock::class, $blockId);
        if (!$block || $block->getLayout() !== 'vikus') {
            $logger->err(
                'The block #{block_id} is not a Vikus block.', // @translate
                ['block_id' => $blockId]
            );
            return;
        }

        $defaults = $services->get('Config')['vikus']['block_settings']['vikus'];
        $options = ($block->getData() ?? []) + $defaults;
        $hash = DataBuilder::hash($options);
        $page = $block->getPage();
        $site = $services->get('Omeka\ApiManager')->read('sites', $page->getSite()->getId())->getContent();
        $maxItems = (int) $services->get('Omeka\Settings')->get('vikus_max_items', 10000);
        $pageId = $page->getId();

        $storage = $services->get('Vikus\CacheStorage');
        $dir = $storage->prepareTemp($blockId);
        $shouldStop = fn (): bool => $this->shouldStop();
        try {
            $sources = $services->get('Vikus\DataBuilder')->build($options, $site, $dir, $maxItems, $shouldStop);
            if ($this->shouldStop()) {
                $storage->discardTemp($dir);
                $logger->notice(
                    'Block #{block_id}: the generation was stopped.', // @translate
                    ['block_id' => $blockId]
                );
                return;
            }
            $failed = $services->get('Vikus\SpriteBuilder')->build($sources, $dir, $shouldStop);
            if ($this->shouldStop()) {
                $storage->discardTemp($dir);
                $logger->notice(
                    'Block #{block_id}: the generation was stopped.', // @translate
                    ['block_id' => $blockId]
                );
                return;
            }
            if (@file_put_contents($dir . '/info.md', (string) ($options['info'] ?? '')) === false) {
                throw new \RuntimeException(sprintf('Unable to write file "%s/info.md".', $dir));
            }
            $meta = json_encode([
                'hash' => $hash,
                'total' => count($sources),
                'page_id' => $pageId,
                'generated' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);
            if (@file_put_contents($dir . '/meta.json', $meta) === false) {
                throw new \RuntimeException(sprintf('Unable to write file "%s/meta.json".', $dir));
            }

            // Reload the block to guard against a concurrent update: if its
            // options changed (or it was removed) while this job was building
            // the cache, another job was dispatched for the same block, and its
            // result, not this one, must win. The block read above is detached,
            // since the builder clears the entity manager, so it is fetched
            // again instead of being refreshed.
            $current = $services->get('Omeka\EntityManager')->find(SitePageBlock::class, $blockId);
            $currentHash = $current && $current->getLayout() === 'vikus'
                ? DataBuilder::hash(($current->getData() ?? []) + $defaults)
                : null;
            if ($currentHash !== $hash) {
                $storage->discardTemp($dir);
                $logger->notice(
                    'Block #{block_id}: the options changed during the generation, the result was discarded.', // @translate
                    ['block_id' => $blockId]
                );
                return;
            }

            $storage->publish($blockId, $dir);
        } catch (\Throwable $e) {
            $storage->discardTemp($dir);
            throw $e;
        }

        if (!$sources) {
            $logger->warn(
                'Block #{block_id}: no item with an image.', // @translate
                ['block_id' => $blockId]
            );
        }
        if ($failed) {
            $logger->warn(
                'Block #{block_id}: the thumbnail of {count} items is not readable: {item_ids}.', // @translate
                ['block_id' => $blockId, 'count' => count($failed), 'item_ids' => implode(', ', $failed)]
            );
        }
        $logger->notice(
            'Block #{block_id}: {total} items generated.', // @translate
            ['block_id' => $blockId, 'total' => count($sources)]
        );
    }
}
