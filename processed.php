<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/oclc.php';

auth_require_login();

$isAdmin = auth_is_admin();
$userId = auth_user_id();

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    csrf_check();
}

$batches = batches_list($userId, $isAdmin);

$batchId = (int)($_GET['batch'] ?? ($_POST['batch'] ?? 0));
if ($batchId <= 0) {
    $latest = latest_processed_batch($userId, $isAdmin);
    if ($latest !== null) {
        $batchId = $latest;
    } elseif (!empty($batches)) {
        $batchId = (int)$batches[0]['id'];
    }
}

$batch = $batchId > 0 ? auth_get_batch($batchId) : null;
if ($batchId > 0 && !$batch) {
    $batchId = 0;
}

if ($action === 'unprocess' || $action === 'unprocess_all') {
    if (!$batch) {
        flash_set('danger', 'That batch could not be found.');
        redirect('processed.php');
    }
    if ($action === 'unprocess_all') {
        $ids = [];
        if ($batchId > 0) {
            $stmt = db()->prepare('SELECT id FROM batch_rows WHERE batch_id = ? AND processed = 1');
            $stmt->execute([$batchId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $count = set_processed($ids, false, $batchId);
        flash_set('success', $count . ' record(s) were unprocessed.');
    } else {
        $ids = post_ids();
        $single = (int)($_POST['unprocess_id'] ?? 0);
        if ($single > 0) {
            $ids = [$single];
        }
        if (empty($ids)) {
            flash_set('warning', 'Select at least one processed record first.');
        } else {
            $count = set_processed($ids, false, $batchId);
            flash_set('success', $count . ' record(s) were unprocessed.');
        }
    }
    redirect('processed.php?batch=' . $batchId);
}

$counts = $batch ? batch_counts($batchId) : null;

$filters = ['processed' => '1'];
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $filters['q'] = $q;
}
$perPage = rows_per_page();
$total = $batch ? batch_rows_count($batchId, $filters) : 0;
$pages = max(1, (int)ceil($total / max(1, $perPage)));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$rows = $batch ? hydrate_rows(batch_rows_page($batchId, $filters, $perPage, ($page - 1) * $perPage)) : [];

$maxMarc = max(1, (int)setting_get('max_marc_records', '250'));
$demo = oclc_is_demo();
$batchQuery = ['batch' => $batchId, 'per_page' => $perPage];
if ($q !== '') {
    $batchQuery['q'] = $q;
}
$backUrl = 'processed.php?' . http_build_query($batchQuery);

function processed_title(array $row) {
    $title = $row['result']['title_new'] ?? '';
    if (trim((string)$title) === '') {
        foreach (($row['data'] ?? []) as $value) {
            if (trim((string)$value) !== '') {
                $title = $value;
                break;
            }
        }
    }
    return short_text((string)$title, 80);
}

function processed_subtitle(array $row) {
    $parts = [];
    foreach (['creator_new', 'publisher_new', 'publicationPlace_new', 'date_new'] as $key) {
        $value = trim((string)($row['result'][$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    return short_text(implode(' · ', $parts), 90);
}

include __DIR__ . '/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="bi bi-check2-square"></i> Processed records</h1>
      <p class="text-muted mb-0">Review the records you have accepted, then export them to Excel or MARC.</p>
    </div>
    <?php if ($batch): ?>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary btn-sm" href="batch.php?id=<?php echo (int)$batch['id']; ?>">
          <i class="bi bi-folder2-open"></i> Open batch
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="download.php?batch=<?php echo (int)$batch['id']; ?>&amp;format=xlsx&amp;processed=1">
          <i class="bi bi-file-earmark-excel"></i> Excel (all processed)
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php flash_render(); ?>

  <?php if (empty($batches)): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-5">
        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
        <p class="mb-3">No batches yet. Upload a spreadsheet and process some records first.</p>
        <a class="btn btn-primary" href="index.php"><i class="bi bi-upload"></i> Upload a spreadsheet</a>
      </div>
    </div>
  <?php else: ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <form method="get" action="processed.php" class="row g-2 align-items-end">
          <div class="col-md-6">
            <label class="form-label small mb-1" for="batch">Batch</label>
            <select class="form-select" id="batch" name="batch" onchange="this.form.submit()">
              <?php foreach ($batches as $item): ?>
                <option value="<?php echo (int)$item['id']; ?>"<?php echo (int)$item['id'] === $batchId ? ' selected' : ''; ?>>
                  <?php echo h($item['name']); ?>
                  — <?php echo (int)$item['counts']['processed']; ?> processed
                  (<?php echo h($item['filename']); ?><?php if ($isAdmin && !empty($item['owner_lan'])): ?> · <?php echo h($item['owner_lan']); ?><?php endif; ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-1" for="q">Search processed records</label>
            <input class="form-control" type="search" id="q" name="q" value="<?php echo h($q); ?>" placeholder="Title, ISBN, OCLC number…">
          </div>
          <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1" type="submit">Filter</button>
            <a class="btn btn-outline-secondary" href="processed.php?batch=<?php echo $batchId; ?>">Clear</a>
          </div>
        </form>
      </div>
    </div>

    <?php if (!$batch): ?>
      <div class="alert alert-warning">The selected batch could not be found.</div>
    <?php else: ?>
      <?php if ($demo): ?>
        <div class="alert alert-warning py-2 small"><i class="bi bi-flask"></i> Demo mode: MARC files are generated locally from the retrieved brief-bib data.</div>
      <?php endif; ?>

      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="card border-0 shadow-sm"><div class="card-body py-3">
            <div class="text-muted small text-uppercase">Processed</div>
            <div class="fs-4 fw-semibold text-primary"><?php echo (int)$counts['processed']; ?></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card border-0 shadow-sm"><div class="card-body py-3">
            <div class="text-muted small text-uppercase">Found</div>
            <div class="fs-4 fw-semibold text-success"><?php echo (int)$counts['success']; ?></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card border-0 shadow-sm"><div class="card-body py-3">
            <div class="text-muted small text-uppercase">Not processed</div>
            <div class="fs-4 fw-semibold"><?php echo max(0, (int)$counts['success'] - (int)$counts['processed']); ?></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card border-0 shadow-sm"><div class="card-body py-3">
            <div class="text-muted small text-uppercase">MARC limit</div>
            <div class="fs-4 fw-semibold"><?php echo $maxMarc; ?></div>
            <div class="small text-muted">records per export</div>
          </div></div>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="fw-semibold"><?php echo h($batch['name']); ?></span>
          <span class="text-muted small">
            <?php if ($total > 0): ?>
              <?php echo (($page - 1) * $perPage) + 1; ?>–<?php echo min($total, $page * $perPage); ?> of <?php echo $total; ?>
            <?php else: ?>
              No processed records match
            <?php endif; ?>
          </span>
        </div>

        <form method="post" action="processed.php?batch=<?php echo (int)$batchId; ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="batch" value="<?php echo (int)$batchId; ?>">
          <input type="hidden" name="processed" value="1">
          <input type="hidden" name="back" value="<?php echo h($backUrl); ?>">

          <div class="card-body border-bottom d-flex flex-wrap align-items-center gap-2">
            <div class="form-check me-2">
              <input class="form-check-input" type="checkbox" id="select-all" data-select-all="input[name='ids[]']">
              <label class="form-check-label small" for="select-all">Select all on page</label>
            </div>

            <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="unprocess">
              <i class="bi bi-x-square"></i> Unprocess selected
            </button>
            <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="unprocess_all"
                    data-confirm="Unprocess every record in this batch?">
              Unprocess all
            </button>

            <span class="vr d-none d-lg-inline"></span>

            <button class="btn btn-sm btn-primary" type="submit"
                    formaction="download.php?format=xlsx" formmethod="post">
              <i class="bi bi-file-earmark-excel"></i> Excel
            </button>
            <button class="btn btn-sm btn-outline-secondary" type="submit"
                    formaction="download.php?format=csv" formmethod="post">
              CSV
            </button>
            <button class="btn btn-sm btn-dark" type="submit"
                    formaction="marc.php?format=marcxml" formmethod="post">
              <i class="bi bi-filetype-xml"></i> MARCXML
            </button>
            <button class="btn btn-sm btn-dark" type="submit"
                    formaction="marc.php?format=marc" formmethod="post">
              <i class="bi bi-file-binary"></i> Raw MARC
            </button>

            <span class="text-muted small ms-auto">No selection = all processed records in this batch.</span>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width: 2.5rem;"></th>
                  <th>#</th>
                  <th>Record</th>
                  <th>OCLC</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                      <i class="bi bi-check2-circle fs-2 d-block mb-2"></i>
                      No processed records yet.
                      <a href="batch.php?id=<?php echo (int)$batchId; ?>">Search this batch</a> and mark the best matches as processed.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $row): ?>
                    <tr>
                      <td>
                        <input class="form-check-input" type="checkbox" name="ids[]" value="<?php echo (int)$row['id']; ?>" aria-label="Select row <?php echo (int)$row['row_num']; ?>">
                      </td>
                      <td class="text-muted small"><?php echo (int)$row['row_num']; ?></td>
                      <td>
                        <div class="fw-semibold"><?php echo h(processed_title($row)); ?></div>
                        <div class="text-muted small"><?php echo h(processed_subtitle($row)); ?></div>
                      </td>
                      <td class="text-nowrap">
                        <?php if ((string)$row['oclc_number'] !== ''): ?>
                          <a href="https://search.worldcat.org/title/<?php echo h(rawurlencode($row['oclc_number'])); ?>" target="_blank" rel="noopener">
                            <?php echo h($row['oclc_number']); ?>
                          </a>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-secondary js-row-details" type="button"
                                data-row-id="<?php echo (int)$row['id']; ?>" title="Row details">
                          <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="unprocess"
                                formnovalidate onclick="this.form.querySelector('[name=unprocess_id]').value='<?php echo (int)$row['id']; ?>'"
                                title="Unprocess this record">
                          <i class="bi bi-x-square"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <input type="hidden" name="unprocess_id" value="">
        </form>

        <?php if ($total > 0): ?>
          <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="text-muted small">Page <?php echo $page; ?> of <?php echo $pages; ?></div>
            <?php render_pagination($page, $perPage, $total, $batchQuery); ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
include __DIR__ . '/footer.php';
?>
