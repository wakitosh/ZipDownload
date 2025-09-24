<?php

declare(strict_types=1);

namespace ZipDownload;

use Omeka\Module\AbstractModule;
use Laminas\Mvc\MvcEvent;
use ZipDownload\Controller\ZipController;

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
    $acl = $services->get('Omeka\\Acl');
    $acl->allow(NULL, [ZipController::class]);
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

}
