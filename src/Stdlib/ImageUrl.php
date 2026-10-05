<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Omeka\Api\Representation\MediaRepresentation;

class ImageUrl
{
    /**
     * @var callable|null
     */
    protected $iiifMediaUrl;

    public function __construct(?callable $iiifMediaUrl)
    {
        $this->iiifMediaUrl = $iiifMediaUrl;
    }

    /**
     * Get the urls of the images of 1024 and 4096 pixels of a media.
     *
     * @return array With keys "detail" and "big".
     */
    public function urls(MediaRepresentation $media, string $imageSource): array
    {
        if ($imageSource !== 'omeka' && $this->iiifMediaUrl) {
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
}
