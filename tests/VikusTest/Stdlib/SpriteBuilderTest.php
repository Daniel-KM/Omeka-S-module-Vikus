<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use PHPUnit\Framework\TestCase;
use Vikus\Stdlib\SpriteBuilder;

class SpriteBuilderTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vikussprite' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/src', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/src/*'), glob($this->dir . '/sprites/*') ?: []) as $file) {
            unlink($file);
        }
        @rmdir($this->dir . '/sprites');
        rmdir($this->dir . '/src');
        rmdir($this->dir);
    }

    protected function image(int $id, int $width, int $height): string
    {
        $path = $this->dir . '/src/' . $id . '.jpg';
        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $path);
        imagedestroy($image);
        return $path;
    }

    /**
     * @dataProvider engineProvider
     */
    public function testManifest(bool $useImagick): void
    {
        if ($useImagick && !extension_loaded('imagick')) {
            $this->markTestSkipped('Requires imagick.');
        }

        $sources = [
            1 => $this->image(1, 400, 200),
            2 => $this->image(2, 100, 300),
            3 => $this->image(3, 80, 60),
            4 => $this->dir . '/src/missing.jpg',
        ];

        $failed = (new SpriteBuilder($useImagick))->build($sources, $this->dir);
        $this->assertSame([4], $failed);

        $manifest = json_decode(file_get_contents($this->dir . '/sprites/sprites.json'), true);
        $this->assertSame('pixi-packer', $manifest['meta']['type']);
        $this->assertSame('1', $manifest['meta']['version']);
        $this->assertCount(1, $manifest['spritesheets']);
        $this->assertSame('0.jpg', $manifest['spritesheets'][0]['image']);
        $this->assertFileExists($this->dir . '/sprites/0.jpg');

        $sprites = array_column($manifest['spritesheets'][0]['sprites'], null, 'name');
        $this->assertSame(['1', '2', '3'], array_map('strval', array_keys($sprites)));
        $this->assertSame(['w' => 256, 'h' => 128], $sprites['1']['dimension']);
        $this->assertSame(['w' => 85, 'h' => 256], $sprites['2']['dimension']);
        // No upscale.
        $this->assertSame(['w' => 80, 'h' => 60], $sprites['3']['dimension']);
        $this->assertNoOverlap(array_values($sprites));
    }

    public function engineProvider(): array
    {
        return [[false], [true]];
    }

    public function testSecondAtlas(): void
    {
        // 7 x 7 sprites of 256 + 1 px fit in 2048: the 50th goes elsewhere.
        $sources = [];
        for ($i = 1; $i <= 50; $i++) {
            $sources[$i] = $this->image($i, 256, 256);
        }
        (new SpriteBuilder(false))->build($sources, $this->dir);

        $manifest = json_decode(file_get_contents($this->dir . '/sprites/sprites.json'), true);
        $this->assertCount(2, $manifest['spritesheets']);
        $this->assertCount(49, $manifest['spritesheets'][0]['sprites']);
        $this->assertCount(1, $manifest['spritesheets'][1]['sprites']);
        $this->assertFileExists($this->dir . '/sprites/1.jpg');
    }

    public function testSmallerSpriteSize(): void
    {
        // 15 x 15 sprites of 128 + 1 px fit in 2048: the 226th goes elsewhere.
        $sources = [];
        for ($i = 1; $i <= 230; $i++) {
            $sources[$i] = $this->image($i, 256, 256);
        }
        (new SpriteBuilder(false))->build($sources, $this->dir, null, 128);

        $manifest = json_decode(file_get_contents($this->dir . '/sprites/sprites.json'), true);
        $this->assertCount(2, $manifest['spritesheets']);
        $this->assertCount(225, $manifest['spritesheets'][0]['sprites']);
        foreach ($manifest['spritesheets'][0]['sprites'] as $sprite) {
            $this->assertSame(['w' => 128, 'h' => 128], $sprite['dimension']);
        }
    }

    public function testShouldStopEarlyExit(): void
    {
        $sources = [];
        for ($i = 1; $i <= 150; $i++) {
            $sources[$i] = $this->image($i, 4, 4);
        }
        $shouldStop = fn (): bool => true;
        $failed = (new SpriteBuilder(false))->build($sources, $this->dir, $shouldStop);
        $this->assertSame([], $failed);

        $manifest = json_decode(file_get_contents($this->dir . '/sprites/sprites.json'), true);
        $total = array_sum(array_map(fn ($sheet) => count($sheet['sprites']), $manifest['spritesheets']));
        $this->assertLessThan(150, $total);
    }

    public function testBuildThrowsWhenDirectoryExists(): void
    {
        mkdir($this->dir . '/sprites', 0775, true);
        $this->expectException(\RuntimeException::class);
        (new SpriteBuilder(false))->build([], $this->dir);
    }

    /**
     * @dataProvider engineProvider
     */
    public function testDegenerateSourcesAreSkipped(bool $useImagick): void
    {
        if ($useImagick && !extension_loaded('imagick')) {
            $this->markTestSkipped('Requires imagick.');
        }

        $empty = $this->dir . '/src/empty.jpg';
        file_put_contents($empty, '');
        $garbage = $this->dir . '/src/garbage.jpg';
        file_put_contents($garbage, 'not an image');

        $sources = [1 => $empty, 2 => $garbage];
        $failed = (new SpriteBuilder($useImagick))->build($sources, $this->dir);
        $this->assertSame([1, 2], $failed);

        $manifest = json_decode(file_get_contents($this->dir . '/sprites/sprites.json'), true);
        $this->assertSame([], $manifest['spritesheets']);
    }

    protected function assertNoOverlap(array $sprites): void
    {
        foreach ($sprites as $i => $a) {
            foreach (array_slice($sprites, $i + 1) as $b) {
                $separated = $a['position']['x'] + $a['dimension']['w'] <= $b['position']['x']
                    || $b['position']['x'] + $b['dimension']['w'] <= $a['position']['x']
                    || $a['position']['y'] + $a['dimension']['h'] <= $b['position']['y']
                    || $b['position']['y'] + $b['dimension']['h'] <= $a['position']['y'];
                $this->assertTrue($separated);
            }
        }
    }
}
