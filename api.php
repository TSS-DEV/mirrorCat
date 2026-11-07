<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/oclc.php';

/**
 * Small JSON API used by the batch results screen.
 * Actions: search, details, test, counts
 */

auth_require_login(true);

$action = strtolower(trim((string)($_GET['action'] ?? '')));

try {
    switch ($action) {
        case 'details':
            api_details();
            break;

        case 'search':
            api_search();
            break;

        case 'test':
            auth_require_admin(true);
            api_test();
            break;

        default:
            json_out(['success' => false, 'error' => 'Unknown API action.'], 404);
    }
} catch (Throwable $e) {
    json_out(['success' => false, 'error' => $e->getMessage()], 500);
}

function api_details() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_out(['success' => false, 'error' => 'Use GET for row details.'], 405);
    }

    $id = (int)($_GET['id'] ?? 0);
    $row = $id > 0 ? get_row($id) : null;
    if (!$row || !auth_can_access_batch(get_batch((int)$row['batch_id']))) {
        json_out(['success' => false, 'error' => 'That row could not be found.'], 404);
    }

    $raw = $row['raw'];
    if (!is_array($raw) && !empty($row['raw_json'])) {
        $decoded = json_decode($row['raw_json'], true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($raw)) {
        $raw = [];
    }

    json_out([
        'success' => true,
        'row'     => [
            'id'           => (int)$row['id'],
            'batch_id'     => (int)$row['batch_id'],
            'row_num'      => (int)$row['row_num'],
            'status'       => (string)$row['status'],
            'error'        => (string)$row['error'],
            'oclc_number'  => (string)$row['oclc_number'],
            'search_query' => (string)$row['search_query'],
            'processed'    => (int)$row['processed'],
            'updated'      => (string)$row['updated'],
            'data'         => $row['data'],
            'fields'       => $row['result'],
            'raw'          => $raw,
        ],
    ]);
}

function api_search() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['success' => false, 'error' => 'Use POST to start a search.'], 405);
    }
    csrf_check();

    $input = json_input();
    $batchId = (int)($input['batch_id'] ?? ($_POST['batch_id'] ?? 0));
    $batch = $batchId > 0 ? auth_get_batch($batchId) : null;
    if (!$batch) {
        json_out(['success' => false, 'error' => 'That batch could not be found.'], 404);
    }

    $scope = strtolower(trim((string)($input['scope'] ?? 'pending')));
    if (!in_array($scope, ['pending', 'error'], true)) {
        $scope = 'pending';
    }

    $limit = (int)($input['limit'] ?? 0);
    if ($limit <= 0) {
        $limit = (int)setting_get('batch_size', '10');
    }
    $limit = max(1, min(50, $limit));

    if (!oclc_is_demo() && !oclc_configured()) {
        json_out([
            'success' => false,
            'error'   => 'WorldCat credentials are not configured yet. Add a WSKey and secret on the Settings page, or enable demo mode.',
        ], 422);
    }

    $queue = batch_rows_queue($batchId, $scope, $limit);
    $rows = [];

    if (!empty($queue)) {
        $result = oclc_search_rows($batch, $queue);
        $rows = $result['rows'];
        $halt = !empty($result['halt']);
        $message = (string)($result['message'] ?? '');
        $remaining = $result['remaining'] ?? null;
    } else {
        $halt = false;
        $message = '';
        $remaining = null;
    }

    $counts = batch_counts($batchId);
    $pendingRemaining = $scope === 'error' ? $counts['errors'] : $counts['pending'];

    json_out([
        'success'           => true,
        'scope'             => $scope,
        'rows'              => $rows,
        'searched'          => count($rows),
        'counts'            => $counts,
        'pending_remaining' => $pendingRemaining,
        'halt'              => $halt,
        'message'           => $message,
        'remaining'         => $remaining,
    ]);
}

function api_test() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['success' => false, 'error' => 'Use POST to test the connection.'], 405);
    }
    csrf_check();

    $result = oclc_test_connection();
    json_out([
        'success' => !empty($result['success']),
        'message' => (string)($result['message'] ?? ''),
        'error'   => empty($result['success']) ? (string)($result['message'] ?? 'The connection test failed.') : '',
    ]);
}
