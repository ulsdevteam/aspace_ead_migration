<?php

namespace Drupal\aspace_ead_migration\Plugin\migrate\source;

use Drupal\aspace_ead_migration\ArchivesSpaceSession;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\field\Entity\FieldConfig;

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
  protected ?ArchivesSpaceSession $session = null;
  protected ?string $sessionError = null;
  protected FileSystemInterface $fileSystem;
  protected $request_retry;

  protected string $apiBaseUrl;
  protected int $repoId;
  protected string $eadXmlDir;
  protected $configuredStatus;

  protected $pageSize = 250;

  /**
   * Base private URI used when saving EAD XML files.
   */
  const SAVE_BASE_URI = 'private://findingaid';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);

    if (isset($configuration['constants']['bundle_type'])) {
      $this->eadXmlDir = $this->getMediaFileDir($configuration['constants']['bundle_type']); 
    } else {
      $this->eadXmlDir = self::SAVE_BASE_URI;
    }

    //retrieve findingaid status value(s) from yml
    $conf_status = $this->configuration['findingaid_status'] ?? NULL;
    // Normalize: allow either a single or mulitple status values defined
    if ($conf_status !== NULL && !is_array($conf_status)) {
      $conf_status = [$conf_status];
      }
    $this->configuredStatus = $conf_status;

    $configs = \Drupal::service('config.factory')->get('aspace_ead_migration.settings');
    if (! empty($configs->get('archivesspace_base_uri') )) {
      $this->apiBaseUrl = rtrim($configs->get('archivesspace_base_uri'), '/');
    }
    
    $username = $configs->get('archivesspace_username');
    $password = $configs->get('archivesspace_password');
    $this->repoId = (int) ($configuration['repo_id'] ?? 0);
    try {
    	$this->session = ArchivesSpaceSession::withConnectionInfo(
        $this->apiBaseUrl, $username, $password
      );
    }
    catch (\Throwable $e) {
      $this->session = null;
      $this->sessionError = $e->getMessage();
      \Drupal::logger('aspace_ead_migration')->error(
        'Could not initialize ArchivesSpace session: @msg',
        ['@msg' => $e->getMessage()],
      );
    }
    $this->fileSystem = \Drupal::service('file_system');
    $this->entityTypeManager = \Drupal::entityTypeManager();

    $this->request_retry = [
		'retries_num' => $this->configuration['max_retires'] ?? 3,
		'delay' => $this->configuration['delay'] ?? 5  
	];
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator(): \ArrayIterator {
    $apiBaseUrl = $this->apiBaseUrl;
    $repo_id = $this->repoId;
    $eadXmlDir = $this->eadXmlDir;
    if ($this->sessionError !== null) {
      throw new MigrateException('ASpace EAD Migration: could not connect to ArchivesSpace: ' . $this->sessionError);
    }
    if (empty($apiBaseUrl)) {
      throw new MigrateException('ASpace EAD Migration: API base url is not configured.');
    }
    
    // Prepare ead directory                                                                                               
    if (!$this->fileSystem->prepareDirectory($eadXmlDir, FileSystemInterface::CREATE_DIRECTORY)) {
      \Drupal::logger('aspace_ead_migration')->error('Failed to prepare destination directory: @dir', ['@dir' => $eadXmlDir],);
    } 
  
    // Accumulate all rows from all repositories
    $rows = [];
    try {
      // Fetch all paginated resource per repository filter by last importing
      $last_import = $this->getHighWater() ?? 0;
      $xml_results = $this->fetchEAD($repo_id, $last_import);
      if (empty($xml_results)) {
          \Drupal::logger('aspace_ead_migration')->info('No EAD returned for repository @id.', ['@id' => $repo_id],); 
      } 
      else {
        foreach ($xml_results as $item) {
          $filename = basename($item['xml_path']); //.xml
          $rows[] = [
            'file_uri'       => $item['file_uri'],        // migration key
            'file_name'      => $filename,
            'file_mime'      => 'application/xml',
            'repo_id'        => $repo_id,
            'xml_path'       => $item['xml_path'],
            'save_dir'       => $eadXmlDir,
            'system_modified' => $item['system_modified'],
          ];
        }
      }
    }
    catch (\Throwable $e) {
        \Drupal::logger('aspace_ead_migration')->error(
          'Repository @id — could not fetch resource: @msg',
          ['@id' => $repo_id, '@msg' => $e->getMessage()],
        );
    }
    \Drupal::logger('aspace_ead_migration')->info(
      'ASpace Source (repository @id) built @count migration rows.',['@id' => $repo_id, '@count' => count($rows)],);
    
    $modified = array_column($rows, 'system_modified');
    //Sort all $rows with high water field before process
    array_multisort($modified, SORT_ASC, SORT_NUMERIC, $rows);
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
      'file_uri'      => $this->t('Unique ID'), //resource unique id e.g./repositories/16/resource/1129
      'file_name'     => $this->t('Filename of the saved XML file, e.g. "1.xml"'),
      'file_mime'     => $this->t('MIME type — always "application/xml"'),
      'repo_id'       => $this->t('Source repository ID'),
      'xml_path'      => $this->t('Source EAD file path'), //apiPath to fetch EAD e.g./repositories/16/resource_descriptions/1129.xml
      'save_dir'      => $this->t('EAD directory the process plugin should save into'),
      'system_modified' => $this->t('resource last updated timestamp'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'ASpace EAD XML Source';
  }

  /**
  * Retrieve Media File directory from File field 
  */
  protected function getMediaFileDir(string $bundletype) {
    //Load the fieldConfig Object
    $field_instance = FieldConfig::load('media.findingaid.field_media_file');
    if ($field_instance) {
       //Get the URI scheme (e.g., 'public', 'private', s3).
      $file_storage = \Drupal::service('config.factory')->get('field.storage.media.field_media_file');
      $uploadDestination = $file_storage->get('settings.uri_scheme') ?? 'private';
       
      $fileDirectory = $field_instance->getSetting('file_directory') ?? 'findingaid';
      $fileDirectory = trim($fileDirectory, '/');

      return $uploadDestination . '://' . $fileDirectory;
    } else {
      \Drupal::logger('apace_ead_migration')->warning('Field Media File not configured.');
      return self::SAVE_BASE_URI;
    }
  }
  /**
   * Retrieve EAD files from API per ASpace repository
   * @param int    $repo_id: repository ID
   * @return int   Total number of files reported by the API.
   */
   protected function fetchEAD(int $repo_id, int $last_import): array {
    if (!$this->session) {
      \Drupal::logger('aspace_ead_migration')->error('ASpace session unavailable; skipping repository @id.', ['@id' => $repo_id]);
      return [];
    }
    $count_per_repo = 0;
    $current_page = 1;
    $all_eads = [];
    $assoc_aq = [];
    //convert $last_import timestamp to UTC for advance query
    if ($last_import !==0 ) {
      $last_import_date = date('Y-m-d', strtotime('-1 day', $last_import));
      $assoc_aq = [
        "jsonmodel_type" => "advanced_query",
        "query" => [
          "jsonmodel_type" => "date_field_query",
          "negated" => false,
          "comparator" => "greater_than",
          "field" => "system_mtime",
          "value" => $last_import_date,
          "precision" => "day"
        ],
      ];
    }

    do {
      $parameters = [
          'page' => $current_page,
          'page_size' => $this->pageSize,
          'type[]' =>'resource',
          'sort' => 'system_mtime asc'
        ];

      if ($assoc_aq) {
        $parameters ["aq"]= json_encode($assoc_aq);
        }
      
      // Fetch resources in order from the repository via searchAPI
      $response = null;
      for ($try =0; $try <= $this->request_retry['retries_num']; $try++) {
        	try {
              $response = $this->session->request('GET', '/repositories/'. $repo_id . '/search', $parameters);
              break;
          }
          catch(RequestException $e) {
            \Drupal::logger('aspace_ead_migration')->warning('Connection ASpace API error on attempt @attempt/@max: @message',
             ['@attempt' => $try,
              '@max'     => $this->request_retry['retries_num'],
              '@err'=>$e->getMessage()]);
          }
          if ($try < $this->request_retry['retries_num']) {
              \Drupal::logger('aspace_ead_migration')->info(
                'Retrying ID @id in @delay seconds...',
              ['@id' => $repo_id, '@delay' => $this->request_retry['delay']]);
              sleep($this->request_retry['delay']);
          }
      } 
      if (empty($response)) {
          \Drupal::logger('aspace_ead_migration')->warning('No EAD returned for repository @id at @page.',
            ['@id' => $repo_id, '@page' => $current_page]);
	  $current_page++;
          continue;
      }

      $pagination =[
      'first_page' => (int) ($response['first_page'] ?? 1),
      'last_page'  => (int) ($response['last_page']),
      'this_page'  => (int) ($response['this_page']),
      'total'      => (int) ($response['total_hits']),
      ];

      // Iterate all resources in repository per page
      foreach ($response['results'] as $item) {
         $item_ead = [];

        if ($item['publish']) {
         //parse json in response
         $data = json_decode($item['json'], true);
         if ((isset($data['is_finding_aid_status_published']) && $data['is_finding_aid_status_published'])
             && ($this->configuredStatus === NULL 
                || (isset($data['finding_aid_status']) && in_array($data['finding_aid_status'], $this->configuredStatus, TRUE))))
         {
          // Retrieve URI for later Media title usage
          $item_ead['file_uri'] = $item['uri'];

          //Get resource last updated timestamp
          $item_ead['system_modified'] = strtotime($item['system_mtime']);
         
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
         } else {
	\Drupal::logger('aspace_ead_migration')->info('Skip processing findingaid: @itemid. Check publish status.', ['@itemid' => $item['id'] ]); 
         }
      } else {
        \Drupal::logger('aspace_ead_migration')->info('Skip processing unpublished resource: @title', ['@title' => $item['title'] ]);
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
   *  @return int Total source row count
   */
  public function count($refresh = FALSE): int {
    if ($this->sessionError !== null || empty($this->apiBaseUrl) || empty($this->repoId)) {
      return 0;
    }
    return $this->fetchRepositoryTotal($this->repoId);
  }
 
  /**
   * Fetch 'total' per ASpace repository
   * @param int    $repo_id: repository ID
   * @return int   Total number of files reported by the API.
   */
  protected function fetchRepositoryTotal(int $repo_id): int {
    if (!$this->session) {
      return 0;
    }
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
