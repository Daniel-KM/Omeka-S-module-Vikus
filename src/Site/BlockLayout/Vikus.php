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

class Vikus extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    const PARTIAL_NAME = 'common/block-layout/vikus';

    public function getLabel()
    {
        return 'Vikus Viewer'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData() ?? [];

        if (empty($data['query'])) {
            $data['query'] = [];
        } elseif (!is_array($data['query'])) {
            $query = [];
            parse_str(ltrim($data['query'], "? \t\n\r\0\x0B"), $query);
            $data['query'] = $query;
        }

        foreach (['property_keywords', 'properties_detail'] as $key) {
            $data[$key] = array_values(array_filter((array) ($data[$key] ?? [])));
        }
        $data['link_to_item'] = !empty($data['link_to_item']);
        $data['columns'] = max(1, (int) ($data['columns'] ?? 6));
        $data['height'] = preg_match('~^\d+(\.\d+)?(px|vh|em|rem|%)$~', trim((string) ($data['height'] ?? '')))
            ? trim($data['height'])
            : '80vh';

        $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $services = $site->getServiceLocator();
        $defaults = $services->get('Config')['vikus']['block_settings']['vikus'];
        $data = $block ? ($block->data() ?? []) + $defaults : $defaults;
        $data['query'] = is_array($data['query'])
            ? urldecode(http_build_query($data['query'], '', '&', PHP_QUERY_RFC3986))
            : (string) $data['query'];

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }

        $fieldset = $services->get('FormElementManager')->get(\Vikus\Form\VikusFieldset::class);
        $fieldset->populateValues($dataForm);

        return $view->partial('common/block-layout/admin/vikus-form', [
            'fieldset' => $fieldset,
        ]);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = self::PARTIAL_NAME)
    {
        return $view->partial($templateViewScript, [
            'block' => $block,
        ]);
    }
}
