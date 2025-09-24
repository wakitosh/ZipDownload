<?php

declare(strict_types=1);

namespace ZipDownload\Controller;

use ZipStream\Option\Archive;
use ZipStream\ZipStream;

require_once __DIR__ . '/../../vendor/autoload.php';

use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Entity\Media;

/**
 * Build and stream a ZIP archive (IIIF-first).
 */
class ZipController extends AbstractActionController {
  /**
   * Default runtime limits.
   *
   * These values are conservative for the described production environment
   * (MySQL and Cantaloupe are already allocated significant memory).
   */
  private const MAX_CONCURRENT_DOWNLOADS_GLOBAL = 1;
  private const MAX_BYTES_PER_DOWNLOAD = 3221225472;
  private const MAX_TOTAL_ACTIVE_BYTES = 6442450944;
  private const MAX_FILES_PER_DOWNLOAD = 1000;
  private const PROGRESS_TOKEN_TTL = 7200;

  /**
   * Doctrine ORM entity manager.
   *
   * @var \Doctrine\ORM\EntityManager
   */
  private $em;

  /**
   * Application logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * Temporary directory path.
   *
   * @var string
   */
  private $tempDir;

  public function __construct($entityManager, $container) {
    $this->em = $entityManager;
    $this->logger = $container->get('Omeka\\Logger');
    $this->tempDir = sys_get_temp_dir();
  }

  /**
   * Stream a ZIP for an item with the given media ids (POST media_ids).
   */
  public function itemAction() {
    $id = (int) $this->params()->fromRoute('id');
    // ---- server-side concurrency/size guard ----
    // Clean old progress files and compute current active totals.
    $tmpdir = sys_get_temp_dir();
    $pattern = $tmpdir . DIRECTORY_SEPARATOR . 'zipdownload_progress_*.json';
    $files = glob($pattern) ?: [];
    $activeCount = 0;
    $activeBytes = 0;
    $now = time();
    foreach ($files as $f) {
      $ok = @is_file($f) && @is_readable($f);
      if (!$ok) {
        continue;
      }
      $data = @json_decode(@file_get_contents($f), TRUE) ?: [];
      $ts = @filemtime($f) ?: 0;
      if ($ts > 0 && ($now - $ts) > self::PROGRESS_TOKEN_TTL) {
        @unlink($f);
        continue;
      }
      $status = $data['status'] ?? '';
      if ($status === 'running') {
        $activeCount++;
        $activeBytes += (int) ($data['total_bytes'] ?? 0);
      }
    }

    // Determine requested estimate from client or do a quick local estimate.
    $requestedEstimate = (int) $this->params()->fromPost('estimated_total_bytes', $this->params()->fromQuery('estimated_total_bytes', 0));
    if ($requestedEstimate <= 0) {
      // Quick estimation: sum media file sizes when available, otherwise
      // fall back to a conservative default size per file.
      // to a conservative per-file default of 2MB.
      $midParam = $this->params()->fromPost('media_ids', $this->params()->fromQuery('media_ids', []));
      if (is_string($midParam)) {
        $midArr = array_filter(array_map('intval', explode(',', $midParam)));
      }
      elseif (is_array($midParam)) {
        $midArr = array_map('intval', $midParam);
      }
      else {
        $midArr = [];
      }
      $repo = $this->em->getRepository(Media::class);
      $quickTotal = 0;
      $countFiles = 0;
      foreach ($midArr as $mid) {
        $m = $repo->find($mid);
        if (!$m) {
          continue;
        }
        $countFiles++;
        $sz = 0;
        try {
          $d = $m->getData();
          if (is_array($d) && isset($d['file_size'])) {
            $sz = (int) $d['file_size'];
          }
        }
        catch (\Throwable $e) {
          $sz = 0;
        }
        if ($sz <= 0 && $m->hasOriginal()) {
          $services = $this->getEvent()->getApplication()->getServiceManager();
          $store = $services->get('Omeka\\File\\Store');
          $ext = method_exists($m, 'getExtension') ? (string) $m->getExtension() : '';
          $ext = $ext !== '' ? ('.' . ltrim($ext, '.')) : '';
          $orig = sprintf('original/%s%s', $m->getStorageId(), $ext);
          if (method_exists($store, 'getLocalPath')) {
            $p = $store->getLocalPath($orig);
            if ($p && is_file($p)) {
              $sz = filesize($p);
            }
          }
        }
        if ($sz <= 0) {
          $sz = 2000000;
        }
        $quickTotal += $sz;
      }
      $requestedEstimate = $quickTotal;
      $requestedFileCount = $countFiles;
    }
    else {
      $requestedFileCount = (int) $this->params()->fromPost('estimated_file_count', $this->params()->fromQuery('estimated_file_count', 0));
    }

    // Check limits: concurrent downloads and total bytes.
    if ($activeCount >= self::MAX_CONCURRENT_DOWNLOADS_GLOBAL) {
      header('Content-Type: application/json', TRUE, 429);
      echo json_encode(['error' => 'Too many concurrent downloads', 'retry_after' => 60]);
      exit;
    }
    if ($requestedEstimate > self::MAX_BYTES_PER_DOWNLOAD) {
      header('Content-Type: application/json', TRUE, 413);
      echo json_encode([
        'error' => 'Requested download too large',
        'max_bytes_per_download' => self::MAX_BYTES_PER_DOWNLOAD,
      ]);
      exit;
    }
    if (($activeBytes + $requestedEstimate) > self::MAX_TOTAL_ACTIVE_BYTES) {
      header('Content-Type: application/json', TRUE, 429);
      echo json_encode(['error' => 'Server busy: total active bytes limit reached', 'retry_after' => 60]);
      exit;
    }
    if ($requestedFileCount > self::MAX_FILES_PER_DOWNLOAD) {
      header('Content-Type: application/json', TRUE, 413);
      echo json_encode(['error' => 'Too many files requested', 'max_files_per_download' => self::MAX_FILES_PER_DOWNLOAD]);
      exit;
    }

    $mediaIds = $this->params()->fromPost('media_ids', $this->params()->fromQuery('media_ids', []));

    if (is_string($mediaIds)) {
      $mediaIds = array_filter(array_map('intval', explode(',', $mediaIds)));
    }
    elseif (!is_array($mediaIds)) {
      $mediaIds = [];
    }
    if (!$mediaIds) {
      return $this->jsonError(400, 'No media selected');
    }

    $repo = $this->em->getRepository(Media::class);
    $medias = [];
    foreach ($mediaIds as $mid) {
      $m = $repo->find($mid);
      if (!$m) {
        continue;
      }
      if (!$m->getItem() || (int) $m->getItem()->getId() !== $id) {
        continue;
      }
      try {
        $this->api()->read('media', $m->getId());
      }
      catch (\Exception $e) {
        continue;
      }
      $medias[] = $m;
    }
    if (!$medias) {
      return $this->jsonError(403, 'No accessible media');
    }

    $addedTotal = 0;
    $addedOrig = 0;
    $addedIiif = 0;
    $addedThumb = 0;

    // Optional progress token from client to report status.
    $progressToken = (string) $this->params()->fromPost('progress_token', $this->params()->fromQuery('progress_token', ''));
    $totalBytesEstimate = 0;
    $bytesSent = 0;
    // Ensure startedAt is always defined for progress records.
    $startedAt = time();
    if ($progressToken) {
      // Try to read total estimate from meta file if present.
      $meta = $this->readProgress($progressToken);
      if (isset($meta['total_bytes'])) {
        $totalBytesEstimate = (int) $meta['total_bytes'];
      }
      // Preserve an existing started_at if present.
      if (isset($meta['started_at'])) {
        $startedAt = (int) $meta['started_at'];
      }
      $this->writeProgress(
        $progressToken,
        [
          'status' => 'running',
          'bytes_sent' => 0,
          'total_bytes' => $totalBytesEstimate,
          'started_at' => $startedAt,
        ]
      );
    }

    $services = $this->getEvent()->getApplication()->getServiceManager();
    $store = $services->get('Omeka\\File\\Store');

    $item = NULL;
    $title = '';
    try {
      $item = $this->api()->read('items', $id)->getContent();
      $title = trim((string) $item->displayTitle());
    }
    catch (\Exception $e) {
      $title = '';
    }
    $title = $title !== '' ? $title : ('item-' . $id);
    $safeTitle = $this->sanitizeFilename($title);
    $encoded = rawurlencode($safeTitle . '.zip');

    while (ob_get_level() > 0) {
      @ob_end_clean();
    }

    if (function_exists('ini_get') && function_exists('ini_set')) {
      $zlib = @ini_get('zlib.output_compression');
      if ($zlib) {
        @ini_set('zlib.output_compression', 'Off');
      }
    }

    if (function_exists('header_remove') && !headers_sent()) {
      @header_remove('Content-Type');
      @header_remove('Content-Encoding');
      @header_remove('Transfer-Encoding');
      @header_remove('Content-Length');
      @header_remove('Vary');
    }

    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"download.zip\"; filename*=UTF-8''" . $encoded);
    header('Content-Transfer-Encoding: binary');
    header('Content-Encoding: identity');
    header('Accept-Ranges: none');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Zip-Trace: ZipController:itemAction');

    $options = new Archive();
    // Disable ZipStream header sending (we already sent HTTP headers).
    $options->setSendHttpHeaders(FALSE);
    $zip = new ZipStream(NULL, $options);

    $usedNames = [];
    $makeUnique = function (string $name) use (&$usedNames): string {
      $base = $name;
      $ext = '';
      if (FALSE !== ($pos = strrpos($name, '.'))) {
        $base = substr($name, 0, $pos);
        $ext = substr($name, $pos);
      }
      $try = $name;
      $i = 2;
      while (isset($usedNames[strtolower($try)])) {
        $try = sprintf('%s-%d%s', $base, $i++, $ext);
      }
      $usedNames[strtolower($try)] = TRUE;
      return $try;
    };

    foreach ($medias as $media) {
      try {
        $localPath = NULL;
        $zipName = NULL;

        // First, try IIIF fallback. If it yields images, prefer that.
        $this->safeLog(
          'info',
          'Zip: trying IIIF fallback',
          [
            'media' => $media->getId(),
            'source' => (string) $media->getSource(),
          ]
        );
        $added = $this->addIiifImagesToZipStream($zip, $media, $makeUnique);
        if ($added > 0) {
          $addedIiif += $added;
          $addedTotal += $added;
          // Update progress: approximate bytes added from IIIF.
          if ($progressToken) {
            // If we have no precise size, add a rough default per file.
            $approx = (int) max(
              0,
              floor(
                ($totalBytesEstimate > 0 ? $totalBytesEstimate / max(1, $addedTotal) : 2000000)
              )
            );
            $bytesSent += $approx * $added;
            $this->writeProgress(
              $progressToken,
              [
                'status' => 'running',
                'bytes_sent' => $bytesSent,
                'total_bytes' => $totalBytesEstimate,
                'started_at' => $startedAt,
              ]
            );
          }
          continue;
        }

        // Next, try local original file if available.
        if ($media->hasOriginal()) {
          $ext = method_exists($media, 'getExtension') ? (string) $media->getExtension() : '';
          $ext = $ext !== '' ? ('.' . ltrim($ext, '.')) : '';
          $originalStoragePath = sprintf('original/%s%s', $media->getStorageId(), $ext);
          if (method_exists($store, 'getLocalPath')) {
            $candidatePath = $store->getLocalPath($originalStoragePath);
          }
          else {
            $candidatePath = NULL;
          }
          // Debug: log candidatePath and flags.
          $this->safeLog(
            'info',
            'Zip: media original check',
            [
              'media' => $media->getId(),
              'hasOriginal' => $media->hasOriginal(),
              'candidatePath' => $candidatePath ?? NULL,
            ]
          );
          if ($candidatePath && is_readable($candidatePath)) {
            $localPath = $candidatePath;
            $zipName = $media->getFilename() ?: ($media->getStorageId() . $ext);
          }
        }

        // Then try large thumbnail local file.
        if (!$localPath && $media->hasThumbnails()) {
          $largeStoragePath = sprintf('large/%s.jpg', $media->getStorageId());
          if (method_exists($store, 'getLocalPath')) {
            $thumbPath = $store->getLocalPath($largeStoragePath);
          }
          else {
            $thumbPath = NULL;
          }
          if ($thumbPath && is_readable($thumbPath)) {
            $localPath = $thumbPath;
            $zipName = sprintf('%s_large.jpg', $media->getStorageId());
          }
        }

        if (!$localPath) {
          continue;
        }

        $zip->addFileFromPath((string) $makeUnique($zipName ?: basename($localPath)), (string) $localPath);
        // Update progress with actual filesize when possible.
        if ($progressToken) {
          try {
            $sz = is_file($localPath) ? filesize($localPath) : 0;
            $bytesSent += $sz;
            $this->writeProgress(
              $progressToken,
              [
                'status' => 'running',
                'bytes_sent' => $bytesSent,
                'total_bytes' => $totalBytesEstimate,
                'started_at' => $startedAt,
              ]
            );
          }
          catch (\Throwable $e) {
          }
        }
        if ($media->hasOriginal()) {
          $addedOrig++;
        }
        else {
          $addedThumb++;
        }
        $addedTotal++;
      }
      catch (\Throwable $e) {
        if ($this->logger) {
          $this->logWarning('Zip add failed: ' . $e->getMessage());
        }
      }
    }

    $zip->finish();
    if ($progressToken) {
      $this->writeProgress(
        $progressToken,
        [
          'status' => 'done',
          'bytes_sent' => $bytesSent,
          'total_bytes' => $totalBytesEstimate,
          'started_at' => $startedAt,
        ]
      );
    }

    if ($this->logger) {
      try {
        $this->logger->info(
        'Zip done: item={item} added={total} (orig={orig}, iiif={iiif}, thumb={thumb})',
        [
          'item' => $id,
          'total' => $addedTotal,
          'orig' => $addedOrig,
          'iiif' => $addedIiif,
          'thumb' => $addedThumb,
        ]
        );
      }
      catch (\Throwable $e) {
      }
    }

    exit;
  }

  /**
   * Try to fetch IIIF images and add them to the given ZipStream.
   */
  private function addIiifImagesToZipStream(ZipStream $zip, Media $media, callable $makeUnique): int {
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $client = NULL;
    try {
      $client = $services->get('Omeka\\HttpClient');
    }
    catch (\Throwable $e) {
      $client = NULL;
    }
    $this->safeLog(
      'info',
      'Zip: IIIF client availability',
      [
        'media' => $media->getId(),
        'hasClient' => $client ? TRUE : FALSE,
      ]
    );

    $iiif = $media->getData();
    if (!is_array($iiif) || !$iiif) {
      $src = (string) $media->getSource();
      if ($src && $client) {
        try {
          $client->reset();
          $client->setOptions(['timeout' => 20]);
          $resp = $client->setUri($src)->setMethod('GET')->send();
          $this->safeLog(
            'info',
            'Zip: fetched IIIF info.json',
            [
              'media' => $media->getId(),
              'uri' => $src,
              'ok' => $resp->isOk(),
            ]
          );
          if ($resp->isOk()) {
            $body = $resp->getBody();
            $this->safeLog(
              'info',
              'Zip: IIIF info.json length',
              [
                'media' => $media->getId(),
                'len' => is_string($body) ? strlen($body) : 0,
              ]
            );
            $iiif = json_decode($body, TRUE) ?: [];
            if (!is_array($iiif) || !$iiif) {
              $this->safeLog(
                'warning',
                'Zip: IIIF info.json JSON decode empty or invalid',
                [
                  'media' => $media->getId(),
                  'uri' => $src,
                ]
              );
            }
          }
        }
        catch (\Throwable $e) {
          $this->safeLog(
            'warning',
            'Zip: IIIF info.json fetch exception',
            [
              'media' => $media->getId(),
              'uri' => $src,
              'exception' => $e->getMessage(),
            ]
          );
        }
      }
    }

    if (!is_array($iiif) || !$iiif) {
      return 0;
    }

    // Look for a service id in a few common places (simple heuristic).
    $serviceIds = [];
    if (isset($iiif['sequences']) && is_array($iiif['sequences'])) {
      foreach ($iiif['sequences'] as $seq) {
        if (!isset($seq['canvases']) || !is_array($seq['canvases'])) {
          continue;
        }
        foreach ($seq['canvases'] as $canvas) {
          if (isset($canvas['images']) && is_array($canvas['images'])) {
            foreach ($canvas['images'] as $img) {
              $svc = $img['resource']['service'] ?? NULL;
              $id = is_string($svc) ? $svc : ($svc['@id'] ?? ($svc['id'] ?? NULL));
              if ($id) {
                $serviceIds[] = (string) $id;
              }
            }
          }
        }
      }
    }

    // Simple IIIF v3 structure scan.
    if (empty($serviceIds) && isset($iiif['items']) && is_array($iiif['items'])) {
      foreach ($iiif['items'] as $canvas) {
        if (isset($canvas['items']) && is_array($canvas['items'])) {
          foreach ($canvas['items'] as $page) {
            if (isset($page['items']) && is_array($page['items'])) {
              foreach ($page['items'] as $anno) {
                $svc = $anno['body']['service'] ?? NULL;
                $id = is_string($svc) ? $svc : ($svc['@id'] ?? ($svc['id'] ?? NULL));
                if ($id) {
                  $serviceIds[] = (string) $id;
                }
              }
            }
          }
        }
      }
    }

    if (empty($serviceIds)) {
      // Last fallback: use media source if it looks like IIIF.
      $src = (string) $media->getSource();
      if ($src && (strpos($src, '/iiif/2/') !== FALSE || strpos($src, '/iiif/3/') !== FALSE)) {
        $serviceIds[] = rtrim($src, '/');
      }
    }

    $this->safeLog(
      'info',
      'Zip: IIIF service ids computed',
      [
        'media' => $media->getId(),
        'serviceIds' => $serviceIds,
      ]
    );

    if (empty($serviceIds)) {
      return 0;
    }

    $added = 0;
    foreach ($serviceIds as $idx => $serviceId) {
      if (!$serviceId || !$client) {
        $this->safeLog(
          'info',
          'Zip: skipping iiif service, no client or empty id',
          [
            'media' => $media->getId(),
            'service' => $serviceId,
          ]
        );
        continue;
      }
      $base = rtrim($serviceId, '/');
      // If the service id points to an info.json, strip that suffix.
      if (preg_match('@/info\\.json$@', $base)) {
        $base = preg_replace('@/info\\.json$@', '', $base);
      }
      elseif (strpos($base, '/info.json') !== FALSE) {
        $base = preg_replace('@/info\\.json.*$@', '', $base);
      }
      $this->safeLog(
        'info',
        'Zip: IIIF base computed',
        [
          'media' => $media->getId(),
          'base' => $base,
        ]
      );
      $candidates = [
        $base . '/full/max/0/default.jpg',
        $base . '/full/full/0/default.jpg',
        $base . '/max/full/0/default.jpg',
        $base . '/full/max/0/default.png',
      ];

      $fetched = FALSE;
      $body = '';
      $ext = '';
      foreach ($candidates as $url) {
        try {
          $this->safeLog(
            'info',
            'Zip: IIIF try url',
            [
              'media' => $media->getId(),
              'url' => $url,
            ]
          );
          $client->reset();
          $client->setOptions([
            'timeout' => 25,
            'maxredirects' => 3,
          ]);
          $client->setHeaders([
            'Accept' => 'image/jpeg,image/*;q=0.8,*/*;q=0.5',
            'User-Agent' => 'Omeka-ZipDownload/1.0',
          ]);
          $resp = $client->setUri($url)->setMethod('GET')->send();
          $status = method_exists($resp, 'getStatusCode') ? $resp->getStatusCode() : (method_exists($resp, 'getStatus') ? $resp->getStatus() : NULL);
          $this->safeLog(
            'info',
            'Zip: IIIF response',
            [
              'media' => $media->getId(),
              'url' => $url,
              'status' => $status,
            ]
          );
          if ($resp->isOk()) {
            $b = $resp->getBody();
            $len = is_string($b) ? strlen($b) : 0;
            $this->safeLog(
              'info',
              'Zip: IIIF body length',
              [
                'media' => $media->getId(),
                'url' => $url,
                'len' => $len,
              ]
            );
            if ($b !== '' && $b !== NULL && $len > 0) {
              $body = $b;
              $contentType = NULL;
              try {
                $contentType = $resp->getHeaders()->get('Content-Type');
              }
              catch (\Throwable $e) {
                $contentType = NULL;
              }
              $ext = $this->guessImageExtension($url, $contentType);
              $fetched = TRUE;
              break;
            }
          }
        }
        catch (\Throwable $e) {
          $this->safeLog(
            'warning',
            'Zip: IIIF fetch exception',
            [
              'media' => $media->getId(),
              'url' => $url,
              'exception' => $e->getMessage(),
            ]
          );
        }
      }
      if (!$fetched) {
        $this->safeLog(
          'info',
          'Zip: IIIF service yielded no image',
          [
            'media' => $media->getId(),
            'service' => $serviceId,
          ]
        );
        continue;
      }
      $name = $this->sanitizeFilename((string) $media->getItem()->getId() . '_page-' . ($idx + 1)) . $ext;
      try {
        $zip->addFile($makeUnique($name), $body);
        $this->safeLog(
          'info',
          'Zip: added IIIF file to zip',
          [
            'media' => $media->getId(),
            'name' => $name,
            'bytes' => is_string($body) ? strlen($body) : 0,
          ]
        );
        $added++;
      }
      catch (\Throwable $e) {
        $this->safeLog(
          'warning',
          'Zip: add IIIF file failed',
          [
            'media' => $media->getId(),
            'name' => $name,
            'exception' => $e->getMessage(),
          ]
        );
      }
    }

    return $added;
  }

  /**
   * Read progress data for a token from a temp file.
   */
  private function readProgress(string $token): array {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zipdownload_progress_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $token) . '.json';
    if (!is_file($file)) {
      return [];
    }
    $data = @file_get_contents($file);
    if ($data === FALSE) {
      return [];
    }
    $json = @json_decode($data, TRUE);
    return is_array($json) ? $json : [];
  }

  /**
   * Write progress data for a token to a temp file.
   */
  private function writeProgress(string $token, array $data): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zipdownload_progress_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $token) . '.json';
    @file_put_contents($file, json_encode($data));
  }

  /**
   * Determine an image file extension from a URL or content-type.
   */
  private function guessImageExtension(string $url, $contentType = NULL): string {
    $ext = '';
    if (is_array($contentType)) {
      $contentType = reset($contentType);
    }
    $ct = is_string($contentType) ? strtolower($contentType) : '';
    if ($ct !== '') {
      if (strpos($ct, 'jpeg') !== FALSE || strpos($ct, 'jpg') !== FALSE) {
        return '.jpg';
      }
      if (strpos($ct, 'png') !== FALSE) {
        return '.png';
      }
      if (strpos($ct, 'gif') !== FALSE) {
        return '.gif';
      }
    }
    if (preg_match('/\\.([a-z0-9]+)(?:\?|$)/i', $url, $m)) {
      $ext = '.' . strtolower($m[1]);
    }
    return $ext;
  }

  /**
   * Simple safe logger wrapper to avoid method-not-found failures.
   */
  private function safeLog(string $level, string $message, array $context = []): void {
    if (!$this->logger) {
      return;
    }
    try {
      if (method_exists($this->logger, $level)) {
        $this->logger->{$level}($message, $context);
        return;
      }
      // Fallbacks: try common names.
      if ($level === 'warning' && method_exists($this->logger, 'warn')) {
        $this->logger->warn($message, $context);
        return;
      }
      if (method_exists($this->logger, 'log')) {
        $this->logger->log($level, $message, $context);
        return;
      }
    }
    catch (\Throwable $e) {
      // Ignore logging problems during debug.
    }
  }

  /**
   * Sanitize a string into a safe filename.
   */
  private function sanitizeFilename(string $name): string {
    $map = ["\\" => '_', '/' => '_', ':' => '_', '*' => '_', '?' => '_', '"' => '_', '<' => '_', '>' => '_', '|' => '_'];
    $s = strtr($name, $map);
    $s = preg_replace('/[^\\w\\-\\._ ]+/', '', $s);
    $s = trim($s);
    return $s !== '' ? $s : 'file';
  }

  /**
   * Send a JSON error response and exit.
   */
  private function jsonError(int $status, string $message) {
    header('Content-Type: application/json', TRUE, $status);
    echo json_encode(['error' => $message]);
    exit;
  }

  /**
   * Return progress JSON for a given token.
   *
   * GET /zip-download/status?token=TOKEN.
   */
  public function statusAction() {
    $token = (string) $this->params()->fromQuery('token', '');
    if ($token === '') {
      return $this->jsonError(400, 'Missing token');
    }
    $data = $this->readProgress($token);
    header('Content-Type: application/json');
    echo json_encode($data ?: ['status' => 'unknown']);
    exit;
  }

  /**
   * Estimate total bytes for a set of media ids.
   *
   * Accepts POST/GET media_ids (csv or array).
   * POST /zip-download/estimate?item=:id.
   */
  public function estimateAction() {
    $mediaIds = $this->params()->fromPost('media_ids', $this->params()->fromQuery('media_ids', []));
    if (is_string($mediaIds)) {
      $mediaIds = array_filter(array_map('intval', explode(',', $mediaIds)));
    }
    elseif (!is_array($mediaIds)) {
      $mediaIds = [];
    }
    if (!$mediaIds) {
      return $this->jsonError(400, 'No media selected');
    }
    $repo = $this->em->getRepository(Media::class);
    $services = $this->getEvent()->getApplication()->getServiceManager();
    try {
      $store = $services->get('Omeka\\File\\Store');
    }
    catch (\Throwable $e) {
      $store = NULL;
    }

    $httpClient = NULL;
    try {
      $httpClient = $services->get('Omeka\\HttpClient');
    }
    catch (\Throwable $e) {
      $httpClient = NULL;
    }

    $total = 0;
    $fileCount = 0;
    foreach ($mediaIds as $mid) {
      $m = $repo->find($mid);
      if (!$m) {
        continue;
      }
      $fileCount++;
      // Prefer stored size if available in metadata.
      $size = 0;
      try {
        $data = $m->getData();
        if (is_array($data) && isset($data['file_size'])) {
          $size = (int) $data['file_size'];
        }
      }
      catch (\Throwable $e) {
        $size = 0;
      }

      // If has original, try filesystem size.
      if ($size === 0 && $m->hasOriginal() && $store) {
        $ext = method_exists($m, 'getExtension') ? (string) $m->getExtension() : '';
        $ext = $ext !== '' ? ('.' . ltrim($ext, '.')) : '';
        $originalStoragePath = sprintf('original/%s%s', $m->getStorageId(), $ext);
        if (method_exists($store, 'getLocalPath')) {
          $candidatePath = $store->getLocalPath($originalStoragePath);
          if ($candidatePath && is_file($candidatePath)) {
            $size = filesize($candidatePath);
          }
        }
      }

      // If still unknown, try IIIF HEAD to get Content-Length when possible.
      if ($size === 0) {
        $src = (string) $m->getSource();
        if ($src && $httpClient && (strpos($src, '/iiif/') !== FALSE || strpos($src, '/info.json') !== FALSE)) {
          try {
            $httpClient->reset();
            $httpClient->setOptions(['timeout' => 5, 'maxredirects' => 2]);
            // Attempt HEAD on a likely image resource.
            $candidate = rtrim($src, '/') . '/full/max/0/default.jpg';
            $resp = $httpClient->setUri($candidate)->setMethod('HEAD')->send();
            if (method_exists($resp, 'isOk') && $resp->isOk()) {
              $cl = NULL;
              try {
                $cl = $resp->getHeaders()->get('Content-Length');
              }
              catch (\Throwable $e) {
                $cl = NULL;
              }
              if ($cl) {
                $size = (int) $cl;
              }
            }
          }
          catch (\Throwable $e) {
            // Ignore network errors. Fallback to default below.
          }
        }
      }

      // Fallback default estimate for unknown: 2MB per file.
      if ($size <= 0) {
        $size = 2000000;
      }
      $total += $size;
    }
    header('Content-Type: application/json');
    echo json_encode(['total_bytes' => $total, 'total_files' => $fileCount]);
    exit;
  }

}
