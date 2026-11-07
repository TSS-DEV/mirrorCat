<?php
require_once __DIR__ . '/include/auth.php';

$next = auth_safe_next($_GET['next'] ?? ($_POST['next'] ?? ''));

if (auth_check()) {
    redirect($next);
}

$error = '';
$username = trim((string)($_POST['username'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $result = auth_attempt_login($username, $_POST['password'] ?? '');
    if (!empty($result['success'])) {
        redirect($next);
    }
    $error = (string)($result['error'] ?? 'Sign-in failed.');
}

$localBypass = auth_is_localhost();
$ldapEnabled = auth_ldap_enabled();
$ldapAvailable = function_exists('ldap_connect');

include __DIR__ . '/header.php';
?>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-sm-11 col-md-8 col-lg-5 col-xl-4">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
          <div class="text-center mb-4">
            <div class="display-6 text-primary mb-2"><i class="bi bi-shield-lock"></i></div>
            <h1 class="h4 mb-1"><?php echo h($app_name); ?> sign in</h1>
            <p class="text-muted small mb-0">
              <?php echo $ldapEnabled ? 'Use your sign-in ID to continue.' : 'Use your sign-in ID and local password to continue.'; ?>
            </p>
          </div>

          <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
          <?php endif; ?>

          <?php if (!$ldapEnabled): ?>
            <div class="alert alert-info small">
              <i class="bi bi-person-lock"></i>
              <strong>Local sign-in.</strong> LDAP is disabled, so passwords are checked against the local mirrorCAT user list.
            </div>
          <?php elseif ($localBypass): ?>
            <div class="alert alert-info small">
              <i class="bi bi-pc-display"></i>
              <strong>Local test mode.</strong> LDAP is bypassed on localhost. Active administrator sign-in IDs, such as the seeded <code>ADMIN</code> account, can sign in without a password.
            </div>
          <?php elseif (!$ldapAvailable): ?>
            <div class="alert alert-warning small">
              <i class="bi bi-exclamation-triangle"></i>
              The PHP LDAP extension is not enabled on this server, so directory sign-in is unavailable. Enable <code>extension=ldap</code> in <code>php.ini</code>, or ask an administrator to disable LDAP sign-in on the Settings page.
            </div>
          <?php else: ?>
            <div class="alert alert-light border small">
              <i class="bi bi-building"></i>
              Use the same sign-in ID and password you use for your organization's network.
            </div>
          <?php endif; ?>

          <form method="post" action="login.php" autocomplete="on">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo h($next); ?>">

            <div class="mb-3">
              <label class="form-label" for="username">Sign-in ID</label>
              <input class="form-control form-control-lg" type="text" id="username" name="username"
                     value="<?php echo h($username); ?>" autocomplete="username" autocapitalize="characters"
                     spellcheck="false" placeholder="ADMIN" required autofocus>
            </div>

            <div class="mb-3">
              <label class="form-label" for="password"><?php echo $ldapEnabled ? 'Password' : 'Local password'; ?></label>
              <input class="form-control form-control-lg" type="password" id="password" name="password"
                     autocomplete="current-password" placeholder="<?php echo $localBypass ? 'Not required for administrators on localhost' : ($ldapEnabled ? 'Password' : 'Local password'); ?>">
              <?php if ($localBypass): ?>
                <div class="form-text">Administrators can leave this blank on localhost.</div>
              <?php elseif (!$ldapEnabled): ?>
                <div class="form-text">Passwords are managed by an administrator on the Users page.</div>
              <?php endif; ?>
            </div>

            <div class="d-grid mt-4">
              <button class="btn btn-primary btn-lg" type="submit">
                <i class="bi bi-box-arrow-in-right"></i> Sign in
              </button>
            </div>
          </form>

          <p class="text-muted small text-center mt-4 mb-0">
            Access is limited to authorized mirrorCAT users. Contact an administrator if your sign-in ID is not recognized.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
include __DIR__ . '/footer.php';
?>
