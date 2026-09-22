#!/bin/bash
# The script is to run two ead migration groups in sequence via cron every other day at midnight.
#   1. aspace_ead_migration //migrate all derivative repositories configurated on ui
#   2. ead_migration  //migrate media with finingaid file to node
#   3. setup cron: e.g. 0 0 */2 * * /var/local/pitt-islandora/codebase/ead_script/run_aspace_ead_migration.sh

set -uo pipefail

PROJECT_ROOT="/var/local/pitt-islandora"
SERVICE_NAME="drupal" 
LOG_DIR="/var/local/pitt-islandora/codebase/logs/ead_migration"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
AS_LOG_FILE="${LOG_DIR}/aspace_ead_migration_${TIMESTAMP}.log"

if [ ! -d "$LOG_DIR" ]; then
  mkdir -p "$LOG_DIR"
fi

set -e
cd "$PROJECT_ROOT"
set +e

# Execution migration
run_migration_group() {
  local group_name="$1"
  local log_file="${LOG_DIR}/${group_name}_${TIMESTAMP}.log"

  echo "=== ${group_name} migration run started at $(date) ===" >> "$log_file"
  docker compose exec -T "$SERVICE_NAME" drush ms --group="$group_name" >> "$log_file" 2>&1
  docker compose exec -T "$SERVICE_NAME" drush mim --group="$group_name" --continue-on-failure --vvv  >> "$log_file" 2>&1
  local exit_code=$?

  echo "=== ${group_name} run finished at $(date) with exit code ${exit_code} ===" >> "$log_file"

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

exit $EXIT_CODE
