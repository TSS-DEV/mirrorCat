<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/xlsx.php';
require_once __DIR__ . '/include/oclc.php';

auth_require_login();

/**
 * Download batch results as .xlsx or .csv.
 * Accepts either filters (GET) or an explicit list of row ids (POST form).
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    csrf_check();
}

$batchId = (int)($_REQUEST['batch'] ?? ($_GET['id'] ?? 0));
$batch = $batchId > 0 ? auth_get_batch($batchId) : null;
if (!$batch) {
    http_response_code(404);
    exit('That batch could not be found.');
}

$format = strtolower((string)($_REQUEST['format'] ?? 'xlsx'));
if (!in_array($format, ['xlsx', 'csv'], true)) {
    $format = 'xlsx';
}

$filters = result_filters($_REQUEST);
$ids = post_ids();
$rawRows = !empty($ids) ? batch_rows_by_ids($batchId, $ids) : batch_rows_all($batchId, $filters);
$rows = hydrate_rows($rawRows);

$columns = table_column_keys($batch);

$headers = ['Row', 'Status', 'Processed'];
foreach ($columns as $column) {
    $headers[] = column_label($column, $batch);
}

$data = [];
foreach ($rows as $row) {
    $line = [
        (int)$row['row_num'],
        (string)$row['status'],
        (int)$row['processed'] === 1 ? 'Yes' : 'No',
    ];
    foreach ($columns as $column) {
        $line[] = (string)row_column_value($row, $column);
    }
    $data[] = $line;
}

$stem = download_stem($batch) . '-results-' . date('Ymd');

if ($format === 'csv') {
    if (ob_get_level() > 0) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($data as $line) {
        fputcsv($out, $line, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

XlsxWriter::download($stem . '.xlsx', $headers, $data);
