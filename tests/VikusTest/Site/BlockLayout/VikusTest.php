<?php declare(strict_types=1);

namespace VikusTest\Site\BlockLayout;

use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Site\BlockLayout\Vikus as BlockLayout;
use VikusTest\VikusTestTrait;

class VikusTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    /**
     * @var int|null
     */
    protected $blockId;

    public function tearDown(): void
    {
        if ($this->blockId) {
            $this->getServiceLocator()->get('Vikus\CacheStorage')->delete('b' . $this->blockId);
        }
        parent::tearDown();
    }

    public function testOnHydrateSanitizesData(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-blocklayout-' . bin2hex(random_bytes(3)));
        $block = $this->createPageWithBlock($site, [
            'columns' => 999999,
            'style_fontColor' => 'red;} body{display:none',
            'style_canvasBackground' => '#ABCDEF',
            'image_source' => 'evil',
            'height' => '10px; color: red',
            'link_to_item' => '0',
            'property_keywords' => '',
            'query' => 'fulltext_search=abc',
            'filter_type' => 'evil',
            'properties_group' => ['dcterms:subject', ''],
        ]);
        $this->blockId = $block->id();

        $data = $block->data();

        $this->assertSame(50, $data['columns']);
        $this->assertSame('#2d3b43', $data['style_fontColor']);
        $this->assertSame('#ABCDEF', $data['style_canvasBackground']);
        $this->assertSame('auto', $data['image_source']);
        $this->assertSame('80vh', $data['height']);
        $this->assertFalse($data['link_to_item']);
        $this->assertSame([], $data['property_keywords']);
        $this->assertSame(['fulltext_search' => 'abc'], $data['query']);
        $this->assertSame('tags', $data['filter_type']);
        $this->assertSame(['dcterms:subject'], $data['properties_group']);
    }

    public function testFormDisplaysTheItemCountWithoutWarning(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-form-' . bin2hex(random_bytes(3)));
        $services = $this->getServiceLocator();

        $html = $services->get('Omeka\\BlockLayoutManager')->get('vikus')
            ->form($services->get('ViewRenderer'), $site);

        $this->assertStringContainsString('Items of the query:', $html);
        $this->assertStringContainsString('/admin/item?site_id=' . $site->id(), html_entity_decode($html));
        $this->assertStringNotContainsString('Displayed items:', $html);
        $this->assertStringNotContainsString('is slow on laptops', $html);
        $this->assertStringNotContainsString('is not usable on most devices', $html);
    }

    public function testFormRendersTheElementGroups(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-form-groups-' . bin2hex(random_bytes(3)));
        $services = $this->getServiceLocator();

        $html = $services->get('Omeka\\BlockLayoutManager')->get('vikus')
            ->form($services->get('ViewRenderer'), $site);

        $this->assertStringContainsString('block-layout-fieldset', $html);
        foreach (['vikus_items', 'vikus_alignment', 'vikus_layouts', 'vikus_map', 'vikus_images', 'vikus_style'] as $group) {
            $this->assertStringContainsString('id="' . $group . '"', $html);
        }
    }

    public function testFormWarnsWhenTheQueryLimitsTheGeneration(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-limit-' . bin2hex(random_bytes(3)));
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\\ApiManager');
        $api->create('items', ['o:site' => [['o:id' => $site->id()]]]);
        $api->create('items', ['o:site' => [['o:id' => $site->id()]]]);
        $block = $this->createPageWithBlock($site, ['query' => 'limit=1']);
        $this->blockId = $block->id();

        $html = $services->get('Omeka\\BlockLayoutManager')->get('vikus')
            ->form($services->get('ViewRenderer'), $site, $block->page(), $block);

        $this->assertStringContainsString('Displayed items: 1.', $html);
    }

    public function testFormWarnsWhenTheOptionLimitsTheGeneration(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-max-' . bin2hex(random_bytes(3)));
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\\ApiManager');
        $api->create('items', ['o:site' => [['o:id' => $site->id()]]]);
        $api->create('items', ['o:site' => [['o:id' => $site->id()]]]);
        $block = $this->createPageWithBlock($site, ['max_items' => 1]);
        $this->blockId = $block->id();

        $html = $services->get('Omeka\\BlockLayoutManager')->get('vikus')
            ->form($services->get('ViewRenderer'), $site, $block->page(), $block);

        $this->assertStringContainsString('Displayed items: 1.', $html);
    }

    /**
     * @dataProvider maxItemsProvider
     */
    public function testMaxItems(array $options, int $expected): void
    {
        $this->assertSame($expected, \Vikus\Job\GenerateData::maxItems($options, 10000));
    }

    public function maxItemsProvider(): array
    {
        return [
            [[], 10000],
            [['max_items' => 0], 10000],
            [['max_items' => 500], 500],
            [['max_items' => 20000], 10000],
        ];
    }

    public function testFormKeepsWorkingWhenTheCountFails(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-count-' . bin2hex(random_bytes(3)));
        $services = $this->getServiceLocator();
        // The adapter rejects an unknown scalar field with an exception.
        $block = $this->createPageWithBlock($site, ['query' => 'return_scalar=evil']);
        $this->blockId = $block->id();

        $html = $services->get('Omeka\\BlockLayoutManager')->get('vikus')
            ->form($services->get('ViewRenderer'), $site, $block->page(), $block);

        $this->assertStringContainsString('The count of the items of the query is not available.', $html);
        $this->assertStringNotContainsString('Items of the query:', $html);
    }

    /**
     * @dataProvider warningLevelProvider
     */
    public function testWarningLevel(int $itemCount, string $expected): void
    {
        $thresholds = ['warn' => 2000, 'max_recommended' => 5000];
        $this->assertSame($expected, BlockLayout::warningLevel($itemCount, $thresholds));
    }

    public function warningLevelProvider(): array
    {
        return [
            [0, ''],
            [2000, ''],
            [2001, 'warn'],
            [5000, 'warn'],
            [5001, 'max_recommended'],
        ];
    }

    public function testOnHydrateParsesTimelineEvents(): void
    {
        $this->loginAdmin();
        $site = $this->createSite('vikus-timeline-' . bin2hex(random_bytes(3)));
        $block = $this->createPageWithBlock($site, [
            'timeline_events' => "1789 = Revolution = Bastille = More\n\n1900 = Fair\n",
        ]);
        $this->blockId = $block->id();

        $this->assertSame([
            ['year' => '1789', 'title' => 'Revolution', 'text' => 'Bastille', 'extra' => 'More'],
            ['year' => '1900', 'title' => 'Fair', 'text' => '', 'extra' => ''],
        ], $block->data()['timeline_events']);
    }
}
