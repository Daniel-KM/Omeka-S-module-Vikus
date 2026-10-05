<?php declare(strict_types=1);

namespace Vikus\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\SitePageBlockRepresentation;
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
     * Render the viewer of a block, or the status of its cache.
     */
    public function __invoke(SitePageBlockRepresentation $block): string
    {
        $view = $this->getView();
        $meta = $this->cacheStorage->readMeta(CacheStorage::blockKey($block->id()));

        $status = !$meta ? 'pending' : (empty($meta['total']) ? 'empty' : 'ready');
        $vars = [
            'block' => $block,
            'status' => $status,
            'height' => $block->dataValue('height', '80vh'),
            'url' => null,
        ];

        if ($status === 'ready') {
            $params = ['site-slug' => $block->page()->site()->slug(), 'block-id' => $block->id()];
            // The server url is prepended apart to be compatible with the
            // module Clean Url.
            $configUrl = $view->serverUrl($view->url('site/vikus', $params + ['action' => 'config']));
            $vars['url'] = $view->url('site/vikus', $params, ['query' => ['config' => $configUrl]]);
            $view->headLink()->appendStylesheet($view->assetUrl('css/vikus.css', 'Vikus'));
        }

        return $view->partial(self::PARTIAL_NAME, $vars);
    }
}
