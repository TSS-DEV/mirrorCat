<?php
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/oclc.php';

$site_date = filemtime(__DIR__ . '/site.css');
$favicon_date = @filemtime(__DIR__ . '/favicon.ico') ?: $site_date;
$app_name = setting_get('app_name', 'mirrorCAT');
if (trim($app_name) === '') {
    $app_name = 'mirrorCAT';
}
$demo_mode = oclc_is_demo();
$remaining = setting_get('last_remaining_day', '');
$active_page = $g_current_file ?? basename($_SERVER['SCRIPT_NAME']);
$auth_user = auth_current_user();
?>
<!doctype html>
<html lang="en">

<head>
  <title><?php echo h($app_name); ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">

  <link rel="icon" href="./favicon.ico?v=<?php echo (int)$favicon_date; ?>" sizes="any">
  <link rel="icon" type="image/svg+xml" href="./favicon.svg?v=<?php echo (int)$favicon_date; ?>">
  <link rel="apple-touch-icon" href="./apple-touch-icon.png?v=<?php echo (int)$favicon_date; ?>">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC"
        crossorigin="anonymous" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css"
        integrity="sha384-He3RckdFB2wffiHOcESa3sf4Ida+ni/fw9SSzAcfY2EPnU1zkK/sLUzw2C5Tyuhj"
        crossorigin="anonymous" referrerpolicy="no-referrer">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"
          integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM"
          crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" type="text/css" href="./site.css?<?php echo $site_date; ?>" />
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
      <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $auth_user ? './' : 'login.php'; ?>">
        <i class="bi bi-book-half"></i>
        <span><?php echo h($app_name); ?></span>
      </a>
      <?php if ($auth_user): ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNavDropdown">
          <ul class="navbar-nav me-auto">
            <li class="nav-item">
              <a class="nav-link<?php echo $active_page === 'index.php' ? ' active' : ''; ?>" href="./">
                <i class="bi bi-upload"></i> Upload &amp; batches
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link<?php echo $active_page === 'processed.php' ? ' active' : ''; ?>" href="processed.php">
                <i class="bi bi-check2-square"></i> Processed
              </a>
            </li>
            <?php if (auth_is_admin()): ?>
              <li class="nav-item">
                <a class="nav-link<?php echo $active_page === 'settings.php' ? ' active' : ''; ?>" href="settings.php">
                  <i class="bi bi-gear"></i> Settings
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link<?php echo $active_page === 'users.php' ? ' active' : ''; ?>" href="users.php">
                  <i class="bi bi-people"></i> Users
                </a>
              </li>
            <?php endif; ?>
          </ul>
          <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2 py-2 py-lg-0 ms-lg-auto">
            <?php if ($demo_mode): ?>
              <span class="badge text-bg-warning" title="No live WorldCat requests are made while demo mode is enabled.">Demo mode</span>
            <?php endif; ?>
            <?php if ($remaining !== ''): ?>
              <span class="badge text-bg-secondary" title="WorldCat requests left for today"><?php echo (int)$remaining; ?> OCLC calls left</span>
            <?php endif; ?>
            <?php if (auth_method() === 'localhost' || (auth_method() === 'local' && auth_ldap_enabled())): ?>
              <span class="badge text-bg-warning" title="The password check was bypassed because this is the localhost domain.">Local test</span>
            <?php elseif (auth_method() === 'local'): ?>
              <span class="badge text-bg-info" title="Signed in with a local mirrorCAT password because LDAP is disabled.">Local account</span>
            <?php endif; ?>
            <span class="navbar-text text-white-50 py-0">
              Signed in: <strong class="text-white"><?php echo h(auth_display_name($auth_user)); ?></strong>
              <span class="badge text-bg-info"><?php echo h(auth_role_label($auth_user['level'])); ?></span>
            </span>
            <form method="post" action="logout.php" class="d-inline">
              <?php echo csrf_field(); ?>
              <button class="btn btn-sm btn-outline-light" type="submit">
                <i class="bi bi-box-arrow-right"></i> Log out
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </nav>
