#!/usr/bin/env bash
# 06-mariadb.sh: MariaDB 11.8 from Ubuntu's own archive, hardened and local
# only; the aio database; the migrations in db/migrations (the schema gate);
# three application users with their own grants and generated passwords.
#
# Needs sudo: yes. Reboots: no (restarts MariaDB when its config changes).
# Safe to re-run: yes. Applied migrations are recorded with a checksum and
# never applied twice; passwords already generated are kept.
# Usage:  sudo bash setup/06-mariadb.sh
#         sudo bash setup/06-mariadb.sh --recreate-db   (only after a failed
#             first migration: drops and recreates the empty aio database;
#             refused once any agent is enrolled)
# Passwords are written only to root-only files under /etc/aio-mothership/db/
# and are never printed (MS-SEC-10).
# Requirements: MS-SEC-04, MS-DAT-01, MS-DAT-02, MS-ING-04, M-036.

source "$(dirname "$0")/lib.sh"
need_root
banner "MariaDB $AIO_MARIADB_SERIES (Ubuntu), database '$AIO_DB_NAME', schema, app users" "yes" "no (restarts MariaDB on config change)"

RECREATE=0
[[ "${1:-}" == "--recreate-db" ]] && RECREATE=1
DB=$AIO_DB_NAME
APP_USERS=(ingest dashboard maint)
CRED_DIR="$AIO_ETC/db"
LOG_DIR=/var/log/aio-mothership/migrations

sql() { mariadb --batch --skip-column-names -e "$1"; }

# --- 1. Install from Ubuntu's archive only (M-029) ---------------------------
if grep -rqsE 'mariadb\.org|mirror\.mariadb|downloads\.mariadb' /etc/apt/sources.list /etc/apt/sources.list.d/; then
    die "a MariaDB.org apt source is configured; M-029 uses Ubuntu's own package only. Remove it first."
fi
apt-get update -q
CANDIDATE=$(apt-cache policy mariadb-server | awk '/Candidate:/{print $2}')
info "mariadb-server candidate: $CANDIDATE"
[[ "$CANDIDATE" == *":$AIO_MARIADB_SERIES."* || "$CANDIDATE" == "$AIO_MARIADB_SERIES."* ]] \
    || die "Ubuntu offers mariadb-server $CANDIDATE, not $AIO_MARIADB_SERIES.x"
apt_install mariadb-server mariadb-client

# --- 2. Config ---------------------------------------------------------------
CONF_DIR=/etc/mysql/mariadb.conf.d
[[ -d "$CONF_DIR" ]] || die "$CONF_DIR not found; check where Ubuntu 26.04's MariaDB reads its config"
systemctl enable --quiet mariadb
if render mariadb-90-aio-mothership.cnf "$CONF_DIR/90-aio-mothership.cnf"; then
    info "restarting MariaDB for the new config"
    systemctl restart mariadb
fi
systemctl start mariadb
SOCKET=$(sql "SELECT @@socket")
info "server $(sql "SELECT VERSION()"), datadir $(sql "SELECT @@datadir"), socket $SOCKET"

# --- 3. Hardening (what mariadb-secure-installation does, non-interactive) ---
sql "DROP DATABASE IF EXISTS test"
sql "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\\\_%'"
while read -r u; do
    [[ -n "$u" ]] && sql "DROP USER $u" && info "dropped user $u"
done < <(sql "SELECT CONCAT(QUOTE(User),'@',QUOTE(Host)) FROM mysql.user WHERE User='' OR (User='root' AND Host<>'localhost')")
sql "FLUSH PRIVILEGES"

# --- 4. Database, optionally recreated ----------------------------------------
db_exists() { [[ $(sql "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$DB'") -eq 1 ]]; }
has_table() { [[ $(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='$1'") -eq 1 ]]; }
table_count() { sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'"; }

if [[ $RECREATE -eq 1 ]] && db_exists; then
    if has_table agents && [[ $(sql "SELECT COUNT(*) FROM $DB.agents") -gt 0 ]]; then
        die "--recreate-db refused: $DB.agents has rows (agents are enrolled)"
    fi
    info "--recreate-db: dropping database $DB"
    sql "DROP DATABASE $DB"
fi
sql "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# --- 5. Migrations: the schema gate ------------------------------------------
install -d -m 0700 -o root -g root "$LOG_DIR"
MIG_OK=1
EXPECTED_TABLES=0
for f in "$AIO_REPO"/db/migrations/[0-9][0-9][0-9][0-9]_*.sql; do
    v=$(basename "$f" .sql)
    sum=$(sha256sum "$f" | cut -c1-64)
    EXPECTED_TABLES=$((EXPECTED_TABLES + $(grep -c '^CREATE TABLE' "$f" || true)))
    applied=""
    has_table schema_migrations && applied=$(sql "SELECT checksum FROM $DB.schema_migrations WHERE version='$v'")
    if [[ -n "$applied" ]]; then
        if [[ "$applied" == "$sum" ]]; then
            info "migration $v already applied"
        else
            fail "migration $v was applied from a different file (checksum changed); add a new migration instead"
            MIG_OK=0
        fi
        continue
    fi
    if [[ "$v" == 0001_* && $(table_count) -gt 0 ]]; then
        fail "database $DB has tables but 0001 isn't recorded (a failed earlier run). Re-run with --recreate-db"
        MIG_OK=0
        break
    fi
    log="$LOG_DIR/$v-$(date -u +%Y%m%dT%H%M%SZ).log"
    info "applying migration $v (log: $log)"
    if mariadb --show-warnings "$DB" < "$f" > "$log" 2>&1; then
        sql "INSERT INTO $DB.schema_migrations (version, checksum) VALUES ('$v', '$sum')"
        if [[ -s "$log" ]]; then
            echo "---- output and warnings from $v ----"; cat "$log"; echo "---- end ----"
        fi
        pass "migration $v applied"
    else
        echo "---- error output from $v ----"; cat "$log"; echo "---- end ----"
        fail "migration $v failed (see above); fix the file, then re-run with --recreate-db"
        MIG_OK=0
        break
    fi
done

# --- 6. Application users and grants (MS-SEC-04) ------------------------------
install -d -m 0755 -o root -g root "$AIO_ETC"
install -d -m 0700 -o root -g root "$CRED_DIR"
for u in "${APP_USERS[@]}"; do
    file="$CRED_DIR/$u.cnf"
    user="aio_$u"
    pw=""
    [[ -f "$file" ]] && pw=$(awk -F= '/^password=/{print $2}' "$file")
    if [[ -z "$pw" ]]; then
        pw=$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9')
        pw=${pw:0:40}
        info "generated a new password for $user"
    fi
    tmp=$(umask 077; mktemp "$CRED_DIR/.tmp.XXXXXX")
    {
        echo "# Database credentials for $user (M-036). Root-only. Never copy into the repo."
        echo "[client]"
        echo "user=$user"
        echo "password=$pw"
        echo "socket=$SOCKET"
    } > "$tmp"
    chmod 0600 "$tmp"; chown root:root "$tmp"
    mv -f "$tmp" "$file"
    mariadb <<SQL
CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$pw';
ALTER USER '$user'@'localhost' IDENTIFIED BY '$pw';
SQL
done
unset pw
if [[ $MIG_OK -eq 1 ]]; then
    mariadb < "$AIO_SETUP_DIR/sql/grants.sql"
    info "grants applied from setup/sql/grants.sql"
else
    warn "grants not applied: the schema isn't complete"
fi

# --- 7. Checks -----------------------------------------------------------------
as_app() { local u=$1; shift; mariadb --defaults-extra-file="$CRED_DIR/$u.cnf" --batch --skip-column-names -e "$*"; }
version_ok()   { [[ $(sql "SELECT VERSION()") == "$AIO_MARIADB_SERIES".* ]]; }
datadir_ok()   { [[ $(sql "SELECT @@datadir") == "$AIO_MARIADB_DATADIR/" || $(sql "SELECT @@datadir") == "$AIO_MARIADB_DATADIR" ]]; }
local_only()   { ! ss -Hltn | awk '{print $4}' | grep -E ':3306$' | grep -Evq '^(127\.0\.0\.1|\[::1\]):3306$'; }
sql_is()       { [[ $(sql "$1") == "$2" ]]; }
root_socket()  { sql "SHOW CREATE USER 'root'@'localhost'" | grep -q unix_socket; }
mig_recorded() { local f v; for f in "$AIO_REPO"/db/migrations/[0-9][0-9][0-9][0-9]_*.sql; do v=$(basename "$f" .sql); sql_is "SELECT COUNT(*) FROM $DB.schema_migrations WHERE version='$v'" 1 || return 1; done; }
creds_ok()     { local u; for u in "${APP_USERS[@]}"; do file_mode_is "$CRED_DIR/$u.cnf" 600 root root || return 1; done; }

echo
info "Checks"
check "MariaDB is running" systemctl is-active --quiet mariadb
check "server version is $AIO_MARIADB_SERIES.x" version_ok
check "no MariaDB.org apt source (Ubuntu package only, M-029)" bash -c "! grep -rqsE 'mariadb\.org|mirror\.mariadb' /etc/apt/sources.list /etc/apt/sources.list.d/"
check "datadir is $AIO_MARIADB_DATADIR" datadir_ok
check "listens on localhost only (or not on TCP at all)" local_only
check "bind_address is 127.0.0.1" sql_is "SELECT @@bind_address" 127.0.0.1
check "local_infile is off" sql_is "SELECT @@local_infile" 0
check "time_zone is +00:00" sql_is "SELECT @@global.time_zone" "+00:00"
check "character_set_server is utf8mb4" sql_is "SELECT @@character_set_server" utf8mb4
check "no anonymous users" sql_is "SELECT COUNT(*) FROM mysql.user WHERE User=''" 0
check "root only at localhost" sql_is "SELECT COUNT(*) FROM mysql.user WHERE User='root' AND Host<>'localhost'" 0
check "root signs in by unix socket" root_socket
check "no test database" sql_is "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='test'" 0
check "credential files are root-only (600 root root)" creds_ok
check "credential directory is 700" file_mode_is "$CRED_DIR" 700 root root

echo
info "Schema gate"
check "every migration is recorded in schema_migrations" mig_recorded
check "table count is $EXPECTED_TABLES (CREATE TABLE count in db/migrations)" sql_is "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE'" "$EXPECTED_TABLES"
check "partitioned tables exist" test "$(sql "SELECT COUNT(DISTINCT table_name) FROM information_schema.partitions WHERE table_schema='$DB' AND partition_name IS NOT NULL")" -gt 0
check "net_flows has partition p_future" sql_is "SELECT COUNT(*) FROM information_schema.partitions WHERE table_schema='$DB' AND table_name='net_flows' AND partition_name='p_future'" 1
check "alert_rules seeded (9 rules, MS-ALR-01..09)" sql_is "SELECT COUNT(*) FROM $DB.alert_rules" 9
check "threat_lists seeded (4 rows, M-027)" sql_is "SELECT COUNT(*) FROM $DB.threat_lists" 4
check "retention_policies seeded" test "$(sql "SELECT COUNT(*) FROM $DB.retention_policies")" -gt 0
check "quarantine kept 90 days (M-022)" sql_is "SELECT keep_days FROM $DB.retention_policies WHERE table_name='quarantine'" 90
echo
info "Per-table partition counts"
sql "SELECT table_name, COUNT(*) FROM information_schema.partitions WHERE table_schema='$DB' AND partition_name IS NOT NULL GROUP BY table_name ORDER BY table_name" | column -t || true

echo
info "Grants (MS-SEC-04): each user can do its job and nothing more"
check     "ingest: signs in"                                as_app ingest "SELECT 1"
check     "ingest: reads host_groups"                       as_app ingest "SELECT COUNT(*) FROM aio.host_groups"
check_not "ingest: can't read net_flows (insert-only)"      as_app ingest "SELECT COUNT(*) FROM aio.net_flows"
check_not "ingest: can't read users"                        as_app ingest "SELECT COUNT(*) FROM aio.users"
check_not "ingest: can't read audit_log"                    as_app ingest "SELECT COUNT(*) FROM aio.audit_log"
check_not "ingest: can't update net_flows"                  as_app ingest "UPDATE aio.net_flows SET seq=seq WHERE 1=0"
check     "dashboard: signs in and reads alert_rules"       as_app dashboard "SELECT COUNT(*) FROM aio.alert_rules"
check_not "dashboard: can't delete from audit_log"          as_app dashboard "DELETE FROM aio.audit_log WHERE 1=0"
check_not "dashboard: can't update audit_log"               as_app dashboard "UPDATE aio.audit_log SET id=id WHERE 1=0"
check_not "dashboard: can't delete from net_flows"          as_app dashboard "DELETE FROM aio.net_flows WHERE 1=0"
check_not "dashboard: can't alter tables"                   as_app dashboard "ALTER TABLE aio.net_flows COMMENT='x'"
check     "maint: signs in and reads retention_policies"    as_app maint "SELECT COUNT(*) FROM aio.retention_policies"
check_not "maint: can't read the mysql system database"     as_app maint "SELECT COUNT(*) FROM mysql.user"
check_not "no app user has privileges outside aio"          as_app maint "CREATE DATABASE aio_grant_test"

echo
if [[ $MIG_OK -eq 1 && $FAIL_COUNT -eq 0 ]]; then
    echo "SCHEMA GATE: PASS (db/migrations applied cleanly on MariaDB $(sql "SELECT VERSION()"))"
else
    echo "SCHEMA GATE: FAIL (paste the whole output back)"
fi
finish
