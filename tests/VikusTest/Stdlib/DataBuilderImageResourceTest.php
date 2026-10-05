<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Doctrine\ORM\EntityManager;
use Laminas\I18n\Translator\TranslatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Response;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use PHPUnit\Framework\TestCase;
use Vikus\Stdlib\DataBuilder;
use Vikus\Stdlib\ImageUrl;

class DataBuilderImageResourceTest extends TestCase
{
    protected function builder(?callable $digitalObjectInline, ?ApiManager $api = null): DataBuilder
    {
        return new DataBuilder(
            $api ?? $this->createMock(ApiManager::class),
            $this->createMock(EntityManager::class),
            $this->createMock(ImageUrl::class),
            $this->createMock(TranslatorInterface::class),
            sys_get_temp_dir(),
            false,
            $digitalObjectInline
        );
    }

    protected function imageResource(DataBuilder $builder, ItemRepresentation $item)
    {
        $method = new \ReflectionMethod($builder, 'imageResource');
        $method->setAccessible(true);
        return $method->invoke($builder, $item);
    }

    protected function item(?MediaRepresentation $media): ItemRepresentation
    {
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('primaryMedia')->willReturn($media);
        return $item;
    }

    protected function media(bool $hasThumbnails): MediaRepresentation
    {
        $media = $this->createMock(MediaRepresentation::class);
        $media->method('hasThumbnails')->willReturn($hasThumbnails);
        return $media;
    }

    /**
     * A double of a digital object: it is a resource representation with the
     * file api of a media, of which only hasThumbnails() is used here. The
     * class of the module DigitalObject is not referenced.
     */
    protected function digitalObject(bool $hasThumbnails): AbstractResourceEntityRepresentation
    {
        $digitalObject = $this->getMockBuilder(AbstractResourceEntityRepresentation::class)
            ->disableOriginalConstructor()
            ->addMethods(['hasThumbnails'])
            ->getMockForAbstractClass();
        $digitalObject->method('hasThumbnails')->willReturn($hasThumbnails);
        return $digitalObject;
    }

    public function testMediaIsPreferredToDigitalObject(): void
    {
        $media = $this->media(true);
        $digitalObject = $this->digitalObject(true);
        $builder = $this->builder(fn ($item): array => [$digitalObject]);
        $this->assertSame($media, $this->imageResource($builder, $this->item($media)));
    }

    public function testDigitalObjectIsUsedWithoutMedia(): void
    {
        $digitalObject = $this->digitalObject(true);
        $builder = $this->builder(fn ($item): array => [$digitalObject]);
        $this->assertSame($digitalObject, $this->imageResource($builder, $this->item(null)));
    }

    public function testDigitalObjectIsUsedWhenTheMediaHasNoThumbnail(): void
    {
        $digitalObject = $this->digitalObject(true);
        $builder = $this->builder(fn ($item): array => [$digitalObject]);
        $this->assertSame($digitalObject, $this->imageResource($builder, $this->item($this->media(false))));
    }

    public function testFirstDigitalObjectWithThumbnailIsUsed(): void
    {
        $without = $this->digitalObject(false);
        $with = $this->digitalObject(true);
        $builder = $this->builder(fn ($item): array => [$without, $with]);
        $this->assertSame($with, $this->imageResource($builder, $this->item(null)));
    }

    public function testNoImageWithoutMediaAndDigitalObject(): void
    {
        $builder = $this->builder(fn ($item): array => []);
        $this->assertNull($this->imageResource($builder, $this->item(null)));
    }

    public function testNoImageWithoutTheHelper(): void
    {
        $builder = $this->builder(null);
        $this->assertNull($this->imageResource($builder, $this->item(null)));
    }

    /**
     * @dataProvider queryProvider
     */
    public function testItemQueryFiltersOnMediaUnlessDigitalObjectsExist(
        bool $hasHelper,
        int $totalDigitalObjects,
        bool $expectedHasMedia
    ): void {
        $queries = [];
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturnCallback(
            function ($resource, array $data = [], array $options = []) use (&$queries, $totalDigitalObjects) {
                $queries[$resource][] = $data;
                $response = $this->createMock(Response::class);
                $response->method('getTotalResults')
                    ->willReturn($resource === 'digital_objects' ? $totalDigitalObjects : 0);
                $response->method('getContent')->willReturn([]);
                return $response;
            }
        );

        $site = $this->createMock(SiteRepresentation::class);
        $site->method('id')->willReturn(1);
        $site->method('slug')->willReturn('site');

        $dir = sys_get_temp_dir() . '/vikusquery' . bin2hex(random_bytes(4));
        mkdir($dir);
        $builder = $this->builder($hasHelper ? fn ($item): array => [] : null, $api);
        $builder->build([], $site, $dir, 100);
        foreach (['data.csv', 'timeline.csv'] as $file) {
            @unlink($dir . '/' . $file);
        }
        rmdir($dir);

        $this->assertSame($expectedHasMedia, isset($queries['items'][0]['has_media']));
        // The digital objects are only counted when the helper is available.
        $this->assertSame($hasHelper, isset($queries['digital_objects']));
    }

    public function queryProvider(): array
    {
        return [
            'no helper' => [false, 0, true],
            'helper without digital object' => [true, 0, true],
            'helper with digital objects' => [true, 3, false],
        ];
    }
}
