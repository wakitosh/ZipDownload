<?php

/**
 * @file
 * ZipDownload module routing and controller factories configuration.
 */

declare(strict_types=1);

namespace ZipDownload;

use Laminas\Router\Http\Segment;
use ZipDownload\Controller\ZipController;

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
    ],
  ],
];
