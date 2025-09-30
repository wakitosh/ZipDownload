<?php

declare(strict_types=1);

namespace ZipDownload;

use Omeka\Module\AbstractModule;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Form\SiteSettingsForm;
use ZipDownload\Controller\ZipController;
use ZipDownload\Controller\LogsController;
use ZipDownload\Form\ConfigForm;
use ZipDownload\Form\SiteSettingsFieldset;

/**
 * Minimal module bootstrap for ZipDownload.
 */
class Module extends AbstractModule {

  /**
   * Return module configuration.
   *
   * @return array
   *   Module configuration array.
   */
  public function getConfig(): array {
    // Return the module configuration array.
    return include __DIR__ . '/config/module.config.php';
  }

  /**
   * PSR-4 style autoloader configuration.
   *
   * @return array
   *   Autoloader configuration.
   */
  public function getAutoloaderConfig(): array {
    // Configure PSR-4 autoloading for this module's namespace.
    return [
      'Laminas\\Loader\\StandardAutoloader' => [
        'namespaces' => [
          __NAMESPACE__ => __DIR__ . '/src',
        ],
      ],
    ];
  }

  /**
   * Allow public access to the ZIP controller.
   */
  public function onBootstrap(MvcEvent $event): void {
    parent::onBootstrap($event);
    $services = $event->getApplication()->getServiceManager();
    // Ensure logs table exists even if the module was updated
    // without running install().
    try {
      if ($services->has('Omeka\\Connection')) {
        $conn = $services->get('Omeka\\Connection');
        $sql = $this->getLogTableDdl();
        // Safe on MySQL/MariaDB; cheap when already exists.
        $conn->executeStatement($sql);
      }
    }
    catch (\Throwable $e) {
      // Do not block bootstrap if DDL fails; logs UI will still show an error.
    }
    $acl = $services->get('Omeka\\Acl');
    $acl->allow(NULL, [ZipController::class]);
    // Admin-only access to logs UI.
    $acl->allow(['global_admin', 'site_admin'], [LogsController::class]);
    // Temporary debug: log route matching info for zip-download requests.
    // This helps diagnose why the site child route under /s/:site-slug is not
    // being matched at runtime. Remove or lower verbosity after debugging.
    $logger = NULL;
    if ($services->has('Omeka\\Logger')) {
      $logger = $services->get('Omeka\\Logger');
    }
    $event->getApplication()->getEventManager()->attach(MvcEvent::EVENT_ROUTE, function (MvcEvent $ev) use ($logger) {
      try {
        $match = $ev->getRouteMatch();
        if (!$match) {
          if ($logger) {
            $request = $ev->getRequest();
            $uri = method_exists($request, 'getUri') ? (string) $request->getUri() : '';
            $logger->info('ZipDownload route debug: no route match for request ' . $uri);
          }
          return;
        }
        $name = (string) $match->getMatchedRouteName();
        // Only log when zip-download appears in the route name or in the path.
        $request = $ev->getRequest();
        $path = method_exists($request, 'getUri') ? (string) $request->getUri() : '';
        if (strpos($name, 'zip-download') !== FALSE || strpos($path, '/zip-download') !== FALSE) {
          $params = $match->getParams();
          if ($logger) {
            $logger->info('ZipDownload route debug: matched=' . $name . ' params=' . json_encode($params));
          }
          else {
            error_log('ZipDownload route debug: matched=' . $name . ' params=' . json_encode($params));
          }
        }

      }
      catch (\Throwable $e) {
        if ($logger) {
          $logger->err('ZipDownload route debug error: ' . $e->getMessage());
        }
        else {
          error_log('ZipDownload route debug error: ' . $e->getMessage());
        }
      }
    }, 100);
  }

  /**
   * Attach listeners to extend Site settings form with ZipDownload fields.
   */
  public function attachListeners(SharedEventManagerInterface $sharedEventManager): void {
    $sharedEventManager->attach(
      SiteSettingsForm::class,
      'form.add_elements',
      function ($event) {
        $form = $event->getTarget();
        $services = $this->getServiceLocator();
        $fieldset = $services->get('FormElementManager')->get(SiteSettingsFieldset::class);
        // v4 互換: フィールドセットごとではなく、要素をフラットに追加し、要素グループで見出しを出します.
        $groups = $form->getOption('element_groups') ?: [];
        // @translate
        $groups['zipdownload'] = 'ZipDownload';
        $form->setOption('element_groups', $groups);

        foreach ($fieldset->getElements() as $el) {
            $opts = $el->getOptions() ?: [];
            $opts['element_group'] = 'zipdownload';
            $el->setOptions($opts);
            $form->add($el);
        }
        // Prefill with existing site settings (v4 flat structure).
        try {
          $siteSettings = $services->get('Omeka\Settings\Site');
          $form->populateValues([
            'zipdownload_download_panel_title' => (string) ($siteSettings->get('zipdownload_download_panel_title') ?? ''),
            'zipdownload_download_panel_title_ja' => (string) ($siteSettings->get('zipdownload_download_panel_title_ja') ?? ''),
            'zipdownload_download_panel_title_en' => (string) ($siteSettings->get('zipdownload_download_panel_title_en') ?? ''),
            'zipdownload_download_terms_url' => (string) ($siteSettings->get('zipdownload_download_terms_url') ?? ''),
            'zipdownload_download_terms_label' => (string) ($siteSettings->get('zipdownload_download_terms_label') ?? ''),
            'zipdownload_export_block_title' => (string) ($siteSettings->get('zipdownload_export_block_title') ?? ''),
            'zipdownload_export_block_title_ja' => (string) ($siteSettings->get('zipdownload_export_block_title_ja') ?? ''),
            'zipdownload_export_block_title_en' => (string) ($siteSettings->get('zipdownload_export_block_title_en') ?? ''),
            'zipdownload_export_icon_iiif_url' => (string) ($siteSettings->get('zipdownload_export_icon_iiif_url') ?? ''),
            'zipdownload_export_icon_jsonld_url' => (string) ($siteSettings->get('zipdownload_export_icon_jsonld_url') ?? ''),
            'zipdownload_export_manifest_property' => (string) ($siteSettings->get('zipdownload_export_manifest_property') ?? ''),
          ]);
        }
        catch (\Throwable $e) {
          // Ignore if cannot prefill (values will just be empty).
        }
      }
    );

    // 入力フィルタを追加 (保存可能にする).
    $sharedEventManager->attach(
      SiteSettingsForm::class,
      'form.add_input_filters',
      function ($event) {
        $inputFilter = $event->getParam('inputFilter');
        foreach ([
          'zipdownload_download_panel_title',
          'zipdownload_download_panel_title_ja',
          'zipdownload_download_panel_title_en',
          'zipdownload_download_terms_url',
          'zipdownload_download_terms_label',
          'zipdownload_export_block_title',
          'zipdownload_export_block_title_ja',
          'zipdownload_export_block_title_en',
          'zipdownload_export_icon_iiif_url',
          'zipdownload_export_icon_jsonld_url',
          'zipdownload_export_manifest_property',
        ] as $name) {
          $inputFilter->add([
            'name' => $name,
            'required' => FALSE,
            'allow_empty' => TRUE,
            'filters' => [
              ['name' => 'StringTrim'],
            ],
          ]);
        }
      }
    );
  }

  /**
   * Install DB artifacts (logs table).
   */
  public function install($services): void {
    $conn = $services->get('Omeka\\Connection');
    $conn->executeStatement($this->getLogTableDdl());
  }

  /**
   * Render module configuration form.
   */
  public function getConfigForm(PhpRenderer $renderer) {
    // Use the module's service locator to avoid deprecated plugin manager APIs.
    $services = $this->getServiceLocator();
    $settings = $services->get('Omeka\\Settings');
    $form = $services->get('FormElementManager')->get(ConfigForm::class);

    // Populate defaults from settings or fall back to controller constants.
    $form->setData([
      'max_concurrent_downloads_global' => (int) ($settings->get('zipdownload.max_concurrent_downloads_global') ?? 1),
      'max_bytes_per_download' => (string) ($settings->get('zipdownload.max_bytes_per_download') ?? 3221225472),
      'max_total_active_bytes' => (string) ($settings->get('zipdownload.max_total_active_bytes') ?? 6442450944),
      'max_files_per_download' => (int) ($settings->get('zipdownload.max_files_per_download') ?? 1000),
      'progress_token_ttl' => (int) ($settings->get('zipdownload.progress_token_ttl') ?? 7200),
    // Removed: terms link settings moved to theme.
    ]);

    $form->prepare();
    return $renderer->formCollection($form);
  }

  /**
   * Handle saving of the configuration form.
   */
  public function handleConfigForm(AbstractController $controller) {
    $services = $controller->getEvent()->getApplication()->getServiceManager();
    $settings = $services->get('Omeka\\Settings');
    $post = $controller->params()->fromPost();

    $maxConcurrent = max(0, (int) ($post['max_concurrent_downloads_global'] ?? 1));
    $maxBytes = max(0, $this->parseSizeToBytes($post['max_bytes_per_download'] ?? '3221225472'));
    $maxTotal = max(0, $this->parseSizeToBytes($post['max_total_active_bytes'] ?? '6442450944'));
    $maxFiles = max(1, (int) ($post['max_files_per_download'] ?? 1000));
    $ttl = max(60, (int) ($post['progress_token_ttl'] ?? 7200));

    $settings->set('zipdownload.max_concurrent_downloads_global', $maxConcurrent);
    $settings->set('zipdownload.max_bytes_per_download', $maxBytes);
    $settings->set('zipdownload.max_total_active_bytes', $maxTotal);
    $settings->set('zipdownload.max_files_per_download', $maxFiles);
    $settings->set('zipdownload.progress_token_ttl', $ttl);
    // Terms settings removed (theme-only now).
    $controller->messenger()->addSuccess('ZipDownload settings were saved.');
    return TRUE;
  }

  /**
   * Parse human-friendly size like 512M or 1G to bytes.
   *
   * Supports suffixes: K, M, G, T (case-insensitive).
   */
  private function parseSizeToBytes($value): int {
    if ($value === NULL) {
      return 0;
    }
    if (is_int($value)) {
      return max(0, $value);
    }
    $str = trim((string) $value);
    if ($str === '') {
      return 0;
    }
    // Match optional decimal part and suffix like k, kb, m, mb, g, gb, t, tb.
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt]b?|)$/i', $str, $m)) {
      // Fallback: strip non-digits and cast.
      $digits = preg_replace('/[^0-9]/', '', $str);
      return (int) ($digits === '' ? 0 : $digits);
    }
    $num = (float) $m[1];
    $suffix = strtolower($m[2]);
    switch ($suffix) {
      case 'k':
      case 'kb':
        $factor = 1024;
        break;

      case 'm':
      case 'mb':
        $factor = 1024 ** 2;
        break;

      case 'g':
      case 'gb':
        $factor = 1024 ** 3;
        break;

      case 't':
      case 'tb':
        $factor = 1024 ** 4;
        break;

      default:
        $factor = 1;
    }
    $bytes = (int) floor($num * $factor);
    return $bytes < 0 ? 0 : $bytes;
  }

  /**
   * Get DDL for the zipdownload_log table.
   */
  private function getLogTableDdl(): string {
    return <<<SQL
CREATE TABLE IF NOT EXISTS `zipdownload_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at` INT UNSIGNED NOT NULL,
  `finished_at` INT UNSIGNED DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `item_title` VARCHAR(255) DEFAULT NULL,
  `media_ids` MEDIUMTEXT,
  `media_count` INT UNSIGNED DEFAULT 0,
  `bytes_total` BIGINT UNSIGNED DEFAULT 0,
  `bytes_sent` BIGINT UNSIGNED DEFAULT 0,
  `client_ip` VARCHAR(64) DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `user_email` VARCHAR(190) DEFAULT NULL,
  `site_slug` VARCHAR(190) DEFAULT NULL,
  `progress_token` VARCHAR(190) DEFAULT NULL,
  `error_message` VARCHAR(1024) DEFAULT NULL,
  `slot_index` INT UNSIGNED DEFAULT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_status` (`status`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_client_ip` (`client_ip`),
  KEY `idx_progress_token` (`progress_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
  }

}
