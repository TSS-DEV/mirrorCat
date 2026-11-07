<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/xlsx.php';
require_once __DIR__ . '/include/oclc.php';

auth_require_login();

$id = (int)($_GET['id'] ?? 0);
$batch = $id > 0 ? auth_get_batch($id) : null;

if (!$batch) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<div class="container py-5"><div class="alert alert-danger mb-3">That batch could not be found.</div>'
        . '<a class="btn btn-primary" href="index.php">Back to batches</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$action = (string)($_POST['action'] ?? '');
$back = (string)($_POST['back'] ?? '');
$backUrl = $back !== '' && strpos($back, 'batch.php') === 0 ? $back : 'batch.php?id=' . $id;

if ($action !== '') {
    csrf_check();
}

if ($action === 'save') {
    $columns = $batch['columns'];
    $mapping = clean_mapping($_POST['mapping'] ?? [], $columns);
    $orderBy = (string)($_POST['order_by'] ?? 'bestMatch');
    if (!array_key_exists($orderBy, order_by_options())) {
        $orderBy = 'bestMatch';
    }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $name = $batch['name'];
    }

    update_batch($id, [
        'name'           => $name,
        'mapping_json'   => json_encode((object)$mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'query_template' => trim((string)($_POST['query_template'] ?? '')),
        'extra_query'    => trim((string)($_POST['extra_query'] ?? '')),
        'order_by'       => $orderBy,
    ]);
    flash_set('success', 'Search configuration saved. Mapping ' . count($mapping) . ' column(s).');
    redirect($backUrl);
}

if ($action === 'process_selected' || $action === 'unprocess_selected' || $action === 'reset_selected') {
    $ids = post_ids();
    if (empty($ids)) {
        flash_set('warning', 'Select at least one row first.');
    } elseif ($action === 'reset_selected') {
        reset_batch_results($id, $ids);
        flash_set('success', count($ids) . ' row(s) were reset. Search them again to retrieve records.');
    } else {
        set_processed($ids, $action === 'process_selected', $id);
        flash_set('success', count($ids) . ' row(s) were updated.');
    }
    redirect($backUrl);
}

if ($action === 'process_row' || $action === 'unprocess_row') {
    $rowId = (int)($_POST['row_id'] ?? 0);
    if ($rowId > 0) {
        set_processed([$rowId], $action === 'process_row', $id);
    }
    redirect($backUrl);
}

if ($action === 'process_all') {
    $count = process_all_found($id);
    flash_set('success', $count . ' found record(s) were marked as processed.');
    redirect($backUrl);
}

if ($action === 'unprocess_all') {
    $ids = [];
    foreach (db()->query('SELECT id FROM batch_rows WHERE batch_id = ' . (int)$id . ' AND processed = 1') as $row) {
        $ids[] = (int)$row['id'];
    }
    $count = set_processed($ids, false, $id);
    flash_set('success', $count . ' record(s) were unprocessed.');
    redirect($backUrl);
}

if ($action === 'reset_all') {
    $count = reset_batch_results($id);
    flash_set('success', $count . ' row(s) were reset. Searches and processed flags were cleared.');
    redirect($backUrl);
}

if ($action === 'reimport') {
    $sheet = trim((string)($_POST['sheet'] ?? ''));
    $stored = (string)$batch['stored_file'];
    $path = __DIR__ . '/include/uploads/' . basename($stored);
    $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));

    if ($stored === '' || !is_file($path)) {
        flash_set('danger', 'The original spreadsheet is no longer available. Upload it again.');
        redirect($backUrl);
    }

    try {
        $maxRows = max(1, (int)setting_get('max_rows', '5000'));
        $parsed = mirrorcat_read_spreadsheet($path, $extension, $sheet !== '' ? $sheet : null, $maxRows);
        $oldMapping = $batch['mapping'];
        $mapping = auto_map_columns($parsed['headers']);
        foreach ($parsed['headers'] as $column) {
            if (isset($oldMapping[$column])) {
                $mapping[$column] = $oldMapping[$column];
            }
        }

        $count = replace_batch_rows($id, $parsed['rows']);
        update_batch($id, [
            'sheet'        => $sheet,
            'columns_json' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mapping_json' => json_encode((object)$mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        flash_set('success', 'Re-imported ' . $count . ' row(s)' . ($sheet !== '' ? ' from the "' . $sheet . '" sheet' : '') . '.');
    } catch (Throwable $e) {
        flash_set('danger', 'Could not re-import the spreadsheet: ' . $e->getMessage());
    }
    redirect($backUrl);
}

if ($action === 'delete') {
    if (delete_batch($id)) {
        flash_set('success', 'The batch was deleted.');
        redirect('index.php');
    }
    flash_set('danger', 'The batch could not be deleted.');
    redirect('index.php');
}

$batch = auth_get_batch($id);
$filters = result_filters($_GET);
$perPage = rows_per_page();
$total = batch_rows_count($id, $filters);
$pages = max(1, (int)ceil($total / max(1, $perPage)));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$rows = hydrate_rows(batch_rows_page($id, $filters, $perPage, ($page - 1) * $perPage));
$counts = batch_counts($id);
$demo = oclc_is_demo();
$configured = oclc_configured();
$chunk = max(1, min(50, (int)setting_get('batch_size', '10')));
$searchReady = $demo || $configured;

$allColumns = table_column_keys($batch);
$displayColumns = array_values(array_filter($allColumns, function ($key) {
    return $key !== 'search_query';
}));

$sampleRow = db()->prepare('SELECT data_json FROM batch_rows WHERE batch_id = ? ORDER BY row_num ASC LIMIT 1');
$sampleRow->execute([$id]);
$sampleJson = $sampleRow->fetchColumn();
$sample = $sampleJson ? (json_decode($sampleJson, true) ?: []) : [];

// Worksheet names, when the original workbook is still available.
$sheetNames = [];
$storedPath = __DIR__ . '/include/uploads/' . basename((string)$batch['stored_file']);
$storedType = strtolower(pathinfo((string)$batch['stored_file'], PATHINFO_EXTENSION));
if (is_file($storedPath) && in_array($storedType, ['xlsx', 'xlsm'], true)) {
    try {
        $reader = new XlsxReader($storedPath);
        $sheetNames = $reader->sheetNames();
    } catch (Throwable $e) {
        $sheetNames = [];
    }
}

$baseQuery = filter_query($filters, ['id' => $id, 'per_page' => $perPage]);
$firstShown = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$lastShown = min($total, $page * $perPage);

include __DIR__ . '/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <div class="text-muted small"><a href="index.php" class="text-decoration-none"><i class="bi bi-arrow-left"></i> All batches</a></div>
      <h1 class="h3 mb-1"><?php echo h($batch['name']); ?></h1>
      <div class="text-muted small">
        <?php echo h($batch['filename']); ?>
        <?php if (!empty($batch['sheet'])): ?>
          · sheet: <?php echo h($batch['sheet']); ?>
        <?php endif; ?>
        · created <?php echo h(substr((string)$batch['created'], 0, 16)); ?>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-primary btn-sm" href="download.php?batch=<?php echo $id; ?>&amp;format=xlsx">
        <i class="bi bi-file-earmark-excel"></i> Download results (.xlsx)
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="download.php?batch=<?php echo $id; ?>&amp;format=csv">
        <i class="bi bi-filetype-csv"></i> CSV
      </a>
      <?php if ((int)$counts['processed'] > 0): ?>
        <a class="btn btn-outline-success btn-sm" href="processed.php?batch=<?php echo $id; ?>">
          <i class="bi bi-check2-square"></i> Processed (<?php echo (int)$counts['processed']; ?>)
        </a>
      <?php endif; ?>
      <form method="post" action="batch.php?id=<?php echo $id; ?>" class="d-inline" data-confirm="Delete this batch, every row and the stored spreadsheet?">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash"></i> Delete</button>
      </form>
    </div>
  </div>

  <?php flash_render(); ?>

  <?php if (!$searchReady): ?>
    <?php if (auth_is_admin()): ?>
      <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-exclamation-triangle"></i> Add your OCLC WSKey and secret in Settings before searching. Demo mode can be enabled there for a no-credentials trial.</span>
        <a class="btn btn-sm btn-dark" href="settings.php">Open settings</a>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> WorldCat searching is not configured yet. Ask an administrator to add the OCLC credentials or enable demo mode.
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small text-uppercase">Rows</div>
        <div class="fs-4 fw-semibold"><?php echo (int)$counts['total']; ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small text-uppercase">Found</div>
        <div class="fs-4 fw-semibold text-success"><?php echo (int)$counts['success']; ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small text-uppercase">Errors</div>
        <div class="fs-4 fw-semibold text-danger"><?php echo (int)$counts['errors']; ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small text-uppercase">Not searched</div>
        <div class="fs-4 fw-semibold"><?php echo (int)$counts['pending']; ?></div>
      </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small text-uppercase">Processed</div>
        <div class="fs-4 fw-semibold text-primary"><?php echo (int)$counts['processed']; ?></div>
        <?php if ((int)$counts['ready'] > 0): ?>
          <div class="small text-muted"><?php echo (int)$counts['ready']; ?> ready to process</div>
        <?php endif; ?>
      </div></div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><i class="bi bi-sliders"></i> Search configuration</span>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#configPanel" aria-expanded="true" aria-controls="configPanel">
        Show / hide
      </button>
    </div>
    <div class="collapse show" id="configPanel">
      <form method="post" action="batch.php?id=<?php echo $id; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="back" value="<?php echo h('batch.php?' . http_build_query($baseQuery)); ?>">

        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-lg-4">
              <label class="form-label" for="name">Batch name</label>
              <input class="form-control" type="text" id="name" name="name" value="<?php echo h($batch['name']); ?>" maxlength="120">
            </div>
            <div class="col-lg-4">
              <label class="form-label" for="order_by">Result order</label>
              <select class="form-select" id="order_by" name="order_by">
                <?php foreach (order_by_options() as $value => $label): ?>
                  <option value="<?php echo h($value); ?>"<?php echo $batch['order_by'] === $value ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-4">
              <label class="form-label" for="extra_query">Always add to every query <span class="text-muted">(optional)</span></label>
              <input class="form-control" type="text" id="extra_query" name="extra_query" value="<?php echo h($batch['extra_query']); ?>" placeholder="e.g. mt:book">
            </div>
          </div>

          <?php if (!empty($sheetNames)): ?>
            <div class="alert alert-light border d-flex flex-wrap align-items-end gap-2">
              <div class="flex-grow-1" style="min-width: 220px;">
                <label class="form-label mb-1" for="sheet">Worksheet</label>
                <select class="form-select form-select-sm" id="sheet" name="sheet">
                  <?php foreach ($sheetNames as $sheetName): ?>
                    <option value="<?php echo h($sheetName); ?>"<?php echo $batch['sheet'] === $sheetName ? ' selected' : ''; ?>>
                      <?php echo h($sheetName); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-text mb-1">Re-import replaces the rows below with the chosen worksheet and resets search results.</div>
            </div>
          <?php endif; ?>

          <h2 class="h6 mt-4 mb-2">Column mapping</h2>
          <?php if (empty($batch['columns'])): ?>
            <div class="alert alert-warning mb-0">This batch has no columns.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width: 28%;">Spreadsheet column</th>
                    <th style="width: 32%;">Example value</th>
                    <th>WorldCat index</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($batch['columns'] as $column): ?>
                    <?php $mapped = $batch['mapping'][$column] ?? ''; ?>
                    <tr>
                      <td class="fw-semibold"><?php echo h($column); ?></td>
                      <td class="text-muted small text-truncate" style="max-width: 320px;"><?php echo h(short_text($sample[$column] ?? '', 70)); ?></td>
                      <td>
                        <select class="form-select form-select-sm" name="mapping[<?php echo h($column); ?>]">
                          <option value="">— ignore —</option>
                          <?php foreach (oclc_index_fields() as $code => $label): ?>
                            <option value="<?php echo h($code); ?>"<?php echo $mapped === $code ? ' selected' : ''; ?>>
                              <?php echo h($label . ' (' . $code . ')'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <div class="mt-4">
            <label class="form-label" for="query_template">Query template <span class="text-muted">(optional)</span></label>
            <textarea class="form-control font-monospace" id="query_template" name="query_template" rows="2" placeholder="ti:{Title} AND au:{Author}"><?php echo h($batch['query_template']); ?></textarea>
            <div class="form-text">
              Leave blank to build the query from the mapping above. Use <code>{Column Name}</code> to insert a value; Snapicat-style <code>ti:{}</code> uses the column mapped to <code>ti</code>. Values are joined with <code>AND</code>.
            </div>
          </div>

          <div class="alert alert-secondary mt-3 mb-0 small">
            <i class="bi bi-info-circle"></i>
            If a mapped value uses a restricted index (year, Dewey number, call number, etc.) as the first search term, <code>kw:book</code> is added automatically to keep WorldCat happy.
          </div>
        </div>

        <div class="card-footer bg-white d-flex flex-wrap gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save configuration</button>
          <?php if (!empty($sheetNames)): ?>
            <button class="btn btn-outline-secondary" type="submit" name="action" value="reimport"
                    formnovalidate data-confirm="Re-import this worksheet? Existing rows, results and processed flags will be replaced.">
              <i class="bi bi-arrow-repeat"></i> Re-import worksheet
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-search"></i> WorldCat search</div>
    <div class="card-body">
      <?php if ($demo): ?>
        <div class="alert alert-warning py-2 small"><i class="bi bi-flask"></i> Demo mode is on. Searches return generated records and never contact WorldCat.</div>
      <?php endif; ?>

      <?php if ((int)$counts['pending'] === 0 && (int)$counts['errors'] === 0): ?>
        <div class="alert alert-success mb-0">
          <i class="bi bi-check-circle"></i> Every row has been searched.
          <?php if ((int)$counts['ready'] > 0): ?>
            There <?php echo (int)$counts['ready'] === 1 ? 'is' : 'are'; ?> <?php echo (int)$counts['ready']; ?> found record(s) ready to process.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <p class="text-muted small mb-2">
          Searches run in batches of <?php echo $chunk; ?> row(s) so the browser can show progress and stop safely. Results are saved to the database as they arrive.
        </p>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <?php if ((int)$counts['pending'] > 0): ?>
            <button class="btn btn-primary" type="button"
                    data-search-scope="pending"
                    data-batch="<?php echo $id; ?>"
                    data-chunk="<?php echo $chunk; ?>"
                    <?php echo $searchReady ? '' : 'disabled'; ?>>
              <i class="bi bi-search"></i> Search <?php echo (int)$counts['pending']; ?> pending row(s)
            </button>
          <?php endif; ?>
          <?php if ((int)$counts['errors'] > 0): ?>
            <button class="btn btn-outline-danger" type="button"
                    data-search-scope="error"
                    data-batch="<?php echo $id; ?>"
                    data-chunk="<?php echo $chunk; ?>"
                    <?php echo $searchReady ? '' : 'disabled'; ?>>
              <i class="bi bi-arrow-repeat"></i> Retry <?php echo (int)$counts['errors']; ?> error(s)
            </button>
          <?php endif; ?>
          <button class="btn btn-outline-secondary" type="button" id="search-stop" disabled>
            <i class="bi bi-stop-circle"></i> Stop
          </button>
          <span class="spinner-border spinner-border-sm text-primary d-none" id="search-spinner" role="status" aria-hidden="true"></span>
        </div>
        <div class="progress mb-2" style="height: 1.5rem;" id="search-progress-wrap">
          <div class="progress-bar" id="search-progress" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
        </div>
        <div class="small text-muted mb-2" id="search-status">
          <?php echo (int)($counts['success'] + $counts['errors']); ?> of <?php echo (int)$counts['total']; ?> rows resolved.
        </div>
        <div id="search-alert" class="alert d-none mb-0" role="alert"></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span class="fw-semibold"><i class="bi bi-table"></i> Results</span>
      <span class="text-muted small">
        <?php if ($total > 0): ?>
          Showing <?php echo $firstShown; ?>–<?php echo $lastShown; ?> of <?php echo $total; ?> row(s)
        <?php else: ?>
          No rows match the current filters
        <?php endif; ?>
      </span>
    </div>

    <div class="card-body border-bottom">
      <form method="get" action="batch.php" class="row g-2 align-items-end">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <div class="col-md-4">
          <label class="form-label small mb-1" for="q">Search rows</label>
          <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?php echo h($filters['q'] ?? ''); ?>" placeholder="Title, ISBN, OCLC number, error…">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="status">Status</label>
          <select class="form-select form-select-sm" id="status" name="status">
            <option value="">All</option>
            <option value="success"<?php echo ($filters['status'] ?? '') === 'success' ? ' selected' : ''; ?>>Found</option>
            <option value="error"<?php echo ($filters['status'] ?? '') === 'error' ? ' selected' : ''; ?>>Errors</option>
            <option value="pending"<?php echo ($filters['status'] ?? '') === 'pending' ? ' selected' : ''; ?>>Not searched</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="processed">Processed</label>
          <select class="form-select form-select-sm" id="processed" name="processed">
            <option value="">All</option>
            <option value="1"<?php echo ($filters['processed'] ?? '') === '1' ? ' selected' : ''; ?>>Processed</option>
            <option value="0"<?php echo ($filters['processed'] ?? '') === '0' ? ' selected' : ''; ?>>Not processed</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="per_page">Rows per page</label>
          <select class="form-select form-select-sm" id="per_page" name="per_page">
            <?php foreach ([10, 25, 50, 100, 200] as $option): ?>
              <option value="<?php echo $option; ?>"<?php echo $perPage === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
          <button class="btn btn-sm btn-primary flex-grow-1" type="submit">Filter</button>
          <a class="btn btn-sm btn-outline-secondary" href="batch.php?id=<?php echo $id; ?>">Clear</a>
        </div>
      </form>
    </div>

    <form method="post" action="batch.php?id=<?php echo $id; ?>">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="back" value="<?php echo h('batch.php?' . http_build_query(array_merge($baseQuery, ['page' => $page]))); ?>">

      <div class="card-body border-bottom d-flex flex-wrap align-items-center gap-2">
        <div class="form-check me-2">
          <input class="form-check-input" type="checkbox" id="select-all" data-select-all="input[name='ids[]']">
          <label class="form-check-label small" for="select-all">Select all on page</label>
        </div>
        <button class="btn btn-sm btn-success" type="submit" name="action" value="process_selected">
          <i class="bi bi-check2-square"></i> Process selected
        </button>
        <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="unprocess_selected">
          Unprocess selected
        </button>
        <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="reset_selected"
                data-confirm="Clear the retrieved data for the selected rows?">
          <i class="bi bi-eraser"></i> Reset selected
        </button>
        <span class="vr d-none d-md-inline"></span>
        <?php if ((int)$counts['ready'] > 0): ?>
          <button class="btn btn-sm btn-outline-success" type="submit" name="action" value="process_all"
                  data-confirm="Mark every found record in this batch as processed?">
            Process all found (<?php echo (int)$counts['ready']; ?>)
          </button>
        <?php endif; ?>
        <?php if ((int)$counts['processed'] > 0): ?>
          <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="unprocess_all"
                  data-confirm="Unprocess every record in this batch?">
            Unprocess all (<?php echo (int)$counts['processed']; ?>)
          </button>
        <?php endif; ?>
        <?php if ((int)$counts['success'] > 0 || (int)$counts['errors'] > 0): ?>
          <button class="btn btn-sm btn-outline-danger ms-md-auto" type="submit" name="action" value="reset_all"
                  data-confirm="Reset every row in this batch? All retrieved data and processed flags will be cleared.">
            Reset entire batch
          </button>
        <?php endif; ?>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 results-table">
          <thead class="table-light">
            <tr>
              <th style="width: 2.5rem;"></th>
              <th class="text-nowrap">#</th>
              <?php foreach ($displayColumns as $column): ?>
                <th class="text-nowrap"><?php echo h(column_label($column, $batch)); ?></th>
              <?php endforeach; ?>
              <th class="text-nowrap">Status</th>
              <th class="text-nowrap text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="<?php echo count($displayColumns) + 4; ?>" class="text-center text-muted py-4">
                  No rows match the current filters.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr class="<?php echo (int)$row['processed'] === 1 ? 'table-success' : ''; ?>">
                  <td>
                    <input class="form-check-input" type="checkbox" name="ids[]" value="<?php echo (int)$row['id']; ?>" aria-label="Select row <?php echo (int)$row['row_num']; ?>">
                  </td>
                  <td class="text-muted small"><?php echo (int)$row['row_num']; ?></td>
                  <?php foreach ($displayColumns as $column): ?>
                    <?php
                    $value = row_column_value($row, $column);
                    if ($column === 'error') {
                        $display = $value === '' ? '' : short_text($value, 55);
                    } elseif ($column === 'oclc_number') {
                        $display = $value;
                    } else {
                        $display = short_text($value, 60);
                    }
                    ?>
                    <td class="small<?php echo $column === 'error' && $value !== '' ? ' text-danger' : ''; ?>">
                      <?php if ($column === 'oclc_number' && $value !== ''): ?>
                        <a href="https://search.worldcat.org/title/<?php echo h(rawurlencode($value)); ?>" target="_blank" rel="noopener"><?php echo h($value); ?></a>
                      <?php else: ?>
                        <span title="<?php echo h($value); ?>"><?php echo h($display); ?></span>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                  <td class="text-nowrap">
                    <?php echo status_badge($row['status']); ?>
                    <?php if ((int)$row['processed'] === 1): ?>
                      <span class="badge text-bg-primary">Processed</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary js-row-details" type="button"
                            data-row-id="<?php echo (int)$row['id']; ?>" title="Row details">
                      <i class="bi bi-eye"></i>
                    </button>
                    <?php if ((int)$row['processed'] === 1): ?>
                      <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="unprocess_row"
                              formnovalidate onclick="this.form.querySelector('[name=row_id]').value='<?php echo (int)$row['id']; ?>'"
                              title="Unprocess row">
                        <i class="bi bi-x-square"></i>
                      </button>
                    <?php elseif ($row['status'] === 'success'): ?>
                      <button class="btn btn-sm btn-success" type="submit" name="action" value="process_row"
                              formnovalidate onclick="this.form.querySelector('[name=row_id]').value='<?php echo (int)$row['id']; ?>'"
                              title="Mark as processed">
                        <i class="bi bi-check2-square"></i>
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <input type="hidden" name="row_id" value="">
    </form>

    <?php if ($total > 0): ?>
      <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted small">Page <?php echo $page; ?> of <?php echo $pages; ?></div>
        <?php render_pagination($page, $perPage, $total, $baseQuery); ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
include __DIR__ . '/footer.php';
?>
