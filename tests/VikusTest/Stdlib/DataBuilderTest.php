<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Stdlib\DataBuilder;
use VikusTest\VikusTestTrait;

class DataBuilderTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->dir = sys_get_temp_dir() . '/vikusdata' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function tearDown(): void
    {
        @unlink($this->dir . '/data.csv');
        @unlink($this->dir . '/timeline.csv');
        @unlink($this->dir . '/map.csv');
        @rmdir($this->dir);
        $this->cleanVikusTestFiles();
        parent::tearDown();
    }

    /**
     * @dataProvider yearProvider
     */
    public function testExtractYear(string $value, string $expected): void
    {
        $this->assertSame($expected, DataBuilder::extractYear($value));
    }

    public function yearProvider(): array
    {
        return [
            ['1889', '1889'],
            ['1889-05-12', '1889'],
            ['12/05/1889', '1889'],
            ['vers 1900', '1900'],
            ['-0050', '-50'],
            ['1850/1870', '1850'],
            ['-0050/-0025', '-50'],
            ['0476', '476'],
            // A year of less than four digits is not extracted: a date with
            // unknown digits would give a wrong year.
            ['476', ''],
            ['18XX~', ''],
            ['190X~', ''],
            ['sans date', ''],
            ['', ''],
        ];
    }

    /**
     * @dataProvider roundYearProvider
     */
    public function testRoundYear(string $year, string $precision, string $expected): void
    {
        $this->assertSame($expected, DataBuilder::roundYear($year, $precision));
    }

    public function roundYearProvider(): array
    {
        return [
            ['1888', 'year', '1888'],
            ['1888', 'decade', '1880'],
            ['1888', 'century', '1800'],
            ['1900', 'decade', '1900'],
            ['1900', 'century', '1900'],
            ['1880', 'decade', '1880'],
            // A negative year is rounded away from zero, as a positive one is
            // rounded toward the past: the decade of "-45" begins at "-50".
            ['-45', 'decade', '-50'],
            ['-45', 'century', '-100'],
            ['-50', 'decade', '-50'],
            ['-100', 'century', '-100'],
            ['0', 'century', '0'],
            ['', 'decade', ''],
            ['1888', 'evil', '1888'],
        ];
    }

    public function testColumn(): void
    {
        $this->assertSame('_dcterms_title', DataBuilder::column('dcterms:title'));
    }

    public function testColumnFilterAndGroup(): void
    {
        $this->assertSame('_f_dcterms_subject', DataBuilder::columnFilter('dcterms:subject'));
        $this->assertSame('_g_dcterms_subject', DataBuilder::columnGroup('dcterms:subject'));
    }

    public function testBuildCrossfilterColumns(): void
    {
        $site = $this->createSite('vikus-crossfilter');
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $linked = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->create('items', $this->values($site->id(), 'Van Gogh', '1853', []))->getContent();
        $values = $this->values($site->id(), 'Sunflowers', '1889', ['Flowers', 'Still life']);
        $values['dcterms:creator'] = [[
            'type' => 'resource:item',
            'property_id' => $easyMeta->propertyId('dcterms:creator'),
            'value_resource_id' => $linked->id(),
        ]];
        $item = $this->createItemWithImage($values, 200, 200);

        $options = [
            'filter_type' => 'crossfilter',
            'property_keywords' => ['dcterms:subject', 'dcterms:creator', 'dcterms:publisher'],
        ] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = array_combine($headers, fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);

        $this->assertSame([
            'id', 'keywords', 'year', '_dcterms_title',
            '_f_dcterms_subject', '_f_dcterms_creator', '_f_dcterms_publisher',
            '_url', '_detail', '_big',
        ], $headers);
        $this->assertSame((string) $item->id(), $row['id']);
        // A dimension of the crossfilter is a single value, so the first one
        // is used, and the title of a linked resource is its label.
        $this->assertSame('Flowers', $row['_f_dcterms_subject']);
        $this->assertSame('Van Gogh', $row['_f_dcterms_creator']);
        $this->assertSame('', $row['_f_dcterms_publisher']);
        // The keywords are filled in every mode.
        $this->assertSame('Flowers,Still life,Van Gogh', $row['keywords']);
    }

    public function testBuildWithoutCrossfilterHasNoFilterColumn(): void
    {
        $site = $this->createSite('vikus-nofilter');
        $this->createItemWithImage($this->values($site->id(), 'Sunflowers', '1889', ['Flowers']), 100, 100);

        $options = ['filter_type' => 'hierarchical'] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);
        $this->assertSame(['id', 'keywords', 'year', '_dcterms_title', '_url', '_detail', '_big'], $headers);
    }

    public function testBuildGroupColumns(): void
    {
        $site = $this->createSite('vikus-group');
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $linked = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->create('items', $this->values($site->id(), 'Van Gogh', '1853', []))->getContent();
        $values = $this->values($site->id(), 'Sunflowers', '1889', ['Flowers', 'Still life']);
        $values['dcterms:creator'] = [[
            'type' => 'resource:item',
            'property_id' => $easyMeta->propertyId('dcterms:creator'),
            'value_resource_id' => $linked->id(),
        ]];
        $this->createItemWithImage($values, 200, 200);

        $options = [
            'properties_group' => ['dcterms:subject', 'dcterms:creator', 'dcterms:publisher'],
        ] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = array_combine($headers, fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);

        $this->assertSame([
            'id', 'keywords', 'year', '_dcterms_title',
            '_g_dcterms_subject', '_g_dcterms_creator', '_g_dcterms_publisher',
            '_url', '_detail', '_big',
        ], $headers);
        // A group layout nests the items on a single value of the column.
        $this->assertSame('Flowers', $row['_g_dcterms_subject']);
        $this->assertSame('Van Gogh', $row['_g_dcterms_creator']);
        // The viewer removes the empty keys from the domain of a layout, so an
        // item without value would be placed at an unknown key, that is out of
        // the canvas: it is grouped under a label instead.
        $this->assertSame('Unknown', $row['_g_dcterms_publisher']);
    }

    public function testClampOutliersPinsTheIsolatedPointsToTheDenseArea(): void
    {
        // Ninety-six points in a dense area and four isolated ones, out of
        // the range of the second and of the ninety-eighth percentiles.
        $points = [];
        for ($i = 0; $i < 96; $i++) {
            $points[$i] = [-2.0 + $i / 100, 48.0 + $i / 100];
        }
        $points[100] = [-60.0, 10.0];
        $points[101] = [-59.0, 11.0];
        $points[102] = [40.0, 80.0];
        $points[103] = [41.0, 81.0];

        $clamped = DataBuilder::clampOutliers($points);

        $xs = array_column($clamped, 0);
        $ys = array_column($clamped, 1);
        $this->assertGreaterThan(-3.0, min($xs));
        $this->assertLessThan(0.0, max($xs));
        $this->assertGreaterThan(47.0, min($ys));
        $this->assertLessThan(50.0, max($ys));
        // The points of the dense area keep their own coordinates and the ids
        // are all preserved.
        $this->assertSame($points[50], $clamped[50]);
        $this->assertSame(array_keys($points), array_keys($clamped));
    }

    public function testClampOutliersKeepsASmallCollectionAsIs(): void
    {
        $points = [1 => [-2.0, 48.0], 2 => [2.3, 48.9], 3 => [-60.0, 10.0]];
        $this->assertSame($points, DataBuilder::clampOutliers($points));
    }

    public function testHideOutliersDropsTheIsolatedPoints(): void
    {
        $points = [];
        for ($i = 0; $i < 96; $i++) {
            $points[$i] = [-2.0 + $i / 100, 48.0 + $i / 100];
        }
        $points[100] = [-60.0, 10.0];
        $points[101] = [-59.0, 11.0];
        $points[102] = [40.0, 80.0];
        $points[103] = [41.0, 81.0];

        $hidden = DataBuilder::hideOutliers($points);

        // The isolated points are dropped, the dense ones are untouched.
        $this->assertArrayNotHasKey(100, $hidden);
        $this->assertArrayNotHasKey(103, $hidden);
        $this->assertSame($points[50], $hidden[50]);
        $this->assertLessThan(count($points), count($hidden));
    }

    public function testHideOutliersKeepsASmallCollectionAsIs(): void
    {
        $points = [1 => [-2.0, 48.0], 2 => [2.3, 48.9], 3 => [-60.0, 10.0]];
        $this->assertSame($points, DataBuilder::hideOutliers($points));
    }

    public function testMercatorProjectsTheLatitude(): void
    {
        $this->assertEqualsWithDelta(0.0, DataBuilder::mercator(0.0), 1e-12);
        // ln(tan(pi/4 + pi/8)) for 45 degrees north.
        $this->assertEqualsWithDelta(0.881373587, DataBuilder::mercator(45.0), 1e-9);
        $this->assertEqualsWithDelta(-0.881373587, DataBuilder::mercator(-45.0), 1e-9);
        // Beyond the limit of the projection, the latitude is clamped.
        $this->assertSame(
            DataBuilder::mercator(DataBuilder::MAP_LATITUDE_MAX),
            DataBuilder::mercator(89.0)
        );
        $this->assertEqualsWithDelta(
            43.6766,
            DataBuilder::latitude(DataBuilder::mercator(43.6766)),
            1e-9
        );
    }

    public function testMapWindowIsSquareInProjectedUnits(): void
    {
        $points = [
            1 => [-5.0, DataBuilder::mercator(48.0)],
            2 => [3.0, DataBuilder::mercator(49.0)],
        ];
        [$minX, $minY, $maxX, $maxY] = DataBuilder::mapWindow($points);

        // A square window: the width in radians of longitude equals the
        // height in projected units, so the base map is not distorted.
        $this->assertEqualsWithDelta(deg2rad($maxX - $minX), $maxY - $minY, 1e-12);
        // Every point is inside the window.
        $this->assertLessThanOrEqual(-5.0, $minX);
        $this->assertGreaterThanOrEqual(3.0, $maxX);
        $this->assertLessThanOrEqual(DataBuilder::mercator(48.0), $minY);
        $this->assertGreaterThanOrEqual(DataBuilder::mercator(49.0), $maxY);
    }

    public function testMapWindowKeepsAMinimalSizeAndStaysInTheWorld(): void
    {
        $same = [1 => [2.0, DataBuilder::mercator(48.0)], 2 => [2.0, DataBuilder::mercator(48.0)]];
        [$minX, $minY, $maxX, $maxY] = DataBuilder::mapWindow($same);
        $this->assertGreaterThan(0, $maxX - $minX);
        $this->assertEqualsWithDelta(2 * DataBuilder::MAP_HALF_MIN, $maxY - $minY, 1e-12);

        $world = [
            1 => [-179.0, DataBuilder::mercator(-85.0)],
            2 => [179.0, DataBuilder::mercator(85.0)],
        ];
        [$minX, $minY, $maxX, $maxY] = DataBuilder::mapWindow($world);
        $this->assertGreaterThanOrEqual(-180.0, $minX);
        $this->assertLessThanOrEqual(180.0, $maxX);
        $this->assertGreaterThanOrEqual(-M_PI, $minY);
        $this->assertLessThanOrEqual(M_PI, $maxY);
    }

    public function testBuildStatsCountsTheDistinctYearsAndGroups(): void
    {
        $site = $this->createSite('vikus-stats');
        $this->createItemWithImage($this->values($site->id(), 'A', '1889', ['Flowers']), 200, 200);
        $this->createItemWithImage($this->values($site->id(), 'B', '1889', ['Flowers']), 200, 200);
        $this->createItemWithImage($this->values($site->id(), 'C', '1853', ['Still life']), 200, 200);
        $this->createItemWithImage($this->values($site->id(), 'D', '', []), 200, 200);

        $options = ['properties_group' => ['dcterms:subject']] + $this->options();
        $builder = $this->getServiceLocator()->get('Vikus\DataBuilder');
        $builder->build($options, $site, $this->dir, 100);

        // The item without year is not counted, the one without subject is
        // grouped under "Unknown", that is a key of the layout as any other.
        $this->assertSame(
            ['years' => 2, 'groups' => ['dcterms:subject' => 3], 'map_bounds' => []],
            $builder->stats()
        );
    }

    public function testBuildStatsAreEmptyWithoutGroupProperty(): void
    {
        $site = $this->createSite('vikus-stats-none');
        $this->createItemWithImage($this->values($site->id(), 'A', '1889', ['Flowers']), 200, 200);

        $builder = $this->getServiceLocator()->get('Vikus\DataBuilder');
        $builder->build($this->options(), $site, $this->dir, 100);

        $this->assertSame(['years' => 1, 'groups' => [], 'map_bounds' => []], $builder->stats());
    }

    public function testBuildMapFromTheFeaturesOfMapping(): void
    {
        if (!$this->hasMapping()) {
            $this->markTestSkipped('The module Mapping is not active.');
        }
        $site = $this->createSite('vikus-map');
        $with = $this->createItemWithImage($this->values($site->id(), 'Arles', '1888', []), 100, 100);
        $this->createItemWithImage($this->values($site->id(), 'Nowhere', '1889', []), 100, 100);
        $this->addMappingPoint($with->id(), 4.6277, 43.6766);

        $options = ['layout_map' => true] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/map.csv', 'r');
        // The viewer reads the columns id, x and y (canvas.js, addTsneData).
        $this->assertSame(['id', 'x', 'y'], fgetcsv($handle, 0, ',', '"', ''));
        // The two anchors set the extent of the file, that is the square
        // window of the base map; their ids are never those of an item.
        $first = fgetcsv($handle, 0, ',', '"', '');
        $second = fgetcsv($handle, 0, ',', '"', '');
        $this->assertSame('0', $first[0]);
        $this->assertSame('-1', $second[0]);
        // x is the longitude and y the Web Mercator ordinate of the latitude:
        // the viewer negates y to place a row, so the north is up.
        $row = fgetcsv($handle, 0, ',', '"', '');
        $this->assertSame((string) $with->id(), $row[0]);
        $this->assertEqualsWithDelta(4.6277, (float) $row[1], 1e-9);
        $this->assertEqualsWithDelta(DataBuilder::mercator(43.6766), (float) $row[2], 1e-9);
        // An item without point is absent: the viewer hides it in that layout.
        $this->assertFalse(fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);

        // The bounds of the window are recorded for the config of the map.
        $bounds = $this->getServiceLocator()->get('Vikus\\DataBuilder')->stats()['map_bounds'];
        $this->assertCount(4, $bounds);
    }

    public function testBuildMapKeepsTheFirstPointOfAnItem(): void
    {
        if (!$this->hasMapping()) {
            $this->markTestSkipped('The module Mapping is not active.');
        }
        $site = $this->createSite('vikus-map-points');
        $item = $this->createItemWithImage($this->values($site->id(), 'Arles', '1888', []), 100, 100);
        $this->addMappingPoint($item->id(), 4.6277, 43.6766);
        $this->addMappingPoint($item->id(), 2.3522, 48.8566);

        $options = ['layout_map' => true] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/map.csv', 'r');
        fgetcsv($handle, 0, ',', '"', '');
        fgetcsv($handle, 0, ',', '"', '');
        fgetcsv($handle, 0, ',', '"', '');
        // The features are sorted by id, so the same point is kept from one
        // generation to the next.
        $row = fgetcsv($handle, 0, ',', '"', '');
        $this->assertSame((string) $item->id(), $row[0]);
        $this->assertEqualsWithDelta(4.6277, (float) $row[1], 1e-9);
        $this->assertEqualsWithDelta(DataBuilder::mercator(43.6766), (float) $row[2], 1e-9);
        $this->assertFalse(fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);
    }

    public function testBuildMapHidesTheIsolatedPoints(): void
    {
        if (!$this->hasMapping()) {
            $this->markTestSkipped('The module Mapping is not active.');
        }
        $site = $this->createSite('vikus-map-hide');
        // Fifty points are required to process the isolated ones, so the
        // dense area is built with fifty items and a single distant one.
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $item = $this->createItemWithImage($this->values($site->id(), 'D' . $i, '1888', []), 20, 20);
            $this->addMappingPoint($item->id(), -2.0 + $i / 1000, 48.0 + $i / 1000);
            $ids[] = $item->id();
        }
        $far = $this->createItemWithImage($this->values($site->id(), 'Far', '1888', []), 20, 20);
        $this->addMappingPoint($far->id(), 120.0, -35.0);

        $options = ['layout_map' => true, 'map_outliers' => 'hide'] + $this->options();
        $this->getServiceLocator()->get('Vikus\\DataBuilder')->build($options, $site, $this->dir, 100);

        $written = [];
        $handle = fopen($this->dir . '/map.csv', 'r');
        fgetcsv($handle, 0, ',', '"', '');
        while ($row = fgetcsv($handle, 0, ',', '"', '')) {
            $written[] = $row[0];
        }
        fclose($handle);

        // The isolated point is dropped, so the viewer hides that item on the
        // map only; the dense ones are all kept, beside the two anchors.
        $this->assertNotContains((string) $far->id(), $written);
        $this->assertContains((string) $ids[25], $written);
        $this->assertGreaterThan(40, count($written));
    }

    public function testBuildMapIsSkippedWithoutTheOption(): void
    {
        $site = $this->createSite('vikus-nomap');
        $item = $this->createItemWithImage($this->values($site->id(), 'Arles', '1888', []), 100, 100);
        if ($this->hasMapping()) {
            $this->addMappingPoint($item->id(), 4.6277, 43.6766);
        }

        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($this->options(), $site, $this->dir, 100);

        $this->assertFileDoesNotExist($this->dir . '/map.csv');
    }

    public function testBuildMapIsSkippedWithoutAnyPoint(): void
    {
        $site = $this->createSite('vikus-emptymap');
        $this->createItemWithImage($this->values($site->id(), 'Nowhere', '1888', []), 100, 100);

        $options = ['layout_map' => true] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $this->assertFileDoesNotExist($this->dir . '/map.csv');
    }

    public function testBuildGroupOfABlankValueIsUnknown(): void
    {
        $site = $this->createSite('vikus-blank');
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $values = $this->values($site->id(), 'Sunflowers', '1889', ['   ']);
        $values['dcterms:creator'] = [[
            'type' => 'literal',
            'property_id' => $easyMeta->propertyId('dcterms:creator'),
            '@value' => '   ',
        ]];
        $this->createItemWithImage($values, 100, 100);

        $options = ['properties_group' => ['dcterms:creator']] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = array_combine($headers, fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);

        // A value made of spaces only is an invisible key of a group layout.
        $this->assertSame('Unknown', $row['_g_dcterms_creator']);
        $this->assertSame('', $row['keywords']);
    }

    public function testHashIgnoresPresentationOptions(): void
    {
        $options = $this->options();
        $hash = DataBuilder::hash($options);
        $this->assertSame($hash, DataBuilder::hash(['columns' => 9, 'title' => 'x', 'height' => '50vh'] + $options));
        $this->assertSame($hash, DataBuilder::hash(['layout_titles' => ['dcterms:type' => 'Kinds']] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['property_year' => 'dcterms:created'] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['property_year_annotation' => 'dcterms:date'] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['year_precision' => 'decade'] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['filter_type' => 'crossfilter'] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['properties_group' => ['dcterms:type']] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['layout_map' => true] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['map_outliers' => false] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['sprite_size' => 128] + $options));
        $this->assertNotSame($hash, DataBuilder::hash(['timeline_events' => [['year' => '1789']]] + $options));
    }

    public function testBuild(): void
    {
        $site = $this->createSite('vikus-data');
        $with = $this->createItemWithImage($this->values($site->id(), 'Sunflowers', '1889-01', ['Flowers, yellow', 'Still life']), 400, 300);
        $this->getServiceLocator()->get('Omeka\ApiManager')
            ->create('items', $this->values($site->id(), 'No image', '1890', []));

        $builder = $this->getServiceLocator()->get('Vikus\DataBuilder');
        $sources = $builder->build($this->options(), $site, $this->dir, 100);

        $this->assertSame([$with->id()], array_keys($sources));
        $this->assertFileExists($sources[$with->id()]);
        // The sprites are 256 px, so they are packed from the large derivative:
        // the medium one may be configured below that size by the site.
        $this->assertStringContainsString('/large/', $sources[$with->id()]);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = array_combine($headers, fgetcsv($handle, 0, ',', '"', ''));
        $this->assertFalse(fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);

        $this->assertSame(['id', 'keywords', 'year', '_dcterms_title', '_url', '_detail', '_big'], $headers);

        // The viewer always loads a timeline file: it must exist, even empty.
        $timeline = fopen($this->dir . '/timeline.csv', 'r');
        $this->assertSame(['year', 'titel', 'text', 'extra'], fgetcsv($timeline, 0, ',', '"', ''));
        $this->assertFalse(fgetcsv($timeline, 0, ',', '"', ''));
        fclose($timeline);
        $this->assertSame((string) $with->id(), $row['id']);
        $this->assertSame('Flowers yellow,Still life', $row['keywords']);
        $this->assertSame('1889', $row['year']);
        $this->assertSame('Sunflowers', $row['_dcterms_title']);
        $this->assertStringContainsString('/s/vikus-data/item/' . $with->id(), $row['_url']);
        $this->assertStringContainsString('/large/', $row['_detail']);
        $this->assertStringContainsString('/original/', $row['_big']);
    }

    public function testBuildTimelineEvents(): void
    {
        $site = $this->createSite('vikus-timeline');
        $options = ['timeline_events' => [
            ['year' => '1789-07-14', 'title' => 'Revolution', 'text' => 'Bastille', 'extra' => 'More'],
            ['year' => '1900', 'title' => 'Fair', 'text' => '', 'extra' => ''],
            ['year' => '', 'title' => '', 'text' => '', 'extra' => ''],
        ]] + $this->options();

        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $timeline = fopen($this->dir . '/timeline.csv', 'r');
        // The viewer reads d.titel, d.text and d.extra (timeline.js).
        $this->assertSame(['year', 'titel', 'text', 'extra'], fgetcsv($timeline, 0, ',', '"', ''));
        $this->assertSame(['1789', 'Revolution', 'Bastille', 'More'], fgetcsv($timeline, 0, ',', '"', ''));
        $this->assertSame(['1900', 'Fair', '', ''], fgetcsv($timeline, 0, ',', '"', ''));
        $this->assertFalse(fgetcsv($timeline, 0, ',', '"', ''));
        fclose($timeline);
    }

    public function testBuildQuotesBackslashesAndQuotes(): void
    {
        $site = $this->createSite('vikus-quote');
        $title = 'back\\"slash';
        $item = $this->createItemWithImage($this->values($site->id(), $title, '1889', []), 100, 100);

        $builder = $this->getServiceLocator()->get('Vikus\DataBuilder');
        $builder->build($this->options(), $site, $this->dir, 100);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);

        // With the default escape character, the quote is written verbatim and
        // the row loses a column for the parser of the viewer.
        $this->assertCount(count($headers), $row);
        $this->assertSame($title, array_combine($headers, $row)['_dcterms_title']);
        $this->assertSame((string) $item->id(), $row[0]);
    }

    public function testBuildStopsAtMaxItems(): void
    {
        $site = $this->createSite('vikus-max');
        $this->createItemWithImage($this->values($site->id(), 'A', '1889', []), 100, 100);
        $this->createItemWithImage($this->values($site->id(), 'B', '1890', []), 100, 100);

        $builder = $this->getServiceLocator()->get('Vikus\DataBuilder');
        $this->assertCount(1, $builder->build($this->options(), $site, $this->dir, 1));
    }

    public function testBuildStopsAtTheLimitOfTheQuery(): void
    {
        $site = $this->createSite('vikus-query-limit');
        $this->createItemWithImage($this->values($site->id(), 'A', '1889', []), 100, 100);
        $this->createItemWithImage($this->values($site->id(), 'B', '1890', []), 100, 100);
        $this->createItemWithImage($this->values($site->id(), 'C', '1891', []), 100, 100);

        $options = ['query' => ['limit' => 2]] + $this->options();

        $builder = $this->getServiceLocator()->get('Vikus\\DataBuilder');
        $this->assertCount(2, $builder->build($options, $site, $this->dir, 100));
        // The header and the two rows.
        $this->assertCount(3, file($this->dir . '/data.csv'));
    }

    public function testBuildClearsTheEntityManager(): void
    {
        $site = $this->createSite('vikus-clear');
        $item = $this->createItemWithImage($this->values($site->id(), 'A', '1889', []), 100, 100);

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $itemEntity = $entityManager->find(\Omeka\Entity\Item::class, $item->id());
        $this->assertTrue($entityManager->contains($itemEntity));

        $services->get('Vikus\DataBuilder')->build($this->options(), $site, $this->dir, 100);

        // The identity map is cleared after each batch, so a collection of
        // thousands of items does not exhaust the memory.
        $this->assertFalse($entityManager->contains($itemEntity));
    }

    public function testShouldStopEarlyExit(): void
    {
        $site = $this->createSite('vikus-stop');
        $this->createItemWithImage($this->values($site->id(), 'A', '1889', []), 100, 100);

        $builder = $this->getServiceLocator()->get('Vikus\DataBuilder');
        $shouldStop = fn (): bool => true;
        $sources = $builder->build($this->options(), $site, $this->dir, 100, $shouldStop);

        $this->assertSame([], $sources);
        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $this->assertFalse(fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);
        $this->assertSame(['id', 'keywords', 'year', '_dcterms_title', '_url', '_detail', '_big'], $headers);

        // The viewer always loads a timeline file: it must exist, even empty.
        $timeline = fopen($this->dir . '/timeline.csv', 'r');
        $this->assertSame(['year', 'titel', 'text', 'extra'], fgetcsv($timeline, 0, ',', '"', ''));
        $this->assertFalse(fgetcsv($timeline, 0, ',', '"', ''));
        fclose($timeline);
    }

    public function testBuildYearFromValueAnnotation(): void
    {
        $site = $this->createSite('vikus-annotation');
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $values = $this->values($site->id(), 'Sunflowers', '1889', ['Flowers']);
        $values['dcterms:creator'] = [[
            'type' => 'literal',
            'property_id' => $easyMeta->propertyId('dcterms:creator'),
            '@value' => 'Van Gogh',
            '@annotation' => [
                'dcterms:date' => [[
                    'type' => 'literal',
                    'property_id' => $easyMeta->propertyId('dcterms:date'),
                    '@value' => '1865-04-25',
                ]],
            ],
        ]];
        $this->createItemWithImage($values, 100, 100);

        $options = [
            'property_year' => 'dcterms:creator',
            'property_year_annotation' => 'dcterms:date',
        ] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);
        $this->assertSame('1865', $this->firstRow()['year']);
    }

    public function testBuildYearWithoutAnnotationUsesTheValue(): void
    {
        $site = $this->createSite('vikus-noannotation');
        $this->createItemWithImage($this->values($site->id(), 'Sunflowers', '1889', ['Flowers']), 100, 100);

        $options = ['property_year_annotation' => ''] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);
        $this->assertSame('1889', $this->firstRow()['year']);
    }

    public function testBuildYearPrecisionRoundsTheYear(): void
    {
        $site = $this->createSite('vikus-precision');
        $this->createItemWithImage($this->values($site->id(), 'Sunflowers', '1888-05-12', ['Flowers']), 100, 100);

        $options = ['year_precision' => 'decade'] + $this->options();
        $this->getServiceLocator()->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);
        $this->assertSame('1880', $this->firstRow()['year']);
    }

    /**
     * Read the first row of data.csv, keyed by header.
     */
    protected function firstRow(): array
    {
        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);
        return array_combine($headers, $row);
    }

    protected function options(): array
    {
        return [
            'query' => [],
            'property_year' => 'dcterms:date',
            'property_keywords' => ['dcterms:subject'],
            'properties_detail' => ['dcterms:title'],
            'image_source' => 'omeka',
            'link_to_item' => true,
            'info' => '',
        ];
    }

    protected function values(int $siteId, string $title, string $date, array $subjects): array
    {
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $literal = fn (string $term, string $value): array => [
            'type' => 'literal',
            'property_id' => $easyMeta->propertyId($term),
            '@value' => $value,
        ];
        return [
            'o:site' => [['o:id' => $siteId]],
            'dcterms:title' => [$literal('dcterms:title', $title)],
            'dcterms:date' => [$literal('dcterms:date', $date)],
            'dcterms:subject' => array_map(fn ($v) => $literal('dcterms:subject', $v), $subjects),
        ];
    }
}
