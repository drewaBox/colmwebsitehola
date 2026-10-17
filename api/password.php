<?php
// POST {current_password, new_password}  ->  the logged-in user changes their own password.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
require_method('POST');
$user = require_login();
require_csrf();

$input = read_json_body();
$current = (string)($input['current_password'] ?? '');
$new = (string)($input['new_password'] ?? '');

$row = fetch_one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
if (!password_verify($current, $row['password_hash'])) fail('Your current password is not correct.', 422);
if (strlen($new) < 8) fail('The new password must be at least 8 characters.', 422);
if ($new === $current) fail('The new password must be different.', 422);

query('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
json_response(['ok' => true]);
