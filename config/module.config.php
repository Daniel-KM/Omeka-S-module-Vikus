<?php declare(strict_types=1);

namespace Vikus;

return [
    'service_manager' => [
        'factories' => [
            'Vikus\CacheStorage' => Service\Stdlib\CacheStorageFactory::class,
            'Vikus\ImageUrl' => Service\Stdlib\ImageUrlFactory::class,
            'Vikus\DataBuilder' => Service\Stdlib\DataBuilderFactory::class,
            'Vikus\ConfigBuilder' => Service\Stdlib\ConfigBuilderFactory::class,
            'Vikus\Generator' => Service\Stdlib\GeneratorFactory::class,
        ],
        'invokables' => [
            'Vikus\SpriteBuilder' => Stdlib\SpriteBuilder::class,
        ],
        // The sprite builder keeps the state of the packing of the atlases, so
        // a new one is required for each generation.
        'shared' => [
            'Vikus\SpriteBuilder' => false,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Vikus\Controller\Player' => Service\Controller\PlayerControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'vikus' => Service\ViewHelper\VikusFactory::class,
        ],
    ],
    'resource_page_block_layouts' => [
        'invokables' => [
            'vikus' => Site\ResourcePageBlockLayout\Vikus::class,
        ],
    ],
    'block_layouts' => [
        'factories' => [
            'vikus' => Service\BlockLayout\VikusFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\VikusFieldset::class => Form\VikusFieldset::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\SiteSettingsFieldset::class => Form\SiteSettingsFieldset::class,
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'vikus' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/vikus/:key[/:action]',
                            'constraints' => [
                                'key' => 'b\d+|s\d+-r\d+',
                                'action' => 'play|config|generate',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Vikus\Controller',
                                'controller' => 'Player',
                                'action' => 'play',
                            ],
                        ],
                    ],
                ],
            ],
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
        'site_settings' => [
            'vikus_property_year' => 'dcterms:date',
            'vikus_property_keywords' => ['dcterms:subject'],
            'vikus_filter_type' => 'tags',
            'vikus_properties_detail' => [
                'dcterms:title',
                'dcterms:creator',
                'dcterms:date',
                'dcterms:description',
            ],
            'vikus_properties_group' => [],
            'vikus_image_source' => 'auto',
            'vikus_link_to_item' => true,
            'vikus_columns' => 6,
            'vikus_height' => '80vh',
            'vikus_style_fontColor' => '#2d3b43',
            'vikus_style_fontColorActive' => '#eae2cb',
            'vikus_style_fontBackground' => '#295b71',
            'vikus_style_canvasBackground' => '#eae2cb',
            'vikus_style_timelineBackground' => '#fff9eb',
            'vikus_style_timelineFontColor' => '#000000',
            'vikus_style_backgroundHeader' => '#035151',
            'vikus_style_fontColorHeader' => '#f8774f',
            'vikus_style_detailBackground' => '#eeeeee',
            'vikus_style_infoBackground' => '#33444c',
            'vikus_style_infoFontColor' => '#ffffff',
            'vikus_style_searchbarBackground' => '#295b71',
        ],
        'block_settings' => [
            'vikus' => [
                'query' => [],
                'title' => '',
                'info' => '',
                'timeline_events' => [],
                'property_year' => 'dcterms:date',
                'property_keywords' => ['dcterms:subject'],
                'filter_type' => 'tags',
                'properties_detail' => [
                    'dcterms:title',
                    'dcterms:creator',
                    'dcterms:date',
                    'dcterms:description',
                ],
                'properties_group' => [],
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
