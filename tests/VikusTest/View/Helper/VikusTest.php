<?php declare(strict_types=1);

namespace VikusTest\View\Helper;

use Omeka\Test\AbstractHttpControllerTestCase;
use VikusTest\VikusTestTrait;

class VikusTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected $block;

    protected $siteSlug;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        // Tests do not run in isolated transactions here, so the fixed slug may
        // collide between test methods: fall back to a unique one.
        $this->siteSlug = 'vikus-helper';
        try {
            $site = $this->createSite($this->siteSlug);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            $this->siteSlug = 'vikus-helper-' . bin2hex(random_bytes(3));
            $site = $this->createSite($this->siteSlug);
        }
        $this->block = $this->createPageWithBlock($site, ['height' => '60vh']);
        // The helper needs a site route match to build urls.
        $this->dispatch('/s/' . $this->siteSlug);
    }

    public function tearDown(): void
    {
        $this->getServiceLocator()->get('Vikus\CacheStorage')->delete('b' . $this->block->id());
        parent::tearDown();
    }

    protected function render(): string
    {
        $helper = $this->getServiceLocator()->get('ViewHelperManager')->get('vikus');
        return $helper($this->block->page()->site(), 'b' . $this->block->id(), '60vh');
    }

    protected function publish(array $meta): void
    {
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $temp = $storage->prepareTemp('b' . $this->block->id());
        file_put_contents($temp . '/meta.json', json_encode($meta));
        $storage->publish('b' . $this->block->id(), $temp);
    }

    public function testPending(): void
    {
        // The listener queues a job on creation, but the test dispatch strategy
        // never runs it, so no cache exists yet.
        $html = $this->render();
        $this->assertStringContainsString('vikus-pending', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function testEmpty(): void
    {
        $this->publish(['total' => 0]);
        $this->assertStringContainsString('vikus-empty', $this->render());
    }

    public function testIframe(): void
    {
        $this->publish(['total' => 3]);
        $html = $this->render();
        $this->assertStringContainsString('<iframe', $html);
        // The url is html-attribute-escaped (e.g. "/" becomes "&#x2F;").
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('/s/' . $this->siteSlug . '/vikus/b' . $this->block->id(), $decoded);
        $this->assertStringContainsString('config=', $decoded);
        $this->assertStringContainsString('height: 60vh', $html);
        $this->assertMatchesRegularExpression('~<a class="vikus-full" href="[^"]*/vikus/b' . $this->block->id() . '\?config=[^"]+" target="_blank">Full screen</a>~', $decoded);
    }
}
