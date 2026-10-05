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
        $defaults = $this->defaults;
        $data = $block ? ($block->data() ?? []) + $defaults : $defaults;
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
        ]);
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
