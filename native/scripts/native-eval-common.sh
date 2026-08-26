env_value() {
    local key="$1"
    awk -F= -v key="${key}" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "${env_file}"
}

sql_literal() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf "'%s'" "${value}"
}

env_unsigned_int() {
    local key="$1"
    local default="$2"
    local value="${!key:-${default}}"

    if [[ ! "${value}" =~ ^[0-9]+$ ]]; then
        echo "${key} must be an unsigned integer; got ${value}" >&2
        exit 2
    fi

    printf '%s' "${value}"
}

real_leaf_exec_environment_args() {
    if [[ "${allow_real_leaves:-0}" == "1" ]]; then
        printf '%s\n' \
            "-e" "NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=" \
            "-e" "NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG="
    fi
}

require_native_eval_database() {
    local label="$1"

    if [[ "${db_name}" != "nntmux_native_eval" ]]; then
        echo "Refusing ${label} against DB_DATABASE=${db_name}; expected nntmux_native_eval." >&2
        exit 2
    fi
}

seed_eval_worker_data() {
    local eval_group_name="${NNTMUX_NATIVE_EVAL_GROUP_NAME:-alt.binaries.native.eval}"
    local eval_group_description="${NNTMUX_NATIVE_EVAL_GROUP_DESCRIPTION:-native eval smoke group}"
    local eval_group_backfill_target
    local eval_group_first_record
    local eval_group_last_record
    local eval_short_group_first_record
    local eval_short_group_last_record
    local eval_group_name_sql
    local eval_group_description_sql

    eval_group_backfill_target="$(env_unsigned_int NNTMUX_NATIVE_EVAL_GROUP_BACKFILL_TARGET 10)"
    eval_group_first_record="$(env_unsigned_int NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD 50000)"
    eval_group_last_record="$(env_unsigned_int NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD 100000)"
    eval_short_group_first_record="$(env_unsigned_int NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD 1)"
    eval_short_group_last_record="$(env_unsigned_int NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD 200000)"
    eval_group_name_sql="$(sql_literal "${eval_group_name}")"
    eval_group_description_sql="$(sql_literal "${eval_group_description}")"

    "${compose[@]}" exec -T mariadb \
        mariadb -uroot -p"${db_root_password:-nntmux-root}" "${db_name}" <<SQL
SET @eval_group_name := ${eval_group_name_sql};
SET @eval_group_description := ${eval_group_description_sql};
SET @eval_group_backfill_target := ${eval_group_backfill_target};
SET @eval_group_first_record := ${eval_group_first_record};
SET @eval_group_last_record := ${eval_group_last_record};
SET @eval_short_group_first_record := ${eval_short_group_first_record};
SET @eval_short_group_last_record := ${eval_short_group_last_record};

UPDATE usenet_groups
SET active = 0, backfill = 0
WHERE description LIKE 'native eval%'
  AND name <> @eval_group_name;

INSERT INTO settings (name, value) VALUES
  ("binaries", "1"),
  ("backfill", "1"),
  ("backfill_days", "1"),
  ("backfill_groups", "1"),
  ("backfill_qty", "75000"),
  ("releases", "1"),
  ("releasethreads", "1"),
  ("bins_timer", "30"),
  ("back_timer", "30"),
  ("rel_timer", "30"),
  ("fix_timer", "30"),
  ("crap_timer", "30"),
  ("post_timer", "30"),
  ("post_timer_non", "30"),
  ("post_timer_amazon", "30"),
  ("postthreads", "2"),
  ("nfothreads", "2"),
  ("minsizetopostprocess", "1"),
  ("maxsizetopostprocess", "100"),
  ("minsizetoprocessnfo", "1"),
  ("maxsizetoprocessnfo", "100"),
  ("maxnforetries", "8"),
  ("progressive", "0"),
  ("fix_names", "1"),
  ("fix_crap_opt", "Custom"),
  ("fix_crap", "gibberish,executable,hashed,short,installbin,passwordurl,nzb,scr,passworded,sample,size,codec,blfiles,blacklist,par2only"),
  ("post", "3"),
  ("post_non", "1"),
  ("post_amazon", "1"),
  ("metadata_refresh", "1"),
  ("metadata_refresh_limit", "7"),
  ("metadata_refresh_sleep_ms", "11"),
  ("metadata_refresh_timer", "30"),
  ("lookupbooks", "1"),
  ("lookupmusic", "1"),
  ("lookupgames", "1"),
  ("lookupimdb", "1"),
  ("lookuptv", "1"),
  ("lookupanidb", "1"),
  ("lookupnfo", "1"),
  ("run_ircscraper", "1"),
  ("monitor_delay", "30"),
  ("seq_timer", "30"),
  ("sequential", "0"),
  ("safebackfilldate", "1999-01-01")
ON DUPLICATE KEY UPDATE value = VALUES(value);

INSERT INTO categories (id, title, root_categories_id, status, disablepreview)
VALUES
  (20, "Other > Hashed", 1, 1, 0),
  (2000, "Movies", 2000, 1, 0),
  (2010, "Movies > Foreign", 2000, 1, 0),
  (3010, "Audio > MP3", 3000, 1, 0),
  (4050, "PC > Games", 4000, 1, 0),
  (5010, "TV > WEB-DL", 5000, 1, 0),
  (5070, "TV > Anime", 5000, 1, 0),
  (7010, "Books > Magazines", 7000, 1, 0),
  (1010, "Console > NDS", 1000, 1, 0)
ON DUPLICATE KEY UPDATE title = VALUES(title), root_categories_id = VALUES(root_categories_id), status = VALUES(status), disablepreview = VALUES(disablepreview);

INSERT INTO usenet_groups
  (name, backfill_target, first_record, first_record_postdate, last_record, last_record_postdate, last_updated, active, backfill, description)
VALUES
  (@eval_group_name, @eval_group_backfill_target, @eval_group_first_record, "2099-06-15 10:00:00", @eval_group_last_record, "2099-06-15 11:00:00", NOW(), 1, 1, @eval_group_description)
ON DUPLICATE KEY UPDATE
  backfill_target = VALUES(backfill_target),
  first_record = VALUES(first_record),
  first_record_postdate = VALUES(first_record_postdate),
  last_record = VALUES(last_record),
  last_record_postdate = VALUES(last_record_postdate),
  last_updated = VALUES(last_updated),
  active = VALUES(active),
  backfill = VALUES(backfill),
  description = VALUES(description);

DELETE FROM short_groups WHERE name = @eval_group_name;

INSERT INTO short_groups (name, first_record, last_record, updated)
VALUES (@eval_group_name, @eval_short_group_first_record, @eval_short_group_last_record, NOW())
ON DUPLICATE KEY UPDATE
  first_record = VALUES(first_record),
  last_record = VALUES(last_record),
  updated = VALUES(updated);

SET @group_id := (SELECT id FROM usenet_groups WHERE name = @eval_group_name LIMIT 1);
SET @release_proof_subject := "Native Eval Release Proof";
SET @release_proof_from := "native-proof@example.invalid";
SET @release_proof_collection_hash := "native-eval-release-proof-collection";

DELETE rr
FROM release_regexes rr
JOIN releases r ON r.id = rr.releases_id
WHERE r.name = @release_proof_subject
  AND r.fromname = @release_proof_from
  AND r.groups_id = @group_id;

DELETE rg
FROM releases_groups rg
JOIN releases r ON r.id = rg.releases_id
WHERE r.name = @release_proof_subject
  AND r.fromname = @release_proof_from
  AND r.groups_id = @group_id;

DELETE FROM releases
WHERE name = @release_proof_subject
  AND fromname = @release_proof_from
  AND groups_id = @group_id;

DELETE FROM collections
WHERE collectionhash = @release_proof_collection_hash
  AND groups_id = @group_id;

INSERT INTO collections
  (subject, fromname, date, xref, totalfiles, groups_id, collectionhash, collection_regexes_id, dateadded, filecheck, filesize, releases_id, noise)
VALUES
  ("Native Eval Smoke Collection", "native@example.invalid", NOW(), "", 1, @group_id, "native-eval-smoke-collection", 0, NOW(), 0, 123456, NULL, "nativeevalsmokecollection000000")
ON DUPLICATE KEY UPDATE
  groups_id = VALUES(groups_id),
  date = VALUES(date),
  dateadded = VALUES(dateadded),
  releases_id = NULL;

INSERT INTO collections
  (subject, fromname, date, xref, totalfiles, groups_id, collectionhash, collection_regexes_id, dateadded, filecheck, filesize, releases_id, noise)
VALUES
  (@release_proof_subject, @release_proof_from, NOW(), "", 1, @group_id, @release_proof_collection_hash, 0, NOW(), 3, 1048576, NULL, "nativeevalreleaseproof000000")
ON DUPLICATE KEY UPDATE
  subject = VALUES(subject),
  fromname = VALUES(fromname),
  date = VALUES(date),
  dateadded = VALUES(dateadded),
  totalfiles = VALUES(totalfiles),
  groups_id = VALUES(groups_id),
  filecheck = VALUES(filecheck),
  filesize = VALUES(filesize),
  releases_id = NULL;

SET @release_proof_collection_id := (
  SELECT id FROM collections
  WHERE collectionhash = @release_proof_collection_hash
    AND groups_id = @group_id
  LIMIT 1
);

INSERT INTO binaries
  (binaryhash, name, collections_id, filenumber, totalparts, currentparts, partcheck, partsize)
VALUES
  (UNHEX(MD5(CONCAT("native-eval-release-proof-binary-", @group_id))), "Native.Eval.Release.Proof.part01.rar", @release_proof_collection_id, 1, 1, 1, 1, 1048576)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  totalparts = VALUES(totalparts),
  currentparts = VALUES(currentparts),
  partcheck = VALUES(partcheck),
  partsize = VALUES(partsize);

SET @release_proof_binary_id := (
  SELECT id FROM binaries
  WHERE collections_id = @release_proof_collection_id
    AND filenumber = 1
  LIMIT 1
);

INSERT IGNORE INTO parts
  (binaries_id, messageid, number, partnumber, size)
VALUES
  (@release_proof_binary_id, CONCAT("<native-eval-release-proof-", @group_id, "@example.invalid>"), @eval_group_last_record, 1, 1048576);

INSERT INTO releases
  (name, searchname, totalpart, groups_id, size, postdate, adddate, guid, leftguid, fromname, categories_id, passwordstatus, haspreview, nfostatus, proc_nfo, proc_files, proc_par2)
WITH RECURSIVE seq(n) AS (
  SELECT 1
  UNION ALL
  SELECT n + 1 FROM seq WHERE n < 130
)
SELECT
  CONCAT("Native Eval Hashed ", n),
  CONCAT("Native Eval Hashed ", n),
  1,
  @group_id,
  2097152,
  NOW(),
  NOW(),
  CONCAT("nativeevalhashed", LPAD(n, 24, "0")),
  "n",
  "native@example.invalid",
  20,
  0,
  -1,
  1,
  0,
  0,
  0
FROM seq
ON DUPLICATE KEY UPDATE
  categories_id = VALUES(categories_id),
  passwordstatus = VALUES(passwordstatus),
  haspreview = VALUES(haspreview),
  nfostatus = VALUES(nfostatus),
  proc_nfo = VALUES(proc_nfo),
  proc_files = VALUES(proc_files),
  proc_par2 = VALUES(proc_par2);

INSERT INTO releases
  (name, searchname, totalpart, groups_id, size, postdate, adddate, guid, leftguid, fromname, categories_id, videos_id, tv_episodes_id, passwordstatus, haspreview, nfostatus)
VALUES
  ("Native Eval TV", "Native Eval TV", 1, @group_id, 2097152, NOW(), NOW(), "nativeevaltv0000000000000000000001", "n", "native@example.invalid", 5010, 0, 0, -1, -1, 1),
  ("Native Eval Anime", "Native Eval Anime", 1, @group_id, 2097152, NOW(), NOW(), "nativeevalanime0000000000000000001", "n", "native@example.invalid", 5070, 0, 0, -1, -1, 1),
  ("Native Eval Movie", "Native Eval Movie", 1, @group_id, 2097152, NOW(), NOW(), "nativeevalmovie0000000000000000001", "n", "native@example.invalid", 2010, 0, 0, -1, -1, 1),
  ("Native Eval Music", "Native Eval Music", 1, @group_id, 2097152, NOW(), NOW(), "nativeevalmusic0000000000000000001", "n", "native@example.invalid", 3010, 0, 0, -1, -1, 1),
  ("Native Eval Console", "Native Eval Console", 1, @group_id, 2097152, NOW(), NOW(), "nativeevalconsole0000000000000001", "n", "native@example.invalid", 1010, 0, 0, -1, -1, 1),
  ("Native Eval Book", "Native Eval Book", 1, @group_id, 2097152, NOW(), NOW(), "nativeevalbook00000000000000000001", "n", "native@example.invalid", 7010, 0, 0, -1, -1, 1),
  ("Native Eval Game", "Native Eval Game", 1, @group_id, 2097152, NOW(), NOW(), "nativeevalgame00000000000000000001", "n", "native@example.invalid", 4050, 0, 0, -1, -1, 1)
ON DUPLICATE KEY UPDATE
  categories_id = VALUES(categories_id),
  passwordstatus = VALUES(passwordstatus),
  haspreview = VALUES(haspreview),
  nfostatus = VALUES(nfostatus);

INSERT INTO releases
  (name, searchname, totalpart, groups_id, size, postdate, adddate, guid, leftguid, fromname, categories_id, passwordstatus, haspreview, nzbstatus, nfostatus)
VALUES
  ("Native Eval Additional", "Native Eval Additional", 1, @group_id, 4194304, NOW(), NOW(), "nativeevaladditional00000000000001", "a", "native@example.invalid", 2000, -1, -1, 1, 0)
ON DUPLICATE KEY UPDATE
  categories_id = VALUES(categories_id),
  passwordstatus = VALUES(passwordstatus),
  haspreview = VALUES(haspreview),
  nzbstatus = VALUES(nzbstatus),
  nfostatus = VALUES(nfostatus),
  size = VALUES(size);
SQL
}

configure_eval_lane() {
    local lane="$1"
    local sequential="0"
    if [[ "${lane}" == "per-group" ]]; then
        sequential="2"
    fi

    "${compose[@]}" exec -T mariadb \
        mariadb -uroot -p"${db_root_password:-nntmux-root}" "${db_name}" \
        -e "INSERT INTO settings (name, value) VALUES (\"sequential\", \"${sequential}\") ON DUPLICATE KEY UPDATE value = VALUES(value)"
}
