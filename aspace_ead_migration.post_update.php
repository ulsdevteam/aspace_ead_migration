<?php

/**
 * @file
 * Post-update functions for aspace_ead_migration.
 */

/**
 * Splits the old aspace_ead_migration_media map table into per-repository
 * derivative map tables
 */
function aspace_ead_migration_post_update_split_map_table_by_repo(array &$sandbox) {
  $database = \Drupal::database();
  $old_table = 'migrate_map_aspace_ead_migration_media';

  if (!$database->schema()->tableExists($old_table)) {
    return t('Old migration map table @table not found — nothing to split.', [
      '@table' => $old_table,
    ]);
  }

  // Step 1: Get distinct sourceid1 values and group by repository ID,
  // parsed from paths like "/repositories/10/resources/290".
  $sourceid1_values = $database->select($old_table, 'm')
    ->fields('m', ['sourceid1'])
    ->execute()
    ->fetchCol();

  $repo_ids = [];
  foreach ($sourceid1_values as $sourceid1) {
    if (preg_match('#^/repositories/(\d+)/#', $sourceid1, $matches)) {
      $repo_ids[$matches[1]] = TRUE;
    }
    else {
      \Drupal::logger('aspace_ead_migration')->warning(
        'Could not parse repository ID from sourceid1: @id',
        ['@id' => $sourceid1]
      );
    }
  }

  if (empty($repo_ids)) {
    return t('No parsable repository IDs found in @table — nothing to split.', [
      '@table' => $old_table,
    ]);
  }

  //get repo key array
  $repo_ids = array_keys($repo_ids);
  $migration_plugin_manager = \Drupal::service('plugin.manager.migration');
  $base_id = 'aspace_ead_migration_media';

  $summary = [];

  foreach ($repo_ids as $repo_id) {
    $derivative_id = $base_id . ':' . $repo_id;

    // Step 2: Resolve the derivative migration and its REAL map table name
    // via the API — never hardcode/guess the sanitized table name string.
    try {
      $migration = $migration_plugin_manager->createInstance($derivative_id);
    }
    catch (\Exception $e) {
      \Drupal::logger('aspace_ead_migration')->error(
        'Could not instantiate derivative migration @id: @msg',
        ['@id' => $derivative_id, '@msg' => $e->getMessage()]
      );
      continue;
    }

    if (!$migration) {
      \Drupal::logger('aspace_ead_migration')->error(
        'Derivative migration @id was not found — is the deriver configured for repo @repo?',
        ['@id' => $derivative_id, '@repo' => $repo_id]
      );
      continue;
    }

    $id_map = $migration->getIdMap();
    $new_table = $id_map->mapTableName();

    //step 3. Trigger table creation using a dummy lookup key.
    $id_map->getRowBySource(['sourceid1' => '__ensure_table_exists__']);

    if (!$database->schema()->tableExists($new_table)) {
      \Drupal::logger('aspace_ead_migration')->error(
        'Failed to create map table @table for repository @repo.',
        ['@table' => $new_table, '@repo' => $repo_id]
      );
      continue;
    }

    // Step 4: skip repos already populated
    $existing_count = (int) $database->select($new_table, 'm')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($existing_count > 0) {
      \Drupal::logger('aspace_ead_migration')->notice(
        'Map table @table already has @count row(s) — skipping repository @repo.',
        ['@table' => $new_table, '@count' => $existing_count, '@repo' => $repo_id]
      );
      $summary[] = "$derivative_id (already populated, $existing_count rows)";
      continue;
    }

    // Step 5: Copy matching rows via INSERT ... SELECT, scoped by the
    // repository's sourceid1 prefix.
    $prefix = '/repositories/' . $repo_id . '/';

    $select = $database->select($old_table, 'old')
      ->fields('old')
      ->condition('old.sourceid1', $database->escapeLike($prefix) . '%', 'LIKE');

    $database->insert($new_table)
      ->from($select)
      ->execute();

    $copied_count = (int) $database->select($new_table, 'm')
      ->countQuery()
      ->execute()
      ->fetchField();

    \Drupal::logger('aspace_ead_migration')->notice(
      'Copied @count row(s) from @old into @new for repository @repo.',
      ['@count' => $copied_count, '@old' => $old_table, '@new' => $new_table, '@repo' => $repo_id]
    );

    $summary[] = "$derivative_id ($copied_count rows)";
  }

  return t('Map table split complete: @summary', [
    '@summary' => implode('; ', $summary),
  ]);
}
