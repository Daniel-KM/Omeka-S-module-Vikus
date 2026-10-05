<?php declare(strict_types=1);

namespace Vikus\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityNotFoundException;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Exception as ApiException;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception\NotFoundException;
use RuntimeException;
use Vikus\Job\GenerateData;
use Vikus\Stdlib\CacheStorage;
use Vikus\Stdlib\ConfigBuilder;

class PlayerController extends AbstractActionController
{
    protected EntityManager $entityManager;

    protected CacheStorage $cacheStorage;

    protected ConfigBuilder $configBuilder;

    protected array $blockDefaults;

    protected string $viewerPath;

    public function __construct(
        EntityManager $entityManager,
        CacheStorage $cacheStorage,
        ConfigBuilder $configBuilder,
        array $blockDefaults,
        string $viewerPath
    ) {
        $this->entityManager = $entityManager;
        $this->cacheStorage = $cacheStorage;
        $this->configBuilder = $configBuilder;
        $this->blockDefaults = $blockDefaults;
        $this->viewerPath = $viewerPath;
    }

    public function playAction()
    {
        $block = $this->getBlock();
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

        // The script of the module is injected in the page of the viewer,
        // which is the only document of the iframe, and after the scripts of
        // the viewer, that it completes. strtr() is a no-op when "</body>" is
        // missing, so the page is returned as-is instead of breaking: the
        // script and the button are only improvements, not required parts of
        // the page.
        $script = '<script src="'
            . htmlspecialchars($assetUrl('js/viewer.js', 'Vikus'), ENT_QUOTES) . '"></script>';
        if ($block->page()->userIsAllowed('update')) {
            $script .= $this->regenerateForm($block);
        }
        $html = strtr($html, ['</body>' => $script . '</body>']);

        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/html; charset=utf-8');
        return $response->setContent($html);
    }

    public function generateAction()
    {
        $block = $this->getBlock();
        if (!$this->getRequest()->isPost()) {
            return $this->getResponse()->setStatusCode(405);
        }
        if (!$block->page()->userIsAllowed('update')) {
            return $this->getResponse()->setStatusCode(403);
        }
        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            return $this->getResponse()->setStatusCode(403);
        }
        $this->jobDispatcher()->dispatch(GenerateData::class, ['key' => CacheStorage::blockKey($block->id())]);
        return $this->redirect()->toUrl($block->page()->siteUrl());
    }

    public function configAction()
    {
        $block = $this->getBlock();
        return new JsonModel($this->configBuilder->build(
            $block->data() + $this->blockDefaults,
            $block->page()->title(),
            $this->cacheStorage->url(CacheStorage::blockKey($block->id()))
        ));
    }

    /**
     * Build the form allowing an admin to regenerate the cache of a block.
     *
     * The form targets "_top" because the viewer sets a "<base
     * target="_blank">", which would otherwise open the redirection in a new
     * tab instead of leaving the current one.
     */
    protected function regenerateForm(SitePageBlockRepresentation $block): string
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
     * Get the block of the route when it is a vikus one of the current site.
     *
     * The page is read via the api to check the rights of the user.
     */
    protected function getBlock(): SitePageBlockRepresentation
    {
        $blockId = (int) $this->params('block-id');
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
                return $pageBlock;
            }
        }

        throw new NotFoundException;
    }
}
