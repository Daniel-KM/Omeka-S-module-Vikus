<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Stdlib\OptionsNormalizer;
use VikusTest\VikusTestTrait;

class OptionsNormalizerTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected function defaults(): array
    {
        return $this->getServiceLocator()->get('Config')['vikus']['block_settings']['vikus'];
    }

    public function testNormalizeSanitizesEveryOption(): void
    {
        $data = OptionsNormalizer::normalize([
            'query' => 'fulltext_search=abc',
            'max_items' => '-3',
            'columns' => 999999,
            'height' => '10px; color: red',
            'image_source' => 'evil',
            'sprite_size' => '512',
            'link_to_item' => '0',
            'layout_map' => '0',
            'map_outliers' => '0',
            'property_keywords' => '',
            'properties_group' => ['dcterms:subject', ''],
            'style_fontColor' => 'red;} body{display:none',
            'style_canvasBackground' => '#ABCDEF',
            'filter_type' => 'evil',
            'property_year_annotation' => ['dcterms:date'],
            'year_precision' => 'evil',
        ], $this->defaults());

        $this->assertSame(['fulltext_search' => 'abc'], $data['query']);
        $this->assertSame(0, $data['max_items']);
        $this->assertSame(50, $data['columns']);
        $this->assertSame('80vh', $data['height']);
        $this->assertSame('auto', $data['image_source']);
        $this->assertSame(256, $data['sprite_size']);
        $this->assertFalse($data['link_to_item']);
        $this->assertFalse($data['layout_map']);
        $this->assertSame('none', $data['map_outliers']);
        $this->assertSame([], $data['property_keywords']);
        $this->assertSame(['dcterms:subject'], $data['properties_group']);
        $this->assertSame('#2d3b43', $data['style_fontColor']);
        $this->assertSame('#ABCDEF', $data['style_canvasBackground']);
        $this->assertSame('tags', $data['filter_type']);
        $this->assertSame('', $data['property_year_annotation']);
        $this->assertSame('year', $data['year_precision']);
    }

    public function testNormalizeParsesTheTitlesOfTheLayouts(): void
    {
        $data = OptionsNormalizer::normalize([
            'layout_titles' => "dcterms:subject = Themes\ndcterms:type = Kinds\n",
        ], $this->defaults());
        $this->assertSame(['dcterms:subject' => 'Themes', 'dcterms:type' => 'Kinds'], $data['layout_titles']);
    }

    public function testNormalizeKeepsAnEmptyListOfTitles(): void
    {
        $data = OptionsNormalizer::normalize(['layout_titles' => ''], $this->defaults());
        $this->assertSame([], $data['layout_titles']);
    }

    public function testNormalizeKeepsAKnownYearPrecision(): void
    {
        $data = OptionsNormalizer::normalize(['year_precision' => 'century'], $this->defaults());
        $this->assertSame('century', $data['year_precision']);
    }

    public function testNormalizeKeepsTheMapLayout(): void
    {
        $data = OptionsNormalizer::normalize(['layout_map' => '1'], $this->defaults());
        $this->assertTrue($data['layout_map']);
    }

    public function testNormalizeKeepsAKnownModeOfTheOutliers(): void
    {
        $data = OptionsNormalizer::normalize(['map_outliers' => 'hide'], $this->defaults());
        $this->assertSame('hide', $data['map_outliers']);
        $data = OptionsNormalizer::normalize(['map_outliers' => 'none'], $this->defaults());
        $this->assertSame('none', $data['map_outliers']);
        $data = OptionsNormalizer::normalize(['map_outliers' => 'evil'], $this->defaults());
        $this->assertSame('clamp', $data['map_outliers']);
    }

    public function testNormalizeConvertsTheFormerCheckboxOfTheOutliers(): void
    {
        $data = OptionsNormalizer::normalize(['map_outliers' => true], $this->defaults());
        $this->assertSame('clamp', $data['map_outliers']);
        $data = OptionsNormalizer::normalize(['map_outliers' => false], $this->defaults());
        $this->assertSame('none', $data['map_outliers']);
    }

    public function testNormalizeChecksTheUrlOfTheTiles(): void
    {
        $defaults = $this->defaults();
        $url = 'https://tiles.example.org/{z}/{x}/{y}@2x.png';
        $this->assertSame($url, OptionsNormalizer::normalize(['map_tiles' => ' ' . $url . ' '], $defaults)['map_tiles']);
        // A template without the three placeholders would request the same
        // tile everywhere, and only http and https are allowed.
        foreach (['https://tiles.example.org/{z}/{x}.png', 'javascript:alert(1)', 'ftp://x/{z}/{x}/{y}.png', ''] as $wrong) {
            $this->assertSame(
                OptionsNormalizer::MAP_TILES,
                OptionsNormalizer::normalize(['map_tiles' => $wrong], $defaults)['map_tiles']
            );
        }
    }

    public function testNormalizeStripsTheTagsOfTheAttribution(): void
    {
        $defaults = $this->defaults();
        // Leaflet writes the attribution as html in its control, so the
        // option is reduced to a plain text, escaped again by the script.
        $data = OptionsNormalizer::normalize(
            ['map_attribution' => ' <script>alert(1)</script>OSM '],
            $defaults
        );
        $this->assertSame('alert(1)OSM', $data['map_attribution']);
        $this->assertSame(
            'OpenStreetMap contributors',
            OptionsNormalizer::normalize(
                ['map_attribution' => '<a href="https://evil">OpenStreetMap</a> contributors'],
                $defaults
            )['map_attribution']
        );
    }

    public function testNormalizeClampsTheOpacityOfTheBaseMap(): void
    {
        $defaults = $this->defaults();
        $this->assertSame(0.25, OptionsNormalizer::normalize(['map_opacity' => '0.25'], $defaults)['map_opacity']);
        $this->assertSame(1.0, OptionsNormalizer::normalize(['map_opacity' => '9'], $defaults)['map_opacity']);
        $this->assertSame(0.0, OptionsNormalizer::normalize(['map_opacity' => '-1'], $defaults)['map_opacity']);
        $this->assertSame(0.6, OptionsNormalizer::normalize(['map_opacity' => 'evil'], $defaults)['map_opacity']);
    }

    public function testNormalizeCastsTheHidingOfTheItemsWithoutCoordinates(): void
    {
        $defaults = $this->defaults();
        $this->assertFalse(OptionsNormalizer::normalize([], $defaults)['map_hide_missing']);
        $this->assertFalse(
            OptionsNormalizer::normalize(['map_hide_missing' => '0'], $defaults)['map_hide_missing']
        );
        $this->assertTrue(
            OptionsNormalizer::normalize(['map_hide_missing' => '1'], $defaults)['map_hide_missing']
        );
    }

    public function testNormalizeKeepsTheReservedKeysOfTheLayoutTitles(): void
    {
        $data = OptionsNormalizer::normalize(
            ['layout_titles' => "time = Chronologie\nmap = Localisation\ndcterms:subject = Themes"],
            $this->defaults()
        );
        $this->assertSame(
            ['time' => 'Chronologie', 'map' => 'Localisation', 'dcterms:subject' => 'Themes'],
            $data['layout_titles']
        );
    }

    public function testNormalizeKeepsAKnownFilterType(): void
    {
        $data = OptionsNormalizer::normalize(['filter_type' => 'crossfilter'], $this->defaults());
        $this->assertSame('crossfilter', $data['filter_type']);
    }

    public function testFromSettingsUsesDefaultsAndNormalizes(): void
    {
        $services = $this->getServiceLocator();
        $defaults = $services->get('Config')['vikus']['site_settings'];
        $settings = new class extends \Omeka\Settings\SiteSettings {
            public function __construct()
            {
            }

            public function get($id, $default = null, $useCache = true)
            {
                return [
                    'vikus_columns' => 9999,
                    'vikus_image_source' => 'evil',
                    'vikus_sprite_size' => '128',
                    'vikus_style_fontColor' => 'nope',
                    'vikus_filter_type' => 'hierarchical',
                    'vikus_year_precision' => 'evil',
                    'vikus_layout_titles' => 'dcterms:subject = Themes',
                ][$id] ?? $default;
            }
        };

        $options = OptionsNormalizer::fromSettings($settings, $defaults);

        $this->assertSame(50, $options['columns']);
        $this->assertSame('auto', $options['image_source']);
        $this->assertSame(128, $options['sprite_size']);
        $this->assertSame('#2d3b43', $options['style_fontColor']);
        $this->assertSame('dcterms:date', $options['property_year']);
        $this->assertSame('', $options['property_year_annotation']);
        $this->assertSame('hierarchical', $options['filter_type']);
        $this->assertSame('year', $options['year_precision']);
        $this->assertSame(['dcterms:subject' => 'Themes'], $options['layout_titles']);
        $this->assertArrayNotHasKey('title', $options);
        // The events of the timeline are an option of the block only.
        $this->assertArrayNotHasKey('timeline_events', $options);
    }
}
