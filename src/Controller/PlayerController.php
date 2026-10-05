<?php declare(strict_types=1);

namespace Vikus\Controller;

use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Mvc\Exception\NotFoundException;
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
        $this->getBlock();
        $html = @file_get_contents($this->viewerPath . '/index.html');
        if ($html === false) {
            throw new NotFoundException;
        }

        $assetUrl = $this->viewHelpers()->get('assetUrl');
        $base = dirname($assetUrl('vendor/vikus-viewer/index.html', 'Vikus', false, false, true)) . '/';
        $html = strtr($html, [
            '<base target="_blank" />' => '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '" target="_blank" />',
        ]);

        return $this->getResponse()
            ->setContent($html);
    }

    public function configAction()
    {
        $block = $this->getBlock();
        return new JsonModel($this->configBuilder->build(
            $block->data() + $this->blockDefaults,
            $block->page()->title(),
            $this->cacheStorage->url($block->id())
        ));
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
        if (!$block
            || $block->getLayout() !== 'vikus'
            || $block->getPage()->getSite()->getSlug() !== $this->params('site-slug')
        ) {
            throw new NotFoundException;
        }

        $page = $this->api()->read('site_pages', $block->getPage()->getId())->getContent();
        foreach ($page->blocks() as $pageBlock) {
            if ($pageBlock->id() === $blockId) {
                return $pageBlock;
            }
        }

        throw new NotFoundException;
    }
}
