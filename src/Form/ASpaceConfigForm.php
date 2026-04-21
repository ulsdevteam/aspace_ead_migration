<?php

namespace Drupal\aspace_ead_migration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\FileStorage;

/**
 * Configure ArchivesSpace API settings
 */
class ASpaceConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aspace_ead_migration_config';
  }

/**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['aspace_ead_migration.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $config = $this->config('aspace_ead_migration.settings');
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('ArchivesSpace API Connection Settings'),
      '#open' => TRUE,
    ];

    $form['connection']['archivesspace_base_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The URL of ArchivesSpace API endpoint'),
      '#required' => TRUE,
      '#config_target' => 'aspace_ead_migration.settings:archivesspace_base_uri',
    ];

    $form['connection']['archivesspace_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#config_target' => 'aspace_ead_migration.settings:archivesspace_username', 
    ];

    $form['connection']['archivesspace_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#config_target' => 'aspace_ead_migration.settings:archivesspace_password',
      '#description'   => $this->t('Leave blank if no password stored.'),
      '#attributes'    => $config->get('archivesspace_password')
                          ? ['placeholder' => $this->t('(password has already stored)')]
                          : [],
    ];
    
    // Stored as an array of integers; display as a comma-separated string.
    $repo_ids  = $config->get('archivesspace_repository_ids') ?? [];
    $ids_default = implode(', ', $repo_ids);

    $form['connection']['archivesspace_repository_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository IDs'),
      '#description' => $this->t(
        'Comma-separated list of numeric repository IDs to migrate. ' . 'Example: 1, 3, 5'
      ),
      '#required' => TRUE,
      '#default_value' => $ids_default,
    ];
    return parent::buildForm($form, $form_state);
  }

   /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    //retrieve integer repository IDs
    $repo_ids = $this->parseRepoIds($form_state->getValue('archivesspace_repository_ids'));
    $int_ids = array_map('intval', $repo_ids);

    $this->config('aspace_ead_migration.settings')
      ->set('archivesspace_repository_ids', array_values($int_ids))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Parses a comma-separated string of IDs into a trimmed array.
   * @param string user input string.
   * @return array repository IDs array
   */
  private function parseRepoIds(string $s_input): array {
    $s_in = explode(',', $s_input);
    $s_in = array_map('trim', $s_in);
    return array_filter($s_in, fn($v) => $v !== '');
  }

}
