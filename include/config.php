<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', '1');
  $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
  session_name('MIRRORCATSESSID');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

// set time zone
date_default_timezone_set('America/Chicago');
setlocale(LC_MONETARY, 'en_US');

// timezone offset
$now = new DateTime();
$mins = $now->getOffset() / 60;
$sgn = ($mins < 0 ? -1 : 1);
$mins = abs($mins);
$hrs = floor($mins / 60);
$mins -= $hrs * 60;
$g_timezone_offset = sprintf('%+d hours', $hrs*$sgn);

$g_db_path = __DIR__ . DIRECTORY_SEPARATOR . 'mirrorcat.db';
$g_db_connection = "sqlite:" . $g_db_path;

// current files and folders
$g_filename = basename($_SERVER['SCRIPT_NAME']);
$g_host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : "";
$g_root = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : "";
$g_folder = $g_root !== "" ? str_replace($g_root, '', dirname($_SERVER['SCRIPT_FILENAME'])) : "";
$g_current_folder = $g_host . $g_folder;
$g_current_host =  $g_host;
$g_current_file = basename($_SERVER["SCRIPT_NAME"]);

// system pages
define("SYSTEM_MAIN_TITLE", 1);
define("SYSTEM_MAIN_HOME", 2);
define("SYSTEM_SUB_HOME", 3);

// access levels
define("LEVEL_ADMIN", 9);
define("LEVEL_MANAGER", 7);
define("LEVEL_STAFF", 3);
define("LEVEL_USER", 1);

// Active Directory / LDAP defaults. Set these on the Settings page or with
// the environment variables below. Empty defaults mean directory sign-in
// stays unconfigured until an administrator supplies a server and domain.
$g_ldap_server = getenv('MIRRORCAT_LDAP_SERVER') ?: '';
$g_ldap_domain = getenv('MIRRORCAT_LDAP_DOMAIN') ?: '';
$g_ldap_domain_nb = getenv('MIRRORCAT_LDAP_DOMAIN_NB') ?: '';
$g_ldap_base_dn = getenv('MIRRORCAT_LDAP_BASE_DN') ?: '';

$g_session_user_id = "mirrorcat_user_id";
$g_session_user_name = "mirrorcat_user_name";
$g_session_institution_id = "mirrorcat_institution_id";
$g_session_user_level = "mirrorcat_user_level";

// get credentials
$g_login_user_id = 0;
$g_login_user_name = "";
$g_login_institution_id = 0;
$g_login_user_level = 0;
if(isset($_SESSION[$g_session_user_id])) {
  $g_login_user_id = $_SESSION[$g_session_user_id];
  $g_login_user_name = $_SESSION[$g_session_user_name];
  $g_login_institution_id = $_SESSION[$g_session_institution_id] ?? 0;
  $g_login_user_level = $_SESSION[$g_session_user_level] ?? 0;
}
?>
