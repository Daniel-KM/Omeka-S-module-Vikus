<?php declare(strict_types=1);

namespace VikusTest;

use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Job\GenerateData;

class ModuleTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    /**
     * @var int[]
     */
    protected $blockIds = [];

    /**
     * @var string[]
     */
    protected $keys = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        foreach ($this->blockIds as $blockId) {
            $storage->delete('b' . $blockId);
        }
        foreach ($this->keys as $key) {
            $storage->delete($key);
        }
        parent::tearDown();
    }

    protected function countJobs(): int
    {
        return (int) $this->getServiceLocator()->get('Omeka\EntityManager')
            ->createQuery('SELECT COUNT(j.id) FROM Omeka\Entity\Job j WHERE j.class = :class')
            ->setParameter('class', GenerateData::class)
            ->getSingleScalarResult();
    }

    public function testJobIsDispatchedOnCreateAndOnDataChangeOnly(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $site = $this->createSite('vikus-module' . bin2hex(random_bytes(3)));

        $before = $this->countJobs();
        $block = $this->createPageWithBlock($site);
        $this->blockIds[] = $block->id();
        $this->assertSame($before + 1, $this->countJobs());

        // Simulate the generated cache.
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $defaults = $this->getServiceLocator()->get('Config')['vikus']['block_settings']['vikus'];
        $temp = $storage->prepareTemp('b' . $block->id());
        file_put_contents($temp . '/meta.json', json_encode([
            'hash' => \Vikus\Stdlib\DataBuilder::hash($block->data() + $defaults),
            'total' => 0,
            'page_id' => $block->page()->id(),
        ]));
        $storage->publish('b' . $block->id(), $temp);

        // "isPartial" is not used here: for "o:block" it makes Omeka append the
        // submitted blocks instead of updating the existing one in place
        // (SitePageAdapter::hydrate() only reuses blocks positionally on a full
        // update), which would create a second block instead of updating the
        // current one. A full update requires the other required fields too.
        $page = $block->page();
        $update = fn (array $data) => $api->update('site_pages', $page->id(), [
            'o:title' => $page->title(),
            'o:slug' => $page->slug(),
            'o:is_public' => $page->isPublic(),
            'o:block' => [['o:id' => $block->id(), 'o:layout' => 'vikus', 'o:data' => $data]],
        ]);

        // The full current data must be resent (as the block form does),
        // otherwise the block layout's onHydrate() resets the hash-relevant
        // options it always normalizes (e.g. "property_keywords") to an empty
        // array instead of leaving them absent, which would make them differ
        // from the defaults used to compute the stored hash.
        $update(['columns' => 9] + $block->data() + $defaults);
        $this->assertSame($before + 1, $this->countJobs());

        $update(['property_year' => 'dcterms:created'] + $block->data() + $defaults);
        $this->assertSame($before + 2, $this->countJobs());

        // The flag of the form forces the job with unchanged options, and is
        // not stored.
        $temp = $storage->prepareTemp('b' . $block->id());
        file_put_contents($temp . '/meta.json', json_encode([
            'hash' => \Vikus\Stdlib\DataBuilder::hash(['property_year' => 'dcterms:created'] + $block->data() + $defaults),
            'total' => 0,
            'page_id' => $block->page()->id(),
        ]));
        $storage->publish('b' . $block->id(), $temp);
        $update(['regenerate' => '1', 'property_year' => 'dcterms:created'] + $block->data() + $defaults);
        $this->assertSame($before + 3, $this->countJobs());
        $stored = $api->read('site_pages', $page->id())->getContent()->blocks()[0]->data();
        $this->assertArrayNotHasKey('regenerate', $stored);
    }

    public function testCacheIsPurgedWithPage(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $site = $this->createSite('vikus-purge' . bin2hex(random_bytes(3)));
        $block = $this->createPageWithBlock($site);
        // Capture the ids before the deletion: the block representation wraps
        // an entity that the deletion detaches.
        $blockId = $block->id();
        $pageId = $block->page()->id();
        $this->blockIds[] = $blockId;

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $temp = $storage->prepareTemp('b' . $blockId);
        file_put_contents($temp . '/meta.json', json_encode(['page_id' => $pageId]));
        $storage->publish('b' . $blockId, $temp);

        $api->delete('site_pages', $pageId);
        $this->assertDirectoryDoesNotExist($storage->path('b' . $blockId));
    }

    /**
     * Create a page with two vikus blocks and publish a matching cache for each
     * of them.
     *
     * @return array{0: \Omeka\Api\Representation\SitePageRepresentation, 1: \Omeka\Api\Representation\SitePageBlockRepresentation, 2: \Omeka\Api\Representation\SitePageBlockRepresentation, 3: array, 4: array}
     */
    protected function createPageWithTwoCachedBlocks(\Omeka\Api\Representation\SiteRepresentation $site): array
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $defaults = $this->getServiceLocator()->get('Config')['vikus']['block_settings']['vikus'];

        $dataA = ['property_year' => 'dcterms:date'] + $defaults;
        $dataB = ['property_year' => 'dcterms:created'] + $defaults;

        $page = $api->create('site_pages', [
            'o:site' => ['o:id' => $site->id()],
            'o:title' => 'Vikus ' . bin2hex(random_bytes(3)),
            'o:slug' => 'vikus-' . bin2hex(random_bytes(3)),
            'o:is_public' => true,
            'o:block' => [
                ['o:layout' => 'vikus', 'o:data' => $dataA],
                ['o:layout' => 'vikus', 'o:data' => $dataB],
            ],
        ])->getContent();
        [$blockA, $blockB] = $page->blocks();
        $this->blockIds[] = $blockA->id();
        $this->blockIds[] = $blockB->id();

        foreach ([[$blockA, $dataA], [$blockB, $dataB]] as [$block, $data]) {
            $temp = $storage->prepareTemp('b' . $block->id());
            file_put_contents($temp . '/meta.json', json_encode([
                'hash' => \Vikus\Stdlib\DataBuilder::hash($data),
                'total' => 0,
                'page_id' => $page->id(),
            ]));
            $storage->publish('b' . $block->id(), $temp);
        }

        return [$page, $blockA, $blockB, $dataA, $dataB];
    }

    public function testSwappedBlocksHaveTheirCacheDeletedAndTwoJobsDispatched(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $site = $this->createSite('vikus-swap' . bin2hex(random_bytes(3)));
        [$page, $blockA, $blockB, $dataA, $dataB] = $this->createPageWithTwoCachedBlocks($site);

        $before = $this->countJobs();

        // Swap the two blocks' options: same block ids, but the id that
        // published dataA's cache now carries dataB's options, and vice versa,
        // exactly as a positional reorder of two blocks would leave an id in
        // place while its options (and so the cache it should show) change.
        $api->update('site_pages', $page->id(), [
            'o:title' => $page->title(),
            'o:slug' => $page->slug(),
            'o:is_public' => $page->isPublic(),
            'o:block' => [
                ['o:id' => $blockA->id(), 'o:layout' => 'vikus', 'o:data' => $dataB],
                ['o:id' => $blockB->id(), 'o:layout' => 'vikus', 'o:data' => $dataA],
            ],
        ]);

        $this->assertSame($before + 2, $this->countJobs());
        $this->assertDirectoryDoesNotExist($storage->path('b' . $blockA->id()));
        $this->assertDirectoryDoesNotExist($storage->path('b' . $blockB->id()));
    }

    public function testUpdateRemovingABlockPurgesOnlyItsCache(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $site = $this->createSite('vikus-remove' . bin2hex(random_bytes(3)));
        [$page, $blockA, $blockB, $dataA] = $this->createPageWithTwoCachedBlocks($site);
        // Capture ids before the update: it detaches the removed block's
        // representation.
        $blockAId = $blockA->id();
        $blockBId = $blockB->id();

        $api->update('site_pages', $page->id(), [
            'o:title' => $page->title(),
            'o:slug' => $page->slug(),
            'o:is_public' => $page->isPublic(),
            'o:block' => [
                ['o:id' => $blockAId, 'o:layout' => 'vikus', 'o:data' => $dataA],
            ],
        ]);

        $this->assertDirectoryExists($storage->path('b' . $blockAId));
        $this->assertDirectoryDoesNotExist($storage->path('b' . $blockBId));
    }

    public function testCacheOfAnotherPageIsNeverPurged(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');

        $siteOther = $this->createSite('vikus-other-owner' . bin2hex(random_bytes(3)));
        $otherBlock = $this->createPageWithBlock($siteOther);
        $this->blockIds[] = $otherBlock->id();
        $temp = $storage->prepareTemp('b' . $otherBlock->id());
        file_put_contents($temp . '/meta.json', json_encode(['page_id' => $otherBlock->page()->id()]));
        $storage->publish('b' . $otherBlock->id(), $temp);

        // The cache above belongs to "$otherBlock"'s own page, unrelated to the
        // page updated and deleted below: it must survive both.
        $site = $this->createSite('vikus-unrelated' . bin2hex(random_bytes(3)));
        $block = $this->createPageWithBlock($site);
        $pageId = $block->page()->id();
        $this->blockIds[] = $block->id();

        $api->update('site_pages', $pageId, [
            'o:title' => $block->page()->title(),
            'o:slug' => $block->page()->slug(),
            'o:is_public' => $block->page()->isPublic(),
            'o:block' => [],
        ]);
        $this->assertDirectoryExists($storage->path('b' . $otherBlock->id()));

        $api->delete('site_pages', $pageId);
        $this->assertDirectoryExists($storage->path('b' . $otherBlock->id()));
    }

    /**
     * Publish an empty cache for an item set in a site.
     */
    protected function publishItemSetCache(int $siteId, int $itemSetId): string
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $key = 's' . $siteId . '-r' . $itemSetId;
        $this->keys[] = $key;
        $temp = $storage->prepareTemp($key);
        file_put_contents($temp . '/meta.json', json_encode(['total' => 0]));
        $storage->publish($key, $temp);
        return $key;
    }

    public function testCacheIsPurgedWithItemSet(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $site = $this->createSite('vikus-is-purge' . bin2hex(random_bytes(3)));
        $other = $this->createSite('vikus-is-keep' . bin2hex(random_bytes(3)));
        $itemSet = $this->createItemSet($site, 'Estampes');
        $kept = $this->createItemSet($site, 'Autres');

        $key = $this->publishItemSetCache($site->id(), $itemSet->id());
        $keyOtherSite = $this->publishItemSetCache($other->id(), $itemSet->id());
        $keyKept = $this->publishItemSetCache($site->id(), $kept->id());

        $api->delete('item_sets', $itemSet->id());

        $this->assertDirectoryDoesNotExist($storage->path($key));
        $this->assertDirectoryDoesNotExist($storage->path($keyOtherSite));
        $this->assertDirectoryExists($storage->path($keyKept));
    }

    public function testCacheIsPurgedWithSite(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $site = $this->createSite('vikus-site-purge' . bin2hex(random_bytes(3)));
        $other = $this->createSite('vikus-site-keep' . bin2hex(random_bytes(3)));
        $itemSet = $this->createItemSet($site, 'Estampes');

        $key = $this->publishItemSetCache($site->id(), $itemSet->id());
        $keyKept = $this->publishItemSetCache($other->id(), $itemSet->id());

        $api->delete('sites', $site->id());

        $this->assertDirectoryDoesNotExist($storage->path($key));
        $this->assertDirectoryExists($storage->path($keyKept));
    }
}
