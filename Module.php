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
  }

}
