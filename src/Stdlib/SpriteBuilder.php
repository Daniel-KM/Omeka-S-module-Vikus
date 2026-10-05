<?php declare(strict_types=1);

namespace Vikus\Stdlib;

/**
 * Pack thumbnails in atlases with a manifest in the format pixi-packer.
 */
class SpriteBuilder
{
    const SIZE = 256;
    const ATLAS = 2048;
    const GUTTER = 1;
    const QUALITY = 85;

    protected bool $useImagick;

    protected string $dir;

    /**
     * @var \Imagick|\GdImage|null
     */
    protected $atlas;

    protected array $spritesheets = [];

    protected array $sprites = [];

    protected int $x = 0;

    protected int $y = 0;

    protected int $shelfHeight = 0;

    public function __construct(?bool $useImagick = null)
    {
        $this->useImagick = $useImagick ?? extension_loaded('imagick');
    }

    /**
     * @param array $sources Paths or urls of thumbnails by item id.
     * @return array Ids of the items whose thumbnail is not readable.
     */
    public function build(array $sources, string $dir): array
    {
        $this->dir = $dir . '/sprites';
        mkdir($this->dir, 0775, true);
        $this->spritesheets = [];
        $this->openAtlas();

        $failed = [];
        foreach ($sources as $id => $source) {
            $image = $this->load($source);
            if (!$image) {
                $failed[] = $id;
                continue;
            }
            [$width, $height] = $this->fit(...$this->dimensions($image));
            if ($this->x + $width > self::ATLAS) {
                $this->x = 0;
                $this->y += $this->shelfHeight + self::GUTTER;
                $this->shelfHeight = 0;
            }
            if ($this->y + $height > self::ATLAS) {
                $this->closeAtlas();
                $this->openAtlas();
            }
            $this->compose($image, $width, $height);
            $this->sprites[] = [
                'name' => (string) $id,
                'position' => ['x' => $this->x, 'y' => $this->y],
                'dimension' => ['w' => $width, 'h' => $height],
            ];
            $this->x += $width + self::GUTTER;
            $this->shelfHeight = max($this->shelfHeight, $height);
        }
        $this->closeAtlas();

        file_put_contents($this->dir . '/sprites.json', json_encode([
            'meta' => ['type' => 'pixi-packer', 'version' => '1'],
            'resolution' => 1,
            'spritesheets' => $this->spritesheets,
        ], JSON_UNESCAPED_SLASHES));

        return $failed;
    }

    /**
     * Fit dimensions in the size of a sprite, without upscale.
     */
    protected function fit(int $width, int $height): array
    {
        $ratio = min(1, self::SIZE / $width, self::SIZE / $height);
        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    protected function openAtlas(): void
    {
        $this->sprites = [];
        $this->x = $this->y = $this->shelfHeight = 0;
        if ($this->useImagick) {
            $this->atlas = new \Imagick();
            $this->atlas->newImage(self::ATLAS, self::ATLAS, 'black', 'jpeg');
        } else {
            $this->atlas = imagecreatetruecolor(self::ATLAS, self::ATLAS);
        }
    }

    protected function closeAtlas(): void
    {
        if ($this->sprites) {
            $name = count($this->spritesheets) . '.jpg';
            if ($this->useImagick) {
                $this->atlas->setImageCompressionQuality(self::QUALITY);
                $this->atlas->writeImage($this->dir . '/' . $name);
            } else {
                imagejpeg($this->atlas, $this->dir . '/' . $name, self::QUALITY);
            }
            $this->spritesheets[] = ['image' => $name, 'sprites' => $this->sprites];
        }
        $this->useImagick ? $this->atlas->clear() : imagedestroy($this->atlas);
        $this->atlas = null;
    }

    /**
     * @return \Imagick|\GdImage|null
     */
    protected function load(string $source)
    {
        $content = @file_get_contents($source);
        if (!$content) {
            return null;
        }
        if (!$this->useImagick) {
            return @imagecreatefromstring($content) ?: null;
        }
        try {
            $image = new \Imagick();
            $image->readImageBlob($content);
            return $image;
        } catch (\ImagickException $e) {
            return null;
        }
    }

    protected function dimensions($image): array
    {
        return $this->useImagick
            ? [$image->getImageWidth(), $image->getImageHeight()]
            : [imagesx($image), imagesy($image)];
    }

    protected function compose($image, int $width, int $height): void
    {
        if ($this->useImagick) {
            $image->thumbnailImage($width, $height);
            $this->atlas->compositeImage($image, \Imagick::COMPOSITE_COPY, $this->x, $this->y);
            $image->clear();
            return;
        }
        imagecopyresampled($this->atlas, $image, $this->x, $this->y, 0, 0, $width, $height, imagesx($image), imagesy($image));
        imagedestroy($image);
    }
}
