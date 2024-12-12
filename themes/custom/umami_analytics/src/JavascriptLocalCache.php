<?php

namespace Drupal\umami_analytics;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Javascript local cache helper service class.
 */
class JavascriptLocalCache {

  /**
   * The file system service.
   *
   * @var FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The file url generator service.
   *
   * @var FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The configuration factory.
   *
   * @var ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * A logger channel instance for umami_analytics.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The client for sending HTTP requests.
   *
   * @var ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The state system.
   *
   * @var StateInterface
   */
  protected StateInterface $state;

  /**
   * The asset query string service.
   *
   * @var AssetQueryStringInterface
   */
  protected AssetQueryStringInterface $assetQueryString;


  /**
   * The construct.
   */
  public function __construct(ClientInterface $http_client, FileSystemInterface $file_system, FileUrlGeneratorInterface $file_url_generator, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, StateInterface $state, AssetQueryStringInterface $asset_query_string) {
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
    $this->fileUrlGenerator = $file_url_generator;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->assetQueryString = $asset_query_string;
    $this->logger = $logger_factory->get('umami_analytics');
  }

  /**
   * Download/Synchronize/Cache tracking code file locally.
   *
   * @param bool $synchronize
   *   Synchronize to local cache if remote file has changed.
*
   * @return string
   *   The path to the local or remote tracking file.
   */
  public function fetchJavascript(bool $synchronize = FALSE): string {
    $remote_url = $this->configFactory->get('umami_analytics.settings')->get('src');

    // If cache is disabled, just return the URL for Umami Analytics.
    if (!$this->configFactory->get('umami_analytics.settings')->get('local_cache')) {
      return $remote_url;
    }

    $path = 'public://umami_analytics';
    $file_destination = $path . '/umami.js';

    if (!file_exists($file_destination) || $synchronize) {
      // Download the latest tracking code.
      try {
        $data = (string) $this->httpClient
          ->get($remote_url)
          ->getBody();

        if (file_exists($file_destination)) {
          // Synchronize tracking code and replace local file if outdated.
          $data_hash_local = Crypt::hashBase64(file_get_contents($file_destination));
          $data_hash_remote = Crypt::hashBase64($data);
          // Check that the file's directory is writable.
          if ($data_hash_local != $data_hash_remote && $this->fileSystem->prepareDirectory($path)) {
            // Save updated tracking code file to disk.
            $this->fileSystem->saveData($data, $file_destination, FileSystemInterface::EXISTS_REPLACE);
            // Based on Drupal Core class AssetDumper.
            if (extension_loaded('zlib') && $this->configFactory->get('system.performance')->get('js.gzip')) {
              $this->fileSystem->saveData(gzencode($data, 9, FORCE_GZIP), $file_destination . '.gz', FileSystemInterface::EXISTS_REPLACE);
            }
            $this->logger->info('Locally cached tracking code file has been updated.');
            // Change query-strings on css/js files to enforce reload for all
            // users.
            $this->assetQueryString->reset();
          }
        }
        else {
          // Check that the file's directory is writable.
          if ($this->fileSystem->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY)) {
            // There is no need to flush JS here as core refreshes JS caches
            // automatically, if new files are added.
            $this->fileSystem->saveData($data, $file_destination, FileSystemInterface::EXISTS_REPLACE);
            // Based on Drupal Core class AssetDumper.
            if (extension_loaded('zlib') && $this->configFactory->get('system.performance')->get('js.gzip')) {
              $this->fileSystem->saveData(gzencode($data, 9, FORCE_GZIP), $file_destination . '.gz', FileSystemInterface::EXISTS_REPLACE);
            }
            $this->logger->info('Locally cached tracking code file has been saved.');
          }
        }
      }
      catch (GuzzleException $exception) {
        Error::logException($this->logger, $exception);
        return $remote_url;
      }
    }
    // Return the local JS file path.
    $query_string = '?' . ($this->state->get('system.css_js_query_string') ?: '0');
    return $this->fileUrlGenerator->generateString($file_destination) . $query_string;
  }

  /**
   * Delete cached files and directory.
   */
  public function clearJsCache(): void {
    $path = 'public://umami_analytics';
    if (is_dir($path)) {
      $this->fileSystem->deleteRecursive($path);

      // Change query-strings on css/js files to enforce reload for all users.
      $this->assetQueryString->reset();

      $this->logger->info('Local Umami Analytics file cache has been purged.');
    }
  }

}
