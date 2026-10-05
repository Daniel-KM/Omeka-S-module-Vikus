<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use Omeka\Api\Representation\MediaRepresentation;
use PHPUnit\Framework\TestCase;
use Vikus\Stdlib\ImageUrl;

class ImageUrlTest extends TestCase
{
    protected function media(bool $hasOriginal = true, string $mediaType = 'image/jpeg'): MediaRepresentation
    {
        $media = $this->createMock(MediaRepresentation::class);
        $media->method('thumbnailUrl')->with('large')->willReturn('http://example.org/files/large/a.jpg');
        $media->method('originalUrl')->willReturn('http://example.org/files/original/a.jpg');
        $media->method('hasOriginal')->willReturn($hasOriginal);
        $media->method('mediaType')->willReturn($mediaType);
        return $media;
    }

    public function testBigFallsBackToLargeWithoutOriginal(): void
    {
        $imageUrl = new ImageUrl(null);
        $this->assertSame([
            'detail' => 'http://example.org/files/large/a.jpg',
            'big' => 'http://example.org/files/large/a.jpg',
        ], $imageUrl->urls($this->media(false), 'omeka'));
    }

    public function testBigFallsBackToLargeForANonImage(): void
    {
        $imageUrl = new ImageUrl(null);
        $this->assertSame(
            'http://example.org/files/large/a.jpg',
            $imageUrl->urls($this->media(true, 'application/pdf'), 'omeka')['big']
        );
    }

    public function testOmekaFallbackWithoutIiif(): void
    {
        $imageUrl = new ImageUrl(null);
        $expected = [
            'detail' => 'http://example.org/files/large/a.jpg',
            'big' => 'http://example.org/files/original/a.jpg',
        ];
        $this->assertSame($expected, $imageUrl->urls($this->media(), 'auto'));
        $this->assertSame($expected, $imageUrl->urls($this->media(), 'iiif'));
    }

    public function testIiifWhenAvailable(): void
    {
        $imageUrl = new ImageUrl(fn () => 'http://example.org/iiif/3/a');
        $this->assertSame([
            'detail' => 'http://example.org/iiif/3/a/full/!1024,1024/0/default.jpg',
            'big' => 'http://example.org/iiif/3/a/full/!4096,4096/0/default.jpg',
        ], $imageUrl->urls($this->media(), 'auto'));
    }

    public function testIiifVersion3PrefixesTheSizes(): void
    {
        $imageUrl = new ImageUrl(fn () => 'http://example.org/iiif/a', null, true);
        $this->assertSame([
            'detail' => 'http://example.org/iiif/a/full/^!1024,1024/0/default.jpg',
            'big' => 'http://example.org/iiif/a/full/^!4096,4096/0/default.jpg',
        ], $imageUrl->urls($this->media(), 'auto'));
    }

    public function testKnownDimensionsAvoidAnyUpscale(): void
    {
        $small = new ImageUrl(fn () => 'http://example.org/iiif/a', null, true, fn () => ['width' => 800, 'height' => 600]);
        $this->assertSame([
            'detail' => 'http://example.org/iiif/a/full/max/0/default.jpg',
            'big' => 'http://example.org/iiif/a/full/max/0/default.jpg',
        ], $small->urls($this->media(), 'auto'));

        $medium = new ImageUrl(fn () => 'http://example.org/iiif/a', null, true, fn () => ['width' => 1981, 'height' => 2500]);
        $this->assertSame([
            'detail' => 'http://example.org/iiif/a/full/!1024,1024/0/default.jpg',
            'big' => 'http://example.org/iiif/a/full/max/0/default.jpg',
        ], $medium->urls($this->media(), 'auto'));

        $unknown = new ImageUrl(fn () => 'http://example.org/iiif/a', null, true, fn () => ['width' => null, 'height' => null]);
        $this->assertSame([
            'detail' => 'http://example.org/iiif/a/full/^!1024,1024/0/default.jpg',
            'big' => 'http://example.org/iiif/a/full/^!4096,4096/0/default.jpg',
        ], $unknown->urls($this->media(), 'auto'));
    }

    public function testOmekaIsForced(): void
    {
        $imageUrl = new ImageUrl(fn () => 'http://example.org/iiif/3/a');
        $this->assertSame(
            'http://example.org/files/large/a.jpg',
            $imageUrl->urls($this->media(), 'omeka')['detail']
        );
    }

    public function testEmptyIiifUrlFallsBackToOmeka(): void
    {
        $imageUrl = new ImageUrl(fn () => '');
        $this->assertSame(
            'http://example.org/files/large/a.jpg',
            $imageUrl->urls($this->media(), 'auto')['detail']
        );
    }

    public function testNonImageFallsBackToOmekaEvenWithIiif(): void
    {
        $imageUrl = new ImageUrl(fn () => 'http://example.org/iiif/a');
        $media = $this->media(true, 'application/pdf');
        $this->assertSame(
            'http://example.org/files/large/a.jpg',
            $imageUrl->urls($media, 'auto')['detail']
        );
    }

    public function testNonImageUsesIiifWhenDeclaredAsIiifMedia(): void
    {
        $imageUrl = new ImageUrl(
            fn () => 'http://example.org/iiif/a',
            fn ($media, ?string $type) => $type === 'image'
        );
        $media = $this->media(true, 'application/pdf');
        $this->assertSame([
            'detail' => 'http://example.org/iiif/a/full/!1024,1024/0/default.jpg',
            'big' => 'http://example.org/iiif/a/full/!4096,4096/0/default.jpg',
        ], $imageUrl->urls($media, 'auto'));
    }

    public function testNonImageIsRejectedWhenNotDeclaredAsIiifMedia(): void
    {
        $imageUrl = new ImageUrl(
            fn () => 'http://example.org/iiif/a',
            fn ($media, ?string $type) => false
        );
        $media = $this->media(true, 'application/pdf');
        $this->assertSame(
            'http://example.org/files/large/a.jpg',
            $imageUrl->urls($media, 'auto')['detail']
        );
    }
}
