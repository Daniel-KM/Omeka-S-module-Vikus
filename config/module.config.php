<?php declare(strict_types=1);

namespace Vikus;

return [
    'service_manager' => [
        'factories' => [
            'Vikus\CacheStorage' => Service\Stdlib\CacheStorageFactory::class,
            'Vikus\ImageUrl' => Service\Stdlib\ImageUrlFactory::class,
            'Vikus\DataBuilder' => Service\Stdlib\DataBuilderFactory::class,
            'Vikus\ConfigBuilder' => Service\Stdlib\ConfigBuilderFactory::class,
        ],
        'invokables' => [
            'Vikus\SpriteBuilder' => Stdlib\SpriteBuilder::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'vikus' => [
        'settings' => [
            'vikus_max_items' => 10000,
        ],
        'block_settings' => [
            'vikus' => [
                'query' => [],
                'title' => '',
                'info' => '',
                'property_year' => 'dcterms:date',
                'property_keywords' => ['dcterms:subject'],
                'properties_detail' => [
                    'dcterms:title',
                    'dcterms:creator',
                    'dcterms:date',
                    'dcterms:description',
                ],
                'image_source' => 'auto',
                'link_to_item' => true,
                'columns' => 6,
                'height' => '80vh',
                'style_fontColor' => '#2d3b43',
                'style_fontColorActive' => '#eae2cb',
                'style_fontBackground' => '#295b71',
                'style_canvasBackground' => '#eae2cb',
                'style_timelineBackground' => '#fff9eb',
                'style_timelineFontColor' => '#000000',
                'style_backgroundHeader' => '#035151',
                'style_fontColorHeader' => '#f8774f',
                'style_detailBackground' => '#eeeeee',
                'style_infoBackground' => '#33444c',
                'style_infoFontColor' => '#ffffff',
                'style_searchbarBackground' => '#295b71',
            ],
        ],
    ],
];
