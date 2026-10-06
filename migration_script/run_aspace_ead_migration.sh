#!/bin/bash
# The script is to run two ead migration groups in sequence via cron every other day at midnight.
#   1. aspace_ead_migration //migrate all derivative repositories configurated on ui
#   2. ead_migration  //migrate media with finingaid file to node
# Usage:
#   run_aspace_ead_migration.sh [--project-root=/opt/bd-islandora]
# If no project root is given, the default below is used.

set -uo pipefail
PROJECT_ROOT="/opt/bd-islandora"
SERVICE_NAME="drupal" 

usage() {
  echo "Usage: $0 [--project-root=/opt/bd-islandora]"
}

# pass project root path as command line arg
while [ $# -gt 0 ]; do
  case "$1" in
    --project-root=*) PROJECT_ROOT="${1#*=}"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1"; usage; exit 1 ;;
  esac
done


[ -d "$PROJECT_ROOT" ] && cd "$PROJECT_ROOT" || { echo "Failed to cd project root directory $PROJECT_ROOT"; exit 1; }

# Get the migration ID and status for drush mrs usage
get_migration_statuses() {
  local group_name="$1"
  docker-compose exec -T "$SERVICE_NAME" with-contenv bash -lc "drush migrate:status --group=${group_name} --fields=id,status --format=tsv"
}

# Reset the migration if a previous run died mid-import leaves status(Importing, Rolling back or Stopping). 
# Migrations that are Idle or Disabled are left alone.
reset_migration() {
  local group_name="$1"
  local status_output migration_id migration_status
  local found=0

  echo "--Checking migration status for group: ${group_name}"

  if ! status_output=$(get_migration_statuses "$group_name"); then
    echo "--Failed to get migration statuses for group: ${group_name}"
    return 1
  fi

  while IFS=$'\t' read -r migration_id migration_status; do
    migration_id="${migration_id//$'\r'/}"
    migration_status="${migration_status//$'\r'/}"
    [ -n "$migration_id" ] || continue
    found=1

    case "${migration_status,,}" in
      importing|"rolling back"|stopping)
        echo "--Migration ${migration_id} is stuck (status: ${migration_status}), resetting to idle"
        if ! docker-compose exec -T "$SERVICE_NAME" with-contenv bash -lc "drush mrs ${migration_id}"; then
          echo "--Failed to reset migration: ${migration_id}"
          return 1
        fi
        ;;
      *)
        echo "--Migration ${migration_id} status is '${migration_status}', no reset needed"
        ;;
    esac
  done <<< "$status_output"

  if [ "$found" -eq 0 ]; then
    echo "--No migrations found for group: ${group_name}"
    return 1
  fi

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
  docker-compose exec -T "$SERVICE_NAME" with-contenv bash -lc "drush mim --group=${group_name} --continue-on-failure"  
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
