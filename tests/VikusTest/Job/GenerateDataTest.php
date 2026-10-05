<?php declare(strict_types=1);

namespace VikusTest\Job;

use Omeka\Entity\Job;
use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Stdlib\DataBuilder;
use Vikus\Stdlib\SpriteBuilder;
use VikusTest\VikusTestTrait;

class GenerateDataTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    /**
     * @var int[]
     */
    protected $keys = [];

    protected function tearDown(): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        foreach ($this->keys as $key) {
            $storage->delete($key);
        }
        $this->cleanVikusTestFiles();
        parent::tearDown();
    }

    public function testGenerate(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-job' . bin2hex(random_bytes(3)));
        $this->createItemWithImage([
            'o:site' => [['o:id' => $site->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'A']],
        ], 300, 200);
        $block = $this->createPageWithBlock($site, ['info' => '# Hello']);
        $this->keys[] = 'b' . $block->id();

        $this->runJob('b' . $block->id());

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $path = $storage->path('b' . $block->id());
        $meta = $storage->readMeta('b' . $block->id());
        $defaults = $this->getServiceLocator()->get('Config')['vikus']['block_settings']['vikus'];

        $this->assertSame(1, $meta['total']);
        $this->assertSame($block->page()->id(), $meta['page_id']);
        $this->assertSame(DataBuilder::hash($block->data() + $defaults), $meta['hash']);
        $this->assertFileExists($path . '/data.csv');
        $this->assertFileExists($path . '/sprites/sprites.json');
        $this->assertFileExists($path . '/sprites/0.jpg');
        $this->assertSame('# Hello', file_get_contents($path . '/info.md'));
    }

    public function testGenerateStoresTheCountsOfTheLayoutsInTheMeta(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-counts' . bin2hex(random_bytes(3)));
        $literal = fn (string $term, string $value): array => [
            'type' => 'literal',
            'property_id' => $easyMeta->propertyId($term),
            '@value' => $value,
        ];
        foreach ([['A', '1889', 'Flowers'], ['B', '1853', 'Flowers']] as [$title, $date, $subject]) {
            $this->createItemWithImage([
                'o:site' => [['o:id' => $site->id()]],
                'dcterms:title' => [$literal('dcterms:title', $title)],
                'dcterms:date' => [$literal('dcterms:date', $date)],
                'dcterms:subject' => [$literal('dcterms:subject', $subject)],
            ], 300, 200);
        }
        $block = $this->createPageWithBlock($site, [
            'property_year' => 'dcterms:date',
            'properties_group' => ['dcterms:subject'],
        ]);
        $this->keys[] = 'b' . $block->id();

        $this->runJob('b' . $block->id());

        $meta = $this->getServiceLocator()->get('Vikus\CacheStorage')->readMeta('b' . $block->id());
        $this->assertSame(2, $meta['years']);
        $this->assertSame(['dcterms:subject' => 1], $meta['groups']);
    }

    public function testGenerateStoresTheSpriteSizeInTheMeta(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-size' . bin2hex(random_bytes(3)));
        $this->createItemWithImage([
            'o:site' => [['o:id' => $site->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'A']],
        ], 300, 200);
        $block = $this->createPageWithBlock($site, ['sprite_size' => 128]);
        $this->keys[] = 'b' . $block->id();

        $this->runJob('b' . $block->id());

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $meta = $storage->readMeta('b' . $block->id());
        $this->assertSame(128, $meta['sprite_size']);

        $manifest = json_decode(file_get_contents($storage->path('b' . $block->id()) . '/sprites/sprites.json'), true);
        $this->assertSame(['w' => 128, 'h' => 85], $manifest['spritesheets'][0]['sprites'][0]['dimension']);
    }

    public function testGenerateForAnItemSet(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-job-set' . bin2hex(random_bytes(3)));
        $itemSet = $this->createItemSet($site, 'Estampes');
        $key = 's' . $site->id() . '-r' . $itemSet->id();
        $this->keys[] = $key;
        $this->createItemWithImage([
            'o:site' => [['o:id' => $site->id()]],
            'o:item_set' => [['o:id' => $itemSet->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'A']],
        ], 300, 200);

        $this->runJob($key);

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $meta = $storage->readMeta($key);

        $this->assertSame(1, $meta['total']);
        $this->assertSame($key, $meta['key']);
        $this->assertSame($site->id(), $meta['site_id']);
        $this->assertSame($itemSet->id(), $meta['item_set_id']);
        $this->assertArrayNotHasKey('page_id', $meta);
        $this->assertStringEndsWith('/vikus/' . $key, $storage->path($key));
        $this->assertFileExists($storage->path($key) . '/sprites/sprites.json');
    }

    public function testGenerateEmpty(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-job-empty' . bin2hex(random_bytes(3)));
        $block = $this->createPageWithBlock($site);
        $this->keys[] = 'b' . $block->id();

        $this->runJob('b' . $block->id());

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $this->assertSame(0, $storage->readMeta('b' . $block->id())['total']);
    }

    public function testFailureKeepsPreviousCache(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-job-fail' . bin2hex(random_bytes(3)));
        $this->createItemWithImage([
            'o:site' => [['o:id' => $site->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'A']],
        ], 300, 200);
        $block = $this->createPageWithBlock($site, ['info' => '# Hello']);
        $this->keys[] = 'b' . $block->id();

        $this->runJob('b' . $block->id());

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $metaBefore = $storage->readMeta('b' . $block->id());
        $this->assertSame(1, $metaBefore['total']);

        $services = $this->getServiceLocator();
        $services->setAllowOverride(true);
        $services->setService('Vikus\SpriteBuilder', new class extends SpriteBuilder {
            public function build(array $sources, string $dir, ?callable $shouldStop = null, int $size = self::SIZE): array
            {
                throw new \RuntimeException('Simulated sprite failure.');
            }
        });
        $services->setAllowOverride(false);

        $this->expectException(\RuntimeException::class);
        try {
            $this->runJob('b' . $block->id());
        } finally {
            $metaAfter = $storage->readMeta('b' . $block->id());
            $this->assertSame($metaBefore, $metaAfter);
            $this->assertSame([], glob($storage->path('b' . $block->id()) . '.tmp-*'));
        }
    }

    public function testStopDiscardsTemporaryCache(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-job-stop' . bin2hex(random_bytes(3)));
        $this->createItemWithImage([
            'o:site' => [['o:id' => $site->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'A']],
        ], 300, 200);
        $block = $this->createPageWithBlock($site);
        $this->keys[] = 'b' . $block->id();

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');

        $this->runJob('b' . $block->id(), Job::STATUS_STOPPING);

        $this->assertSame([], $storage->readMeta('b' . $block->id()));
        $this->assertSame([], glob($storage->path('b' . $block->id()) . '.tmp-*'));
    }

    public function testConcurrentUpdateDiscardsResult(): void
    {
        $this->loginAdmin();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $site = $this->createSite('vikus-job-concurrent' . bin2hex(random_bytes(3)));
        $this->createItemWithImage([
            'o:site' => [['o:id' => $site->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'A']],
        ], 300, 200);
        $block = $this->createPageWithBlock($site);
        $blockId = $block->id();
        $this->keys[] = 'b' . $blockId;

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        // Simulate another update happening while this job builds its cache:
        // the sprite builder, called after the block's options were read,
        // changes the block's data in the database, as a concurrent api update
        // would.
        $services->setAllowOverride(true);
        $services->setService('Vikus\SpriteBuilder', new class($entityManager, $blockId) extends SpriteBuilder {
            private $entityManager;
            private $blockId;

            public function __construct($entityManager, int $blockId)
            {
                $this->entityManager = $entityManager;
                $this->blockId = $blockId;
            }

            public function build(array $sources, string $dir, ?callable $shouldStop = null, int $size = self::SIZE): array
            {
                $block = $this->entityManager->find(\Omeka\Entity\SitePageBlock::class, $this->blockId);
                $block->setData(['property_year' => 'dcterms:created'] + $block->getData());
                $this->entityManager->flush();
                return [];
            }
        });
        $services->setAllowOverride(false);

        $this->runJob('b' . $blockId);

        $storage = $services->get('Vikus\CacheStorage');
        $this->assertSame([], $storage->readMeta('b' . $blockId));
        $this->assertSame([], glob($storage->path('b' . $blockId) . '.tmp-*'));
    }
}
