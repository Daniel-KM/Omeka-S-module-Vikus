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

        $options = ($block->getData() ?? []) + $services->get('Config')['vikus']['block_settings']['vikus'];
        $page = $block->getPage();
        $site = $services->get('Omeka\ApiManager')->read('sites', $page->getSite()->getId())->getContent();
        $maxItems = (int) $services->get('Omeka\Settings')->get('vikus_max_items', 10000);

        $storage = $services->get('Vikus\CacheStorage');
        $dir = $storage->prepareTemp($blockId);
        try {
            $sources = $services->get('Vikus\DataBuilder')->build($options, $site, $dir, $maxItems);
            $failed = $services->get('Vikus\SpriteBuilder')->build($sources, $dir);
            file_put_contents($dir . '/info.md', (string) ($options['info'] ?? ''));
            file_put_contents($dir . '/meta.json', json_encode([
                'hash' => DataBuilder::hash($options),
                'total' => count($sources),
                'page_id' => $page->getId(),
                'generated' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]));
            $storage->publish($blockId);
        } catch (\Throwable $e) {
            $storage->discardTemp($blockId);
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
