<?php declare(strict_types=1);

namespace Vikus\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\SiteRepresentation;
use Vikus\Stdlib\CacheStorage;

class Vikus extends AbstractHelper
{
    const PARTIAL_NAME = 'common/vikus';

    protected CacheStorage $cacheStorage;

    public function __construct(CacheStorage $cacheStorage)
    {
        $this->cacheStorage = $cacheStorage;
    }

    /**
     * Render the viewer of a cache key, or the status of its cache.
     */
    public function __invoke(SiteRepresentation $site, string $key, string $height = '80vh'): string
    {
        $view = $this->getView();
        $meta = $this->cacheStorage->readMeta($key);

        $status = !$meta ? 'pending' : (empty($meta['total']) ? 'empty' : 'ready');
        $vars = [
            'site' => $site,
            'key' => $key,
            'status' => $status,
            'height' => $height,
            'url' => null,
        ];

        if ($status === 'ready') {
            $params = ['site-slug' => $site->slug(), 'key' => $key];
            // The server url is prepended apart to be compatible with the
            // module Clean Url.
            $configUrl = $view->serverUrl($view->url('site/vikus', $params + ['action' => 'config']));
            $vars['url'] = $view->url('site/vikus', $params, ['query' => ['config' => $configUrl]]);
            $view->headLink()->appendStylesheet($view->assetUrl('css/vikus.css', 'Vikus'));
        }

        return $view->partial(self::PARTIAL_NAME, $vars);
    }
}
