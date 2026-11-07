<?php
require_once __DIR__ . '/db.php';

/** HTML escape. */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* -------------------------------------------------------------------------
 * CSRF protection
 * ---------------------------------------------------------------------- */

function csrf_token() {
    if (empty($_SESSION['mirrorcat_csrf'])) {
        $_SESSION['mirrorcat_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mirrorcat_csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check() {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if (is_json_request()) {
            json_out(['success' => false, 'error' => 'Your session expired. Please reload the page.'], 419);
        }
        http_response_code(419);
        exit('Invalid or expired CSRF token. Please go back and reload the page.');
    }
}

function is_json_request() {
    return stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || ($_GET['action'] ?? '') === 'search';
}

/* -------------------------------------------------------------------------
 * Redirects, flash messages and JSON
 * ---------------------------------------------------------------------- */

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function flash_set($type, $message) {
    $_SESSION['mirrorcat_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get() {
    if (empty($_SESSION['mirrorcat_flash'])) {
        return [];
    }
    $messages = $_SESSION['mirrorcat_flash'];
    unset($_SESSION['mirrorcat_flash']);
    return $messages;
}

function flash_render() {
    foreach (flash_get() as $flash) {
        $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
            . nl2br(h($flash['message']))
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

function json_out($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* -------------------------------------------------------------------------
 * OCLC index fields and query configuration
 * ---------------------------------------------------------------------- */

function oclc_index_fields() {
    return [
        'ti' => 'Title',
        'au' => 'Author / creator',
        'pb' => 'Publisher',
        'pl' => 'Publication place',
        'yr' => 'Year / publication date',
        'la' => 'Language',
        'bn' => 'ISBN',
        'in' => 'ISSN',
        'su' => 'Subject',
        'kw' => 'Keyword',
        'pn' => 'Contributor',
        'mt' => 'Material type',
        'cs' => 'Cataloging source',
        'dd' => 'Dewey Decimal number',
        'li' => 'LC call number',
        'ln' => 'LC control number',
        'sh' => 'Subject heading',
        'x0' => 'Local field x0',
        'x4' => 'Local field x4',
        'l8' => 'Local field l8',
        'zu' => 'Local field zu',
        'sg' => 'Local field sg',
        'pc' => 'Local field pc',
        'lv' => 'Local field lv',
    ];
}

/** OCLC index code => brief-bib JSON field. */
function oclc_index_to_api() {
    return [
        'ti' => 'title',
        'au' => 'creator',
        'la' => 'language',
        'pb' => 'publisher',
        'pl' => 'publicationPlace',
        'yr' => 'machineReadableDate',
        'bn' => 'isbns',
        'in' => 'issns',
        'pn' => 'contributor',
    ];
}

function oclc_restricted_first_fields() {
    return ['dd', 'yr', 'li', 'ln', 'll', 'mt', 'cs', 'x0', 'x4', 'l8', 'zu', 'sh', 'sg', 'pc', 'lv'];
}

function order_by_options() {
    return [
        'bestMatch' => 'Best match',
        'library' => 'Library',
        'recency' => 'Recency',
        'creator' => 'Creator',
        'publicationDateAsc' => 'Publication date (ascending)',
        'publicationDateDesc' => 'Publication date (descending)',
    ];
}

/**
 * Guess which uploaded columns map to OCLC indexes based on their headers.
 */
function auto_map_columns(array $columns) {
    $aliases = [
        'ti' => ['ti', 'title', 'book title', 'title statement', 'titles'],
        'au' => ['au', 'author', 'authors', 'creator', 'creators', 'name', 'names'],
        'pb' => ['pb', 'publisher', 'publishers', 'publisher name'],
        'pl' => ['pl', 'place', 'publication place', 'publicationplace', 'pub place', 'city'],
        'yr' => ['yr', 'year', 'date', 'pub date', 'publication date', 'publication year', 'copyright'],
        'la' => ['la', 'language', 'lang', 'languages'],
        'bn' => ['bn', 'isbn', 'isbns', 'isbn 10', 'isbn 13', 'isbn10', 'isbn13'],
        'in' => ['in', 'issn', 'issns'],
        'su' => ['su', 'subject', 'subjects', 'subject heading', 'topic'],
        'kw' => ['kw', 'keyword', 'keywords'],
        'pn' => ['pn', 'contributor', 'contributors'],
        'mt' => ['mt', 'material type', 'format'],
    ];

    $mapping = [];
    foreach ($columns as $column) {
        $raw = trim((string)$column);
        if ($raw === '' || strpos($raw, '!') === 0) {
            continue;
        }

        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $raw)));
        $found = null;
        foreach ($aliases as $index => $names) {
            foreach ($names as $name) {
                if ($normalized === $name || strpos($normalized, $name . ' ') === 0) {
                    $found = $index;
                    break 2;
                }
            }
        }

        if ($found !== null) {
            $mapping[$raw] = $found;
        }
    }

    return $mapping;
}

/**
 * Validate a submitted column => OCLC index mapping.
 */
function clean_mapping($mapping, array $columns) {
    $allowed = array_keys(oclc_index_fields());
    $clean = [];
    if (!is_array($mapping)) {
        return $clean;
    }
    foreach ($columns as $column) {
        if (!array_key_exists($column, $mapping)) {
            continue;
        }
        $index = trim((string)$mapping[$column]);
        if ($index !== '' && in_array($index, $allowed, true)) {
            $clean[$column] = $index;
        }
    }
    return $clean;
}

/**
 * Ordered result-table columns for a batch.
 */
function table_column_keys(array $batch) {
    $columns = $batch['columns'] ?? [];
    $mapping = $batch['mapping'] ?? [];
    $apiMap = oclc_index_to_api();
    $keys = ['oclc_number'];

    foreach ($columns as $column) {
        $keys[] = $column;
        $index = $mapping[$column] ?? '';
        // Only add a paired retrieved column when the brief-bib record exposes
        // that index; restricted indexes are covered by the fallbacks below.
        if ($index !== '' && isset($apiMap[$index])) {
            $keys[] = $column . '_new';
        }
    }

    // Add generic retrieved columns when the corresponding index is not mapped.
    $mappedIndexes = array_values($mapping);
    $fallbacks = [
        'ti' => 'title_new',
        'au' => 'creator_new',
        'pb' => 'publisher_new',
        'yr' => 'date_new',
    ];
    foreach ($fallbacks as $index => $extra) {
        if (!in_array($index, $mappedIndexes, true) && !in_array($extra, $keys, true)) {
            $keys[] = $extra;
        }
    }

    $keys[] = 'error';
    $keys[] = 'search_query';

    return array_values(array_unique($keys));
}

function column_label($key, array $batch) {
    if ($key === 'oclc_number') {
        return 'OCLC number';
    }
    if ($key === 'error') {
        return 'Error';
    }
    if ($key === 'search_query') {
        return 'Search query';
    }
    if (substr($key, -4) === '_new') {
        $base = substr($key, 0, -4);
        if (in_array($base, $batch['columns'] ?? [], true)) {
            return $base . ' (retrieved)';
        }
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', $base);
        return ucfirst($label) . ' (retrieved)';
    }
    return $key;
}

/** Extract the display value for a column from a hydrated row. */
function row_column_value(array $row, $key) {
    if ($key === 'oclc_number') {
        return $row['oclc_number'] ?? '';
    }
    if ($key === 'error') {
        return $row['error'] ?? '';
    }
    if ($key === 'search_query') {
        return $row['search_query'] ?? '';
    }
    if (substr($key, -4) === '_new') {
        return $row['result'][$key] ?? '';
    }
    return $row['data'][$key] ?? '';
}

/** Decode JSON columns on a list of batch rows. */
function hydrate_rows(array $rows) {
    foreach ($rows as &$row) {
        $row['data'] = json_decode($row['data_json'] ?? '{}', true) ?: [];
        $row['result'] = json_decode($row['result_json'] ?? '{}', true) ?: [];
    }
    unset($row);
    return $rows;
}

/** Flatten a nested API record into a readable key/value list. */
function flatten_array($data, $prefix = '', array &$out = []) {
    if (!is_array($data)) {
        $out[$prefix] = (string)$data;
        return $out;
    }
    foreach ($data as $key => $value) {
        $name = $prefix === '' ? (string)$key : $prefix . '_' . $key;
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $parts = [];
                foreach ($value as $item) {
                    $parts[] = is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string)$item;
                }
                $out[$name] = implode(', ', $parts);
            } else {
                flatten_array($value, $name, $out);
            }
        } else {
            $out[$name] = (string)$value;
        }
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Display helpers
 * ---------------------------------------------------------------------- */

function status_badge($status) {
    switch ($status) {
        case 'success':
            return '<span class="badge text-bg-success">Found</span>';
        case 'error':
            return '<span class="badge text-bg-danger">Error</span>';
        default:
            return '<span class="badge text-bg-secondary">Not searched</span>';
    }
}

function rows_per_page($requested = null) {
    $value = (int)($requested ?? ($_GET['per_page'] ?? setting_get('rows_per_page', '25')));
    $allowed = [10, 25, 50, 100, 200];
    if (!in_array($value, $allowed, true)) {
        $value = 25;
    }
    return $value;
}

/**
 * Render Bootstrap pagination. $query holds the base query string parameters.
 */
function render_pagination($page, $perPage, $total, array $query = []) {
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    if ($pages <= 1) {
        return;
    }

    $page = max(1, min($pages, (int)$page));
    $makeUrl = function ($p) use ($query, $perPage) {
        $query['page'] = $p;
        $query['per_page'] = $perPage;
        return '?' . http_build_query($query);
    };

    echo '<nav aria-label="Result pages"><ul class="pagination pagination-sm mb-0">';
    echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . h($makeUrl(max(1, $page - 1))) . '">Previous</a></li>';

    $start = max(1, $page - 2);
    $end = min($pages, $start + 4);
    $start = max(1, $end - 4);

    if ($start > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . h($makeUrl(1)) . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    for ($p = $start; $p <= $end; $p++) {
        echo '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . h($makeUrl($p)) . '">' . $p . '</a></li>';
    }

    if ($end < $pages) {
        if ($end < $pages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        echo '<li class="page-item"><a class="page-link" href="' . h($makeUrl($pages)) . '">' . $pages . '</a></li>';
    }

    echo '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . h($makeUrl(min($pages, $page + 1))) . '">Next</a></li>';
    echo '</ul></nav>';
}

/* -------------------------------------------------------------------------
 * Request helpers
 * ---------------------------------------------------------------------- */

/** Decode a JSON request body into an array (empty on failure). */
function json_input() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Collect submitted id lists from ids[], id, or a comma-separated ids value. */
function post_ids($key = 'ids') {
    $ids = [];

    if (isset($_POST[$key]) && is_array($_POST[$key])) {
        $ids = $_POST[$key];
    } elseif (isset($_POST[$key])) {
        $ids = explode(',', (string)$_POST[$key]);
    } elseif (isset($_GET[$key])) {
        $ids = is_array($_GET[$key]) ? $_GET[$key] : explode(',', (string)$_GET[$key]);
    }

    $ids = array_map('intval', $ids);
    return array_values(array_unique(array_filter($ids)));
}

/** Normalize the filters used by result tables and exports. */
function result_filters(array $source) {
    $filters = [];
    foreach (['status', 'processed', 'q'] as $key) {
        if (isset($source[$key])) {
            $filters[$key] = trim((string)$source[$key]);
        }
    }
    if (isset($filters['status']) && !in_array($filters['status'], ['success', 'error', 'pending'], true)) {
        unset($filters['status']);
    }
    if (isset($filters['processed']) && !in_array($filters['processed'], ['0', '1'], true)) {
        unset($filters['processed']);
    }
    return $filters;
}

/** Query string for the current result filters (without the page number). */
function filter_query(array $filters, array $extra = []) {
    $query = array_merge($filters, $extra);
    return array_filter($query, function ($value) {
        return $value !== '' && $value !== null;
    });
}

/** File name stem for downloads. */
function download_stem(array $batch) {
    $name = trim((string)($batch['name'] ?? ''));
    if ($name === '') {
        $name = 'batch-' . (int)($batch['id'] ?? 0);
    }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim($name, '-_.');
    return $name === '' ? 'results' : $name;
}

function human_file_size($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function short_text($text, $length = 90) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length - 1) . '…';
}

/* -------------------------------------------------------------------------
 * Upload storage
 * ---------------------------------------------------------------------- */

function upload_dir() {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        file_put_contents($index, '');
    }
    return $dir;
}

function safe_upload_name($original) {
    $base = pathinfo($original, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base);
    $base = trim($base, '-_.');
    if ($base === '') {
        $base = 'upload';
    }
    return substr($base, 0, 60) . '.' . $ext;
}
