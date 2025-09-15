<?php

declare(strict_types=1);

namespace ZipDownload\Controller;

use Laminas\Http\Headers;
use Laminas\Http\Response as HttpResponse;
use Laminas\Http\Response\Stream as StreamResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Entity\Media;

/**
 * Build and stream a ZIP archive of item media (local store only).
 */
class ZipController extends AbstractActionController {
  /**
   * Doctrine entity manager.
   *
   * @var \Doctrine\ORM\EntityManager
   */
  private $em;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface|null
   */
  private $logger;

  /**
   * Temporary directory path.
   *
   * @var string
   */
  private $tempDir;

  /**
   * Construct controller with services.
   *
   * @param \Doctrine\ORM\EntityManager $entityManager
   *   Entity manager.
   * @param mixed $container
   *   Service container.
   */
  public function __construct($entityManager, $container) {
    $this->em = $entityManager;
    $this->logger = $container->get('Omeka\Logger');
    $this->tempDir = sys_get_temp_dir();
  }

  /**
   * Build and return a ZIP stream response for selected media.
   *
   * Accepts media_ids as a comma-separated string via POST (or query).
   *
   * @return \Laminas\Http\Response\Stream|HttpResponse
   *   Streaming ZIP on success, or JSON error response.
   */
  public function itemAction() {
    $id = (int) $this->params()->fromRoute('id');

    $mediaIds = $this->params()->fromPost('media_ids', $this->params()->fromQuery('media_ids', []));
    // Early trace: ensure we can confirm routing and param receipt in logs.
    if ($this->logger) {
      try {
        $rawPost = $this->params()->fromPost('media_ids', '');
        $rawGet = $this->params()->fromQuery('media_ids', '');
        $this->logger->info('Zip request hit: item={item} media_ids_post={post} media_ids_query={query}', [
          'item' => $id,
          'post' => is_array($rawPost) ? implode(',', $rawPost) : (string) $rawPost,
          'query' => is_array($rawGet) ? implode(',', $rawGet) : (string) $rawGet,
        ]);
      }
      catch (\Throwable $e) {
        // Ignore.
      }
    }
    if (is_string($mediaIds)) {
      $mediaIds = array_filter(array_map('intval', explode(',', $mediaIds)));
    }
    elseif (!is_array($mediaIds)) {
      $mediaIds = [];
    }
    if (!$mediaIds) {
      return $this->jsonError(400, 'No media selected');
    }

    // Fetch media and verify permissions.
    $repo = $this->em->getRepository(Media::class);
    $medias = [];
    foreach ($mediaIds as $mid) {
      $m = $repo->find($mid);
      if (!$m) {
        continue;
      }
      // Ensure media belongs to requested item.
      if (!$m->getItem() || (int) $m->getItem()->getId() !== $id) {
        continue;
      }
      // Authorization: ensure current user can see this media.
      $api = $this->api();
      try {
        $api->read('media', $m->getId());
      }
      catch (\Exception $e) {
        // Skip unauthorized.
        continue;
      }
      $medias[] = $m;
    }
    if (!$medias) {
      return $this->jsonError(403, 'No accessible media');
    }

    // Stats for debug headers/logging.
    $addedTotal = 0;
    $addedOrig = 0;
    $addedIiif = 0;
    $addedThumb = 0;
    if ($this->logger) {
      try {
        $this->logger->info('Zip start: item={item} medias={count}', ['item' => $id, 'count' => count($medias)]);
      }
      catch (\Throwable $e) {
        // Ignore.
      }
    }

    // Create temporary ZIP file.
    $zipPath = tempnam($this->tempDir, 'omeka_zip_');
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== TRUE) {
      return $this->jsonError(500, 'Cannot create zip');
    }

    // Add files by local path from local store (no HTTP fetch).
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $store = $services->get('Omeka\File\Store');

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

        // Prefer original file from local storage.
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

          if ($candidatePath && is_readable($candidatePath)) {
            $localPath = $candidatePath;
            // Use original filename when available; otherwise storageId.ext.
            $zipName = $media->getFilename() ?: ($media->getStorageId() . $ext);
          }
          else {
            // Some installs may store original using the filename.
            // Try as a fallback.
            $filename = (string) $media->getFilename();
            if ($filename !== '') {
              $altOriginal = 'original/' . $filename;
              if (method_exists($store, 'getLocalPath')) {
                $altPath = $store->getLocalPath($altOriginal);
                if ($altPath && is_readable($altPath)) {
                  $localPath = $altPath;
                  $zipName = $filename;
                }
              }
            }
          }
        }

        if (!$localPath) {
          // Always try IIIF fetch when no local original is available. This
          // supports media imported via external tools where ingester detection
          // is unreliable.
          $added = $this->addIiifImagesToZip($zip, $media, $makeUnique);
          if ($added > 0) {
            $addedIiif += $added;
            $addedTotal += $added;
            // Added one or more images from IIIF; skip thumbnail fallback.
            continue;
          }
        }

        // Fallback to large thumbnail when original is not available.
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

        $zip->addFile($localPath, $makeUnique($zipName ?: basename($localPath)));
        // Count local additions by type.
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
          $this->logger->warning('Zip add failed: ' . $e->getMessage());
        }
      }
    }

    $closed = $zip->close();
    if ($closed !== TRUE) {
      return $this->jsonError(500, 'Zip finalize failed');
    }

    // Stream ZIP to client.
    // Clear any previous output buffers to avoid corrupting the binary stream.
    try {
      while (ob_get_level() > 0) {
        @ob_end_clean();
      }
    }
    catch (\Throwable $e) {
      // Ignore.
    }

    // Avoid server-side output compression for binary stream.
    if (function_exists('ini_get') && function_exists('ini_set')) {
      $zlib = @ini_get('zlib.output_compression');
      if ($zlib) {
        @ini_set('zlib.output_compression', 'Off');
      }
    }

    $stream = @fopen($zipPath, 'rb');
    if (!$stream) {
      return $this->jsonError(500, 'Cannot read zip');
    }
    @rewind($stream);

    // Try to remove any previously set headers that could corrupt the stream.
    if (function_exists('header_remove') && !headers_sent()) {
      @header_remove('Content-Type');
      @header_remove('Content-Encoding');
      @header_remove('Transfer-Encoding');
      @header_remove('Content-Length');
      @header_remove('Vary');
    }
    else {
      // If headers are already sent, log the origin to help debugging.
      $file = '';
      $line = 0;
      if (headers_sent($file, $line) && $this->logger) {
        $this->logger->warning(sprintf('Headers already sent before ZIP stream (at %s:%d).', (string) $file, (int) $line));
      }
    }

    $response = new StreamResponse();
    $response->setStream($stream);
    $response->setStatusCode(200);
    $headers = new Headers();
    $headers->addHeaderLine('Content-Type', 'application/zip');
    $headers->addHeaderLine('Content-Transfer-Encoding', 'binary');
    $headers->addHeaderLine('Content-Encoding', 'identity');
    $headers->addHeaderLine('Accept-Ranges', 'none');
    $headers->addHeaderLine('Cache-Control', 'no-store, no-cache, must-revalidate');
    $headers->addHeaderLine('Pragma', 'no-cache');
    $headers->addHeaderLine('Expires', '0');
    // Derive a nice filename from item title.
    try {
      $item = $this->api()->read('items', $id)->getContent();
      $title = trim((string) $item->displayTitle());
    }
    catch (\Exception $e) {
      $title = '';
    }
    $title = $title !== '' ? $title : ('item-' . $id);
    // Sanitize filename without regex to avoid modifier issues and warnings.
    $replMap = [
      "\\" => '_',
      "/" => '_',
      ":" => '_',
      "*" => '_',
      "?" => '_',
      '"' => '_',
      "<" => '_',
      ">" => '_',
      "|" => '_',
    ];
    $safe = strtr($title, $replMap);
    $encoded = rawurlencode($safe . '.zip');
    // Provide both legacy filename and RFC 5987 filename*.
    $headers->addHeaderLine('Content-Disposition', 'attachment; filename="download.zip"; filename*=UTF-8\'\'' . $encoded);
    $size = @filesize($zipPath);
    if ($size !== FALSE) {
      $headers->addHeaderLine('Content-Length', (string) $size);
    }
    // Trace headers to help diagnose proxies/middleware issues.
    $headers->addHeaderLine('X-Zip-Trace', 'ZipController:itemAction');
    // Added files stats headers.
    $headers->addHeaderLine('X-Zip-Added', (string) $addedTotal);
    $headers->addHeaderLine('X-Zip-Added-Original', (string) $addedOrig);
    $headers->addHeaderLine('X-Zip-Added-IIIF', (string) $addedIiif);
    $headers->addHeaderLine('X-Zip-Added-Thumbnail', (string) $addedThumb);
    if ($size !== FALSE) {
      $headers->addHeaderLine('X-Zip-Size', (string) $size);
    }
    $response->setHeaders($headers);

    // Cleanup after send.
    register_shutdown_function(function () use ($zipPath): void {
      if (file_exists($zipPath)) {
          @unlink($zipPath);
      }
    });

    // Log completion with stats.
    if ($this->logger) {
      try {
        $this->logger->info(
          'Zip done: item={item} added={total} (orig={orig}, iiif={iiif}, thumb={thumb}) size={size}',
          [
            'item' => $id,
            'total' => $addedTotal,
            'orig' => $addedOrig,
            'iiif' => $addedIiif,
            'thumb' => $addedThumb,
            'size' => ($size !== FALSE ? (int) $size : -1),
          ]
        );
      }
      catch (\Throwable $e) {
        // Ignore.
      }
    }

    return $response;
  }

  /**
   * Add IIIF images (max size) into the ZIP for a given media.
   *
   * This parses the IIIF presentation JSON stored in the media (v2 or v3),
   * derives Image API service URLs for each canvas, fetches the max-size
   * rendition, and adds it to the ZipArchive.
   *
   * @param \ZipArchive $zip
   *   The ZIP archive to add files into.
   * @param \Omeka\Entity\Media $media
   *   The media whose IIIF data to read.
   * @param callable $makeUnique
   *   A callback to uniquify filenames inside the zip.
   *
   * @return int
   *   Number of images successfully added.
   */
  private function addIiifImagesToZip(\ZipArchive $zip, Media $media, callable $makeUnique): int {
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $client = NULL;
    try {
      $client = $services->get('Omeka\\HttpClient');
    }
    catch (\Throwable $e) {
      $client = NULL;
    }

    $iiif = $media->getData();
    if (!is_array($iiif) || !$iiif) {
      // As a fallback, try to fetch the manifest from source URL.
      $src = (string) $media->getSource();
      if ($src && $client) {
        try {
          $client->reset();
          $client->setOptions(['timeout' => 20]);
          $response = $client->setUri($src)->setMethod('GET')->send();
          if ($response->isOk()) {
            $iiif = json_decode($response->getBody(), TRUE) ?: [];
          }
        }
        catch (\Throwable $e) {
          // Ignore.
        }
      }
    }
    if (!is_array($iiif) || !$iiif) {
      // Second fallback: resolve item-level manifest URL and fetch it.
      try {
        $entityItem = $media->getItem();
        $itemId = $entityItem ? (int) $entityItem->getId() : 0;
        if ($itemId && $client) {
          $manifestUrl = '';
          // Try view helper 'iiifUrl' if present (IiifServer module installed).
          try {
            $vh = $services->get('ViewHelperManager');
            if ($vh && $vh->has('iiifUrl')) {
              $iiifUrlHelper = $vh->get('iiifUrl');
              if ($iiifUrlHelper) {
                $rep = $this->api()->read('items', $itemId)->getContent();
                $manifestUrl = (string) $iiifUrlHelper($rep, 'manifest');
              }
            }
          }
          catch (\Throwable $e) {
            // Ignore.
          }
          // If not resolved, read from item properties.
          if ($manifestUrl === '') {
            try {
              $rep = $rep ?? $this->api()->read('items', $itemId)->getContent();
              $val = $rep->value('dcterms:hasFormat', ['type' => 'uri', 'default' => NULL]);
              if ($val) {
                $manifestUrl = (string) (method_exists($val, 'uri') ? $val->uri() : (string) $val);
              }
              if ($manifestUrl === '') {
                $val = $rep->value('dcterms:source', ['type' => 'uri', 'default' => NULL]);
                if ($val) {
                  $manifestUrl = (string) (method_exists($val, 'uri') ? $val->uri() : (string) $val);
                }
              }
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
          // Final fallback: assume IiifServer route by item id (v3).
          if ($manifestUrl === '') {
            try {
              $serverUrlHelper = $services->get('ViewHelperManager')->get('serverUrl');
              $base = rtrim((string) $serverUrlHelper('/'), '/');
              $manifestUrl = $base . '/iiif/3/' . $itemId . '/manifest';
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
          if ($manifestUrl !== '') {
            try {
              $client->reset();
              $client->setOptions(['timeout' => 20]);
              $client->setHeaders(['Accept' => 'application/json']);
              $response = $client->setUri($manifestUrl)->setMethod('GET')->send();
              if ($response->isOk()) {
                $iiif = json_decode($response->getBody(), TRUE) ?: [];
              }
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore errors in manifest fallback.
      }
    }
    if (!is_array($iiif) || !$iiif) {
      return 0;
    }

    $images = $this->extractIiifImageEntries($iiif);
    if (!$images) {
      // The media data did not contain extractable IIIF entries.
      // Try resolving the item-level manifest again as a fallback.
      try {
        if ($this->logger) {
          $this->logger->info('IIIF: No images extracted from media data; trying item-level manifest fallback.');
        }
        $entityItem = $media->getItem();
        $itemId = $entityItem ? (int) $entityItem->getId() : 0;
        if ($itemId) {
          $services = $this->getEvent()->getApplication()->getServiceManager();
          $vh = $services->get('ViewHelperManager');
          $manifestUrl = '';
          // Helper if available.
          try {
            if ($vh && $vh->has('iiifUrl')) {
              $iiifUrlHelper = $vh->get('iiifUrl');
              if ($iiifUrlHelper) {
                $rep = $this->api()->read('items', $itemId)->getContent();
                $manifestUrl = (string) $iiifUrlHelper($rep, 'manifest');
              }
            }
          }
          catch (\Throwable $e) {
            // Ignore.
          }
          // Properties fallback.
          if ($manifestUrl === '') {
            try {
              $rep = $rep ?? $this->api()->read('items', $itemId)->getContent();
              $val = $rep->value('dcterms:hasFormat', ['type' => 'uri', 'default' => NULL]);
              if ($val) {
                $manifestUrl = (string) (method_exists($val, 'uri') ? $val->uri() : (string) $val);
              }
              if ($manifestUrl === '') {
                $val = $rep->value('dcterms:source', ['type' => 'uri', 'default' => NULL]);
                if ($val) {
                  $manifestUrl = (string) (method_exists($val, 'uri') ? $val->uri() : (string) $val);
                }
              }
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
          // Local IiifServer fallback by id.
          if ($manifestUrl === '') {
            try {
              $serverUrlHelper = $vh->get('serverUrl');
              $base = rtrim((string) $serverUrlHelper('/'), '/');
              $manifestUrl = $base . '/iiif/3/' . $itemId . '/manifest';
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
          if ($manifestUrl !== '' && $client) {
            try {
              $client->reset();
              $client->setOptions(['timeout' => 20]);
              $client->setHeaders(['Accept' => 'application/json']);
              $response = $client->setUri($manifestUrl)->setMethod('GET')->send();
              if ($response->isOk()) {
                $iiif2 = json_decode($response->getBody(), TRUE) ?: [];
                if (is_array($iiif2) && $iiif2) {
                  $images = $this->extractIiifImageEntries($iiif2);
                  if ($this->logger) {
                    $this->logger->info('IIIF: Fallback manifest fetched and parsed. entries={count}', ['count' => count($images)]);
                  }
                }
              }
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore.
      }
    }
    if (!$images) {
      // Fallback: use media-level IIIF URL (source) directly as Image API base.
      try {
        $src = (string) $media->getSource();
        if ($src && (strpos($src, '/iiif/2/') !== FALSE || strpos($src, '/iiif/3/') !== FALSE)) {
          $serviceId = rtrim($src, '/');
          // Optionally probe info.json to normalize the base id.
          $infoBase = '';
          if ($client) {
            try {
              $client->reset();
              $client->setOptions(['timeout' => 15, 'maxredirects' => 3]);
              $client->setHeaders([
                'Accept' => 'application/ld+json, application/json',
                'User-Agent' => 'Omeka-ZipDownload/1.0',
              ]);
              $r = $client->setUri($serviceId . '/info.json')->setMethod('GET')->send();
              if ($r->isOk()) {
                $j = json_decode($r->getBody(), TRUE) ?: [];
                $infoBase = (string) ($j['id'] ?? ($j['@id'] ?? ''));
              }
            }
            catch (\Throwable $e) {
              // Ignore.
            }
          }
          $base = $infoBase !== '' ? rtrim($infoBase, '/') : $serviceId;
          $candidates = [
            $base . '/full/max/0/default.jpg',
            $base . '/full/full/0/default.jpg',
            $base . '/max/full/0/default.jpg',
            $base . '/full/max/0/default.png',
            $base . '/full/pct:100/0/default.jpg',
            $base . '/full/pct:100/0/color.jpg',
          ];
          $fetched = FALSE;
          $body = '';
          $ext = '';
          foreach ($candidates as $url) {
            try {
              $client->reset();
              $client->setOptions(['timeout' => 25, 'maxredirects' => 3]);
              $client->setHeaders([
                'Accept' => 'image/jpeg,image/*;q=0.8,*/*;q=0.5',
                'User-Agent' => 'Omeka-ZipDownload/1.0',
              ]);
              $resp = $client->setUri($url)->setMethod('GET')->send();
              if ($resp->isOk()) {
                $b = $resp->getBody();
                if ($b !== '' && $b !== NULL) {
                  $body = $b;
                  $ext = $this->guessImageExtension($url, $resp->getHeaders()->get('Content-Type'));
                  $fetched = TRUE;
                  break;
                }
              }
            }
            catch (\Throwable $e) {
              // Continue to next.
            }
          }
          if ($fetched) {
            $entityItem = $media->getItem();
            $itemId = $entityItem ? (int) $entityItem->getId() : 0;
            $titleBase = 'item-' . $itemId;
            try {
              if ($itemId) {
                $rep = $this->api()->read('items', $itemId)->getContent();
                $titleBase = trim((string) $rep->displayTitle()) ?: $titleBase;
              }
            }
            catch (\Throwable $e) {
              // Ignore.
            }
            $titleBase = $this->sanitizeFilename($titleBase);
            $label = 'media-' . $media->getId();
            $name = $titleBase . '_' . $label . $ext;
            $zip->addFromString($makeUnique($name), $body);
            return 1;
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore errors in source-based fallback.
      }
      return 0;
    }

    // If multiple images were found from the item manifest but only a single
    // media was selected, try to filter images down to the one corresponding
    // to this media using the media source identifier (when available).
    try {
      $src = (string) $media->getSource();
      if ($src && (strpos($src, '/iiif/2/') !== FALSE || strpos($src, '/iiif/3/') !== FALSE) && count($images) > 1) {
        $srcId = $this->iiifIdentifierFromUrl($src);
        if ($srcId !== '') {
          $filtered = [];
          foreach ($images as $img) {
            $sid = isset($img['serviceId']) ? (string) $img['serviceId'] : '';
            $did = isset($img['directId']) ? (string) $img['directId'] : '';
            $sidId = $sid !== '' ? $this->iiifIdentifierFromUrl($sid) : '';
            $didId = $did !== '' ? $this->iiifIdentifierFromUrl($did) : '';
            if (($sidId !== '' && $sidId === $srcId) || ($didId !== '' && $didId === $srcId)) {
              $filtered[] = $img;
            }
          }
          if ($filtered) {
            $images = $filtered;
          }
          else {
            // As a safety, if we cannot map, limit to the first image to
            // avoid packing all canvases when only one media is selected.
            $images = [$images[0]];
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Ignore.
    }

    $added = 0;
    $titleBase = '';
    try {
      $entityItem = $media->getItem();
      $itemId = $entityItem ? (int) $entityItem->getId() : 0;
      if ($itemId) {
        $rep = $this->api()->read('items', $itemId)->getContent();
        $titleBase = trim((string) $rep->displayTitle());
      }
    }
    catch (\Throwable $e) {
      $titleBase = '';
    }
    $titleBase = $this->sanitizeFilename($titleBase ?: ('item-' . (int) $media->getItem()->getId()));

    foreach ($images as $idx => $img) {
      $candidates = isset($img['candidates']) && is_array($img['candidates']) ? $img['candidates'] : [];
      $serviceId = isset($img['serviceId']) ? (string) $img['serviceId'] : '';
      $label = $img['label'];
      // Skip if no HTTP client available.
      if (!$client) {
        continue;
      }
      try {
        // If a service id is available, try fetching info.json to derive a
        // stable base URL and possibly a best-fit size. Prepend these
        // candidates.
        if ($serviceId !== '') {
          try {
            $client->reset();
            $client->setOptions(['timeout' => 20, 'maxredirects' => 3]);
            $client->setHeaders([
              'Accept' => 'application/ld+json, application/json',
              'User-Agent' => 'Omeka-ZipDownload/1.0',
            ]);
            $respInfo = $client->setUri(rtrim($serviceId, '/') . '/info.json')->setMethod('GET')->send();
            if ($respInfo->isOk()) {
              $info = json_decode($respInfo->getBody(), TRUE) ?: [];
              $infoBase = (string) ($info['id'] ?? ($info['@id'] ?? ''));
              if ($infoBase !== '') {
                $infoBase = rtrim($infoBase, '/');
                $infoW = (int) ($info['width'] ?? 0);
                $infoH = (int) ($info['height'] ?? 0);
                $infoCandidates = [];
                // If server doesn't accept "max", try explicit full pixel
                // sizes first.
                if ($infoW > 0) {
                  $infoCandidates[] = $infoBase . '/full/' . $infoW . ',/0/default.jpg';
                }
                if ($infoH > 0) {
                  $infoCandidates[] = $infoBase . '/full/,' . $infoH . '/0/default.jpg';
                }
                // General full-size fallbacks.
                $infoCandidates[] = $infoBase . '/full/max/0/default.jpg';
                $infoCandidates[] = $infoBase . '/full/full/0/default.jpg';
                $infoCandidates[] = $infoBase . '/max/full/0/default.jpg';
                $infoCandidates[] = $infoBase . '/full/max/0/default.png';
                // If sizes are provided, try the largest width as an
                // additional safe candidate.
                if (isset($info['sizes']) && is_array($info['sizes']) && !empty($info['sizes'])) {
                  $sizes = $info['sizes'];
                  usort($sizes, function ($a, $b) {
                    $wa = (int) ($a['width'] ?? 0);
                    $wb = (int) ($b['width'] ?? 0);
                    return $wb <=> $wa;
                  });
                  $w = (int) ($sizes[0]['width'] ?? 0);
                  $h = (int) ($sizes[0]['height'] ?? 0);
                  if ($w > 0 && $h > 0) {
                    // Prefer width-constrained; servers generally accept "w,".
                    $infoCandidates[] = $infoBase . '/full/' . $w . ',/0/default.jpg';
                  }
                }
                // Prepend and deduplicate.
                $candidates = array_values(array_unique(array_merge($infoCandidates, $candidates)));
              }
            }
          }
          catch (\Throwable $e) {
            // Ignore info.json errors.
          }
        }
        $fetched = FALSE;
        $body = '';
        $ext = '';
        foreach ($candidates as $url) {
          try {
            $client->reset();
            $client->setOptions(['timeout' => 30, 'maxredirects' => 3]);
            $client->setHeaders([
              'Accept' => 'image/jpeg,image/*;q=0.8,*/*;q=0.5',
              'User-Agent' => 'Omeka-ZipDownload/1.0',
            ]);
            $response = $client->setUri($url)->setMethod('GET')->send();
            if ($response->isOk()) {
              $b = $response->getBody();
              if ($b !== '' && $b !== NULL) {
                $body = $b;
                $ext = $this->guessImageExtension($url, $response->getHeaders()->get('Content-Type'));
                $fetched = TRUE;
                break;
              }
            }
            elseif ($this->logger) {
              $this->logger->notice(
                'IIIF candidate not OK: {status} {url}',
                [
                  'status' => $response->getStatusCode(),
                  'url' => $url,
                ]
              );
            }
          }
          catch (\Throwable $e) {
            if ($this->logger) {
              $this->logger->notice('IIIF candidate error: ' . $e->getMessage());
            }
            // Try next candidate.
          }
        }
        // As a last resort, retry with SSL verification disabled (dev envs).
        if (!$fetched) {
          foreach ($candidates as $url) {
            if (stripos($url, 'https://') !== 0) {
              continue;
            }
            try {
              $client->reset();
              $client->setOptions([
                'timeout' => 30,
                'maxredirects' => 3,
                'sslverifypeer' => FALSE,
                'sslallowselfsigned' => TRUE,
              ]);
              $client->setHeaders([
                'Accept' => 'image/jpeg,image/*;q=0.8,*/*;q=0.5',
                'User-Agent' => 'Omeka-ZipDownload/1.0',
              ]);
              $response = $client->setUri($url)->setMethod('GET')->send();
              if ($response->isOk()) {
                $b = $response->getBody();
                if ($b !== '' && $b !== NULL) {
                  $body = $b;
                  $ext = $this->guessImageExtension($url, $response->getHeaders()->get('Content-Type'));
                  $fetched = TRUE;
                  break;
                }
              }
            }
            catch (\Throwable $e) {
              // Ignore and continue.
            }
          }
        }
        if (!$fetched) {
          continue;
        }
        $name = $titleBase . '_' . $this->sanitizeFilename($label ?: ('page-' . ($idx + 1))) . $ext;
        $zip->addFromString($makeUnique($name), $body);
        $added++;
      }
      catch (\Throwable $e) {
        if ($this->logger) {
          $this->logger->notice('IIIF fetch failed: ' . $e->getMessage());
        }
      }
    }

    return $added;
  }

  /**
   * Extract image entries (URL + label) from IIIF v2/v3 JSON.
   *
   * @param array $iiif
   *   IIIF Presentation JSON.
   *
   * @return array<int,array{candidates:array,label:string}>
   *   List of image entries with candidate URLs and a label.
   */
  private function extractIiifImageEntries(array $iiif): array {
    $entries = [];
    $context = (string) ($iiif['@context'] ?? '');

    if (strpos($context, '/presentation/2/') !== FALSE) {
      // v2: sequences[0].canvases[].images[0].resource{ @id, service{@id} }.
      $seqs = $iiif['sequences'] ?? [];
      $canvases = $seqs[0]['canvases'] ?? [];
      foreach ($canvases as $i => $canvas) {
        $label = (string) ($canvas['label'] ?? (string) ($i + 1));
        $image = $canvas['images'][0] ?? NULL;
        if (!$image) {
          continue;
        }
        $resource = $image['resource'] ?? [];
        $direct = (string) ($resource['@id'] ?? '');
        $service = $resource['service'] ?? [];
        $serviceId = '';
        if (is_array($service)) {
          $serviceId = (string) ($service['@id'] ?? $service['id'] ?? '');
        }
        $w = (int) ($resource['width'] ?? ($canvas['width'] ?? 0));
        $h = (int) ($resource['height'] ?? ($canvas['height'] ?? 0));
        $cands = $this->bestIiifImageCandidates($serviceId, $direct, $w, $h);
        if ($cands) {
          $entries[] = [
            'candidates' => $cands,
            'label' => (string) $label,
            'serviceId' => (string) $serviceId,
            'directId' => (string) $direct,
            'width' => $w,
            'height' => $h,
          ];
        }
      }
    }
    else {
      // v3: items[].items[0].items[0].body{ id, service[{id}] }.
      $canvases = $iiif['items'] ?? [];
      foreach ($canvases as $i => $canvas) {
        $label = '';
        $labelVal = $canvas['label'] ?? '';
        if (is_string($labelVal)) {
          $label = $labelVal;
        }
        elseif (is_array($labelVal)) {
          // Prefer "none" language.
          if (isset($labelVal['none']) && is_array($labelVal['none']) && !empty($labelVal['none'][0])) {
            $label = (string) $labelVal['none'][0];
          }
          else {
            // Use first value of the first language key.
            $keys = array_keys($labelVal);
            if ($keys && isset($labelVal[$keys[0]]) && is_array($labelVal[$keys[0]]) && !empty($labelVal[$keys[0]][0])) {
              $label = (string) $labelVal[$keys[0]][0];
            }
          }
        }
        if ($label === '') {
          $label = (string) ($i + 1);
        }
        $annos = $canvas['items'][0]['items'][0] ?? NULL;
        if (!$annos) {
          continue;
        }
        $body = $annos['body'] ?? NULL;
        if (!$body) {
          continue;
        }
        if (isset($body['id']) || isset($body['service'])) {
          $direct = (string) ($body['id'] ?? '');
          $svc = $body['service'] ?? [];
          $serviceId = '';
          if (is_array($svc)) {
            // Could be array or object.
            if (isset($svc[0]) && is_array($svc[0])) {
              $serviceId = (string) ($svc[0]['id'] ?? $svc[0]['@id'] ?? '');
            }
            elseif (isset($svc['id']) || isset($svc['@id'])) {
              $serviceId = (string) ($svc['id'] ?? $svc['@id'] ?? '');
            }
          }
          $w = (int) ($body['width'] ?? ($canvas['width'] ?? 0));
          $h = (int) ($body['height'] ?? ($canvas['height'] ?? 0));
          $cands = $this->bestIiifImageCandidates($serviceId, $direct, $w, $h);
          if ($cands) {
            $entries[] = [
              'candidates' => $cands,
              'label' => (string) $label,
              'serviceId' => (string) $serviceId,
              'directId' => (string) $direct,
              'width' => $w,
              'height' => $h,
            ];
          }
        }
      }
    }

    return $entries;
  }

  /**
   * Extract the Image API identifier from a IIIF URL.
   *
   * Examples:
   *  - https://host/iiif/3/identifier/full/max/0/default.jpg -> identifier
   *  - https://host/iiif/2/identifier/info.json -> identifier
   *  - https://host/iiif/3/identifier.ext -> identifier (extension stripped)
   */
  private function iiifIdentifierFromUrl(string $url): string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '') {
      return '';
    }
    $parts = explode('/', trim($path, '/'));
    $i = array_search('iiif', $parts, TRUE);
    if ($i === FALSE || !isset($parts[$i + 2])) {
      // Not a typical /iiif/{ver}/{identifier} path.
      // Fall back to last segment.
      $last = end($parts) ?: '';
      $dot = strrpos($last, '.');
      return $dot !== FALSE ? substr($last, 0, $dot) : $last;
    }
    $identifier = $parts[$i + 2];
    // Strip trailing transform segments like 'info.json'.
    $dot = strrpos($identifier, '.');
    if ($dot !== FALSE) {
      $identifier = substr($identifier, 0, $dot);
    }
    return $identifier;
  }

  /**
   * Build the best IIIF Image URL from service id and direct image URL.
   */
  private function bestIiifImageCandidates(string $serviceId, string $direct, int $width = 0, int $height = 0): array {
    $candidates = [];
    if ($serviceId !== '') {
      $serviceId = rtrim($serviceId, '/');
      // Try both full and max variants (some servers only accept one).
      $candidates[] = $serviceId . '/full/max/0/default.jpg';
      $candidates[] = $serviceId . '/full/full/0/default.jpg';
      $candidates[] = $serviceId . '/full/max/0/color.jpg';
      $candidates[] = $serviceId . '/full/full/0/color.jpg';
      // Also try explicit pixel size using "max" shortcut (some impls differ).
      $candidates[] = $serviceId . '/max/full/0/default.jpg';
      // Some servers support png only for huge images; try png as well.
      $candidates[] = $serviceId . '/full/max/0/default.png';
      // Try percentage based size (some servers prefer pct:100 as full scale).
      $candidates[] = $serviceId . '/full/pct:100/0/default.jpg';
      $candidates[] = $serviceId . '/full/pct:100/0/color.jpg';
      if ($width > 0) {
        $candidates[] = $serviceId . '/full/' . $width . ',/0/default.jpg';
        $candidates[] = $serviceId . '/full/!' . $width . ',' . ($height > 0 ? $height : $width) . '/0/default.jpg';
      }
      if ($height > 0) {
        $candidates[] = $serviceId . '/full/,' . $height . '/0/default.jpg';
      }
    }
    if ($direct !== '') {
      $candidates[] = $direct;
      // If direct looks like an Image API identifier, try building Image API
      // URLs directly from it (identifier may include dots or extensions).
      $directBase = rtrim($direct, '/');
      $candidates[] = $directBase . '/full/max/0/default.jpg';
      $candidates[] = $directBase . '/full/full/0/default.jpg';
      $candidates[] = $directBase . '/full/max/0/color.jpg';
      $candidates[] = $directBase . '/full/full/0/color.jpg';
      $candidates[] = $directBase . '/max/full/0/default.jpg';
      $candidates[] = $directBase . '/full/max/0/default.png';
      $candidates[] = $directBase . '/full/pct:100/0/default.jpg';
      $candidates[] = $directBase . '/full/pct:100/0/color.jpg';
      if ($width > 0) {
        $candidates[] = $directBase . '/full/' . $width . ',/0/default.jpg';
        $candidates[] = $directBase . '/full/!' . $width . ',' . ($height > 0 ? $height : $width) . '/0/default.jpg';
      }
      if ($height > 0) {
        $candidates[] = $directBase . '/full/,' . $height . '/0/default.jpg';
      }
      // As an extra fallback, if the last path segment ends with an extension,
      // also try stripping it and building standard Image API URLs.
      $parsed = @parse_url($direct);
      $path = is_array($parsed) ? (string) ($parsed['path'] ?? '') : '';
      if ($path !== '' && (strpos($path, '/iiif/2/') !== FALSE || strpos($path, '/iiif/3/') !== FALSE)) {
        $segments = explode('/', trim($path, '/'));
        // Last segment may be identifier with extension, e.g., foo_1.tif.
        $last = end($segments) ?: '';
        if ($last !== '') {
          $dot = strrpos($last, '.');
          if ($dot !== FALSE) {
            $idNoExt = substr($last, 0, $dot);
            if ($idNoExt !== '') {
              $segments[count($segments) - 1] = $idNoExt;
              $schemeHost = '';
              if (is_array($parsed)) {
                $scheme = (string) ($parsed['scheme'] ?? '');
                $host = (string) ($parsed['host'] ?? '');
                $port = isset($parsed['port']) ? (':' . (string) $parsed['port']) : '';
                $schemeHost = ($scheme !== '' && $host !== '') ? ($scheme . '://' . $host . $port) : '';
              }
              $serviceBase = ($schemeHost !== '' ? $schemeHost : '') . '/' . implode('/', $segments);
              $serviceBase = rtrim($serviceBase, '/');
              $candidates[] = $serviceBase . '/full/max/0/default.jpg';
              $candidates[] = $serviceBase . '/full/full/0/default.jpg';
              $candidates[] = $serviceBase . '/max/full/0/default.jpg';
              $candidates[] = $serviceBase . '/full/max/0/default.png';
              $candidates[] = $serviceBase . '/full/pct:100/0/default.jpg';
              $candidates[] = $serviceBase . '/full/pct:100/0/color.jpg';
              if ($width > 0) {
                $candidates[] = $serviceBase . '/full/' . $width . ',/0/default.jpg';
                $candidates[] = $serviceBase . '/full/!' . $width . ',' . ($height > 0 ? $height : $width) . '/0/default.jpg';
              }
              if ($height > 0) {
                $candidates[] = $serviceBase . '/full/,' . $height . '/0/default.jpg';
              }
            }
          }
        }
      }
    }
    // Deduplicate while preserving order.
    $seen = [];
    $unique = [];
    foreach ($candidates as $u) {
      if (!isset($seen[$u])) {
        $seen[$u] = TRUE;
        $unique[] = $u;
      }
    }
    return $unique;
  }

  /**
   * Guess file extension from URL path or Content-Type header.
   */
  private function guessImageExtension(string $url, $contentTypeHeader): string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') {
      $ct = '';
      try {
        $ct = (string) ($contentTypeHeader ? $contentTypeHeader->getFieldValue() : '');
      }
      catch (\Throwable $e) {
        $ct = '';
      }
      if (strpos($ct, 'image/jpeg') !== FALSE) {
        return '.jpg';
      }
      if (strpos($ct, 'image/jp2') !== FALSE) {
        return '.jp2';
      }
      if (strpos($ct, 'image/png') !== FALSE) {
        return '.png';
      }
      if (strpos($ct, 'image/tiff') !== FALSE || strpos($ct, 'image/tif') !== FALSE) {
        return '.tif';
      }
      return '.img';
    }
    // Normalize common extensions.
    if ($ext === 'jpeg') {
      return '.jpg';
    }
    if ($ext === 'tiff') {
      return '.tif';
    }
    return '.' . $ext;
  }

  /**
   * Sanitize a filename component.
   */
  private function sanitizeFilename(string $name): string {
    $name = trim($name);
    if ($name === '') {
      return 'file';
    }
    $replMap = [
      "\\" => '_',
      "/" => '_',
      ":" => '_',
      "*" => '_',
      "?" => '_',
      '"' => '_',
      "<" => '_',
      ">" => '_',
      "|" => '_',
    ];
    return strtr($name, $replMap);
  }

  /**
   * Build a JSON error response with http status code.
   */
  private function jsonError(int $status, string $message): HttpResponse {
    $response = new HttpResponse();
    $response->setStatusCode($status);
    $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
    // Provide a trace header for easier debugging even on error responses.
    $response->getHeaders()->addHeaderLine('X-Zip-Trace', 'ZipController:jsonError');
    $response->getHeaders()->addHeaderLine('X-Zip-Error', $message);
    $response->setContent(json_encode([
      'error' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $response;
  }

}
