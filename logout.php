<?php
require_once __DIR__ . '/include/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect(auth_check() ? 'index.php' : 'login.php');
}

csrf_check();
auth_logout();
redirect('login.php');
