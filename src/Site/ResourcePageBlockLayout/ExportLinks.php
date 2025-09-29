<?php

declare(strict_types=1);

namespace ZipDownload\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

/**
 * Resource page block: Export links (IIIF Manifest, JSON-LD, etc.).
 */
class ExportLinks implements ResourcePageBlockLayoutInterface {

  /**
   * {@inheritDoc}
   */
  public function getLabel(): string {
    // @translate
    return 'Export links';
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
    return $view->partial('common/resource-page-blocks/export-links', [
      'resource' => $resource,
    ]);
  }

}
