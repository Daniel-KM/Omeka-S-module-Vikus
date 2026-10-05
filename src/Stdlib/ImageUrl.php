<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class ImageUrl
{
    /**
     * @var callable|null
     */
    protected $iiifMediaUrl;

    /**
     * @var callable|null
     */
    protected $isIiifMedia;

    /**
     * @var bool
     */
    protected $iiifUpscalePrefix;

    /**
     * @var callable|null
     */
    protected $mediaDimension;

    /**
     * @param bool $iiifUpscalePrefix Prefix the sizes with "^", as the Image
     * API 3 requires to allow a size larger than the image; the API 2 does not
     * know the prefix. Only used when the dimensions of the image are unknown.
     * @param callable|null $mediaDimension Get the width and the height of a
     * media, as an array, or null when unknown.
     */
    public function __construct(
        ?callable $iiifMediaUrl,
        ?callable $isIiifMedia = null,
        bool $iiifUpscalePrefix = false,
        ?callable $mediaDimension = null
    ) {
        $this->iiifMediaUrl = $iiifMediaUrl;
        $this->isIiifMedia = $isIiifMedia;
        $this->iiifUpscalePrefix = $iiifUpscalePrefix;
        $this->mediaDimension = $mediaDimension;
    }

    /**
     * Get the urls of the images of 1024 and 4096 pixels of a media.
     *
     * The resource is a media or any resource sharing its file api (a digital
     * object of the module DigitalObject): only the common file methods are
     * used, so no class of an optional module is referenced.
     *
     * @return array With keys "detail" and "big".
     */
    public function urls(AbstractResourceEntityRepresentation $media, string $imageSource): array
    {
        if ($imageSource !== 'omeka' && $this->iiifMediaUrl && $this->isImage($media)) {
            $base = ($this->iiifMediaUrl)($media);
            if ($base) {
                $largest = $this->largestDimension($media);
                return [
                    'detail' => $base . '/full/' . $this->iiifSize(1024, $largest) . '/0/default.jpg',
                    'big' => $base . '/full/' . $this->iiifSize(4096, $largest) . '/0/default.jpg',
                ];
            }
        }
        $large = (string) $media->thumbnailUrl('large');
        // A media without original file, or whose original is not an image
        // (a pdf, a video with a poster), has no usable url of big image.
        $hasImage = $media->hasOriginal() && str_starts_with((string) $media->mediaType(), 'image/');
        return [
            'detail' => $large,
            'big' => $hasImage ? (string) $media->originalUrl() : $large,
        ];
    }

    /**
     * Get the size parameter of an image fitting a square, without upscaling.
     *
     * A server of the Image API 3 refuses a size larger than the image, or
     * requires the prefix "^" to allow it, and some servers forbid any upscale:
     * when the image is known to be smaller than the square, its full size is
     * requested instead. When its dimensions are unknown, the prefix is the
     * only way to get the image whatever its size.
     */
    protected function iiifSize(int $square, ?int $largest): string
    {
        if ($largest !== null) {
            return $largest <= $square ? 'max' : sprintf('!%1$d,%1$d', $square);
        }
        return ($this->iiifUpscalePrefix ? '^' : '') . sprintf('!%1$d,%1$d', $square);
    }

    protected function largestDimension(AbstractResourceEntityRepresentation $media): ?int
    {
        if (!$this->mediaDimension) {
            return null;
        }
        $dimensions = ($this->mediaDimension)($media);
        $width = (int) ($dimensions['width'] ?? 0);
        $height = (int) ($dimensions['height'] ?? 0);
        return $width > 0 && $height > 0 ? max($width, $height) : null;
    }

    /**
     * Check if a media can be served by an image server.
     *
     * A local image (media type "image/*") is servable by the internal or an
     * external image server. A remote iiif image, whose original file is not
     * necessarily an image, is servable too when the helper isIiifMedia is
     * available (see \IiifServer\View\Helper\IiifManifest2).
     */
    protected function isImage(AbstractResourceEntityRepresentation $media): bool
    {
        if (str_starts_with((string) $media->mediaType(), 'image/')) {
            return true;
        }
        return $this->isIiifMedia && ($this->isIiifMedia)($media, 'image');
    }
}
