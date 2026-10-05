<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Common\Stdlib\EasyMeta;
use Laminas\I18n\Translator\TranslatorInterface;

class ConfigBuilder
{
    protected EasyMeta $easyMeta;

    protected TranslatorInterface $translator;

    public function __construct(EasyMeta $easyMeta, TranslatorInterface $translator)
    {
        $this->easyMeta = $easyMeta;
        $this->translator = $translator;
    }

    /**
     * Build the config of the viewer from the options of a block.
     */
    public function build(array $options, string $title, string $cacheUrl): array
    {
        $style = [];
        foreach ($options as $key => $value) {
            if (str_starts_with((string) $key, 'style_')) {
                $style[substr($key, 6)] = (string) $value;
            }
        }
        $style['textShadow'] = '1px 1px 0px ' . ($style['canvasBackground'] ?? '#ffffff');

        $structure = [];
        foreach ((array) ($options['properties_detail'] ?? []) as $term) {
            $structure[] = [
                'name' => $this->translator->translate((string) $this->easyMeta->propertyLabel($term)),
                'source' => DataBuilder::column($term),
                'display' => 'column',
                'type' => 'text',
            ];
        }
        if (!empty($options['link_to_item'])) {
            $structure[] = [
                'name' => $this->translator->translate('View the record'), // @translate
                'source' => '_url',
                'display' => 'column',
                'type' => 'link',
            ];
        }

        return [
            'project' => [
                'name' => trim((string) ($options['title'] ?? '')) ?: $title,
                'quality' => 1,
            ],
            'loader' => [
                'info' => $cacheUrl . '/info.md',
                'items' => $cacheUrl . '/data.csv',
                'layouts' => [
                    ['title' => 'Time'],
                ],
                'textures' => [
                    'medium' => ['size' => 256, 'url' => $cacheUrl . '/sprites/sprites.json'],
                    'detail' => ['size' => 1024, 'csv' => '_detail'],
                    'big' => ['size' => 4096, 'csv' => '_big'],
                ],
            ],
            'style' => $style,
            'projection' => [
                'columns' => (int) ($options['columns'] ?? 6) ?: 6,
            ],
            'detail' => [
                'structure' => $structure,
            ],
        ];
    }
}
