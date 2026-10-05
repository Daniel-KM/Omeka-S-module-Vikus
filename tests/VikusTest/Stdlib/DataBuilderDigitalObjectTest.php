<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Omeka\Test\AbstractHttpControllerTestCase;
use VikusTest\VikusTestTrait;

class DataBuilderDigitalObjectTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected string $dir;

    protected array $digitalObjectIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->dir = sys_get_temp_dir() . '/vikusdo' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function tearDown(): void
    {
        foreach ($this->digitalObjectIds as $id) {
            $this->deleteDigitalObject($id);
        }
        $this->digitalObjectIds = [];
        @unlink($this->dir . '/data.csv');
        @unlink($this->dir . '/timeline.csv');
        @unlink($this->dir . '/map.csv');
        @rmdir($this->dir);
        $this->cleanVikusTestFiles();
        parent::tearDown();
    }

    public function testItemWithoutMediaUsesItsDigitalObject(): void
    {
        if (!$this->hasDigitalObject()) {
            $this->markTestSkipped('Requires DigitalObject.');
        }

        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');
        $site = $this->createSite('vikus-digitalobject');
        $item = $services->get('Omeka\ApiManager')->create('items', [
            'o:site' => [['o:id' => $site->id()]],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Sunflowers',
            ]],
        ])->getContent();
        $this->digitalObjectIds[] = $this->createDigitalObjectForItem($item->id(), 200, 200);

        $options = [
            'query' => [],
            'property_year' => 'dcterms:date',
            'property_keywords' => [],
            'properties_detail' => ['dcterms:title'],
            'image_source' => 'omeka',
            'link_to_item' => false,
            'info' => '',
        ];
        $sources = $services->get('Vikus\DataBuilder')->build($options, $site, $this->dir, 100);

        $handle = fopen($this->dir . '/data.csv', 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $row = array_combine($headers, fgetcsv($handle, 0, ',', '"', ''));
        fclose($handle);

        $this->assertSame((string) $item->id(), $row['id']);
        $this->assertStringContainsString('/large/vikustest', $row['_detail']);

        // The thumbnail packed in the sprite is the local large derivative of
        // the digital object.
        $this->assertArrayHasKey($item->id(), $sources);
        $this->assertStringContainsString('/large/vikustest', $sources[$item->id()]);
        $this->assertFileExists($sources[$item->id()]);
    }
}
