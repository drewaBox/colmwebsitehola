<?php
// POST  ->  logs the user out.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
require_method('POST');
require_csrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
}
session_destroy();
json_response(['ok' => true]);
