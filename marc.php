<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/oclc.php';
require_once __DIR__ . '/include/xlsx.php';

auth_require_login();

/**
 * Generate MARCXML or raw MARC (ISO 2709) for selected processed rows.
 * POST only, because this calls the OCLC API.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Use the processed records page to generate MARC files.');
}

csrf_check();

$batchId = (int)($_POST['batch'] ?? 0);
$batch = $batchId > 0 ? auth_get_batch($batchId) : null;
if (!$batch) {
    http_response_code(404);
    exit('That batch could not be found.');
}

$back = (string)($_POST['back'] ?? '');
if ($back === '' || strpos($back, 'processed.php') !== 0) {
    $back = 'processed.php?batch=' . $batchId;
}

$format = strtolower((string)($_POST['format'] ?? $_GET['format'] ?? 'marcxml'));
if (!in_array($format, ['marcxml', 'marc'], true)) {
    $format = 'marcxml';
}

$ids = post_ids();
$rawRows = !empty($ids)
    ? batch_rows_by_ids($batchId, $ids)
    : batch_rows_all($batchId, ['processed' => '1']);
$rows = hydrate_rows($rawRows);

$rows = array_values(array_filter($rows, function ($row) {
    return trim((string)($row['oclc_number'] ?? '')) !== '';
}));

if (empty($rows)) {
    flash_set('danger', 'Select at least one processed row with an OCLC number before generating MARC.');
    redirect($back);
}

$max = max(1, (int)setting_get('max_marc_records', '250'));
if (count($rows) > $max) {
    flash_set('danger', 'You selected ' . count($rows) . ' records, but MARC generation is limited to ' . $max . ' at a time. Change the limit in Settings or select fewer rows.');
    redirect($back);
}

$result = oclc_generate_marc($rows, $format);
if (empty($result['content'])) {
    $message = 'No MARC records could be generated.';
    if (!empty($result['errors'])) {
        $message .= ' ' . implode(' ', array_slice($result['errors'], 0, 5));
    }
    flash_set('danger', $message);
    redirect($back);
}

if (!empty($result['errors'])) {
    flash_set('warning', count($result['errors']) . ' record(s) could not be retrieved: '
        . implode(' ', array_slice($result['errors'], 0, 3)));
}

$stem = download_stem($batch);
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem);
$extension = $format === 'marcxml' ? '.xml' : '.mrc';
$contentType = $format === 'marcxml'
    ? 'application/marcxml+xml; charset=utf-8'
    : 'application/marc';

if (ob_get_level() > 0) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . $extension . '"');
header('Content-Length: ' . strlen($result['content']));
header('Cache-Control: no-store');
header('X-Generated-Records: ' . (int)$result['returned']);

echo $result['content'];
exit;
