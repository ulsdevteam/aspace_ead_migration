<?php

namespace Drupal\aspace_ead_migration\Plugin\migrate\process;

use Drupal\aspace_ead_migration\ArchivesSpaceSession;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Downloads, saves, and parses one XML file during migration row processing.
 *
 * @MigrateProcessPlugin(
 *   id = "get_xml_file"
 * )
 */
class GetXmlFile extends ProcessPluginBase implements ContainerFactoryPluginInterface 
{
  protected ArchivesSpaceSession $session;
  protected string $apiBaseUrl;
  protected string $username;
  protected string $password;
  /**
   * File repository service.
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * Entity type manager for checking existing File entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  const SAVE_BASE_URI = 'private://findingaid';
  const EAD_PARAMETERS = [
      		'include_unpublished' => "False",
      		'include_daos' => "True",
          'include_uris' => "True",
          'ead3'=> "False",
    ];

  /**
   * Constructs a GetXmlFile process plugin.
   */
  public function __construct(
    array $configuration, 
    $plugin_id, 
    $plugin_definition, 
    ConfigFactoryInterface $configFactory,
    FileRepositoryInterface $file_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    
    $configs = $configFactory->get('aspace_ead_migration.settings');
    if (! empty($configs->get('archivesspace_base_uri'))) {
      $this->apiBaseUrl = rtrim($configs->get('archivesspace_base_uri'), '/');
    }
    $this->username = $configs->get('archivesspace_username');
    $this->password = $configs->get('archivesspace_password');
    $this->session = ArchivesSpaceSession::withConnectionInfo(
          $this->apiBaseUrl, $this->username, $this->password
        );
    $this->fileRepository  = $file_repository;
  }

   /**
   * {@inheritdoc}
   * Get services from container and pass to construct.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('file.repository')
    );
  }

  // ---------------------------------------------------------------------------
  // ProcessPluginBase
  // ---------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   * Called once per row by drupal migration process
   */
  public function transform(
    $value,
    MigrateExecutableInterface $migrate_executable,
    Row $row,
    $destination,
  ): int {
    // Read all required values from the source row.
   [
      $file_uri,
      $file_name,
      $file_mime,
      $repo_id,
      $xml_path,
      $save_dir,
    ] = array_values((array) $value) + ['', '', '', 0, '',''];

    if (empty($xml_path) || empty($file_name)) {
      throw new MigrateException(sprintf(
        'DownloadXmlFile: missing required source properties. '
        . 'xml_path="%s"  filename="%s"', $xml_path, $file_name,));
    }
    try {
      // download the XML content via the authenticated session 
      $ead_xml = $this->session->request('GET', $xml_path, self::EAD_PARAMETERS, FALSE, TRUE);
      $destination_uri = rtrim($save_dir, '/') . '/' . $file_name;
      $file = $this->fileRepository->writeData(
                $ead_xml,
                $destination_uri,
                FileExists::Replace
              );
       return (int) $file->id();
    }
    catch (MigrateException $e) {
             \Drupal::logger('aspace_ead_migration')->error(
              'Skipping @url: @msg',
              ['@url' => $xml_path, '@msg' => $e->getMessage()],
              );
    } 
  }
}

