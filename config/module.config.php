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
    'block_layouts' => [
        'factories' => [
            'vikus' => Service\BlockLayout\VikusFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\VikusFieldset::class => Form\VikusFieldset::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'vikus' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/vikus/:block-id[/:action]',
                            'constraints' => [
                                'block-id' => '\d+',
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
