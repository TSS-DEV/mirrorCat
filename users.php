<?php
require_once __DIR__ . '/include/auth.php';
auth_require_admin();

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    csrf_check();
}

$levels = [
    LEVEL_ADMIN   => 'Administrator',
    LEVEL_MANAGER => 'Manager',
    LEVEL_STAFF   => 'Staff',
    LEVEL_USER    => 'Inactive / no access',
];

function users_redirect($url = 'users.php') {
    redirect($url);
}

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $lan = strtoupper(trim((string)($_POST['lan_id'] ?? '')));
    $level = (int)($_POST['level'] ?? LEVEL_STAFF);
    $active = !empty($_POST['active']) ? 1 : 0;
    $password = (string)($_POST['password'] ?? '');
    $clearPassword = !empty($_POST['clear_password']) && $password === '';

    $data = [
        'id'          => $id,
        'lan_id'      => $lan,
        'name_first'  => trim((string)($_POST['name_first'] ?? '')),
        'name_last'   => trim((string)($_POST['name_last'] ?? '')),
        'email'       => trim((string)($_POST['email'] ?? '')),
        'employee_id' => trim((string)($_POST['employee_id'] ?? '')),
        'level'       => $level,
        'active'      => $active,
    ];

    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,31}$/', $lan)) {
        flash_set('danger', 'Enter a valid sign-in ID (2-32 letters, numbers, dots, dashes or underscores).');
        users_redirect($id > 0 ? 'users.php?edit=' . $id : 'users.php');
    }
    if (!array_key_exists($level, $levels)) {
        flash_set('danger', 'Choose a valid access level.');
        users_redirect($id > 0 ? 'users.php?edit=' . $id : 'users.php');
    }
    if ($password !== '' && mb_strlen($password) < 8) {
        flash_set('danger', 'Local passwords must be at least 8 characters.');
        users_redirect($id > 0 ? 'users.php?edit=' . $id : 'users.php');
    }

    $passwordHash = null;
    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    } elseif ($clearPassword) {
        $passwordHash = '';
    }
    if ($passwordHash !== null) {
        $data['password_hash'] = $passwordHash;
    }

    $duplicate = user_by_lan($lan);
    if ($duplicate && (int)$duplicate['id'] !== $id) {
        flash_set('danger', 'The sign-in ID ' . $lan . ' is already on the list.');
        users_redirect($id > 0 ? 'users.php?edit=' . $id : 'users.php');
    }

    if ($id > 0) {
        $existing = user_get($id);
        if (!$existing) {
            flash_set('danger', 'That user no longer exists.');
            users_redirect();
        }

        $me = auth_current_user();
        if ($me && (int)$me['id'] === $id && (!$active || $level < LEVEL_ADMIN)) {
            flash_set('danger', 'You cannot remove your own administrator access.');
            users_redirect('users.php?edit=' . $id);
        }

        $wasActiveAdmin = (int)$existing['active'] === 1 && (int)$existing['level'] >= LEVEL_ADMIN;
        $staysActiveAdmin = $active === 1 && $level >= LEVEL_ADMIN;
        if ($wasActiveAdmin && !$staysActiveAdmin && active_admin_count() <= 1) {
            flash_set('danger', 'At least one active administrator must remain.');
            users_redirect('users.php?edit=' . $id);
        }

        if (!auth_ldap_enabled()) {
            $existingHasLocal = trim((string)($existing['password_hash'] ?? '')) !== '';
            $willHaveLocal = $staysActiveAdmin && ($passwordHash !== null ? $passwordHash !== '' : $existingHasLocal);
            if ($existingHasLocal && !$willHaveLocal && active_admin_password_count() <= 1) {
                flash_set('danger', 'At least one active administrator with a local password must remain while LDAP is disabled.');
                users_redirect('users.php?edit=' . $id);
            }
        }

        user_save($data);
        flash_set('success', 'Updated access for ' . $lan . '.');
    } else {
        $data['id'] = 0;
        user_save($data);
        if (auth_ldap_enabled()) {
            flash_set('success', 'Added ' . $lan . '. They can now sign in with their LDAP password.');
        } elseif ($passwordHash !== null && $passwordHash !== '') {
            flash_set('success', 'Added ' . $lan . '. They can now sign in with their local password.');
        } else {
            flash_set('success', 'Added ' . $lan . '. Set a local password before they can sign in.');
        }
    }

    users_redirect();
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $existing = user_get($id);
    $me = auth_current_user();

    if (!$existing) {
        flash_set('warning', 'That user no longer exists.');
        users_redirect();
    }
    if ($me && (int)$me['id'] === $id) {
        flash_set('danger', 'You cannot delete your own account.');
        users_redirect();
    }
    if ((int)$existing['active'] === 1 && (int)$existing['level'] >= LEVEL_ADMIN && active_admin_count() <= 1) {
        flash_set('danger', 'At least one active administrator must remain.');
        users_redirect();
    }
    if (!auth_ldap_enabled()
        && (int)$existing['active'] === 1
        && (int)$existing['level'] >= LEVEL_ADMIN
        && trim((string)($existing['password_hash'] ?? '')) !== ''
        && active_admin_password_count() <= 1) {
        flash_set('danger', 'At least one active administrator with a local password must remain while LDAP is disabled.');
        users_redirect();
    }

    user_delete($id);
    flash_set('success', 'Removed ' . $existing['lan_id'] . '.');
    users_redirect();
}

$editId = (int)($_GET['edit'] ?? 0);
$editUser = $editId > 0 ? user_get($editId) : null;
if ($editId > 0 && !$editUser) {
    flash_set('warning', 'That user could not be found.');
    users_redirect();
}

$find = trim((string)($_GET['q'] ?? ''));
$users = users_list($find);
$me = auth_current_user();
$ldapEnabled = auth_ldap_enabled();
$adminPasswordCount = active_admin_password_count();

$form = [
    'id'          => $editUser ? (int)$editUser['id'] : 0,
    'lan_id'      => $editUser['lan_id'] ?? '',
    'name_first'  => $editUser['name_first'] ?? '',
    'name_last'   => $editUser['name_last'] ?? '',
    'email'       => $editUser['email'] ?? '',
    'employee_id' => $editUser['employee_id'] ?? '',
    'level'       => $editUser ? (int)$editUser['level'] : LEVEL_STAFF,
    'active'      => $editUser ? (int)$editUser['active'] : 1,
];

include __DIR__ . '/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="bi bi-people"></i> Authorized users</h1>
      <p class="text-muted mb-0">
        <?php echo $ldapEnabled
            ? 'LDAP sign-in is allowed only for sign-in IDs on this list.'
            : 'Local password sign-in is enabled. Set or reset each user’s password below.'; ?>
      </p>
    </div>
    <span class="badge text-bg-secondary align-self-center"><?php echo count($users); ?> user<?php echo count($users) === 1 ? '' : 's'; ?></span>
  </div>

  <?php flash_render(); ?>

  <?php if (!$ldapEnabled && $adminPasswordCount < 1): ?>
    <div class="alert alert-danger">
      <i class="bi bi-shield-exclamation"></i>
      LDAP sign-in is disabled, but no active administrator has a local password. Set one now to avoid being locked out.
    </div>
  <?php elseif ($ldapEnabled && !function_exists('ldap_connect')): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle"></i>
      The PHP LDAP extension is not enabled, so only the localhost administrator bypass can sign in right now. Enable <code>extension=ldap</code> in <code>php.ini</code>, or disable LDAP sign-in on the <a href="settings.php" class="alert-link">Settings page</a>.
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-xl-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span><i class="bi bi-list-check"></i> Access list</span>
          <form class="d-flex gap-2" method="get" action="users.php">
            <input class="form-control form-control-sm" type="search" name="q" value="<?php echo h($find); ?>" placeholder="Sign-in ID, name or email">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($find !== ''): ?>
              <a class="btn btn-sm btn-outline-secondary" href="users.php"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Sign-in ID</th>
                <th>Name</th>
                <th>Access</th>
                <th>Status</th>
                <th>Local password</th>
                <th>Last sign-in</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No users match that search.</td></tr>
              <?php endif; ?>
              <?php foreach ($users as $user): ?>
                <?php
                $level = (int)$user['level'];
                $roleClass = $level >= LEVEL_ADMIN ? 'text-bg-primary' : ($level >= LEVEL_MANAGER ? 'text-bg-info' : ($level >= LEVEL_STAFF ? 'text-bg-secondary' : 'text-bg-warning'));
                $name = trim(trim((string)$user['name_first']) . ' ' . trim((string)$user['name_last']));
                ?>
                <tr>
                  <td class="font-monospace"><?php echo h($user['lan_id']); ?></td>
                  <td>
                    <?php echo $name !== '' ? h($name) : '<span class="text-muted">—</span>'; ?>
                    <?php if (($user['email'] ?? '') !== ''): ?>
                      <div class="small text-muted text-truncate" style="max-width: 220px;"><?php echo h($user['email']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge <?php echo $roleClass; ?>"><?php echo h(auth_role_label($level)); ?></span></td>
                  <td>
                    <?php if ((int)$user['active'] === 1): ?>
                      <span class="badge text-bg-success">Active</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (trim((string)($user['password_hash'] ?? '')) !== ''): ?>
                      <span class="badge text-bg-info">Set</span>
                    <?php else: ?>
                      <span class="badge text-bg-light text-muted">Not set</span>
                    <?php endif; ?>
                  </td>
                  <td class="small text-muted"><?php echo $user['last_login'] !== '' ? h($user['last_login']) : '—'; ?></td>
                  <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-secondary" href="users.php?edit=<?php echo (int)$user['id']; ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                    <?php if (!$me || (int)$me['id'] !== (int)$user['id']): ?>
                      <form class="d-inline" method="post" action="users.php" data-confirm="Remove <?php echo h($user['lan_id']); ?> from the authorized list?">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Remove"><i class="bi bi-trash"></i></button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
          <i class="bi <?php echo $editUser ? 'bi-pencil-square' : 'bi-person-plus'; ?>"></i>
          <?php echo $editUser ? 'Edit ' . h($editUser['lan_id']) : 'Add an authorized user'; ?>
        </div>
        <div class="card-body">
          <form method="post" action="users.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

            <div class="mb-3">
              <label class="form-label" for="lan_id">Sign-in ID</label>
              <input class="form-control font-monospace text-uppercase" type="text" id="lan_id" name="lan_id"
                     value="<?php echo h($form['lan_id']); ?>" maxlength="32" required
                     <?php echo $editUser ? 'readonly' : 'placeholder="ADMIN"'; ?>>
              <div class="form-text">Used to sign in. With LDAP this is the directory CN, for example <code>ADMIN</code>.</div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="name_first">First name</label>
                <input class="form-control" type="text" id="name_first" name="name_first" value="<?php echo h($form['name_first']); ?>" maxlength="64">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="name_last">Last name</label>
                <input class="form-control" type="text" id="name_last" name="name_last" value="<?php echo h($form['name_last']); ?>" maxlength="64">
              </div>
              <div class="col-md-7">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" type="email" id="email" name="email" value="<?php echo h($form['email']); ?>" maxlength="128">
              </div>
              <div class="col-md-5">
                <label class="form-label" for="employee_id">Employee ID</label>
                <input class="form-control font-monospace" type="text" id="employee_id" name="employee_id" value="<?php echo h($form['employee_id']); ?>" maxlength="32">
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label" for="level">Access level</label>
              <select class="form-select" id="level" name="level">
                <?php foreach ($levels as $value => $label): ?>
                  <option value="<?php echo (int)$value; ?>"<?php echo (int)$form['level'] === (int)$value ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Administrators can manage settings and this user list. Staff can import and export records.</div>
            </div>

            <div class="mt-3">
              <label class="form-label" for="password">
                <?php echo $editUser ? 'Reset local password' : 'Local password'; ?>
                <span class="text-muted">(optional)</span>
              </label>
              <input class="form-control" type="password" id="password" name="password"
                     autocomplete="new-password" minlength="8"
                     placeholder="<?php echo $editUser && trim((string)($editUser['password_hash'] ?? '')) !== '' ? 'Leave blank to keep the current password' : 'At least 8 characters'; ?>">
              <div class="form-text">Used only when LDAP sign-in is disabled.</div>
              <?php if ($editUser && trim((string)($editUser['password_hash'] ?? '')) !== ''): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="clear_password" name="clear_password" value="1">
                  <label class="form-check-label small" for="clear_password">Remove the local password</label>
                </div>
              <?php endif; ?>
            </div>

            <div class="form-check form-switch mt-3">
              <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1"<?php echo (int)$form['active'] === 1 ? ' checked' : ''; ?>>
              <label class="form-check-label" for="active">Account is active</label>
            </div>

            <div class="d-flex gap-2 mt-4">
              <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?php echo $editUser ? 'Save changes' : 'Add user'; ?></button>
              <?php if ($editUser): ?>
                <a class="btn btn-outline-secondary" href="users.php">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <p class="text-muted small mt-3 mb-0">
        <?php if ($ldapEnabled): ?>
          This list controls authorization only. Passwords are checked against Active Directory and are never stored.
          Local passwords are only used if LDAP sign-in is disabled on the Settings page.
        <?php else: ?>
          LDAP sign-in is disabled. Passwords are stored as one-way hashes in the local database and can be reset here.
        <?php endif; ?>
        On localhost, active administrators can still sign in without a password for testing.
      </p>
    </div>
  </div>
</div>
<?php
include __DIR__ . '/footer.php';
?>
