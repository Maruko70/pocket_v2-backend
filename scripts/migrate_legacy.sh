#!/usr/bin/env bash
# Wrapper for migrate_legacy.ps1 on Windows, or run SQL steps on Unix with mysql client.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

usage() {
  cat <<'EOF'
Usage: migrate_legacy.sh [options]

Required:
  --sql PATH              Legacy dump .sql file
  --staging NAME          Staging database (default: pocket_legacy_stage)
  --target NAME           Target database (default: pocket_v2)
  --mode merge|fresh      merge = fill missing only; fresh = full import

Optional:
  --host HOST             (default: 127.0.0.1)
  --port PORT             (default: 3306)
  --user USER             (default: root)
  --password PASS
  --mysql PATH            mysql client binary
  --no-drop-staging       Keep existing staging DB
  --skip-import           Staging already loaded
  --drop-target           Fresh only: recreate target DB
  --no-migrate            Fresh only: skip artisan migrate
  --no-verify             Skip verification queries
  --dry-run               Print steps only

Examples:
  ./migrate_legacy.sh --sql ../alnahdag_pocket.sql --staging pocket_legacy_stage --target pocket_v2 --mode merge
  ./migrate_legacy.sh --sql ../legacy.sql --target pocket_v2_import --mode fresh --drop-target
EOF
}

LEGACY_SQL=""
STAGING_DB="pocket_legacy_stage"
TARGET_DB="pocket_v2"
MODE="merge"
MYSQL_HOST="127.0.0.1"
MYSQL_PORT="3306"
MYSQL_USER="root"
MYSQL_PASSWORD=""
MYSQL_BIN=""
DROP_STAGING=1
SKIP_IMPORT=0
DROP_TARGET=0
RUN_MIGRATIONS=0
SKIP_VERIFY=0
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --sql) LEGACY_SQL="$2"; shift 2 ;;
    --staging) STAGING_DB="$2"; shift 2 ;;
    --target) TARGET_DB="$2"; shift 2 ;;
    --mode) MODE="$2"; shift 2 ;;
    --host) MYSQL_HOST="$2"; shift 2 ;;
    --port) MYSQL_PORT="$2"; shift 2 ;;
    --user) MYSQL_USER="$2"; shift 2 ;;
    --password) MYSQL_PASSWORD="$2"; shift 2 ;;
    --mysql) MYSQL_BIN="$2"; shift 2 ;;
    --no-drop-staging) DROP_STAGING=0; shift ;;
    --skip-import) SKIP_IMPORT=1; shift ;;
    --drop-target) DROP_TARGET=1; shift ;;
    --no-migrate) RUN_MIGRATIONS=0; shift ;;
    --no-verify) SKIP_VERIFY=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ -z "$LEGACY_SQL" ]]; then
  echo "Missing --sql" >&2
  usage
  exit 1
fi

MODE_LC="$(echo "$MODE" | tr '[:upper:]' '[:lower:]')"
if [[ "$MODE_LC" != "merge" && "$MODE_LC" != "fresh" ]]; then
  echo "--mode must be merge or fresh" >&2
  exit 1
fi

if [[ "$(uname -s)" == MINGW* || "$(uname -s)" == MSYS* || "$(uname -s)" == CYGWIN* ]]; then
  PS_MODE="Merge"
  [[ "$MODE_LC" == "fresh" ]] && PS_MODE="Fresh"
  ARGS=(
    -LegacySqlFile "$LEGACY_SQL"
    -StagingDatabase "$STAGING_DB"
    -TargetDatabase "$TARGET_DB"
    -Mode "$PS_MODE"
    -MySqlHost "$MYSQL_HOST"
    -MySqlPort "$MYSQL_PORT"
    -MySqlUser "$MYSQL_USER"
    -MySqlPassword "$MYSQL_PASSWORD"
  )
  [[ -n "$MYSQL_BIN" ]] && ARGS+=(-MySqlPath "$MYSQL_BIN")
  [[ "$DROP_STAGING" -eq 1 ]] && ARGS+=(-DropStaging)
  [[ "$SKIP_IMPORT" -eq 1 ]] && ARGS+=(-SkipStagingImport)
  [[ "$DROP_TARGET" -eq 1 ]] && ARGS+=(-DropTarget)
  [[ "$RUN_MIGRATIONS" -eq 1 ]] && ARGS+=(-RunMigrations)
  [[ "$SKIP_VERIFY" -eq 1 ]] && ARGS+=(-SkipVerify)
  [[ "$DRY_RUN" -eq 1 ]] && ARGS+=(-WhatIf)
  if [[ "$MODE_LC" == "fresh" && "$DROP_TARGET" -eq 0 && "$RUN_MIGRATIONS" -eq 0 ]]; then
    ARGS+=(-DropTarget -RunMigrations)
  fi
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$SCRIPT_DIR/migrate_legacy.ps1" "${ARGS[@]}"
  exit $?
fi

if [[ -z "$MYSQL_BIN" ]]; then
  MYSQL_BIN="$(command -v mysql || true)"
fi
if [[ -z "$MYSQL_BIN" ]]; then
  echo "mysql not found; pass --mysql" >&2
  exit 1
fi

mysql_args=(-h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" --default-character-set=utf8mb4)
[[ -n "$MYSQL_PASSWORD" ]] && mysql_args+=(-p"$MYSQL_PASSWORD")

run_sql() {
  local db="${1:-}"
  local sql="$2"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[dry-run] mysql ${mysql_args[*]} ${db:+$db }-e \"$sql\""
    return
  fi
  if [[ -n "$db" ]]; then
    "$MYSQL_BIN" "${mysql_args[@]}" "$db" -e "$sql"
  else
    "$MYSQL_BIN" "${mysql_args[@]}" -e "$sql"
  fi
}

import_file() {
  local db="$1"
  local file="$2"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[dry-run] mysql ${mysql_args[*]} $db < $file"
    return
  fi
  "$MYSQL_BIN" "${mysql_args[@]}" "$db" < "$file"
}

substitute_sql() {
  local template="$1"
  local out
  out="$(mktemp)"
  sed -e "s/pocket_legacy_stage_2026_04_02/$STAGING_DB/g" \
      -e "s/pocket_legacy_stage/$STAGING_DB/g" \
      -e "s/pocket_v2_import/$TARGET_DB/g" \
      -e "s/pocket_v2/$TARGET_DB/g" \
      "$template" > "$out"
  echo "$out"
}

step() { echo ""; echo "==> $*"; }

step "Recreate staging: $STAGING_DB"
if [[ "$DROP_STAGING" -eq 1 ]]; then
  run_sql "" "DROP DATABASE IF EXISTS \`$STAGING_DB\`; CREATE DATABASE \`$STAGING_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi

if [[ "$SKIP_IMPORT" -eq 0 ]]; then
  step "Import legacy dump"
  import_file "$STAGING_DB" "$LEGACY_SQL"
fi

if [[ "$MODE_LC" == "fresh" ]]; then
  if [[ "$DROP_TARGET" -eq 1 ]]; then
    step "Recreate target: $TARGET_DB"
    run_sql "" "DROP DATABASE IF EXISTS \`$TARGET_DB\`; CREATE DATABASE \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  fi
  if [[ "$RUN_MIGRATIONS" -eq 1 ]]; then
    step "Laravel migrate"
    if [[ "$DRY_RUN" -eq 0 ]]; then
      (cd "$BACKEND_ROOT" && DB_DATABASE="$TARGET_DB" php artisan migrate --force)
    else
      echo "[dry-run] DB_DATABASE=$TARGET_DB php artisan migrate --force"
    fi
  fi
  step "Fresh map"
  tmp="$(substitute_sql "$SCRIPT_DIR/import_stage_to_clean.sql")"
  import_file "$TARGET_DB" "$tmp"
  rm -f "$tmp"
else
  step "Merge fill-missing"
  tmp="$(substitute_sql "$SCRIPT_DIR/merge_fill_missing.sql")"
  import_file "$TARGET_DB" "$tmp"
  rm -f "$tmp"
fi

if [[ "$SKIP_VERIFY" -eq 0 ]]; then
  step "Verify"
  run_sql "" "SELECT 'staging_users' m, COUNT(*) c FROM \`$STAGING_DB\`.users
UNION ALL SELECT 'target_users', COUNT(*) FROM \`$TARGET_DB\`.users
UNION ALL SELECT 'staging_recs', COUNT(*) FROM \`$STAGING_DB\`.recs WHERE complixid IS NOT NULL
UNION ALL SELECT 'target_reservations', COUNT(*) FROM \`$TARGET_DB\`.reservations;"
fi

echo ""
echo "Done. mode=$MODE_LC staging=$STAGING_DB target=$TARGET_DB"
