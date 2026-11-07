<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/oclc.php';

auth_require_admin();

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    csrf_check();
}

if ($action === 'save') {
    $ldapEnabled = !empty($_POST['ldap_enabled']);
    if (!$ldapEnabled && active_admin_password_count() < 1) {
        flash_set('danger', 'Set a local password for at least one active administrator on the Users page before disabling LDAP. No settings were changed.');
        redirect('settings.php');
    }

    $appName = trim((string)($_POST['app_name'] ?? ''));
    if ($appName === '') {
        $appName = 'mirrorCAT';
    }
    setting_set('app_name', mb_substr($appName, 0, 60));

    setting_set('ldap_enabled', $ldapEnabled ? '1' : '0');
    setting_set('ldap_server', trim((string)($_POST['ldap_server'] ?? '')) ?: (string)$g_ldap_server);
    setting_set('ldap_domain', trim((string)($_POST['ldap_domain'] ?? '')) ?: (string)$g_ldap_domain);
    setting_set('ldap_domain_nb', trim((string)($_POST['ldap_domain_nb'] ?? '')) ?: (string)$g_ldap_domain_nb);
    setting_set('ldap_base_dn', trim((string)($_POST['ldap_base_dn'] ?? '')));

    setting_set('oclc_wskey', trim((string)($_POST['oclc_wskey'] ?? '')));
    setting_set('oclc_principal_id', trim((string)($_POST['oclc_principal_id'] ?? '')));
    setting_set('oclc_principal_idns', trim((string)($_POST['oclc_principal_idns'] ?? '')));
    setting_set('oclc_scope', trim((string)($_POST['oclc_scope'] ?? '')) ?: OCLC_DEFAULT_SCOPE);

    $baseUrl = rtrim(trim((string)($_POST['oclc_base_url'] ?? '')), '/');
    if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
        $baseUrl = OCLC_DEFAULT_BASE;
    }
    setting_set('oclc_base_url', $baseUrl);

    $oauthUrl = trim((string)($_POST['oclc_oauth_url'] ?? ''));
    if ($oauthUrl === '' || !preg_match('#^https?://#i', $oauthUrl)) {
        $oauthUrl = OCLC_DEFAULT_OAUTH;
    }
    setting_set('oclc_oauth_url', $oauthUrl);

    $secret = trim((string)($_POST['oclc_secret'] ?? ''));
    if (!empty($_POST['clear_secret'])) {
        setting_set('oclc_secret', '');
    } elseif ($secret !== '') {
        setting_set('oclc_secret', $secret);
    }

    $orderBy = (string)($_POST['default_order_by'] ?? 'bestMatch');
    if (!array_key_exists($orderBy, order_by_options())) {
        $orderBy = 'bestMatch';
    }
    setting_set('default_order_by', $orderBy);
    setting_set('default_query_template', trim((string)($_POST['default_query_template'] ?? '')));

    $batchSize = max(1, min(50, (int)($_POST['batch_size'] ?? 10)));
    setting_set('batch_size', (string)$batchSize);

    $perPage = (int)($_POST['rows_per_page'] ?? 25);
    if (!in_array($perPage, [10, 25, 50, 100, 200], true)) {
        $perPage = 25;
    }
    setting_set('rows_per_page', (string)$perPage);

    $maxRows = max(1, min(100000, (int)($_POST['max_rows'] ?? 5000)));
    setting_set('max_rows', (string)$maxRows);

    $maxMarc = max(1, min(1000, (int)($_POST['max_marc_records'] ?? 250)));
    setting_set('max_marc_records', (string)$maxMarc);

    setting_set('demo_mode', !empty($_POST['demo_mode']) ? '1' : '0');

    oclc_clear_token();
    flash_set('success', 'Settings saved. The cached OCLC token was cleared so the next request uses the new credentials.');
    redirect('settings.php');
}

if ($action === 'test') {
    $result = oclc_test_connection();
    flash_set(!empty($result['success']) ? 'success' : 'danger', (string)($result['message'] ?? 'The connection test finished.'));
    redirect('settings.php');
}

if ($action === 'test_ldap') {
    $result = auth_ldap_test_connection();
    flash_set(!empty($result['success']) ? 'success' : 'danger', (string)($result['message'] ?? 'The LDAP connection test finished.'));
    redirect('settings.php');
}

if ($action === 'clear_token') {
    oclc_clear_token();
    flash_set('success', 'The cached OCLC access token was cleared.');
    redirect('settings.php');
}

$settings = settings_all();
$token = oclc_token_info();
$demo = oclc_is_demo();
$configured = oclc_configured();
$secretSet = trim((string)($settings['oclc_secret'] ?? '')) !== '';
$ldapEnabled = auth_ldap_enabled();
$ldapConfig = auth_ldap_config();
$ldapAvailable = function_exists('ldap_connect');
$adminPasswordCount = active_admin_password_count();

include __DIR__ . '/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="bi bi-gear"></i> Settings</h1>
      <p class="text-muted mb-0">Sign-in, WorldCat credentials, defaults and demo behaviour.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <span class="badge <?php echo $ldapEnabled ? 'text-bg-primary' : 'text-bg-info'; ?> align-self-center">
        <?php echo $ldapEnabled ? 'LDAP sign-in' : 'Local sign-in'; ?>
      </span>
      <?php if ($demo): ?>
        <span class="badge text-bg-warning align-self-center">Demo mode enabled</span>
      <?php elseif ($configured): ?>
        <span class="badge text-bg-success align-self-center">Credentials configured</span>
      <?php else: ?>
        <span class="badge text-bg-danger align-self-center">Credentials missing</span>
      <?php endif; ?>
    </div>
  </div>

  <?php flash_render(); ?>

  <?php if ($demo): ?>
    <div class="alert alert-warning">
      <i class="bi bi-flask"></i> Demo mode is on. Searches and MARC exports use generated sample records; no requests are sent to OCLC. Turn it off before working with real data.
    </div>
  <?php endif; ?>

  <?php if (!$ldapEnabled && $adminPasswordCount < 1): ?>
    <div class="alert alert-danger">
      <i class="bi bi-shield-exclamation"></i> LDAP is disabled, but no active administrator has a local password. Add one on the <a href="users.php" class="alert-link">Users page</a> so administrators can sign in.
    </div>
  <?php elseif ($ldapEnabled && !$ldapAvailable): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle"></i> LDAP sign-in is enabled, but the PHP LDAP extension is not loaded. Enable <code>extension=ldap</code> in <code>php.ini</code>, or disable LDAP sign-in below to use local passwords.
    </div>
  <?php endif; ?>

  <form method="post" action="settings.php">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">

    <div class="row g-4">
      <div class="col-xl-7">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-braces"></i> Application</div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label" for="app_name">Application name</label>
              <input class="form-control" type="text" id="app_name" name="app_name" maxlength="60" value="<?php echo h($settings['app_name'] ?? 'mirrorCAT'); ?>">
              <div class="form-text">Shown in the navigation bar, the page title and exports.</div>
            </div>

            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="demo_mode" name="demo_mode" value="1"<?php echo $demo ? ' checked' : ''; ?>>
              <label class="form-check-label" for="demo_mode">Demo mode</label>
            </div>
            <div class="form-text mb-0">Return generated records and MARC data without contacting WorldCat. Useful for testing and training.</div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-lock"></i> Authentication</div>
          <div class="card-body">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="ldap_enabled" name="ldap_enabled" value="1"<?php echo $ldapEnabled ? ' checked' : ''; ?>>
              <label class="form-check-label" for="ldap_enabled">Enable LDAP / Active Directory sign-in</label>
            </div>
            <div class="form-text mb-3">
              When this is off (the default), users sign in with a SnapiCat-style local password stored in the mirrorCAT database.
              Local passwords are set and reset on the <a href="users.php">Users page</a>.
            </div>

            <?php if (!$ldapEnabled): ?>
              <div class="alert alert-info py-2 small mb-3">
                <i class="bi bi-info-circle"></i>
                <strong>Local sign-in is active.</strong> LDAP credentials are not checked.
                <?php if ($adminPasswordCount < 1): ?>
                  No active administrator has a local password yet.
                <?php endif; ?>
              </div>
            <?php elseif (!$ldapAvailable): ?>
              <div class="alert alert-warning py-2 small mb-3">
                <i class="bi bi-exclamation-triangle"></i>
                The PHP LDAP extension is not enabled, so LDAP sign-in cannot work until it is installed.
              </div>
            <?php endif; ?>

            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label" for="ldap_server">LDAP server</label>
                <input class="form-control font-monospace" type="text" id="ldap_server" name="ldap_server"
                       value="<?php echo h($settings['ldap_server'] ?? $ldapConfig['server']); ?>"
                       placeholder="ldaps://ldap.example.org:636">
                <div class="form-text">Use <code>ldaps://host:636</code> for TLS. Environment variables provide the initial default.</div>
              </div>
              <div class="col-md-5">
                <label class="form-label" for="ldap_domain">Domain</label>
                <input class="form-control font-monospace" type="text" id="ldap_domain" name="ldap_domain"
                       value="<?php echo h($settings['ldap_domain'] ?? $ldapConfig['domain']); ?>"
                       placeholder="example.org">
                <div class="form-text">Used to build the search base DN.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="ldap_domain_nb">NetBIOS domain</label>
                <input class="form-control font-monospace" type="text" id="ldap_domain_nb" name="ldap_domain_nb"
                       value="<?php echo h($settings['ldap_domain_nb'] ?? $ldapConfig['domain_nb']); ?>"
                       placeholder="EXAMPLE">
                <div class="form-text">The bind name becomes <code>DOMAIN\\LANID</code>.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="ldap_base_dn">Base DN <span class="text-muted">(optional)</span></label>
                <input class="form-control font-monospace" type="text" id="ldap_base_dn" name="ldap_base_dn"
                       value="<?php echo h($settings['ldap_base_dn'] ?? $ldapConfig['base_dn']); ?>"
                       placeholder="DC=EXAMPLE,DC=ORG">
                <div class="form-text">Leave blank to derive it from the domain.</div>
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
              <button class="btn btn-outline-primary btn-sm" type="submit" name="action" value="test_ldap" formnovalidate>
                <i class="bi bi-plug"></i> Test saved connection
              </button>
              <span class="text-muted small">Save the settings first, then test.</span>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-key"></i> OCLC WorldCat Metadata API</div>
          <div class="card-body">
            <div class="alert alert-secondary py-2 small">
              Create a WSKey with the WorldCat Metadata API at
              <a href="https://platform.worldcat.org/wskey/" target="_blank" rel="noopener">platform.worldcat.org</a>.
              The credentials are stored in the local SQLite database.
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="oclc_wskey">WSKey</label>
                <input class="form-control font-monospace" type="text" id="oclc_wskey" name="oclc_wskey" autocomplete="off" value="<?php echo h($settings['oclc_wskey'] ?? ''); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="oclc_secret">WSKey secret</label>
                <input class="form-control font-monospace" type="password" id="oclc_secret" name="oclc_secret" autocomplete="new-password"
                       placeholder="<?php echo $secretSet ? '•••••••• (leave blank to keep the saved secret)' : 'Enter the WSKey secret'; ?>">
                <?php if ($secretSet): ?>
                  <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="clear_secret" name="clear_secret" value="1">
                    <label class="form-check-label small" for="clear_secret">Remove the stored secret</label>
                  </div>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="oclc_principal_id">Principal ID <span class="text-muted">(optional)</span></label>
                <input class="form-control font-monospace" type="text" id="oclc_principal_id" name="oclc_principal_id" value="<?php echo h($settings['oclc_principal_id'] ?? ''); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="oclc_principal_idns">Principal ID namespace <span class="text-muted">(optional)</span></label>
                <input class="form-control font-monospace" type="text" id="oclc_principal_idns" name="oclc_principal_idns" value="<?php echo h($settings['oclc_principal_idns'] ?? ''); ?>">
              </div>

              <div class="col-12">
                <label class="form-label" for="oclc_scope">Scopes</label>
                <input class="form-control font-monospace" type="text" id="oclc_scope" name="oclc_scope" value="<?php echo h($settings['oclc_scope'] ?? OCLC_DEFAULT_SCOPE); ?>">
                <div class="form-text">Space-separated OAuth scopes. The defaults cover brief-bib search and MARC retrieval.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="oclc_base_url">Metadata API base URL</label>
                <input class="form-control font-monospace" type="url" id="oclc_base_url" name="oclc_base_url" value="<?php echo h($settings['oclc_base_url'] ?? OCLC_DEFAULT_BASE); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="oclc_oauth_url">OAuth token URL</label>
                <input class="form-control font-monospace" type="url" id="oclc_oauth_url" name="oclc_oauth_url" value="<?php echo h($settings['oclc_oauth_url'] ?? OCLC_DEFAULT_OAUTH); ?>">
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders2"></i> Defaults</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="default_order_by">Default result order</label>
                <select class="form-select" id="default_order_by" name="default_order_by">
                  <?php foreach (order_by_options() as $value => $label): ?>
                    <option value="<?php echo h($value); ?>"<?php echo ($settings['default_order_by'] ?? 'bestMatch') === $value ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="batch_size">Rows per search request</label>
                <input class="form-control" type="number" id="batch_size" name="batch_size" min="1" max="50" value="<?php echo (int)($settings['batch_size'] ?? 10); ?>">
                <div class="form-text">Larger chunks finish faster but are harder to stop mid-way.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="rows_per_page">Rows per page</label>
                <select class="form-select" id="rows_per_page" name="rows_per_page">
                  <?php foreach ([10, 25, 50, 100, 200] as $option): ?>
                    <option value="<?php echo $option; ?>"<?php echo (int)($settings['rows_per_page'] ?? 25) === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="max_rows">Maximum import rows</label>
                <input class="form-control" type="number" id="max_rows" name="max_rows" min="1" max="100000" value="<?php echo (int)($settings['max_rows'] ?? 5000); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="max_marc_records">Maximum MARC records per export</label>
                <input class="form-control" type="number" id="max_marc_records" name="max_marc_records" min="1" max="1000" value="<?php echo (int)($settings['max_marc_records'] ?? 250); ?>">
              </div>

              <div class="col-12">
                <label class="form-label" for="default_query_template">Default query template <span class="text-muted">(optional)</span></label>
                <textarea class="form-control font-monospace" id="default_query_template" name="default_query_template" rows="2"><?php echo h($settings['default_query_template'] ?? ''); ?></textarea>
                <div class="form-text">Applied to new batches, for example <code>ti:{Title} AND au:{Author}</code>. Leave blank to use the column mapping.</div>
              </div>
            </div>
          </div>
        </div>

        <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save settings</button>
      </div>

      <div class="col-xl-5">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-plug"></i> Connection</div>
          <div class="card-body">
            <p class="mb-2">
              <?php if ($token): ?>
                <span class="badge text-bg-success">Token cached</span>
                <span class="text-muted small">valid for about <?php echo (int)$token['minutes']; ?> more minutes</span>
              <?php else: ?>
                <span class="badge text-bg-secondary">No cached token</span>
              <?php endif; ?>
            </p>
            <p class="text-muted small">Save your credentials first, then test the connection. The test clears and re-requests an OAuth token.</p>
            <div class="d-flex flex-wrap gap-2">
              <button class="btn btn-outline-primary" type="submit" name="action" value="test" formnovalidate>
                <i class="bi bi-plugin"></i> Test saved connection
              </button>
              <button class="btn btn-outline-secondary" type="submit" name="action" value="clear_token" formnovalidate>
                Clear cached token
              </button>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle"></i> Current setup</div>
          <div class="card-body">
            <dl class="row mb-0 small">
              <dt class="col-5">Sign-in mode</dt>
              <dd class="col-7"><?php echo h(auth_mode_label()); ?></dd>
              <dt class="col-5">LDAP server</dt>
              <dd class="col-7 text-break"><?php echo h($ldapConfig['server']); ?></dd>
              <dt class="col-5">WSKey</dt>
              <dd class="col-7 text-break"><?php echo $settings['oclc_wskey'] !== '' ? h($settings['oclc_wskey']) : '<span class="text-muted">not set</span>'; ?></dd>
              <dt class="col-5">Secret</dt>
              <dd class="col-7"><?php echo $secretSet ? 'saved' : '<span class="text-muted">not set</span>'; ?></dd>
              <dt class="col-5">Demo mode</dt>
              <dd class="col-7"><?php echo $demo ? 'on' : 'off'; ?></dd>
              <dt class="col-5">Database</dt>
              <dd class="col-7 text-break"><?php echo h(basename($g_db_path)); ?></dd>
              <dt class="col-5">PHP</dt>
              <dd class="col-7"><?php echo h(PHP_VERSION); ?></dd>
              <dt class="col-5">cURL</dt>
              <dd class="col-7"><?php echo function_exists('curl_init') ? 'available' : '<span class="text-danger">missing</span>'; ?></dd>
              <dt class="col-5">SQLite</dt>
              <dd class="col-7"><?php echo extension_loaded('pdo_sqlite') ? 'available' : '<span class="text-danger">missing</span>'; ?></dd>
            </dl>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-lightbulb"></i> Notes</div>
          <div class="card-body small text-muted">
            <ul class="mb-0 ps-3">
              <li>Local passwords are the default. When LDAP is enabled, passwords are checked against Active Directory; when it is disabled, local passwords are hashed in the <code>users</code> table.</li>
              <li>At least one active administrator needs a local password before LDAP can be disabled.</li>
              <li>Credentials are stored in <code>include/mirrorcat.db</code>; this app is intended for a trusted local or staff workstation.</li>
              <li>Search results use the first brief-bib match returned by WorldCat, ordered by the batch's result order setting.</li>
              <li>The daily WorldCat request quota is read from the <code>x-ratelimit-remaining-day</code> response header and shown in the navigation bar.</li>
              <li>Enable demo mode to try the complete workflow without OCLC credentials.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>
<?php
include __DIR__ . '/footer.php';
?>
