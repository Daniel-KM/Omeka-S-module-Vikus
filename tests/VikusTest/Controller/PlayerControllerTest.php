<?php declare(strict_types=1);

namespace VikusTest\Controller;

use Laminas\Form\Element\Csrf;
use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Controller\PlayerController;
use Vikus\Job\GenerateData;
use VikusTest\VikusTestTrait;

class PlayerControllerTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected $site;

    protected $block;

    /**
     * @var int[]
     */
    protected $extraBlockIds = [];

    /**
     * @var string|null
     */
    protected $viewerDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->site = $this->createSite('vikus-player' . bin2hex(random_bytes(3)));
        $this->block = $this->createPageWithBlock($this->site, ['title' => 'Van Gogh']);
    }

    public function tearDown(): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $storage->delete('b' . $this->block->id());
        foreach ($this->extraBlockIds as $blockId) {
            $storage->delete('b' . $blockId);
        }
        $this->cleanVikusTestFiles();
        if ($this->viewerDir) {
            @unlink($this->viewerDir . '/index.html');
            @rmdir($this->viewerDir);
            $this->viewerDir = null;
        }
        parent::tearDown();
    }

    /**
     * Serve the page of the viewer from a temporary directory.
     *
     * The path of the viewer is hardcoded in the factory of the controller, so
     * the controller is registered again with another one.
     */
    protected function useViewerPage(string $html): void
    {
        $this->viewerDir = sys_get_temp_dir() . '/vikusviewer' . bin2hex(random_bytes(4));
        mkdir($this->viewerDir);
        file_put_contents($this->viewerDir . '/index.html', $html);

        $services = $this->getServiceLocator();
        $path = $this->viewerDir;
        $controllers = $services->get('ControllerManager');
        $controllers->setAllowOverride(true);
        $controllers->setFactory('Vikus\Controller\Player', fn (): PlayerController => new PlayerController(
            $services->get('Omeka\EntityManager'),
            $services->get('Vikus\CacheStorage'),
            $services->get('Vikus\ConfigBuilder'),
            $services->get('Omeka\Settings\Site'),
            $services->get('Config')['vikus']['block_settings']['vikus'],
            $services->get('Config')['vikus']['site_settings'],
            $path
        ));
        $controllers->setAllowOverride(false);
    }

    protected function path(string $action = ''): string
    {
        return '/s/' . $this->site->slug() . '/vikus/b' . $this->block->id() . ($action ? '/' . $action : '');
    }

    public function testConfig(): void
    {
        $this->dispatch($this->path('config'));
        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'application/json; charset=utf-8');
        $config = json_decode($this->getResponse()->getContent(), true);
        $this->assertSame('Van Gogh', $config['project']['name']);
        $this->assertStringEndsWith('/vikus/b' . $this->block->id() . '/data.csv', $config['loader']['items']);
    }

    /**
     * The layout of the map is declared from the meta of the generated cache,
     * so the job and the config are checked together.
     */
    public function testConfigHasTheMapLayoutAfterTheGeneration(): void
    {
        if (!$this->hasMapping()) {
            $this->markTestSkipped('The module Mapping is not active.');
        }
        $mapBlock = $this->createPageWithBlock($this->site, ['title' => 'Map', 'layout_map' => true]);
        $this->extraBlockIds[] = $mapBlock->id();
        $key = 'b' . $mapBlock->id();
        $item = $this->createItemWithImage([
            'o:site' => [['o:id' => $this->site->id()]],
        ], 200, 200);
        $this->addMappingPoint($item->id(), 4.6277, 43.6766);

        $this->runJob($key);

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $this->assertTrue($storage->readMeta($key)['map']);
        $this->assertFileExists($storage->path($key) . '/map.csv');

        $this->dispatch('/s/' . $this->site->slug() . '/vikus/' . $key . '/config');
        $this->assertResponseStatusCode(200);
        $config = json_decode($this->getResponse()->getContent(), true);
        $layouts = $config['loader']['layouts'];
        $this->assertSame('Map', end($layouts)['title']);
        $this->assertSame($storage->url($key) . '/map.csv', end($layouts)['url']);
    }

    public function testConfigHasNoMapLayoutWithoutAnyPoint(): void
    {
        $mapBlock = $this->createPageWithBlock($this->site, ['title' => 'Map', 'layout_map' => true]);
        $this->extraBlockIds[] = $mapBlock->id();
        $key = 'b' . $mapBlock->id();
        $this->createItemWithImage([
            'o:site' => [['o:id' => $this->site->id()]],
        ], 200, 200);

        $this->runJob($key);

        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $this->assertFalse($storage->readMeta($key)['map']);
        $this->assertFileDoesNotExist($storage->path($key) . '/map.csv');

        $this->dispatch('/s/' . $this->site->slug() . '/vikus/' . $key . '/config');
        $this->assertResponseStatusCode(200);
        $config = json_decode($this->getResponse()->getContent(), true);
        $this->assertSame([['title' => 'Time']], $config['loader']['layouts']);
    }

    public function testPlayWithoutConfigRedirectsToTheCompleteUrl(): void
    {
        $this->dispatch($this->path());
        $this->assertResponseStatusCode(302);
        $location = $this->getResponse()->getHeaders()->get('Location')->getFieldValue();
        $this->assertStringContainsString($this->path() . '?config=', $location);
        $this->assertStringContainsString($this->path('config'), $location);
    }

    public function testPlay(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'text/html; charset=utf-8');
        $html = $this->getResponse()->getContent();
        $this->assertMatchesRegularExpression('~<base href="[^"]+/modules/Vikus/asset/vendor/vikus-viewer/" target="_blank"~', $html);
        $this->assertStringContainsString('js/viz.js', $html);
    }

    public function testPlayInjectsTheScriptOfTheModule(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $html = $this->getResponse()->getContent();
        $this->assertMatchesRegularExpression(
            '~<script src="[^"]*/modules/Vikus/asset/js/viewer\.js[^"]*" data-vikus-hint="~',
            $html
        );
        // The wrapper of Canvas() must be installed before viz.js starts.
        $this->assertLessThan(strpos($html, 'js/viz.js'), strpos($html, 'asset/js/viewer.js'));
    }

    public function testScriptExposesTheDataAndResetsTheNanPositions(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/asset/js/viewer.js');
        $this->assertStringContainsString('window.data = data;', $js);
        $this->assertStringContainsString('isFinite(d.sprite.position.x)', $js);
        $this->assertStringContainsString('vikusNoCsv', $js);
        $this->assertStringNotContainsString('DELAY_LAYOUT', $js);
    }

    public function testPlayInjectsTheTranslatedHintToZoom(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $translate = $this->getApplication()->getServiceManager()->get('ViewHelperManager')->get('translate');
        $this->assertStringContainsString(
            'data-vikus-hint="' . htmlspecialchars(
                $translate('Scroll or pinch to zoom, click an image to open its record.'),
                ENT_QUOTES
            ) . '"',
            $this->getResponse()->getContent()
        );
    }

    public function testPlayInjectsTheStylesheetOfTheModule(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $this->assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="[^"]*/modules/Vikus/asset/css/viewer\.css[^"]*"></head>~',
            $this->getResponse()->getContent()
        );
    }

    public function testPlayInjectsTheBaseMapOnlyWithAMapLayout(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        // No map layout: nothing more is loaded by the page.
        $this->assertStringNotContainsString('leaflet', $this->getResponse()->getContent());

        $mapBlock = $this->createPageWithBlock($this->site, [
            'title' => 'Map',
            'layout_map' => true,
            'map_attribution' => 'OSM',
        ]);
        $this->extraBlockIds[] = $mapBlock->id();
        $key = 'b' . $mapBlock->id();
        $storage = $this->getServiceLocator()->get('Vikus\\CacheStorage');
        $temp = $storage->prepareTemp($key);
        file_put_contents($temp . '/meta.json', json_encode([
            'map' => true,
            'map_bounds' => [-5.0, 47.0, 3.0, 49.5],
            'page_id' => $mapBlock->page()->id(),
        ]));
        $storage->publish($key, $temp);

        $this->reset();
        $this->loginAdmin();
        $this->dispatch('/s/' . $this->site->slug() . '/vikus/' . $key . '/play', 'GET', [
            'config' => 'https://example.org/config',
        ]);
        $this->assertResponseStatusCode(200);
        $html = $this->getResponse()->getContent();
        $this->assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="[^"]*/modules/Vikus/asset/vendor/leaflet/dist/leaflet\.css[^"]*">~',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~<script src="[^"]*/modules/Vikus/asset/vendor/leaflet/dist/leaflet\.js[^"]*"></script>~',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~<script src="[^"]*/modules/Vikus/asset/js/map\.js[^"]*"></script>~',
            $html
        );
        $this->assertStringContainsString('window.vikusMap = {', $html);
        $map = json_decode(
            (string) preg_replace('~^.*window\.vikusMap = (\{.*?\});</script>.*$~s', '$1', $html),
            true
        );
        $this->assertSame('Map', $map['title']);
        // json_encode writes a round float as an integer, and javascript
        // reads both as numbers.
        $this->assertSame([-5, 47, 3, 49.5], $map['bounds']);
        $this->assertSame('OSM', $map['attribution']);
        // The option is off by default, so the page of the production never
        // hides the items without coordinates without being asked to.
        $this->assertFalse($map['hideMissing']);
    }

    public function testScriptOfTheMapHidesTheItemsWithoutCoordinates(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/asset/js/map.js');
        $this->assertStringContainsString('settings.hideMissing', $js);
        // The renderer of the viewer eases the alpha of the sprite toward
        // these two properties of the item.
        $this->assertMatchesRegularExpression('~d\.alpha = 0;\s*d\.alpha2 = 0;~', $js);
    }

    public function testScriptGuardsSetModeAgainstAnOutdatedLayoutTitle(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/asset/js/viewer.js');
        $this->assertStringContainsString('utils.setMode', $js);
    }

    public function testStylesheetSetsTheSizeOfTheLabelsOfTheYears(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/asset/css/viewer.css');
        $this->assertMatchesRegularExpression(
            '~\.timeline \.year\s*\{[^}]*font-size:\s*11px\s*!important~',
            $css
        );
        $this->assertMatchesRegularExpression(
            '~\.timeline \.year\s*\{[^}]*white-space:\s*nowrap~',
            $css
        );
    }

    public function testPlayReplacesTheExistingBaseTag(): void
    {
        $this->useViewerPage('<html><head><base href="/x/"></head><body><p>v</p></body></html>');
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $html = $this->getResponse()->getContent();
        $this->assertStringNotContainsString('href="/x/"', $html);
        $this->assertMatchesRegularExpression(
            '~<head><base href="[^"]+/asset/vendor/vikus-viewer/" target="_blank"><link ~',
            $html
        );
    }

    public function testPlayInjectsTheBaseTagWhenMissing(): void
    {
        $this->useViewerPage('<html><head><title>v</title></head><body><p>v</p></body></html>');
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $this->assertMatchesRegularExpression(
            '~<head><base href="[^"]+/asset/vendor/vikus-viewer/" target="_blank"><title>~',
            $this->getResponse()->getContent()
        );
    }

    public function testNotFoundForOtherLayout(): void
    {
        $other = $this->createPageWithBlock($this->site, ['html' => 'x'], 'html');
        $this->dispatch('/s/' . $this->site->slug() . '/vikus/b' . $other->id() . '/config');
        $this->assertResponseStatusCode(404);
    }

    public function testNotFoundForOtherSite(): void
    {
        $other = $this->createSite('vikus-other' . bin2hex(random_bytes(3)));
        $this->dispatch('/s/' . $other->slug() . '/vikus/b' . $this->block->id() . '/config');
        $this->assertResponseStatusCode(404);
    }

    public function testNotFoundForPrivatePageWhenAnonymous(): void
    {
        $privateBlock = $this->createPageWithBlock($this->site, ['title' => 'Private'], 'vikus', false);
        $this->extraBlockIds[] = $privateBlock->id();
        $path = '/s/' . $this->site->slug() . '/vikus/b' . $privateBlock->id() . '/config';

        // A new application is required to get a real anonymous request: the
        // visibility filter of doctrine is set up once per application, from
        // the identity of its first request.
        $this->reset();
        $this->dispatch($path);
        $this->assertResponseStatusCode(404);

        $this->reset();
        $this->loginAdmin();
        $this->dispatch($path);
        $this->assertResponseStatusCode(200);
    }

    public function testGenerateRequiresPost(): void
    {
        $this->dispatch($this->path('generate'));
        $this->assertResponseStatusCode(405);
    }

    public function testGenerateIsForbiddenForAnonymous(): void
    {
        $this->getServiceLocator()->get('Omeka\AuthenticationService')->clearIdentity();
        $this->dispatch($this->path('generate'), 'POST', []);
        $this->assertResponseStatusCode(403);
    }

    public function testPlayHasNoButtonForABlock(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        // The form of the block has the checkbox "Regenerate on save".
        $this->dispatch($this->path(), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $this->assertStringNotContainsString('vikus-regenerate', $this->getResponse()->getContent());
    }

    public function testGenerateDispatchesJobAndRedirects(): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $temp = $storage->prepareTemp('b' . $this->block->id());
        file_put_contents($temp . '/meta.json', json_encode([
            'hash' => 'stale',
            'total' => 0,
            'page_id' => $this->block->page()->id(),
        ]));
        $storage->publish('b' . $this->block->id(), $temp);

        $before = $this->countGenerateDataJobs();
        // "Omeka\Form\ConfirmForm" gets its csrf field named after the form's
        // own name ("confirmform"), set by the form element manager.
        $csrf = (new Csrf('confirmform_csrf'))->getValue();
        $this->dispatch($this->path('generate'), 'POST', ['confirmform_csrf' => $csrf]);

        $this->assertResponseStatusCode(302);
        $this->assertSame($before + 1, $this->countGenerateDataJobs());
        // The "generate" action never touches the cache itself: the currently
        // published one is kept while the job is running.
        $this->assertDirectoryExists($storage->path('b' . $this->block->id()));
    }

    protected function countGenerateDataJobs(): int
    {
        return (int) $this->getServiceLocator()->get('Omeka\EntityManager')
            ->createQuery('SELECT COUNT(j.id) FROM Omeka\Entity\Job j WHERE j.class = :class')
            ->setParameter('class', GenerateData::class)
            ->getSingleScalarResult();
    }
}
