<?php

namespace Drupal\aspace_ead_migration\Plugin\migrate;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one migration per configured ASpace repository ID.
 *
 * Each derivative gets its own repositoryID, and Highwater Mark
 * value is set per repositoryID.
 */
class ASpaceRepositoryDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $config = $this->configFactory->get('aspace_ead_migration.settings');
    $repo_ids = array_map('intval', $config->get('archivesspace_repository_ids') ?? []);

    if (empty($repo_ids)) {
      // No repos configured — produce no derivatives.
      return $this->derivatives;
    }

    foreach ($repo_ids as $repo_id) {
      $derivative_id = (string) $repo_id;
      $definition = $base_plugin_definition;
      $definition['label'] = $this->t('@label (Repository @id)', [
        '@label' => $base_plugin_definition['label'] ?? $base_plugin_definition['id'],
        '@id' => $repo_id,
      ]);

      //set the repo_id into this derivative's source config.
      $definition['source']['repo_id'] = $repo_id;

      $this->derivatives[$derivative_id] = $definition;
    }
    return $this->derivatives;
  }
}
