<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/xlsx.php';
require_once __DIR__ . '/include/oclc.php';

auth_require_login();

$isAdmin = auth_is_admin();
$userId = auth_user_id();

$action = (string)($_POST['action'] ?? '');

if ($action === 'upload') {
    csrf_check();

    if (empty($_FILES['spreadsheet']) || !is_array($_FILES['spreadsheet'])) {
        flash_set('danger', 'Choose a spreadsheet to upload.');
        redirect('index.php');
    }

    $file = $_FILES['spreadsheet'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server upload limit (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the allowed size.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'Choose a spreadsheet to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        ];
        flash_set('danger', $messages[$file['error']] ?? 'The upload failed.');
        redirect('index.php');
    }

    $original = (string)($file['name'] ?? 'upload');
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xlsm', 'csv', 'txt'], true)) {
        flash_set('danger', 'Unsupported file type. Upload an .xlsx, .xlsm or .csv file. Older .xls files must be saved as .xlsx first.');
        redirect('index.php');
    }
    if ((int)$file['size'] > 25 * 1024 * 1024) {
        flash_set('danger', 'The file is too large (limit: 25 MB).');
        redirect('index.php');
    }

    $maxRows = max(1, (int)setting_get('max_rows', '5000'));
    $sheet = trim((string)($_POST['sheet'] ?? ''));
    $stored = date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
    $target = upload_dir() . DIRECTORY_SEPARATOR . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        flash_set('danger', 'The uploaded file could not be stored.');
        redirect('index.php');
    }

    try {
        $parsed = mirrorcat_read_spreadsheet($target, $extension, $sheet !== '' ? $sheet : null, $maxRows);
    } catch (Throwable $e) {
        @unlink($target);
        flash_set('danger', 'Could not read the spreadsheet: ' . $e->getMessage());
        redirect('index.php');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $name = pathinfo($original, PATHINFO_FILENAME);
    }
    if ($name === '') {
        $name = 'Imported batch';
    }

    $mapping = auto_map_columns($parsed['headers']);
    try {
        $batchId = create_batch([
            'name'          => $name,
            'filename'      => $original,
            'stored_file'   => $stored,
            'sheet'         => $sheet,
            'columns'       => $parsed['headers'],
            'mapping'       => $mapping,
            'query_template'=> setting_get('default_query_template', ''),
            'extra_query'   => '',
            'order_by'      => setting_get('default_order_by', 'bestMatch'),
            'user_id'       => $userId,
        ], $parsed['rows']);
    } catch (Throwable $e) {
        @unlink($target);
        flash_set('danger', 'Could not save the batch: ' . $e->getMessage());
        redirect('index.php');
    }

    $message = 'Imported ' . count($parsed['rows']) . ' row' . (count($parsed['rows']) === 1 ? '' : 's') . '. Review the column mapping and start the WorldCat search.';
    if (count($parsed['rows']) >= $maxRows) {
        $message .= ' The import stopped at the configured limit of ' . $maxRows . ' rows.';
    }
    flash_set('success', $message);
    redirect('batch.php?id=' . $batchId);
}

if ($action === 'delete') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $batch = $id > 0 ? auth_get_batch($id) : null;
    if ($batch && delete_batch($id)) {
        flash_set('success', 'Batch "' . $batch['name'] . '" was deleted.');
    } else {
        flash_set('danger', 'That batch could not be found.');
    }
    redirect('index.php');
}

$counts = global_counts($userId, $isAdmin);
$batches = batches_list($userId, $isAdmin);
$demo = oclc_is_demo();
$configured = oclc_configured();

include __DIR__ . '/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h3 mb-0">Upload &amp; batches</h1>
      <p class="text-muted mb-0">Import a spreadsheet, match columns to WorldCat indexes and retrieve records with the OCLC Metadata API.</p>
    </div>
    <div class="text-end">
      <?php if ($demo): ?>
        <span class="badge text-bg-warning">Demo mode — no live WorldCat calls</span>
      <?php elseif ($configured): ?>
        <span class="badge text-bg-success">OCLC credentials configured</span>
      <?php else: ?>
        <span class="badge text-bg-danger">OCLC credentials missing</span>
      <?php endif; ?>
    </div>
  </div>

  <?php flash_render(); ?>

  <?php if (!$demo && !$configured): ?>
    <?php if (auth_is_admin()): ?>
      <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-exclamation-triangle"></i> WorldCat searches need a WSKey and secret. Add them in Settings, or turn on demo mode to try the workflow with generated records.</span>
        <a class="btn btn-sm btn-dark" href="settings.php"><i class="bi bi-gear"></i> Open settings</a>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> WorldCat searches need a WSKey and secret. Ask an administrator to add them in Settings or enable demo mode.
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Batches</div>
          <div class="fs-3 fw-semibold"><?php echo (int)$counts['batches']; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Rows</div>
          <div class="fs-3 fw-semibold"><?php echo (int)$counts['rows']; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Found</div>
          <div class="fs-3 fw-semibold text-success"><?php echo (int)$counts['success']; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Errors</div>
          <div class="fs-3 fw-semibold text-danger"><?php echo (int)$counts['errors']; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Processed</div>
          <div class="fs-3 fw-semibold text-primary"><?php echo (int)$counts['processed']; ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-upload"></i> Import a spreadsheet</div>
        <div class="card-body">
          <form method="post" action="index.php" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload">

            <div class="mb-3">
              <label class="form-label" for="spreadsheet">Spreadsheet</label>
              <input class="form-control" type="file" id="spreadsheet" name="spreadsheet"
                     accept=".xlsx,.xlsm,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
              <div class="form-text">.xlsx, .xlsm or .csv. The first worksheet is imported; the header row must be the first non-empty row.</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="batch-name">Batch name <span class="text-muted">(optional)</span></label>
              <input class="form-control" type="text" id="batch-name" name="name" maxlength="120" placeholder="e.g. September new titles">
            </div>

            <div class="d-grid">
              <button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-arrow-up"></i> Upload &amp; continue</button>
            </div>
          </form>

          <hr>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-sm btn-outline-secondary" href="sample.php"><i class="bi bi-download"></i> Sample .xlsx</a>
            <span class="text-muted small">Try the workflow without your own file.</span>
          </div>

          <div class="alert alert-secondary mt-3 mb-0 small">
            <div class="fw-semibold mb-1">How it works</div>
            <ol class="mb-0 ps-3">
              <li>Upload the spreadsheet and check the column-to-index mapping.</li>
              <li>Search pending rows against WorldCat (results are saved per row).</li>
              <li>Mark the best records as processed and export Excel or MARC.</li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="fw-semibold"><i class="bi bi-collection"></i> <?php echo $isAdmin ? 'All batches' : 'Your batches'; ?></span>
          <span class="text-muted small"><?php echo count($batches); ?> total</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($batches)): ?>
            <div class="p-4 text-center text-muted">
              <i class="bi bi-inbox fs-1 d-block mb-2"></i>
              No batches yet. Upload a spreadsheet to get started.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Batch</th>
                    <th class="text-end">Rows</th>
                    <th class="text-end">Found</th>
                    <th class="text-end">Errors</th>
                    <th class="text-end">Pending</th>
                    <th class="text-end">Processed</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($batches as $batch): ?>
                    <?php $c = $batch['counts']; ?>
                    <tr>
                      <td>
                        <a class="fw-semibold text-decoration-none" href="batch.php?id=<?php echo (int)$batch['id']; ?>">
                          <?php echo h($batch['name']); ?>
                        </a>
                        <div class="text-muted small text-truncate" style="max-width: 260px;">
                          <?php echo h($batch['filename']); ?>
                          <?php if (!empty($batch['sheet'])): ?>
                            · sheet: <?php echo h($batch['sheet']); ?>
                          <?php endif; ?>
                          <?php if ($isAdmin): ?>
                            · owner: <?php echo h(($batch['owner_lan'] ?? '') !== '' ? $batch['owner_lan'] : 'unknown'); ?>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="text-end"><?php echo (int)$c['total']; ?></td>
                      <td class="text-end text-success"><?php echo (int)$c['success']; ?></td>
                      <td class="text-end text-danger"><?php echo (int)$c['errors']; ?></td>
                      <td class="text-end"><?php echo (int)$c['pending']; ?></td>
                      <td class="text-end"><?php echo (int)$c['processed']; ?></td>
                      <td class="text-nowrap small text-muted"><?php echo h(substr((string)$batch['created'], 0, 16)); ?></td>
                      <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="batch.php?id=<?php echo (int)$batch['id']; ?>" title="Open batch">
                          <i class="bi bi-folder2-open"></i>
                        </a>
                        <?php if ((int)$c['processed'] > 0): ?>
                          <a class="btn btn-sm btn-outline-success" href="processed.php?batch=<?php echo (int)$batch['id']; ?>" title="Processed records">
                            <i class="bi bi-check2-square"></i>
                          </a>
                        <?php endif; ?>
                        <form method="post" action="index.php" class="d-inline" data-confirm="Delete this batch and all of its rows?">
                          <?php echo csrf_field(); ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo (int)$batch['id']; ?>">
                          <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete batch">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
include __DIR__ . '/footer.php';
?>
