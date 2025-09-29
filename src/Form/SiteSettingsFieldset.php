<?php

declare(strict_types=1);

namespace ZipDownload\Form;

use Laminas\Form\Element\Text;
use Laminas\Form\Fieldset;

/**
 * Site settings fieldset for ZipDownload.
 */
class SiteSettingsFieldset extends Fieldset {

  /**
   * Build site settings fields.
   */
  public function init(): void {
    $this->setAttribute('id', 'zipdownload-site-settings');

    $this->add([
      'name' => 'zipdownload_download_panel_title',
      'type' => Text::class,
      'options' => [
        'label' => 'Download panel title',
      ],
      'attributes' => [
        'id' => 'zipdownload_download_panel_title',
        'placeholder' => 'Download',
      ],
    ]);

    $this->add([
      'name' => 'zipdownload_download_terms_url',
      'type' => Text::class,
      'options' => [
        'label' => 'Terms link URL (optional)',
      ],
      'attributes' => [
        'id' => 'zipdownload_download_terms_url',
        'placeholder' => 'https://example.org/terms',
      ],
    ]);

    $this->add([
      'name' => 'zipdownload_download_terms_label',
      'type' => Text::class,
      'options' => [
        'label' => 'Terms link label',
      ],
      'attributes' => [
        'id' => 'zipdownload_download_terms_label',
        'placeholder' => 'Terms of use / 利用条件',
      ],
    ]);

    $this->add([
      'name' => 'zipdownload_export_block_title',
      'type' => Text::class,
      'options' => [
        'label' => 'Export block title',
      ],
      'attributes' => [
        'id' => 'zipdownload_export_block_title',
        'placeholder' => 'Export',
      ],
    ]);

    // Export icons (IIIF / JSON-LD)
    $this->add([
      'name' => 'zipdownload_export_icon_iiif_url',
      'type' => Text::class,
      'options' => [
        'label' => 'IIIF icon URL',
      ],
      'attributes' => [
        'id' => 'zipdownload_export_icon_iiif_url',
        'placeholder' => 'https://avatars3.githubusercontent.com/u/5812589?v=3&s=48',
      ],
    ]);

    $this->add([
      'name' => 'zipdownload_export_icon_jsonld_url',
      'type' => Text::class,
      'options' => [
        'label' => 'JSON-LD icon URL',
      ],
      'attributes' => [
        'id' => 'zipdownload_export_icon_jsonld_url',
        'placeholder' => 'https://json-ld.org/images/json-ld-logo-64.png',
      ],
    ]);

    // Manifest property term to override IIIF manifest URL.
    // Example: dcterms:source.
    $this->add([
      'name' => 'zipdownload_export_manifest_property',
      'type' => Text::class,
      'options' => [
        'label' => 'Manifest property term',
      ],
      'attributes' => [
        'id' => 'zipdownload_export_manifest_property',
        'placeholder' => 'e.g. dcterms:source',
      ],
    ]);
  }

}
