# ArchivesSpace EAD Migration

This module is designed to migrate ArchivesSpace EAD into drupal media entities.

## Installation and Configuration
1. Install module 'aspace ead migration'
    - Install via composer (`composer require drupal/aspace_ead_migration`)
2. Enable the module via drush or Drupal site
    -  via drush: `drush en -y aspace_ead_migration`
    -  via Drupal site: Go to Extend/Install new module, locate custom module 'locate 'ASpace EAD Migration' and install.
    -   Confirm modules status (`drush pml --type=module --status=enabled | grep migrate_plus`) 
3. Configurate Module Settings
   ASpace ead migration uses ArchivesSpace API endpoint, which must be configured with your ArchivesSpace URL, username, and password. Please visit  `/admin/configuration/ASpace EAD migration configuration settings` in your Drupal site to configure these settings before migration.

## Migration 
1. Use drush to execute migration. Add --limit or --update to limit data migration record or process a migration data updates
   -    `drush mim aspace_ead_migration_media --limit=10`
