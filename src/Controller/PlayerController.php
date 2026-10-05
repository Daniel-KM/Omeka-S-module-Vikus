<?php declare(strict_types=1);

namespace Vikus\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityNotFoundException;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Exception as ApiException;
use Omeka\Entity\SitePageBlock;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Settings\SiteSettings;
use RuntimeException;
use Vikus\Job\GenerateData;
use Vikus\Stdlib\CacheStorage;
use Vikus\Stdlib\ConfigBuilder;
use Vikus\Stdlib\SpriteBuilder;
use Vikus\Stdlib\OptionsNormalizer;

class PlayerController extends AbstractActionController
{
    protected EntityManager $entityManager;

    protected CacheStorage $cacheStorage;

    protected ConfigBuilder $configBuilder;

    protected SiteSettings $siteSettings;

    protected array $blockDefaults;

    protected array $siteSettingsDefaults;

    protected string $viewerPath;

    public function __construct(
        EntityManager $entityManager,
        CacheStorage $cacheStorage,
        ConfigBuilder $configBuilder,
        SiteSettings $siteSettings,
        array $blockDefaults,
        array $siteSettingsDefaults,
        string $viewerPath
    ) {
        $this->entityManager = $entityManager;
        $this->cacheStorage = $cacheStorage;
        $this->configBuilder = $configBuilder;
        $this->siteSettings = $siteSettings;
        $this->blockDefaults = $blockDefaults;
        $this->siteSettingsDefaults = $siteSettingsDefaults;
        $this->viewerPath = $viewerPath;
    }

    public function playAction()
    {
        $target = $this->getTarget();

        // The viewer reads the url of its config in the query string and
        // falls back on a relative "data/config.json" without it, so a direct
        // access to the page is redirected to the complete url.
        if (!$this->params()->fromQuery('config')) {
            $params = ['key' => $target['key'], 'action' => 'config'];
            $configUrl = $this->viewHelpers()->get('serverUrl')
                ->__invoke($this->url()->fromRoute('site/vikus', $params, [], true));
            return $this->redirect()->toRoute('site/vikus', ['key' => $target['key'], 'action' => 'play'], [
                'query' => ['config' => $configUrl],
            ], true);
        }

        $html = @file_get_contents($this->viewerPath . '/index.html');
        if ($html === false) {
            throw new NotFoundException;
        }

        $assetUrl = $this->viewHelpers()->get('assetUrl');
        $base = dirname($assetUrl('vendor/vikus-viewer/index.html', 'Vikus', false, false, true)) . '/';
        $replacement = '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '" target="_blank">';
        // preg_replace_callback avoids interpreting "$" or "\" in $replacement.
        $html = preg_replace_callback('~<base\b[^>]*>~i', fn () => $replacement, $html, 1, $count);
        if ($count !== 1) {
            // An upstream version of the page without a base tag is supported:
            // the tag is added at the beginning of the head, so the relative
            // css and js of the viewer still resolve.
            $html = preg_replace_callback(
                '~<head\b[^>]*>~i',
                fn (array $matches): string => $matches[0] . $replacement,
                $html,
                1,
                $count
            );
        }
        if ($count !== 1) {
            throw new RuntimeException('Unable to find the base or the head tag of the viewer.');
        }

        // The styles of the module are injected in the page of the viewer,
        // which is the only document of the iframe: the css of the block is
        // loaded by the page of the site, not by the iframe. strtr() is a
        // no-op when "</head>" is missing.
        $link = '<link rel="stylesheet" href="'
            . htmlspecialchars($assetUrl('css/viewer.css', 'Vikus'), ENT_QUOTES) . '">';
        $html = strtr($html, ['</head>' => $link . '</head>']);

        // The script of the module is injected in the page of the viewer, which
        // is the only document of the iframe, and after the scripts of the
        // viewer, that it completes. strtr() is a no-op when "</body>" is
        // missing, so the page is returned as-is instead of breaking: the
        // script and the button are only improvements, not required parts of
        // the page.
        // The hint of the script is translated here: the viewer has no
        // translation mechanism of its own.
        $translate = $this->viewHelpers()->get('translate');
        $hint = $translate('Scroll or pinch to zoom, click an image to open its record.'); // @translate
        $script = '<script src="'
            . htmlspecialchars($assetUrl('js/viewer.js', 'Vikus'), ENT_QUOTES)
            . '" data-vikus-hint="' . htmlspecialchars($hint, ENT_QUOTES) . '"></script>';
        if ($target['allowed']) {
            $script .= $this->regenerateForm();
        }
        $html = strtr($html, ['</body>' => $script . '</body>']);

        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/html; charset=utf-8');
        return $response->setContent($html);
    }

    public function generateAction()
    {
        $target = $this->getTarget();
        if (!$this->getRequest()->isPost()) {
            return $this->getResponse()->setStatusCode(405);
        }
        if (!$target['allowed']) {
            return $this->getResponse()->setStatusCode(403);
        }
        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            return $this->getResponse()->setStatusCode(403);
        }
        $this->jobDispatcher()->dispatch(GenerateData::class, ['key' => $target['key']]);
        return $this->redirect()->toUrl($target['url']);
    }

    public function configAction()
    {
        $target = $this->getTarget();
        $meta = $this->cacheStorage->readMeta($target['key']);
        return new JsonModel($this->configBuilder->build(
            $target['options'],
            $target['title'],
            $this->cacheStorage->url($target['key']),
            !empty($meta['map']),
            (int) ($meta['sprite_size'] ?? SpriteBuilder::SIZE)
        ));
    }

    /**
     * Build the form allowing an admin to regenerate the cache of a viewer.
     *
     * The form targets "_top" because the viewer sets a "<base
     * target="_blank">", which would otherwise open the redirection in a new
     * tab instead of leaving the current one.
     */
    protected function regenerateForm(): string
    {
        $helpers = $this->viewHelpers();
        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $this->url()->fromRoute('site/vikus', ['action' => 'generate'], true));
        $form->setAttribute('target', '_top');
        $form->setAttribute('class', 'vikus-regenerate');
        $form->setAttribute('style', 'position: fixed; bottom: 8px; left: 8px; z-index: 1000;');
        $form->setButtonLabel($helpers->get('translate')('Regenerate')); // @translate
        return $helpers->get('form')($form);
    }

    /**
     * Get the viewer of the route: a block of a page or an item set of a site.
     *
     * @return array Keys "key", "options", "title", "allowed" (right to
     * regenerate) and "url" (page to return to after a regeneration).
     */
    protected function getTarget(): array
    {
        $key = (string) $this->params('key');
        $ids = CacheStorage::parseKey($key);
        if (!$ids) {
            throw new NotFoundException;
        }
        return isset($ids['block_id'])
            ? $this->blockTarget($key, $ids['block_id'])
            : $this->itemSetTarget($key, $ids['site_id'], $ids['item_set_id']);
    }

    /**
     * Get the viewer of a vikus block of the current site.
     *
     * The page is read via the api to check the rights of the user.
     */
    protected function blockTarget(string $key, int $blockId): array
    {
        $block = $this->entityManager->find(SitePageBlock::class, $blockId);
        // The page is a lazy proxy and the visibility filter of doctrine hides
        // the private ones from a visitor, so loading it throws instead of
        // returning null: this is a missing page for the current user.
        try {
            if (!$block
                || $block->getLayout() !== 'vikus'
                || $block->getPage()->getSite()->getSlug() !== $this->params('site-slug')
            ) {
                throw new NotFoundException;
            }
        } catch (EntityNotFoundException $e) {
            throw new NotFoundException;
        }

        try {
            $page = $this->api()->read('site_pages', $block->getPage()->getId())->getContent();
        } catch (ApiException\ExceptionInterface) {
            throw new NotFoundException;
        }
        foreach ($page->blocks() as $pageBlock) {
            if ($pageBlock->id() === $blockId) {
                return [
                    'key' => $key,
                    'options' => $pageBlock->data() + $this->blockDefaults,
                    'title' => $page->title(),
                    'allowed' => $page->userIsAllowed('update'),
                    'url' => $page->siteUrl(),
                ];
            }
        }

        throw new NotFoundException;
    }

    /**
     * Get the viewer of an item set attached to the current site.
     */
    protected function itemSetTarget(string $key, int $siteId, int $itemSetId): array
    {
        $site = $this->currentSite();
        if (!$site || $site->id() !== $siteId) {
            throw new NotFoundException;
        }

        try {
            $itemSet = $this->api()->read('item_sets', $itemSetId)->getContent();
        } catch (ApiException\ExceptionInterface) {
            throw new NotFoundException;
        }
        $isInSite = $this->api()
            ->search('item_sets', ['id' => $itemSetId, 'site_id' => $siteId, 'limit' => 0])
            ->getTotalResults();
        if (!$isInSite) {
            throw new NotFoundException;
        }

        $options = OptionsNormalizer::fromSettings($this->siteSettings, $this->siteSettingsDefaults);
        $options['query'] = ['item_set_id' => $itemSetId];

        return [
            'key' => $key,
            'options' => $options,
            'title' => (string) $itemSet->displayTitle(),
            'allowed' => $itemSet->userIsAllowed('update'),
            'url' => $itemSet->siteUrl($site->slug()),
        ];
    }
}
