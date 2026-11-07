<?php
require_once __DIR__ . '/helpers.php';

/**
 * Authentication and authorization.
 *
 * Sign-in can use an LDAP / Active Directory server or local SnapiCat-style passwords.
 * The mode is controlled by the "ldap_enabled" setting on the Settings page.
 * Either way, the sign-in ID must also be present and active in the local
 * "users" table. On the localhost domain, active administrator accounts can
 * sign in with only their sign-in ID so the app can be tested without LDAP
 * access.
 */

/** True when the request is coming from this workstation via localhost. */
function auth_is_localhost() {
    $host = strtolower((string)($_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);
    if (strpos($host, '[') === 0) {
        $host = trim($host, '[]');
    }

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $loopback = in_array($remote, ['127.0.0.1', '::1'], true) || strpos($remote, '127.') === 0;
    $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || substr($host, -10) === '.localhost';

    return $loopback && $localHost;
}

/** The user represented by the current session, or null. */
function auth_current_user() {
    if (array_key_exists('mirrorcat_auth_user', $GLOBALS)) {
        return $GLOBALS['mirrorcat_auth_user'];
    }

    $sessionKey = $GLOBALS['g_session_user_id'] ?? 'mirrorcat_user_id';
    $id = (int)($_SESSION[$sessionKey] ?? 0);
    if ($id <= 0) {
        $GLOBALS['mirrorcat_auth_user'] = null;
        return null;
    }

    $user = user_get($id);
    if (!$user || (int)$user['active'] !== 1) {
        auth_forget_identity();
        return null;
    }

    $GLOBALS['mirrorcat_auth_user'] = $user;
    return $user;
}

/** Remove the identity from the session without destroying the whole session. */
function auth_forget_identity() {
    unset($GLOBALS['mirrorcat_auth_user']);
    $keys = [
        $GLOBALS['g_session_user_id'] ?? 'mirrorcat_user_id',
        $GLOBALS['g_session_user_name'] ?? 'mirrorcat_user_name',
        $GLOBALS['g_session_institution_id'] ?? 'mirrorcat_institution_id',
        $GLOBALS['g_session_user_level'] ?? 'mirrorcat_user_level',
        'mirrorcat_user_display',
        'mirrorcat_auth_method',
    ];
    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

function auth_check() {
    return auth_current_user() !== null;
}

function auth_level() {
    $user = auth_current_user();
    return $user ? (int)$user['level'] : 0;
}

function auth_is_admin() {
    return auth_level() >= LEVEL_ADMIN;
}

/** Database id of the signed-in user, or 0 when signed out. */
function auth_user_id() {
    $user = auth_current_user();
    return $user ? (int)$user['id'] : 0;
}

/**
 * True when the signed-in user may open a batch.
 * Administrators can open every batch; everyone else only their own.
 */
function auth_can_access_batch($batch) {
    if (!$batch) {
        return false;
    }
    if (auth_is_admin()) {
        return true;
    }
    $userId = auth_user_id();
    return $userId > 0 && (int)($batch['user_id'] ?? 0) === $userId;
}

/**
 * Fetch a batch only when the signed-in user is allowed to see it.
 * Returns null for both missing and unauthorized batches so IDs do not leak.
 */
function auth_get_batch($id) {
    $batch = get_batch((int)$id);
    return auth_can_access_batch($batch) ? $batch : null;
}

function auth_method() {
    return (string)($_SESSION['mirrorcat_auth_method'] ?? '');
}

/** Human-readable name for the signed-in user. */
function auth_display_name($user = null) {
    $user = $user ?? auth_current_user();
    if (!$user) {
        return '';
    }
    $name = trim(trim((string)$user['name_first']) . ' ' . trim((string)$user['name_last']));
    return $name !== '' ? $name : (string)$user['lan_id'];
}

function auth_role_label($level) {
    $level = (int)$level;
    if ($level >= LEVEL_ADMIN) {
        return 'Administrator';
    }
    if ($level >= LEVEL_MANAGER) {
        return 'Manager';
    }
    if ($level >= LEVEL_STAFF) {
        return 'Staff';
    }
    return 'Inactive';
}

/** True when LDAP / Active Directory sign-in is enabled. */
function auth_ldap_enabled() {
    return setting_get('ldap_enabled', '0') === '1';
}

/** Read one LDAP setting, falling back to the config/environment default. */
function auth_ldap_setting($key, $default = '') {
    $value = trim((string)setting_get('ldap_' . $key, ''));
    return $value !== '' ? $value : (string)$default;
}

/** Effective LDAP connection details after applying the saved settings. */
function auth_ldap_config() {
    global $g_ldap_server, $g_ldap_domain, $g_ldap_domain_nb, $g_ldap_base_dn;

    $domain = auth_ldap_setting('domain', $g_ldap_domain ?? '');
    $base = auth_ldap_setting('base_dn', $g_ldap_base_dn ?? '');

    return [
        'server'    => auth_ldap_setting('server', $g_ldap_server ?? ''),
        'domain'    => $domain,
        'domain_nb' => auth_ldap_setting('domain_nb', $g_ldap_domain_nb ?? ''),
        'base_dn'   => $base !== '' ? $base : auth_ldap_base_dn($domain),
    ];
}

/** Human-readable name for the active sign-in mode. */
function auth_mode_label() {
    return auth_ldap_enabled() ? 'LDAP / Active Directory' : 'Local passwords';
}

/** Keep redirect targets inside this application. */
function auth_safe_next($next) {
    $next = trim((string)$next);
    if ($next === '' || strpos($next, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $next)) {
        return 'index.php';
    }
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $next)) {
        return 'index.php';
    }

    $path = parse_url($next, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('/^[a-z0-9_\-]+\.php$/i', basename($path))) {
        return 'index.php';
    }
    if (in_array(strtolower(basename($path)), ['login.php', 'logout.php'], true)) {
        return 'index.php';
    }
    return $next;
}

/** Current page (file + query string) for post-login redirects. */
function auth_current_url() {
    $file = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    return $file . ($query !== '' ? '?' . $query : '');
}

/** Require a signed-in user. Redirects browsers and returns 401 to JSON callers. */
function auth_require_login($json = false) {
    if (auth_check()) {
        return;
    }

    if ($json || is_json_request()) {
        json_out(['success' => false, 'error' => 'Your session has expired. Please sign in again.'], 401);
    }

    redirect('login.php?next=' . rawurlencode(auth_current_url()));
}

/** Require an administrator. */
function auth_require_admin($json = false) {
    auth_require_login($json);

    if (auth_is_admin()) {
        return;
    }

    if ($json || is_json_request()) {
        json_out(['success' => false, 'error' => 'Administrator access is required.'], 403);
    }

    flash_set('danger', 'Administrator access is required for that page.');
    redirect('index.php');
}

/** Record the signed-in user in the session. */
function auth_complete_login(array $user, $method, array $details = []) {
    session_regenerate_id(true);

    $userIdKey = $GLOBALS['g_session_user_id'] ?? 'mirrorcat_user_id';
    $userNameKey = $GLOBALS['g_session_user_name'] ?? 'mirrorcat_user_name';
    $instKey = $GLOBALS['g_session_institution_id'] ?? 'mirrorcat_institution_id';
    $levelKey = $GLOBALS['g_session_user_level'] ?? 'mirrorcat_user_level';

    $_SESSION[$userIdKey] = (int)$user['id'];
    $_SESSION[$userNameKey] = (string)$user['lan_id'];
    $_SESSION[$instKey] = 0;
    $_SESSION[$levelKey] = (int)$user['level'];
    $_SESSION['mirrorcat_user_display'] = auth_display_name($user);
    $_SESSION['mirrorcat_auth_method'] = $method;

    user_touch_login((int)$user['id'], $details);

    $GLOBALS['g_login_user_id'] = (int)$user['id'];
    $GLOBALS['g_login_user_name'] = (string)$user['lan_id'];
    $GLOBALS['g_login_user_level'] = (int)$user['level'];
    $GLOBALS['mirrorcat_auth_user'] = user_get((int)$user['id']);
}

/** Remove the session and its cookie. */
function auth_logout() {
    unset($GLOBALS['mirrorcat_auth_user']);
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Attempt a sign-in.
 *
 * @return array{success:bool,error?:string,message?:string,user?:array}
 */
function auth_attempt_login($username, $password) {
    $lanId = strtoupper(trim((string)$username));
    if ($lanId === '') {
        return ['success' => false, 'error' => 'Enter your sign-in ID.'];
    }

    $user = user_by_lan($lanId);
    if ($user && (int)$user['active'] !== 1) {
        return ['success' => false, 'error' => 'That account is inactive. Contact a mirrorCAT administrator.'];
    }

    // Localhost test sign-in: active administrators do not need a password.
    if (auth_is_localhost() && $user && (int)$user['level'] >= LEVEL_ADMIN) {
        auth_complete_login($user, 'localhost');
        return [
            'success' => true,
            'message' => 'Signed in with the localhost administrator bypass.',
            'user'    => $user,
        ];
    }

    $password = (string)$password;

    // LDAP is disabled: verify against the password hash stored in the users table.
    if (!auth_ldap_enabled()) {
        if ($password === '') {
            return ['success' => false, 'error' => 'Enter your sign-in ID and local password.'];
        }
        if (!$user) {
            return ['success' => false, 'error' => 'The sign-in ID or password was not recognized.'];
        }

        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return [
                'success' => false,
                'error'   => 'The sign-in ID or password was not recognized. If this is a local account, ask an administrator to set a password.',
            ];
        }
        if ((int)$user['level'] < LEVEL_STAFF) {
            return ['success' => false, 'error' => 'Your account does not have mirrorCAT staff access.'];
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            user_set_password_hash((int)$user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        auth_complete_login($user, 'local');
        return ['success' => true, 'user' => $user];
    }

    if ($password === '') {
        if (auth_is_localhost()) {
            return [
                'success' => false,
                'error'   => 'Local password-free sign-in is limited to active administrator accounts. Enter a password to authenticate through LDAP.',
            ];
        }
        return ['success' => false, 'error' => 'Enter your sign-in ID and password.'];
    }

    $ldap = auth_ldap_authenticate($lanId, $password);
    if (empty($ldap['success'])) {
        return ['success' => false, 'error' => (string)($ldap['error'] ?? 'Sign-in failed.')];
    }

    $user = user_by_lan($ldap['lan_id'] ?? $lanId);
    if (!$user) {
        return [
            'success' => false,
            'error'   => 'Your sign-in ID is not authorized for mirrorCAT. Contact an administrator to be added.',
        ];
    }
    if ((int)$user['active'] !== 1) {
        return ['success' => false, 'error' => 'That account is inactive. Contact a mirrorCAT administrator.'];
    }
    if ((int)$user['level'] < LEVEL_STAFF) {
        return ['success' => false, 'error' => 'Your account does not have mirrorCAT staff access.'];
    }

    auth_complete_login($user, 'ldap', $ldap);
    return ['success' => true, 'user' => $user];
}

/** Bind to Active Directory with the supplied credentials and read the entry. */
function auth_ldap_authenticate($username, $password) {
    if (!function_exists('ldap_connect')) {
        return [
            'success' => false,
            'error'   => 'LDAP authentication is unavailable because the PHP LDAP extension is not enabled on this server.',
        ];
    }

    $config = auth_ldap_config();
    $username = strtoupper(trim((string)$username));
    $ds = @ldap_connect((string)$config['server']);
    if (!$ds) {
        return ['success' => false, 'error' => 'Could not contact the directory server.'];
    }

    @ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        @ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 8);
    }

    $bind = @ldap_bind($ds, (string)$config['domain_nb'] . '\\' . $username, (string)$password);
    if (!$bind) {
        @ldap_close($ds);
        return ['success' => false, 'error' => 'Please check your sign-in ID and password.'];
    }

    $base = (string)$config['base_dn'];
    if (function_exists('ldap_escape')) {
        $escaped = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
    } else {
        $escaped = str_replace(['\\', '*', '(', ')', "\0"], ['\\5c', '\\2a', '\\28', '\\29', '\\00'], $username);
    }

    $attributes = ['cn', 'mail', 'displayname', 'givenname', 'sn', 'employeeid'];
    $search = @ldap_search($ds, $base, '(cn=' . $escaped . ')', $attributes, 0, 5);
    $info = $search ? @ldap_get_entries($ds, $search) : false;
    @ldap_close($ds);

    if (!$info || (int)($info['count'] ?? 0) < 1 || empty($info[0])) {
        return [
            'success' => false,
            'error'   => 'Your credentials were accepted, but your directory entry could not be read. Contact an administrator.',
        ];
    }

    $entry = $info[0];
    $value = function ($key) use ($entry) {
        return isset($entry[$key][0]) ? trim((string)$entry[$key][0]) : '';
    };

    return [
        'success'     => true,
        'lan_id'      => strtoupper($value('cn') !== '' ? $value('cn') : $username),
        'name_first'  => $value('givenname'),
        'name_last'   => $value('sn'),
        'email'       => $value('mail'),
        'employee_id' => $value('employeeid'),
    ];
}

/**
 * Check whether the saved LDAP server can be reached.
 * Active Directory normally rejects an anonymous bind, so a "stronger auth
 * required" response still proves that the server is reachable.
 *
 * @return array{success:bool,message:string}
 */
function auth_ldap_test_connection() {
    if (!function_exists('ldap_connect')) {
        return [
            'success' => false,
            'message' => 'The PHP LDAP extension is not enabled on this server.',
        ];
    }

    $config = auth_ldap_config();
    if ($config['server'] === '') {
        return ['success' => false, 'message' => 'Enter an LDAP server address first.'];
    }

    $ds = @ldap_connect((string)$config['server']);
    if (!$ds) {
        return ['success' => false, 'message' => 'Could not create an LDAP connection to ' . $config['server'] . '.'];
    }

    @ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        @ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 8);
    }

    $bind = @ldap_bind($ds);
    $errno = function_exists('ldap_errno') ? (int)@ldap_errno($ds) : 0;
    $error = function_exists('ldap_error') ? (string)@ldap_error($ds) : '';
    @ldap_close($ds);

    if ($bind) {
        return [
            'success' => true,
            'message' => 'Connected to ' . $config['server'] . '. The directory accepts anonymous binds.',
        ];
    }

    if (in_array($errno, [32, 49, 50, 53], true)) {
        return [
            'success' => true,
            'message' => 'Connected to ' . $config['server'] . '. The directory rejected the anonymous test bind, which is normal for Active Directory.',
        ];
    }

    return [
        'success' => false,
        'message' => 'Could not reach ' . $config['server'] . ': ' . ($error !== '' ? $error : 'the server did not respond') . '.',
    ];
}

/** Build dc=... base DN from the configured domain. */
function auth_ldap_base_dn($domain) {
    $parts = array_filter(array_map('trim', explode('.', (string)$domain)));
    $pieces = [];
    foreach ($parts as $part) {
        $pieces[] = 'DC=' . strtoupper($part);
    }
    return implode(',', $pieces);
}
