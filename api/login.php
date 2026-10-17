<?php
// POST {username, password}  ->  logs the user in.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
require_method('POST');
start_session();

$input = read_json_body();
$username = clean_text($input['username'] ?? '', 50);
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '') fail('Please enter your username and password.', 422);

$user = fetch_one('SELECT * FROM users WHERE username = ?', [$username]);

// Too many wrong passwords: wait 15 minutes.
if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
    fail('Too many failed attempts. Please try again in a few minutes.', 429);
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    if ($user) {
        $attempts = $user['failed_logins'] + 1;
        $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
        query('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$attempts >= 5 ? 0 : $attempts, $lockedUntil, $user['id']]);
    }
    fail('Incorrect username or password. Please try again.', 401);
}
if (!$user['is_active']) fail('This account is disabled. Please contact the registrar.', 403);

query('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
unset($_SESSION['csrf']);

json_response(['ok' => true, 'user' => user_for_browser($user), 'csrf' => csrf_token()]);
