<?php

declare(strict_types=1);

namespace ZipDownload\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;

/**
 * Admin controller for viewing and exporting ZipDownload logs.
 */
class LogsController extends AbstractActionController {
  /**
   * Doctrine DBAL connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  private $conn;

  /**
   * Constructor.
   */
  public function __construct($container) {
    $this->conn = $container->get('Omeka\\Connection');
  }

  /**
   * Paginated logs list.
   */
  public function indexAction() {
    $this->ensureLogsTable();
    $page = max(1, (int) $this->params()->fromQuery('page', 1));
    $perPage = min(100, max(10, (int) $this->params()->fromQuery('per_page', 25)));
    $offset = ($page - 1) * $perPage;
    $filters = $this->getFilters();
    // Prepare a confirm form to provide CSRF token in the view.
    $confirmForm = $this->getForm(ConfirmForm::class);
    $qb = $this->conn->createQueryBuilder();
    $qb->select('*')
      ->from('zipdownload_log')
      ->orderBy('id', 'DESC')
      ->setFirstResult($offset)
      ->setMaxResults($perPage);
    $this->applyFilters($qb, $filters);
    $stmt = method_exists($qb, 'executeQuery') ? $qb->executeQuery() : $qb->execute();
    $rows = method_exists($stmt, 'fetchAllAssociative') ? $stmt->fetchAllAssociative() : $stmt->fetchAll();

    // Count with same filters.
    $qbCount = $this->conn->createQueryBuilder();
    $qbCount->select('COUNT(*) AS c')->from('zipdownload_log');
    $this->applyFilters($qbCount, $filters);
    $stmt2 = method_exists($qbCount, 'executeQuery') ? $qbCount->executeQuery() : $qbCount->execute();
    $rowCount = method_exists($stmt2, 'fetchOne') ? $stmt2->fetchOne() : $stmt2->fetchColumn();
    $total = (int) $rowCount;
    $vm = new ViewModel([
      'rows' => $rows,
      'page' => $page,
      'perPage' => $perPage,
      'total' => $total,
      'filters' => $filters,
      'confirmForm' => $confirmForm,
    ]);
    $vm->setTemplate('zip-download/logs/index');
    return $vm;
  }

  /**
   * Export logs to CSV or TSV.
   */
  public function exportAction() {
    $this->ensureLogsTable();
    $format = strtolower((string) $this->params()->fromQuery('format', 'csv'));
    $sep = ($format === 'tsv') ? "\t" : ',';
    $excelSafe = (bool) $this->params()->fromQuery('excel', 0);
    // Determine system timezone from settings for consistency in naming.
    $tz = 'UTC';
    try {
      $services = $this->getEvent()->getApplication()->getServiceManager();
      if ($services->has('Omeka\\Settings')) {
        $tz = (string) $services->get('Omeka\\Settings')->get('time_zone', 'UTC');
      }
    }
    catch (\Throwable $e) {
      $tz = 'UTC';
    }
    $filters = $this->getFilters();
    // Formatter for epoch seconds -> ISO 8601 extended in system TZ.
    $fmtIso = function ($epoch) use ($tz) {
      $t = (int) $epoch;
      if ($t <= 0) {
        return '';
      }
      try {
        // UTC base.
        $dt = new \DateTime('@' . $t);
        $dt->setTimezone(new \DateTimeZone($tz));
        return $dt->format('Y-m-d\\TH:i:sP');
      }
      catch (\Throwable $e) {
        return '';
      }
    };
    $qb = $this->conn->createQueryBuilder();
    $qb->select('*')->from('zipdownload_log')->orderBy('id', 'DESC');
    $this->applyFilters($qb, $filters);
    $stmt = method_exists($qb, 'executeQuery') ? $qb->executeQuery() : $qb->execute();
    $rows = method_exists($stmt, 'fetchAllAssociative') ? $stmt->fetchAllAssociative() : $stmt->fetchAll();
    $cols = [
      'id', 'started_at', 'finished_at', 'duration_ms', 'status', 'item_id', 'item_title', 'media_ids', 'media_count', 'bytes_total', 'bytes_sent', 'client_ip', 'user_id', 'user_email', 'site_slug', 'progress_token', 'error_message', 'slot_index', 'user_agent',
    ];
    // Build ISO 8601 extended timestamp in system TZ for filename.
    try {
      $now = new \DateTime('now', new \DateTimeZone($tz));
      $stampExt = $now->format('Y-m-d\\TH:i:sP');
    }
    catch (\Throwable $e) {
      $stampExt = date('Y-m-d\\TH:i:sP');
    }
    $ext = ($format === 'tsv') ? 'tsv' : 'csv';
    $base = 'zipdownload_logs_' . $stampExt;
    // RFC 5987 filename* parameter (UTF-8).
    $filenameExt = $base . '.' . $ext;
    // Fallback without colon characters for old agents.
    $filenameSafe = str_replace(':', '-', $base) . '.' . $ext;
    header('Content-Type: text/' . (($format === 'tsv') ? 'tab-separated-values' : 'csv'));
    header('Content-Disposition: attachment; filename="' . $filenameSafe . '"; filename*=UTF-8\'\'' . rawurlencode($filenameExt));
    $out = fopen('php://output', 'w');
    // Add BOM for CSV to help Excel recognize UTF-8.
    if ($format !== 'tsv') {
      fwrite($out, "\xEF\xBB\xBF");
    }
    // Header.
    fputcsv($out, $cols, $sep);
    foreach ($rows as $r) {
      $line = [];
      foreach ($cols as $c) {
        $val = $r[$c] ?? '';
        // Convert timestamps to ISO 8601 extended (system timezone).
        if ($c === 'started_at' || $c === 'finished_at') {
          $val = $fmtIso($val);
        }
        // Normalize media_ids to semicolon-separated
        // for spreadsheet friendliness.
        if ($c === 'media_ids') {
          if (is_array($val)) {
            $ids = array_values(
              array_filter(
                array_map('strval', $val),
                function ($s) {
                  return trim($s) !== '';
                }
              )
            );
          }
          else {
            $parts = explode(',', (string) $val);
            $ids = array_values(
              array_filter(
                array_map('trim', $parts),
                function ($s) {
                  return $s !== '';
                }
              )
            );
          }
          $val = implode(';', $ids);
        }
        if ($excelSafe) {
          // For Excel-friendly export, coerce some numeric-like fields to text.
          if (in_array($c, ['id', 'item_id', 'user_id', 'media_count', 'bytes_total', 'bytes_sent', 'slot_index'], TRUE)) {
            $s = (string) $val;
            if ($s !== '' && preg_match('/^\d+$/', $s)) {
              // Use ="123" to force Excel to keep as text
              // without a visible leading apostrophe.
              $val = '="' . $s . '"';
            }
          }
        }
        $line[] = $val;
      }
      fputcsv($out, $line, $sep);
    }
    fclose($out);
    exit;
  }

  /**
   * Clear logs: either all up to now, or up to specified datetime.
   */
  public function clearAction() {
    $this->ensureLogsTable();
    // Only accept POST for destructive action.
    $req = $this->getRequest();
    $isPost = method_exists($req, 'isPost') ? $req->isPost() : (method_exists($req, 'getMethod') ? strtoupper((string) $req->getMethod()) === 'POST' : FALSE);
    if (!$isPost) {
      return $this->redirect()->toRoute('admin/zip-download-logs');
    }
    // CSRF using generic ConfirmForm, as used across admin destructive actions.
    $form = $this->getForm(ConfirmForm::class);
    $post = $this->params()->fromPost();
    $form->setData($post);
    if (!$form->isValid()) {
      $this->messenger()->addError($this->translate('Security token is invalid.'));
      return $this->redirect()->toRoute('admin/zip-download-logs');
    }

    // Mode can be 'now' or 'before'.
    $mode = trim((string) ($post['mode'] ?? 'now'));
    $before = trim((string) ($post['before_datetime'] ?? ''));
    $cutTs = NULL;
    if ($mode === 'now') {
      $cutTs = time();
    }
    else {
      // Accept HTML5 datetime-local: yyyy-mm-ddThh:mm (system timezone).
      if ($before !== '') {
        try {
          $services = $this->getEvent()->getApplication()->getServiceManager();
          $tz = 'UTC';
          if ($services->has('Omeka\\Settings')) {
            $tz = (string) $services->get('Omeka\\Settings')->get('time_zone', 'UTC');
          }
          $normalized = str_replace('T', ' ', $before);
          $dt = new \DateTime($normalized, new \DateTimeZone($tz));
          // Convert to UTC then get epoch seconds.
          $dt->setTimezone(new \DateTimeZone('UTC'));
          $t = $dt->getTimestamp();
          if ($t && $t > 0) {
            $cutTs = $t;
          }
        }
        catch (\Throwable $e) {
          // Fall through.
        }
      }
      if ($cutTs === NULL) {
        $this->messenger()->addError($this->translate('Please specify a valid date and time.'));
        return $this->redirect()->toRoute('admin/zip-download-logs');
      }
    }

    try {
      $qb = $this->conn->createQueryBuilder();
      $qb->delete('zipdownload_log')->where('started_at <= :cut');
      $qb->setParameter('cut', (int) $cutTs);
      // Optional status narrowing.
      $statuses = $this->params()->fromPost('statuses', []);
      if (is_string($statuses)) {
        $statuses = [$statuses];
      }
      if (is_array($statuses)) {
        $statuses = array_values(array_filter(array_map('strval', $statuses)));
        // Allow only known statuses.
        $allowed = ['running', 'done', 'canceled', 'failed', 'delayed', 'rejected'];
        $statuses = array_values(array_intersect($statuses, $allowed));
        if (!empty($statuses)) {
          // Build an IN (:s0,:s1,...) clause.
          $placeholders = [];
          foreach ($statuses as $i => $val) {
            $ph = ':s' . $i;
            $placeholders[] = $ph;
            $qb->setParameter('s' . $i, $val);
          }
          $qb->andWhere('status IN (' . implode(',', $placeholders) . ')');
        }
      }
      $stmt = method_exists($qb, 'executeStatement') ? $qb->executeStatement() : (method_exists($qb, 'execute') ? $qb->execute() : NULL);
      // Try to compute affected rows if possible.
      $deleted = 0;
      if (is_int($stmt)) {
        $deleted = $stmt;
      }
      elseif (is_object($stmt) && method_exists($stmt, 'rowCount')) {
        $deleted = (int) $stmt->rowCount();
      }
      $this->messenger()->addSuccess(sprintf($this->translate('Deleted %d log(s).'), $deleted));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->translate('Failed to delete logs: ') . $e->getMessage());
    }

    return $this->redirect()->toRoute('admin/zip-download-logs');
  }

  /**
   * Normalize filters from query string.
   */
  private function getFilters(): array {
    $status = trim((string) $this->params()->fromQuery('status', ''));
    $itemId = (int) $this->params()->fromQuery('item_id', 0);
    $userId = (int) $this->params()->fromQuery('user_id', 0);
    $ip = trim((string) $this->params()->fromQuery('client_ip', ''));
    $site = trim((string) $this->params()->fromQuery('site_slug', ''));
    $title = trim((string) $this->params()->fromQuery('item_title', ''));
    $token = trim((string) $this->params()->fromQuery('progress_token', ''));
    $from = trim((string) $this->params()->fromQuery('started_from', ''));
    $to = trim((string) $this->params()->fromQuery('started_to', ''));
    // Parse dates in system timezone.
    $tz = 'UTC';
    try {
      $services = $this->getEvent()->getApplication()->getServiceManager();
      if ($services->has('Omeka\\Settings')) {
        $tz = (string) $services->get('Omeka\\Settings')->get('time_zone', 'UTC');
      }
    }
    catch (\Throwable $e) {
      $tz = 'UTC';
    }
    $fromTs = NULL;
    if ($from !== '') {
      try {
        $d1 = new \DateTime($from . ' 00:00:00', new \DateTimeZone($tz));
        $d1->setTimezone(new \DateTimeZone('UTC'));
        $fromTs = $d1->getTimestamp();
      }
      catch (\Throwable $e) {
        $fromTs = NULL;
      }
    }
    $toTs = NULL;
    if ($to !== '') {
      try {
        $d2 = new \DateTime($to . ' 23:59:59', new \DateTimeZone($tz));
        $d2->setTimezone(new \DateTimeZone('UTC'));
        $toTs = $d2->getTimestamp();
      }
      catch (\Throwable $e) {
        $toTs = NULL;
      }
    }
    return [
      'status' => $status,
      'item_id' => $itemId,
      'user_id' => $userId,
      'client_ip' => $ip,
      'site_slug' => $site,
      'item_title' => $title,
      'progress_token' => $token,
      'started_from' => $fromTs && $fromTs > 0 ? $fromTs : NULL,
      'started_to' => $toTs && $toTs > 0 ? $toTs : NULL,
    ];
  }

  /**
   * Apply filters to a DBAL QueryBuilder.
   */
  private function applyFilters($qb, array $filters): void {
    // No-op; apply simple AND filters.
    if (!empty($filters['status'])) {
      $qb->andWhere('status = :st');
      $qb->setParameter('st', $filters['status']);
    }
    if (!empty($filters['item_id'])) {
      $qb->andWhere('item_id = :iid');
      $qb->setParameter('iid', (int) $filters['item_id']);
    }
    if (!empty($filters['user_id'])) {
      $qb->andWhere('user_id = :uid');
      $qb->setParameter('uid', (int) $filters['user_id']);
    }
    if (!empty($filters['client_ip'])) {
      $qb->andWhere('client_ip = :cip');
      $qb->setParameter('cip', $filters['client_ip']);
    }
    if (!empty($filters['site_slug'])) {
      $qb->andWhere('site_slug = :site');
      $qb->setParameter('site', $filters['site_slug']);
    }
    if (!empty($filters['item_title'])) {
      $qb->andWhere('item_title LIKE :ttl');
      $qb->setParameter('ttl', '%' . $filters['item_title'] . '%');
    }
    if (!empty($filters['progress_token'])) {
      $qb->andWhere('progress_token = :ptk');
      $qb->setParameter('ptk', $filters['progress_token']);
    }
    if (!empty($filters['started_from'])) {
      $qb->andWhere('started_at >= :sf');
      $qb->setParameter('sf', (int) $filters['started_from']);
    }
    if (!empty($filters['started_to'])) {
      $qb->andWhere('started_at <= :st');
      $qb->setParameter('st', (int) $filters['started_to']);
    }
  }

  /**
   * Ensure zipdownload_log table exists.
   */
  private function ensureLogsTable(): void {
    try {
      $ddl = <<<SQL
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
      $this->conn->executeStatement($ddl);
    }
    catch (\Throwable $e) {
      // Ignore; next query may still fail and surface meaningful error.
    }
  }

}
