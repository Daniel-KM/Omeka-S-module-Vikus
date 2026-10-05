<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Omeka\Test\AbstractHttpControllerTestCase;
use VikusTest\VikusTestTrait;

class ConfigBuilderTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    public function testBuild(): void
    {
        $services = $this->getServiceLocator();
        $options = ['title' => '', 'columns' => '4', 'properties_detail' => ['dcterms:title'], 'link_to_item' => true]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')
            ->build($options, 'My page', 'http://example.org/files/vikus/b5');

        $this->assertSame('My page', $config['project']['name']);
        $this->assertSame('http://example.org/files/vikus/b5/data.csv', $config['loader']['items']);
        $this->assertSame('http://example.org/files/vikus/b5/info.md', $config['loader']['info']);
        // The viewer always loads the timeline file, so it must be set: an
        // undefined value makes it request "<base>/undefined" and log an error.
        $this->assertSame('http://example.org/files/vikus/b5/timeline.csv', $config['loader']['timeline']);
        $this->assertSame([['title' => 'Time']], $config['loader']['layouts']);
        $this->assertSame(
            ['size' => 256, 'url' => 'http://example.org/files/vikus/b5/sprites/sprites.json'],
            $config['loader']['textures']['medium']
        );
        $this->assertSame(['size' => 1024, 'csv' => '_detail'], $config['loader']['textures']['detail']);
        $this->assertSame(['size' => 4096, 'csv' => '_big'], $config['loader']['textures']['big']);
        $this->assertSame(4, $config['projection']['columns']);
        $this->assertSame('#eae2cb', $config['style']['canvasBackground']);
        $this->assertSame('1px 1px 0px #eae2cb', $config['style']['textShadow']);
        $this->assertSame([
            ['name' => 'Title', 'source' => '_dcterms_title', 'display' => 'column', 'type' => 'text'],
            ['name' => 'View the record', 'source' => '_url', 'display' => 'column', 'type' => 'link'],
        ], $config['detail']['structure']);
    }

    public function testUnknownPropertyUsesTheTerm(): void
    {
        $services = $this->getServiceLocator();
        $options = ['properties_detail' => ['foo:bar'], 'link_to_item' => false]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame(
            [['name' => 'foo:bar', 'source' => '_foo_bar', 'display' => 'column', 'type' => 'text']],
            $config['detail']['structure']
        );
    }

    public function testFilterTagsHasNoFilterKey(): void
    {
        $services = $this->getServiceLocator();
        $options = ['filter_type' => 'tags'] + $services->get('Config')['vikus']['block_settings']['vikus'];
        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');
        $this->assertArrayNotHasKey('filter', $config);
    }

    public function testFilterHierarchical(): void
    {
        $services = $this->getServiceLocator();
        $options = ['filter_type' => 'hierarchical'] + $services->get('Config')['vikus']['block_settings']['vikus'];
        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');
        $this->assertSame(['type' => 'hierarchical'], $config['filter']);
    }

    public function testFilterCrossfilterHasOneDimensionByProperty(): void
    {
        $services = $this->getServiceLocator();
        $options = [
            'filter_type' => 'crossfilter',
            'property_keywords' => ['dcterms:subject', 'foo:bar'],
        ] + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame([
            'type' => 'crossfilter',
            'dimensions' => [
                ['source' => '_f_dcterms_subject', 'label' => 'Subject'],
                ['source' => '_f_foo_bar', 'label' => 'foo:bar'],
            ],
        ], $config['filter']);
    }

    public function testGroupLayoutsAreAppendedAfterTime(): void
    {
        $services = $this->getServiceLocator();
        $options = [
            'properties_group' => ['dcterms:subject', 'foo:bar'],
            'columns' => 4,
        ] + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame([
            ['title' => 'Time'],
            ['title' => 'Subject', 'type' => 'group', 'groupKey' => '_g_dcterms_subject', 'columns' => 4],
            ['title' => 'foo:bar', 'type' => 'group', 'groupKey' => '_g_foo_bar', 'columns' => 4],
        ], $config['loader']['layouts']);
        $this->assertSame('My page', $config['project']['name']);
    }

    public function testGroupLayoutTitlesAreUnique(): void
    {
        $services = $this->getServiceLocator();
        // The viewer identifies a layout by its title and both properties are
        // labelled "status".
        $options = ['properties_group' => ['bibo:status', 'foaf:status', 'foo:Time']]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame(
            ['Time', 'status', 'status (foaf:status)', 'foo:Time'],
            array_column($config['loader']['layouts'], 'title')
        );
    }

    public function testGroupLayoutUsesTheConfiguredTitle(): void
    {
        $services = $this->getServiceLocator();
        $options = [
            'properties_group' => ['dcterms:subject', 'dcterms:type'],
            'layout_titles' => ['dcterms:subject' => 'Themes'],
            'columns' => 4,
        ] + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        // Only the property with a title is renamed: the other one keeps the
        // label of the property.
        $this->assertSame(
            ['Time', 'Themes', 'Type'],
            array_column($config['loader']['layouts'], 'title')
        );
    }

    public function testGroupLayoutTitleIsStillMadeUnique(): void
    {
        $services = $this->getServiceLocator();
        $options = [
            'properties_group' => ['dcterms:subject', 'dcterms:type'],
            'layout_titles' => ['dcterms:subject' => 'Time', 'dcterms:type' => 'Time'],
        ] + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame(
            ['Time', 'Time (dcterms:subject)', 'Time (dcterms:type)'],
            array_column($config['loader']['layouts'], 'title')
        );
    }

    public function testTimeLayoutUsesTheTranslatedTitle(): void
    {
        $services = $this->getServiceLocator();
        $options = $services->get('Config')['vikus']['block_settings']['vikus'];
        $title = $services->get('MvcTranslator')->translate('Time');

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame([['title' => $title]], $config['loader']['layouts']);
    }

    public function testTimeAndMapLayoutsUseTheirReservedTitles(): void
    {
        $services = $this->getServiceLocator();
        $options = [
            'layout_map' => true,
            'layout_titles' => ['time' => 'Chronologie', 'map' => 'Localisation'],
        ] + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')
            ->build($options, 'My page', 'http://example.org/x', ['map' => true]);

        $this->assertSame(
            ['Chronologie', 'Localisation'],
            array_column($config['loader']['layouts'], 'title')
        );
    }

    public function testUniquenessFollowsTheTitleOfTheTimeLayout(): void
    {
        $services = $this->getServiceLocator();
        // The label of "dcterms:subject" is "Subject", the very title given to
        // the time layout, so the group layout is the one suffixed. The title
        // "Time" is free once the time layout is renamed.
        $options = [
            'properties_group' => ['dcterms:subject', 'dcterms:type'],
            'layout_titles' => ['time' => 'Subject', 'dcterms:type' => 'Time'],
        ] + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame(
            ['Subject', 'Subject (dcterms:subject)', 'Time'],
            array_column($config['loader']['layouts'], 'title')
        );
    }

    public function testMapLayoutIsAppendedAfterTheGroupLayouts(): void
    {
        $services = $this->getServiceLocator();
        $options = ['properties_group' => ['dcterms:subject'], 'layout_map' => true, 'columns' => 4]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')
            ->build($options, 'My page', 'http://example.org/x', ['map' => true]);

        $this->assertSame([
            ['title' => 'Time'],
            ['title' => 'Subject', 'type' => 'group', 'groupKey' => '_g_dcterms_subject', 'columns' => 4],
            ['title' => 'Map', 'url' => 'http://example.org/x/map.csv', 'scale' => 0.5],
        ], $config['loader']['layouts']);
    }

    public function testBaseMapIsDeclaredWithTheBoundsOfTheCache(): void
    {
        $services = $this->getServiceLocator();
        $options = ['layout_map' => true, 'map_opacity' => 0.4, 'map_attribution' => 'OSM']
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\\ConfigBuilder')->build(
            $options,
            'My page',
            'http://example.org/x',
            ['map' => true, 'map_bounds' => [-5.0, 47.0, 3.0, 49.5]]
        );

        // The viewer ignores the keys it does not know, so the settings of
        // the base map travel in the config of the viewer.
        $map = $config['vikus']['map'];
        $this->assertSame('Map', $map['title']);
        $this->assertSame([-5.0, 47.0, 3.0, 49.5], $map['bounds']);
        $this->assertSame(\Vikus\Stdlib\OptionsNormalizer::MAP_TILES, $map['tiles']);
        $this->assertSame('OSM', $map['attribution']);
        $this->assertSame(0.4, $map['opacity']);
        $this->assertSame('#eae2cb', $map['background']);
        $this->assertFalse($map['hideMissing']);
    }

    public function testBaseMapCarriesTheHidingOfTheItemsWithoutCoordinates(): void
    {
        $services = $this->getServiceLocator();
        $options = ['layout_map' => true, 'map_hide_missing' => true]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\\ConfigBuilder')->build(
            $options,
            'My page',
            'http://example.org/x',
            ['map' => true, 'map_bounds' => [-5.0, 47.0, 3.0, 49.5]]
        );

        $this->assertTrue($config['vikus']['map']['hideMissing']);
    }

    public function testBaseMapIsSkippedWithoutBounds(): void
    {
        $services = $this->getServiceLocator();
        $options = ['layout_map' => true] + $services->get('Config')['vikus']['block_settings']['vikus'];

        // A cache generated before the bounds were recorded: the layout is
        // kept, but there is nothing to align a base map on.
        $config = $services->get('Vikus\\ConfigBuilder')
            ->build($options, 'My page', 'http://example.org/x', ['map' => true]);

        $this->assertArrayNotHasKey('vikus', $config);
    }

    public function testMapLayoutRequiresTheFile(): void
    {
        $services = $this->getServiceLocator();
        $options = ['layout_map' => true] + $services->get('Config')['vikus']['block_settings']['vikus'];

        // No item has a point, so the cache has no map.csv: the layout is
        // omitted, otherwise the viewer would fail to load it.
        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame([['title' => 'Time']], $config['loader']['layouts']);
    }

    public function testSpriteSizeComesFromTheMetaOfTheCache(): void
    {
        $services = $this->getServiceLocator();
        $options = ['sprite_size' => 256] + $services->get('Config')['vikus']['block_settings']['vikus'];

        // The published atlases were built with 128 px sprites, whatever the
        // current option says.
        $config = $services->get('Vikus\ConfigBuilder')
            ->build($options, 'My page', 'http://example.org/x', ['sprite_size' => 128]);

        $this->assertSame(128, $config['loader']['textures']['medium']['size']);
    }

    public function testGroupLayoutColumnsAreComputedFromTheStatsOfTheCache(): void
    {
        $services = $this->getServiceLocator();
        $options = ['properties_group' => ['dcterms:subject'], 'columns' => 4]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        // 120 values spread over 4 columns would make 480 columns, when the
        // time layout has 28 decades of 4 columns: one column by value keeps
        // the images of the same size in both layouts.
        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x', [
            'years' => 28,
            'groups' => ['dcterms:subject' => 120],
        ]);

        $this->assertSame(1, $config['loader']['layouts'][1]['columns']);
    }

    public function testGroupLayoutColumnsGrowWithFewValues(): void
    {
        $services = $this->getServiceLocator();
        $options = ['properties_group' => ['dcterms:subject'], 'columns' => 4]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x', [
            'years' => 10,
            'groups' => ['dcterms:subject' => 5],
        ]);

        $this->assertSame(8, $config['loader']['layouts'][1]['columns']);
    }

    public function testGroupLayoutColumnsFallBackOnTheOption(): void
    {
        $services = $this->getServiceLocator();
        $options = ['properties_group' => ['dcterms:subject'], 'columns' => 4]
            + $services->get('Config')['vikus']['block_settings']['vikus'];

        // A cache generated before the counts were recorded has no stats.
        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');

        $this->assertSame(4, $config['loader']['layouts'][1]['columns']);
    }

    public function testTitleOfBlockPrevails(): void
    {
        $services = $this->getServiceLocator();
        $options = ['title' => 'Custom'] + $services->get('Config')['vikus']['block_settings']['vikus'];
        $config = $services->get('Vikus\ConfigBuilder')->build($options, 'My page', 'http://example.org/x');
        $this->assertSame('Custom', $config['project']['name']);
    }
}
