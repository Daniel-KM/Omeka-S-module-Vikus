<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Omeka\Test\AbstractHttpControllerTestCase;
use VikusTest\VikusTestTrait;

class ImageUrlIiifTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        $this->cleanVikusTestFiles();
        parent::tearDown();
    }

    public function testUrlsUseTheImageServerConfiguredInIiifServer(): void
    {
        $helpers = $this->getServiceLocator()->get('ViewHelperManager');
        if (!$helpers->has('iiifMediaUrl')) {
            $this->markTestSkipped('Requires IiifServer.');
        }

        $item = $this->createItemWithImage(['o:item_set' => []], 200, 100);
        $media = $item->primaryMedia();

        $imageUrl = $this->getServiceLocator()->get('Vikus\ImageUrl');

        // The image of 200 px is smaller than the square: no upscale.
        $urls = $imageUrl->urls($media, 'auto');
        $this->assertStringEndsWith('/full/max/0/default.jpg', $urls['detail']);
        $this->assertTrue(
            strpos($urls['detail'], (string) $media->id()) !== false
            || strpos($urls['detail'], (string) $media->storageId()) !== false
        );

        $omekaUrls = $imageUrl->urls($media, 'omeka');
        $this->assertStringContainsString('/large/', $omekaUrls['detail']);
    }
}
