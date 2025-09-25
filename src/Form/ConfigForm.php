<?php

namespace ZipDownload\Form;

use Laminas\Form\Element\Number;
use Laminas\Form\Element\Text;
use Laminas\Form\Form;

/**
 * Module settings form for ZipDownload.
 */
class ConfigForm extends Form {

  /**
   * Build module configuration form elements.
   */
  public function init(): void {
    $this->add([
      'name' => 'max_concurrent_downloads_global',
      'type' => Number::class,
      'options' => [
        'label' => 'Max concurrent downloads (global)',
      ],
      'attributes' => [
        'min' => 0,
        'step' => 1,
      ],
    ]);

    $this->add([
      'name' => 'max_bytes_per_download',
      'type' => Text::class,
      'options' => [
        'label' => 'Max bytes per download (e.g., 512M, 1G)',
      ],
      'attributes' => [
        'placeholder' => 'e.g. 1G or 1073741824',
      ],
    ]);

    $this->add([
      'name' => 'max_total_active_bytes',
      'type' => Text::class,
      'options' => [
        'label' => 'Max total active bytes (e.g., 1G, 10G)',
      ],
      'attributes' => [
        'placeholder' => 'e.g. 10G or 10737418240',
      ],
    ]);

    $this->add([
      'name' => 'max_files_per_download',
      'type' => Number::class,
      'options' => [
        'label' => 'Max files per download',
      ],
      'attributes' => [
        'min' => 1,
        'step' => 1,
      ],
    ]);

    $this->add([
      'name' => 'progress_token_ttl',
      'type' => Number::class,
      'options' => [
        'label' => 'Progress token TTL (seconds)',
      ],
      'attributes' => [
        'min' => 60,
        'step' => 1,
      ],
    ]);

    // CSRF is provided by Omeka core; no need to add here.
  }

}
