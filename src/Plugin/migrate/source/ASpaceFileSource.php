<?php

namespace Drupal\aspace_ead_migration\Plugin\migrate\source;

use Drupal\aspace_ead_migration\ArchivesSpaceSession;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Source plugin: fetches XML files from ASpace API per repository.
 *
 * @MigrateSource(
 *   id = "aspace_file_source",
 *   source_module = "aspace_ead_migration"
 * )
 */
class ASpaceFileSource extends SourcePluginBase {


  protected EntityTypeManagerInterface $entityTypeManager;
  protected ArchivesSpaceSession $session;
  protected FileSystemInterface $fileSystem;

  protected string $apiBaseUrl;
  protected array $repoIds;
  protected string $eadXmlDir;

  protected $pageSize = 250;

  /**
   * Base public:// URI used when saving downloaded XML files.
   */
  const SAVE_BASE_URI = 'private://findingaid';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);

    if (isset($configuration['constants']['aspace_ead_dir'])) {
      $this->eadXmlDir = $configuration['constants']['aspace_ead_dir'] ?? self::SAVE_BASE_URI;
    }
    $configs = \Drupal::service('config.factory')->get('aspace_ead_migration.settings');
    if (! empty($configs->get('archivesspace_base_uri') )) {
      $this->apiBaseUrl    = rtrim($configs->get('archivesspace_base_uri'), '/');
    }
    
    $username = $configs->get('archivesspace_username');
    $password = $configs->get('archivesspace_password');
    $this->repoIds = array_map('intval', $configs->get('archivesspace_repository_ids') ?? []);
    $this->session = ArchivesSpaceSession::withConnectionInfo(
          $this->apiBaseUrl, $username, $password
        );
    $this->fileSystem = \Drupal::service('file_system');
    $this->entityTypeManager = \Drupal::entityTypeManager();
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator(): \ArrayIterator {
    $api_base_url = $this->apiBaseUrl;
    $repo_ids = $this->repoIds;
    $save_dir = $this->eadXmlDir;

    if (empty($api_base_url)) {
      throw new MigrateException('ASpace EAD Migration: api_base_url is not configured.');
    }
    if (empty($repo_ids)) {
      throw new MigrateException('ASpace EAD Migration: No ASpace repositories are configured.');
    }

    // Prepare ead directory                                                                                               
    $this->fileSystem->prepareDirectory($save_dir, FileSystemInterface::CREATE_DIRECTORY); 

    // Accumulate all rows from all repositories
    $rows = [];
    foreach ($repo_ids as $repo_id) {
      try {
        // Fetch all paginated resource per repository
        $xml_results = $this->fetchEAD($repo_id);
        if (empty($xml_results)) {
          \Drupal::logger('aspace_ead_migration')->info('No EAD returned for repository @id.', ['@id' => $repo_id],); 
          continue;
        }

        foreach ($xml_results as $item) {
          $filename = basename($item['xml_path']); //.xml
          $rows[] = [
            'file_uri'       => $item['file_uri'],        // migration key
            'file_name'      => $filename,
            'file_mime'      => 'application/xml',
            'repo_id'        => $repo_id,
            'xml_path'       => $item['xml_path'],
            'save_dir'       => $save_dir,
          ];
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('aspace_ead_migration')->error(
          'Skipping repository @id — could not fetch resource: @msg',
          ['@id' => $repo_id, '@msg' => $e->getMessage()],
        );
      }
    }

    \Drupal::logger('aspace_ead_migration')->info(
      'ASpace Source built @count migration rows.',['@count' => count($rows)],);

    return new \ArrayIterator($rows);
  }


  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return [
      'file_uri' => ['type' => 'string', 'alias' => 'f'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'file_uri'       => $this->t('Unique ID'),
      'file_name'     => $this->t('Filename of the saved XML file, e.g. "1.xml"'),
      'file_mime'     => $this->t('MIME type — always "application/xml"'),
      'repo_id'       => $this->t('Source repository ID'),
      'xml_path'      => $this->t('Source EAD file path'), 
      'save_dir'      => $this->t('EAD directory the process plugin should save into'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'ASpace EAD XML Source';
  }

  /**
   * Retrieve EAD files from API per ASpace repository
   */
  
   protected function fetchEAD(string $repo_id): array {
    $count_per_repo = 0;
    $current_page = 1;
    $all_eads=[];

    do {
      $parameters = [
        'page' => $current_page,
        'page_size' => $this->pageSize,
      ];
      
      // Fetch resources in the repository
       $response = $this->session->request('GET', '/repositories/'. $repo_id . '/resources', $parameters);

      if (empty($response)) {
        \Drupal::logger('aspace_ead_migration')->warning('No EAD returned for repository @id.',
          ['@id' => $repo_id],
        );
        continue;
      }

      $pagination =[
      'first_page' => (int) ($response['first_page'] ?? 1),
      'last_page'  => (int) ($response['last_page']),
      'this_page'  => (int) ($response['this_page']),
      'total'      => (int) ($response['total']),
      ];

      // Iterate all resources in repository per page
      foreach ($response['results'] as $item) {
         $item_ead = [];
         if (($item['publish']) and ($item['is_finding_aid_status_published'])) {
          // Retrieve URI for later Media title usage
          $item_ead['file_uri'] = $item['uri'];
         
          // Build EAD xmlpath to fetch from ASpace api
          $resourceId =  substr(strrchr($item['uri'], "/"), 1);
          $xml_path = '/repositories/'. $repo_id . '/resource_descriptions/'. $resourceId .'.xml';
          try {
            //Store file data for the media entity's file field.
            $item_ead['xml_path'] = $xml_path;
            //print_r($item_ead);
           
            $count_per_repo++;
            //All accumulated eads
            array_push($all_eads, $item_ead); 
            }
            catch (MigrateException $e) {
             \Drupal::logger('aspace_ead_migration')->error(
              'Skipping @url (repository @id): @msg',
              ['@url' => $xml_path, '@id' => $repo_id, '@msg' => $e->getMessage()],
              );
            }
        }
     } //end resource interation

     \Drupal::logger('aspace_ead_migration')->info(
        'Repository @id page @this/@last — @count migration rows on this page, current total migration count: @total',
        [
          '@id'    => $repo_id,
          '@this'  => $pagination['this_page'],
          '@last'  => $pagination['last_page'],
          '@count' => $count_per_repo,
          '@total' => count($all_eads),
        ], 
      );

      $current_page++;
    } while ($pagination['this_page'] < $pagination['last_page']);

    \Drupal::logger('aspace_ead_migration')->info(
      'Repsitory @id :Total Resource count: @total_resource, EAD migration count: @total_migrated.',
      [
        '@id'    => $repo_id,
        '@total_resource' => $pagination['total'],
        '@total_migrated' => count($all_eads),
      ],
    );

    return $all_eads;
   }
  
   /**
   * {@inheritdoc}
   *
   * Returns the total source row count WITHOUT downloading any XML files.
   */
  public function count($refresh = FALSE): int {
    if (empty($this->apiBaseUrl) || empty($this->repoIds)) {
      return 0;
    }
    $total = 0;

    foreach ($this->repoIds as $repo_id) {
      $total += $this->fetchRepositoryTotal($repo_id);
    }
    return $total;
  }
 
  /**
   * Fetch 'total' per ASpace repository
   *
   * @param string $base_api_url 
   * @param int    $repository_id Used in log messages.
   * @return int   Total number of files reported by the API.
   */
  protected function fetchRepositoryTotal(int $repo_id): int {
    $parameters = [
        'page' => 1,
        'page_size' => $this->pageSize,
      ];
    try {
      // Request first page
      $response = $this->session->request('GET', '/repositories/'. $repo_id . '/resources', $parameters);
      return (int) ($response['total']);
    }
    catch (RequestException $e)  {
      \Drupal::logger('aspace_ead_migration')->warning(
        'Could not fetch total for repository@id: @msg',
        ['@id' => $repo_id, '@msg' => $e->getMessage()],
      );
      return 0;
    }
  }

}
