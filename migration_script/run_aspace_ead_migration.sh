#!/bin/bash
# The script is to run two ead migration groups in sequence via cron every other day at midnight.
#   1. aspace_ead_migration //migrate all derivative repositories configurated on ui
#   2. ead_migration  //migrate media with finingaid file to node

set -uo pipefail

PROJECT_ROOT="/opt/bd-islandora"
SERVICE_NAME="drupal" 

[ -d "$PROJECT_ROOT" ] && cd "$PROJECT_ROOT" || { echo "Failed to cd project root directory $PROJECT_ROOT"; exit 1; }

# Get the migration IDs for drush mrs usage
get_migration_ids() {
  local group_name="$1"
  docker compose exec -T "$SERVICE_NAME" with-contenv bash -lc "drush migrate:status --group=${group_name} --field=id"
}

# Reset the migration if a previous run died mid-import leaves 'importing' status 
reset_migration() {
  local group_name="$1"
  local ids_output
  local migration_ids=()
  local migration_id

  echo "--Resetting migration status for group: ${group_name}"

  if ! ids_output=$(get_migration_ids "$group_name"); then
    echo "--Failed to list migration IDs for group: ${group_name}"
    return 1
  fi

  # get IDs removing leading/trailing spaces and tab
  while IFS= read -r migration_id; do
    migration_id="${migration_id//$'\r'/}"
    [ -n "$migration_id" ] && migration_ids+=("$migration_id")
  done <<< "$ids_output"

  if [ "${#migration_ids[@]}" -eq 0 ]; then
    echo "--No migrations found for group: ${group_name}"
    return 1
  fi

  for migration_id in "${migration_ids[@]}"; do
    echo "--Resetting migration: ${migration_id}"
    if ! docker compose exec -T "$SERVICE_NAME" with-contenv bash -lc "drush mrs ${migration_id}"; then
      echo "--Failed to reset migration: ${migration_id}"
      return 1
    fi
  done

  return 0
}

# Execution migration
run_migration_group() {
  local group_name="$1"

  echo "=== ${group_name} migration run started at $(date) ===" 

  if ! reset_migration "$group_name"; then
    echo "=== ${group_name} reset failed at $(date), exiting without running import ==="
    exit 1
  fi
  docker compose exec -T "$SERVICE_NAME" with-contenv bash -lc "drush mim --group=${group_name} --continue-on-failure"  
  local exit_code=$?

  echo "=== ${group_name} run finished at $(date) with exit code ${exit_code} ===" 

  return $exit_code
}

# Main
run_migration_group "aspace_ead_migration"
ASPACE_EXIT_CODE=$?

run_migration_group "ead_migration"
OTHER_EXIT_CODE=$?


set -e   # enable strict mode if migration failed
if [ "$ASPACE_EXIT_CODE" -ne 0 ] || [ "$OTHER_EXIT_CODE" -ne 0 ]; then
  echo "One or more migration groups failed: aspace_ead_migration=${ASPACE_EXIT_CODE}, ead_migration=${OTHER_EXIT_CODE}"
  exit 1
fi

exit 0
