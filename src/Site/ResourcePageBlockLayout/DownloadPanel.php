<?php

declare(strict_types=1);

namespace ZipDownload\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

/**
 * Resource page block: Download panel for media ZIP/individual downloads.
 */
class DownloadPanel implements ResourcePageBlockLayoutInterface {

  /**
   * {@inheritDoc}
   */
  public function getLabel(): string {
    // @translate
    return 'Download panel';
  }

  /**
   * {@inheritDoc}
   */
  public function getCompatibleResourceNames(): array {
    return [
      'items',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string {
    return $view->partial('common/resource-page-blocks/download-panel', [
      'resource' => $resource,
    ]);
  }

}
