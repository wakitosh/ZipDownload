<?php

/**
 * @file
 * ZipDownload module routing and controller factories configuration.
 */

declare(strict_types=1);

namespace ZipDownload;

use Laminas\Router\Http\Segment;
use ZipDownload\Controller\ZipController;
use Laminas\ServiceManager\Factory\InvokableFactory;
use ZipDownload\Form\ConfigForm;

return [
  'controllers' => [
    'factories' => [
      ZipController::class => function ($container) {
                return new ZipController(
                    $container->get('Omeka\EntityManager'),
                    $container
                );
      },
    ],
  ],
  'form_elements' => [
    'factories' => [
      ConfigForm::class => InvokableFactory::class,
    ],
  ],
  'router' => [
    'routes' => [
      // As a child of site route (for site-context routing stacks)
      'site' => [
        'child_routes' => [
          'zip-download' => [
            'type' => Segment::class,
            'priority' => 10000,
            'options' => [
              'route' => '/zip-download/item/:id',
              'constraints' => ['id' => '\\d+'],
              'defaults' => [
                'controller' => ZipController::class,
                'action' => 'item',
              ],
            ],
            'may_terminate' => TRUE,
          ],
        ],
      ],
      'zip-download' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/zip-download/item/:id',
          'constraints' => ['id' => '\\d+'],
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'item',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      'zip-download-status' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/zip-download/status',
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'status',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      'zip-download-estimate' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/zip-download/estimate',
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'estimate',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      // Direct top-level match for site-scoped URLs. Some Omeka setups do not
      // properly attach module child routes under the application 'site'
      // parent route; provide a direct route to handle requests like
      // /s/:site-slug/zip-download/item/:id as a safe fallback.
      'site-zip-download' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/s/:site-slug/zip-download/item/:id',
          'constraints' => [
            'site-slug' => '[a-zA-Z0-9_-]+',
            'id' => '\\d+',
          ],
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'item',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      'site-zip-download-status' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/s/:site-slug/zip-download/status',
          'constraints' => [
            'site-slug' => '[a-zA-Z0-9_-]+',
          ],
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'status',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      'site-zip-download-estimate' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/s/:site-slug/zip-download/estimate',
          'constraints' => [
            'site-slug' => '[a-zA-Z0-9_-]+',
          ],
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'estimate',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      'zip-download-cancel' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/zip-download/cancel',
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'cancel',
          ],
        ],
        'may_terminate' => TRUE,
      ],
      'site-zip-download-cancel' => [
        'type' => Segment::class,
        'priority' => 10000,
        'options' => [
          'route' => '/s/:site-slug/zip-download/cancel',
          'constraints' => [
            'site-slug' => '[a-zA-Z0-9_-]+',
          ],
          'defaults' => [
            'controller' => ZipController::class,
            'action' => 'cancel',
          ],
        ],
        'may_terminate' => TRUE,
      ],
    ],
  ],
];
