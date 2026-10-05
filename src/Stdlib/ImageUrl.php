<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Omeka\Api\Representation\MediaRepresentation;

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

    public function __construct(?callable $iiifMediaUrl, ?callable $isIiifMedia = null)
    {
        $this->iiifMediaUrl = $iiifMediaUrl;
        $this->isIiifMedia = $isIiifMedia;
    }

    /**
     * Get the urls of the images of 1024 and 4096 pixels of a media.
     *
     * @return array With keys "detail" and "big".
     */
    public function urls(MediaRepresentation $media, string $imageSource): array
    {
        if ($imageSource !== 'omeka' && $this->iiifMediaUrl && $this->isImage($media)) {
            $base = ($this->iiifMediaUrl)($media);
            if ($base) {
                return [
                    'detail' => $base . '/full/!1024,1024/0/default.jpg',
                    'big' => $base . '/full/!4096,4096/0/default.jpg',
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
     * Check if a media can be served by an image server.
     *
     * A local image (media type "image/*") is servable by the internal or an
     * external image server. A remote iiif image, whose original file is not
     * necessarily an image, is servable too when the helper isIiifMedia is
     * available (see \IiifServer\View\Helper\IiifManifest2).
     */
    protected function isImage(MediaRepresentation $media): bool
    {
        if (str_starts_with((string) $media->mediaType(), 'image/')) {
            return true;
        }
        return $this->isIiifMedia && ($this->isIiifMedia)($media, 'image');
    }
}
