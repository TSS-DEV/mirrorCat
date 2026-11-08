<?php
require_once __DIR__ . '/config.php';

/**
 * Shared PDO connection for the mirrorCAT SQLite database.
 */
function db() {
    static $pdo = null;

    if ($pdo === null) {
        global $g_db_path;

        $pdo = new PDO('sqlite:' . $g_db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 8000');
    }

    return $pdo;
}

/**
 * Create the application tables and the default settings.
 *
 * The schema version is stored in SQLite's user_version pragma so normal
 * requests do not need to run write statements just to open the database.
 */
function db_init() {
    global $g_ldap_server, $g_ldap_domain, $g_ldap_domain_nb, $g_ldap_base_dn;

    $pdo = db();

    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 4) {
        return;
    }

    if ($version < 1) {
        $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();

        $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT '',
            filename TEXT NOT NULL DEFAULT '',
            stored_file TEXT NOT NULL DEFAULT '',
            sheet TEXT NOT NULL DEFAULT '',
            columns_json TEXT NOT NULL DEFAULT '[]',
            mapping_json TEXT NOT NULL DEFAULT '{}',
            query_template TEXT NOT NULL DEFAULT '',
            extra_query TEXT NOT NULL DEFAULT '',
            order_by TEXT NOT NULL DEFAULT 'bestMatch',
            row_count INTEGER NOT NULL DEFAULT 0,
            created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS batch_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            row_num INTEGER NOT NULL DEFAULT 0,
            data_json TEXT NOT NULL DEFAULT '{}',
            result_json TEXT NOT NULL DEFAULT '{}',
            raw_json TEXT NOT NULL DEFAULT '',
            oclc_number TEXT NOT NULL DEFAULT '',
            search_query TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            error TEXT NOT NULL DEFAULT '',
            processed INTEGER NOT NULL DEFAULT 0,
            updated TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batch_rows_batch ON batch_rows(batch_id, processed, status)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_batch_rows_number ON batch_rows(batch_id, row_num)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )");

        $defaults = [
            'app_name'               => 'mirrorCAT',
            'oclc_wskey'             => '',
            'oclc_secret'            => '',
            'oclc_principal_id'      => '',
            'oclc_principal_idns'    => '',
            'oclc_scope'             => 'WorldCatMetadataAPI:view_brief_bib WorldCatMetadataAPI:manage_bibs',
            'oclc_base_url'          => 'https://metadata.api.oclc.org/worldcat',
            'oclc_oauth_url'         => 'https://oauth.oclc.org/token',
            'demo_mode'              => '0',
            'default_order_by'       => 'bestMatch',
            'default_query_template' => '',
            'batch_size'             => '10',
            'concurrency'            => '6',
            'rows_per_page'          => '25',
            'max_rows'               => '5000',
            'max_marc_records'       => '250',
            'last_remaining_day'     => '',
        ];

        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $pdo->exec('PRAGMA user_version = 1');
        $version = 1;
    }

    if ($version < 2) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lan_id TEXT NOT NULL UNIQUE,
            name_first TEXT NOT NULL DEFAULT '',
            name_last TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            employee_id TEXT NOT NULL DEFAULT '',
            password_hash TEXT NOT NULL DEFAULT '',
            level INTEGER NOT NULL DEFAULT 3,
            active INTEGER NOT NULL DEFAULT 1,
            last_login TEXT NOT NULL DEFAULT '',
            created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        // First administrator. Replace or extend this list from the Users page.
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO users
            (lan_id, name_first, name_last, email, employee_id, level, active)
            VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute(['ADMIN', 'mirrorCAT', 'Administrator', 'admin@example.org', '', LEVEL_ADMIN]);

        $pdo->exec('PRAGMA user_version = 2');
        $version = 2;
    }

    if ($version < 3) {
        // Batches belong to the user who uploaded them. Administrators can
        // still see everything. Existing batches are left unowned (0), which
        // makes them visible to administrators only.
        $hasUserId = false;
        foreach ($pdo->query('PRAGMA table_info(batches)') as $column) {
            if (($column['name'] ?? '') === 'user_id') {
                $hasUserId = true;
                break;
            }
        }
        if (!$hasUserId) {
            $pdo->exec('ALTER TABLE batches ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_batches_user ON batches(user_id, id)');
        $pdo->exec('PRAGMA user_version = 3');
        $version = 3;
    }

    if ($version < 4) {
        // Optional local passwords for installations that do not use LDAP.
        // Users created before this migration have no local password until an
        // administrator sets one on the Users page.
        $hasPasswordHash = false;
        foreach ($pdo->query('PRAGMA table_info(users)') as $column) {
            if (($column['name'] ?? '') === 'password_hash') {
                $hasPasswordHash = true;
                break;
            }
        }
        if (!$hasPasswordHash) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_hash TEXT NOT NULL DEFAULT ''");
        }

        $ldapDefaults = [
            'ldap_enabled'   => '0',
            'ldap_server'    => (string)($g_ldap_server ?? ''),
            'ldap_domain'    => (string)($g_ldap_domain ?? ''),
            'ldap_domain_nb' => (string)($g_ldap_domain_nb ?? ''),
            'ldap_base_dn'   => (string)($g_ldap_base_dn ?? ''),
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($ldapDefaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $pdo->exec('PRAGMA user_version = 4');
    }
}

function setting_get($key, $default = '') {
    $stmt = db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function setting_set($key, $value) {
    $value = is_null($value) ? '' : (string)$value;
    $stmt = db()->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, $value]);
}

function settings_all() {
    $out = [];
    foreach (db()->query("SELECT key, value FROM settings") as $row) {
        $out[$row['key']] = $row['value'];
    }
    return $out;
}

/** Fetch a single batch or null. */
function get_batch($id) {
    $stmt = db()->prepare("SELECT * FROM batches WHERE id = ?");
    $stmt->execute([(int)$id]);
    $batch = $stmt->fetch();
    if (!$batch) {
        return null;
    }

    $batch['columns'] = json_decode($batch['columns_json'], true) ?: [];
    $batch['mapping'] = json_decode($batch['mapping_json'], true) ?: [];
    return $batch;
}

/** Fetch a single row (with its batch) or null. */
function get_row($id) {
    $stmt = db()->prepare("SELECT r.*, b.name AS batch_name, b.columns_json, b.mapping_json
        FROM batch_rows r JOIN batches b ON b.id = r.batch_id WHERE r.id = ?");
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['data'] = json_decode($row['data_json'], true) ?: [];
    $row['result'] = json_decode($row['result_json'], true) ?: [];
    $row['raw'] = $row['raw_json'] !== '' ? json_decode($row['raw_json'], true) : null;
    $row['columns'] = json_decode($row['columns_json'], true) ?: [];
    $row['mapping'] = json_decode($row['mapping_json'], true) ?: [];
    return $row;
}

/** Aggregate search/process counts for one batch. */
function batch_counts($batch_id) {
    $stmt = db()->prepare("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(processed = 1), 0) AS processed,
        COALESCE(SUM(status = 'success'), 0) AS success,
        COALESCE(SUM(status = 'error'), 0) AS errors,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'success' AND processed = 0), 0) AS ready
        FROM batch_rows WHERE batch_id = ?");
    $stmt->execute([(int)$batch_id]);
    $counts = $stmt->fetch() ?: [];
    foreach ($counts as $key => $value) {
        $counts[$key] = (int)$value;
    }
    return $counts;
}

/** Counts for every batch the viewer can see, keyed by batch id. */
function batch_counts_all($userId = 0, $isAdmin = false) {
    $out = [];
    $sql = "SELECT r.batch_id,
        COUNT(*) AS total,
        COALESCE(SUM(r.processed = 1), 0) AS processed,
        COALESCE(SUM(r.status = 'success'), 0) AS success,
        COALESCE(SUM(r.status = 'error'), 0) AS errors,
        COALESCE(SUM(r.status = 'pending'), 0) AS pending
        FROM batch_rows r";
    if (!$isAdmin) {
        $sql .= " JOIN batches b ON b.id = r.batch_id WHERE b.user_id = ?";
    }
    $sql .= " GROUP BY r.batch_id";

    $stmt = db()->prepare($sql);
    $stmt->execute($isAdmin ? [] : [(int)$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['batch_id'];
        foreach ($row as $key => $value) {
            if ($key !== 'batch_id') {
                $row[$key] = (int)$value;
            }
        }
        $out[$id] = $row;
    }
    return $out;
}

/** Aggregate counts, limited to the viewer's batches unless they are an administrator. */
function global_counts($userId = 0, $isAdmin = false) {
    if ($isAdmin) {
        $row = db()->query("SELECT
            (SELECT COUNT(*) FROM batches) AS batches,
            (SELECT COUNT(*) FROM batch_rows) AS rows,
            (SELECT COUNT(*) FROM batch_rows WHERE status = 'success') AS success,
            (SELECT COUNT(*) FROM batch_rows WHERE status = 'error') AS errors,
            (SELECT COUNT(*) FROM batch_rows WHERE processed = 1) AS processed
        ")->fetch();
    } else {
        $stmt = db()->prepare("SELECT
            (SELECT COUNT(*) FROM batches WHERE user_id = ?) AS batches,
            (SELECT COUNT(*) FROM batch_rows r JOIN batches b ON b.id = r.batch_id WHERE b.user_id = ?) AS rows,
            (SELECT COUNT(*) FROM batch_rows r JOIN batches b ON b.id = r.batch_id WHERE b.user_id = ? AND r.status = 'success') AS success,
            (SELECT COUNT(*) FROM batch_rows r JOIN batches b ON b.id = r.batch_id WHERE b.user_id = ? AND r.status = 'error') AS errors,
            (SELECT COUNT(*) FROM batch_rows r JOIN batches b ON b.id = r.batch_id WHERE b.user_id = ? AND r.processed = 1) AS processed
        ");
        $stmt->execute(array_fill(0, 5, (int)$userId));
        $row = $stmt->fetch();
    }
    foreach ($row as $key => $value) {
        $row[$key] = (int)$value;
    }
    return $row;
}

/** Delete a batch together with its stored source file. */
function delete_batch($id) {
    $batch = get_batch($id);
    if (!$batch) {
        return false;
    }
    if (!empty($batch['stored_file'])) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($batch['stored_file']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $stmt = db()->prepare("DELETE FROM batches WHERE id = ?");
    $stmt->execute([(int)$id]);
    return true;
}

/* -------------------------------------------------------------------------
 * Batch + row operations
 * ---------------------------------------------------------------------- */

/**
 * Insert a batch and its rows in one transaction.
 *
 * @param array $meta Keys: name, filename, stored_file, sheet, columns, mapping,
 *                    query_template, extra_query, order_by, user_id.
 * @param array $rows List of associative row arrays.
 * @return int The new batch id.
 */
function create_batch(array $meta, array $rows) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO batches
            (name, filename, stored_file, sheet, columns_json, mapping_json, query_template, extra_query, order_by, row_count, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (string)($meta['name'] ?? ''),
            (string)($meta['filename'] ?? ''),
            (string)($meta['stored_file'] ?? ''),
            (string)($meta['sheet'] ?? ''),
            json_encode(array_values($meta['columns'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode((object)($meta['mapping'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string)($meta['query_template'] ?? ''),
            (string)($meta['extra_query'] ?? ''),
            (string)($meta['order_by'] ?? 'bestMatch'),
            count($rows),
            (int)($meta['user_id'] ?? 0),
        ]);
        $id = (int)$pdo->lastInsertId();

        $rowStmt = $pdo->prepare("INSERT INTO batch_rows (batch_id, row_num, data_json) VALUES (?, ?, ?)");
        $number = 0;
        foreach ($rows as $row) {
            $number++;
            $rowStmt->execute([
                $id,
                $number,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Replace every row in a batch (used when re-importing another worksheet). */
function replace_batch_rows($batch_id, array $rows) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM batch_rows WHERE batch_id = ?")->execute([(int)$batch_id]);

        $rowStmt = $pdo->prepare("INSERT INTO batch_rows (batch_id, row_num, data_json) VALUES (?, ?, ?)");
        $number = 0;
        foreach ($rows as $row) {
            $number++;
            $rowStmt->execute([
                (int)$batch_id,
                $number,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $pdo->prepare("UPDATE batches SET row_count = ?, updated = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$number, (int)$batch_id]);

        $pdo->commit();
        return $number;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Update whitelisted batch columns. */
function update_batch($id, array $fields) {
    $allowed = ['name', 'filename', 'stored_file', 'sheet', 'columns_json', 'mapping_json',
        'query_template', 'extra_query', 'order_by', 'row_count'];
    $sets = [];
    $values = [];
    foreach ($allowed as $column) {
        if (array_key_exists($column, $fields)) {
            $sets[] = $column . ' = ?';
            $values[] = $fields[$column];
        }
    }
    if (empty($sets)) {
        return;
    }
    $sets[] = 'updated = CURRENT_TIMESTAMP';
    $values[] = (int)$id;
    $stmt = db()->prepare("UPDATE batches SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($values);
}

/** Batches visible to the viewer, newest first, with aggregate counts. */
function batches_list($userId = 0, $isAdmin = false) {
    $counts = batch_counts_all($userId, $isAdmin);
    $sql = "SELECT b.*, u.lan_id AS owner_lan, u.name_first AS owner_first, u.name_last AS owner_last
        FROM batches b
        LEFT JOIN users u ON u.id = b.user_id";
    if (!$isAdmin) {
        $sql .= " WHERE b.user_id = ?";
    }
    $sql .= " ORDER BY b.id DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($isAdmin ? [] : [(int)$userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $batch) {
        $id = (int)$batch['id'];
        $batch['counts'] = $counts[$id] ?? [
            'total' => (int)$batch['row_count'], 'processed' => 0, 'success' => 0,
            'errors' => 0, 'pending' => (int)$batch['row_count'],
        ];
        $out[] = $batch;
    }
    return $out;
}

/**
 * Build the WHERE clause for a batch row query.
 *
 * @param array $filters status, processed, q
 * @return array{0:string,1:array}
 */
function batch_rows_where($batch_id, array $filters = []) {
    $where = ['r.batch_id = ?'];
    $params = [(int)$batch_id];

    $status = (string)($filters['status'] ?? '');
    if (in_array($status, ['success', 'error', 'pending'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }

    $processed = (string)($filters['processed'] ?? '');
    if ($processed === '1' || $processed === '0') {
        $where[] = 'r.processed = ?';
        $params[] = (int)$processed;
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(r.oclc_number LIKE ? OR r.data_json LIKE ? OR r.result_json LIKE ?
            OR r.search_query LIKE ? OR r.error LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }

    return [implode(' AND ', $where), $params];
}

/** Count rows matching the filters. */
function batch_rows_count($batch_id, array $filters = []) {
    [$where, $params] = batch_rows_where($batch_id, $filters);
    $stmt = db()->prepare("SELECT COUNT(*) FROM batch_rows r WHERE " . $where);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch a page of rows for a batch.
 *
 * @param string $sort row_num|oclc_number|status|processed
 * @param string $dir  asc|desc
 */
function batch_rows_page($batch_id, array $filters = [], $limit = 25, $offset = 0, $sort = 'row_num', $dir = 'asc') {
    [$where, $params] = batch_rows_where($batch_id, $filters);
    $sortable = ['row_num', 'oclc_number', 'status', 'processed', 'updated'];
    if (!in_array($sort, $sortable, true)) {
        $sort = 'row_num';
    }
    $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
    $limit = max(1, min(1000, (int)$limit));
    $offset = max(0, (int)$offset);

    $sql = "SELECT r.* FROM batch_rows r WHERE " . $where
        . " ORDER BY r." . $sort . " " . $dir . ", r.row_num ASC"
        . " LIMIT " . $limit . " OFFSET " . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Fetch rows for a batch without pagination (exports). */
function batch_rows_all($batch_id, array $filters = []) {
    [$where, $params] = batch_rows_where($batch_id, $filters);
    $stmt = db()->prepare("SELECT r.* FROM batch_rows r WHERE " . $where . " ORDER BY r.row_num ASC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Fetch specific rows belonging to a batch, in row order. */
function batch_rows_by_ids($batch_id, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM batch_rows WHERE batch_id = ? AND id IN (" . $placeholders . ")
        ORDER BY row_num ASC");
    $stmt->execute(array_merge([(int)$batch_id], $ids));
    return $stmt->fetchAll();
}

/** Fetch the next rows to search for a scope. */
function batch_rows_queue($batch_id, $scope = 'pending', $limit = 10) {
    $status = $scope === 'error' ? 'error' : 'pending';
    $limit = max(1, min(50, (int)$limit));
    $stmt = db()->prepare("SELECT * FROM batch_rows WHERE batch_id = ? AND status = ?
        ORDER BY row_num ASC LIMIT " . $limit);
    $stmt->execute([(int)$batch_id, $status]);
    return $stmt->fetchAll();
}

/** Clear search results for a set of rows (or the whole batch). */
function reset_batch_results($batch_id, array $ids = []) {
    $params = [(int)$batch_id];
    $sql = "UPDATE batch_rows SET result_json = '{}', raw_json = '', oclc_number = '',
        search_query = '', status = 'pending', error = '', processed = 0,
        updated = CURRENT_TIMESTAMP WHERE batch_id = ?";
    if (!empty($ids)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $sql .= " AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Mark rows as processed / unprocessed, optionally limited to one batch. */
function set_processed(array $ids, $processed, $batch_id = 0) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE batch_rows SET processed = ?, updated = CURRENT_TIMESTAMP
        WHERE id IN (" . $placeholders . ")";
    $params = array_merge([$processed ? 1 : 0], $ids);
    if ((int)$batch_id > 0) {
        $sql .= " AND batch_id = ?";
        $params[] = (int)$batch_id;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Mark every successful row in a batch as processed. */
function process_all_found($batch_id) {
    $stmt = db()->prepare("UPDATE batch_rows SET processed = 1, updated = CURRENT_TIMESTAMP
        WHERE batch_id = ? AND status = 'success'");
    $stmt->execute([(int)$batch_id]);
    return $stmt->rowCount();
}

/** Count processed rows for a batch. */
function processed_count($batch_id) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM batch_rows WHERE batch_id = ? AND processed = 1");
    $stmt->execute([(int)$batch_id]);
    return (int)$stmt->fetchColumn();
}

/** The viewer's batch that most recently received processed rows, else null. */
function latest_processed_batch($userId = 0, $isAdmin = false) {
    $sql = "SELECT r.batch_id FROM batch_rows r";
    if (!$isAdmin) {
        $sql .= " JOIN batches b ON b.id = r.batch_id";
    }
    $sql .= " WHERE r.processed = 1";
    if (!$isAdmin) {
        $sql .= " AND b.user_id = ?";
    }
    $sql .= " ORDER BY r.updated DESC, r.id DESC LIMIT 1";

    $stmt = db()->prepare($sql);
    $stmt->execute($isAdmin ? [] : [(int)$userId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['batch_id'] : null;
}

/* -------------------------------------------------------------------------
 * Authorized users
 * ---------------------------------------------------------------------- */

/** Fetch one authorized user by id. */
function user_get($id) {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/** Fetch one authorized user by sign-in ID (case-insensitive). */
function user_by_lan($lan_id) {
    $stmt = db()->prepare("SELECT * FROM users WHERE UPPER(lan_id) = ?");
    $stmt->execute([strtoupper(trim((string)$lan_id))]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/** List authorized users, optionally filtered by sign-in ID or name. */
function users_list($find = '') {
    $find = trim((string)$find);
    if ($find === '') {
        return db()->query("SELECT * FROM users ORDER BY lan_id ASC")->fetchAll();
    }
    $like = '%' . $find . '%';
    $stmt = db()->prepare("SELECT * FROM users
        WHERE lan_id LIKE ? OR name_first LIKE ? OR name_last LIKE ? OR email LIKE ?
        ORDER BY lan_id ASC");
    $stmt->execute([$like, $like, $like, $like]);
    return $stmt->fetchAll();
}

/**
 * Insert or update a user.
 *
 * @param array $data id (0 for insert), lan_id, name_first, name_last,
 *                    email, employee_id, level, active
 * @return int user id
 */
function user_save(array $data) {
    $id = (int)($data['id'] ?? 0);
    $values = [
        strtoupper(trim((string)($data['lan_id'] ?? ''))),
        trim((string)($data['name_first'] ?? '')),
        trim((string)($data['name_last'] ?? '')),
        trim((string)($data['email'] ?? '')),
        trim((string)($data['employee_id'] ?? '')),
        (int)($data['level'] ?? LEVEL_STAFF),
        !empty($data['active']) ? 1 : 0,
    ];
    $hasPasswordHash = array_key_exists('password_hash', $data);

    if ($id > 0) {
        $sets = [
            'lan_id = ?', 'name_first = ?', 'name_last = ?', 'email = ?',
            'employee_id = ?', 'level = ?', 'active = ?',
        ];
        $params = $values;
        if ($hasPasswordHash) {
            $sets[] = 'password_hash = ?';
            $params[] = (string)$data['password_hash'];
        }
        $sets[] = 'updated = CURRENT_TIMESTAMP';
        $params[] = $id;

        $stmt = db()->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);
        return $id;
    }

    if ($hasPasswordHash) {
        $values[] = (string)$data['password_hash'];
        $stmt = db()->prepare("INSERT INTO users
            (lan_id, name_first, name_last, email, employee_id, level, active, password_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    } else {
        $stmt = db()->prepare("INSERT INTO users
            (lan_id, name_first, name_last, email, employee_id, level, active)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
    }
    $stmt->execute($values);
    return (int)db()->lastInsertId();
}

/** Delete an authorized user. */
function user_delete($id) {
    $stmt = db()->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->rowCount() > 0;
}

/** Number of active administrator accounts. */
function active_admin_count() {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE active = 1 AND level >= " . LEVEL_ADMIN)->fetchColumn();
}

/** Number of active administrators who can sign in with a local password. */
function active_admin_password_count() {
    return (int)db()->query("SELECT COUNT(*) FROM users
        WHERE active = 1 AND level >= " . LEVEL_ADMIN . " AND password_hash <> ''")->fetchColumn();
}

/** Store a new password hash for one user. */
function user_set_password_hash($id, $hash) {
    $stmt = db()->prepare("UPDATE users SET password_hash = ?, updated = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(string)$hash, (int)$id]);
    return $stmt->rowCount() > 0;
}

/** Record a successful sign-in and refresh directory details. */
function user_touch_login($id, array $details = []) {
    $sets = ["last_login = datetime('now', 'localtime')", 'updated = CURRENT_TIMESTAMP'];
    $values = [];
    if (!empty($details['name_first'])) {
        $sets[] = 'name_first = ?';
        $values[] = (string)$details['name_first'];
    }
    if (!empty($details['name_last'])) {
        $sets[] = 'name_last = ?';
        $values[] = (string)$details['name_last'];
    }
    if (!empty($details['email'])) {
        $sets[] = 'email = ?';
        $values[] = (string)$details['email'];
    }
    if (!empty($details['employee_id'])) {
        $sets[] = 'employee_id = ?';
        $values[] = (string)$details['employee_id'];
    }
    $values[] = (int)$id;
    $stmt = db()->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($values);
}

db_init();
