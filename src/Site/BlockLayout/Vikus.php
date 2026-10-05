<?php declare(strict_types=1);

namespace Vikus\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;
use Omeka\Stdlib\ErrorStore;
use Vikus\Form\VikusFieldset;
use Vikus\Stdlib\CacheStorage;
use Vikus\Stdlib\OptionsNormalizer;

class Vikus extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    const PARTIAL_NAME = 'common/block-layout/vikus';

    /**
     * @var array
     */
    protected $defaults;

    public function __construct(array $defaults)
    {
        $this->defaults = $defaults;
    }

    public function getLabel()
    {
        return 'Vikus Viewer'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $block->setData(OptionsNormalizer::normalize($block->getData() ?? [], $this->defaults));
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $services = $site->getServiceLocator();
        $config = $services->get('Config')['vikus'];
        $defaults = $this->defaults;
        $data = $block ? ($block->data() ?? []) + $defaults : $defaults;

        $query = $data['query'];
        if (!is_array($query)) {
            $parsed = [];
            parse_str(ltrim((string) $query, "? \t\n\r\0\x0B"), $parsed);
            $query = $parsed;
        }
        // The pool is the query of the block completed by the site, as the
        // builder does. The items without image are not filtered here: the
        // image is resolved only during the generation.
        $query['site_id'] = $site->id();
        $itemCount = (int) $services->get('ControllerPluginManager')->get('api')
            ->search('items', ['limit' => 0] + $query)->getTotalResults();

        $data['query'] = is_array($data['query'])
            ? urldecode(http_build_query($data['query'], '', '&', PHP_QUERY_RFC3986))
            : (string) $data['query'];

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }

        $fieldset = $services->get('FormElementManager')->get(VikusFieldset::class);
        $fieldset->populateValues($dataForm);

        return $view->partial('common/block-layout/admin/vikus-form', [
            'fieldset' => $fieldset,
            'query' => $query,
            'itemCount' => $itemCount,
            'thresholds' => $config['thresholds'],
            'maxItems' => (int) $services->get('Omeka\\Settings')->get('vikus_max_items', 10000),
        ]);
    }

    /**
     * Get the level of the warning about the size of a pool of items.
     *
     * @return string "" (no warning), "warn" or "max_recommended".
     */
    public static function warningLevel(int $itemCount, array $thresholds): string
    {
        if ($itemCount > (int) ($thresholds['max_recommended'] ?? 5000)) {
            return 'max_recommended';
        }
        return $itemCount > (int) ($thresholds['warn'] ?? 2000) ? 'warn' : '';
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = self::PARTIAL_NAME)
    {
        return $view->partial($templateViewScript, [
            'block' => $block,
            'site' => $block->page()->site(),
            'key' => CacheStorage::blockKey($block->id()),
            'height' => $block->dataValue('height', '80vh'),
        ]);
    }
}
