<?php
// Staff accounts (Admin, Registrar, Cashier). Only an Admin can use this file.
// Student accounts are created on the Students page (api/students.php), not here.
//   GET                     list staff accounts (optional ?q= and ?role=)
//   POST ?action=create     {username, full_name, email, role, password, confirm_password}
//   POST ?action=toggle     {id}              enable / disable an account
//   POST ?action=reset      {id, password}    set a new password
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
$admin = require_login(['admin']);
const STAFF_ACCOUNT_ROLES = ['admin', 'registrar', 'cashier'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where = ["role <> 'student'"]; $params = [];
    $search = clean_text($_GET['q'] ?? '', 50);
    if ($search !== '') { $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
    if (in_array($_GET['role'] ?? '', STAFF_ACCOUNT_ROLES, true)) { $where[] = 'role = ?'; $params[] = $_GET['role']; }
    if (($_GET['status'] ?? '') === 'active') $where[] = 'is_active = 1';
    elseif (($_GET['status'] ?? '') === 'disabled') $where[] = 'is_active = 0';
    $users = fetch_all('SELECT id, username, full_name, email, role, is_active, created_at FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY role, full_name LIMIT 200', $params);
    foreach ($users as &$u) { $u['id'] = (int)$u['id']; $u['is_active'] = (bool)$u['is_active']; }
    json_response(['ok' => true, 'users' => $users]);
}

require_method('POST');
require_csrf();
$action = $_GET['action'] ?? '';
$input = read_json_body();

if ($action === 'create') {
    $username = clean_text($input['username'] ?? '', 50);
    $fullName = clean_text($input['full_name'] ?? '', 120);
    $email = clean_text($input['email'] ?? '', 120);
    $role = (string)($input['role'] ?? '');
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');

    // 1. Everything filled in?
    if ($username === '' || $fullName === '' || $password === '' || $confirm === '' || $role === '') {
        fail('Please complete all required fields.', 422);
    }
    // 2. Valid values?
    if (!in_array($role, STAFF_ACCOUNT_ROLES, true)) fail('Please choose Admin, Registrar or Cashier.', 422);
    if (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username)) fail('Username: 3-50 letters, numbers, dot, dash, underscore or @.', 422);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('The email address is not valid.', 422);
    if (strlen($password) < 8) fail('The password must be at least 8 characters.', 422);
    if ($password !== $confirm) fail('Passwords do not match.', 422);
    // 3. Already used?
    if (fetch_one('SELECT id FROM users WHERE username = ?', [$username])) fail('Username already exists.', 422);
    if ($email !== '' && fetch_one('SELECT id FROM users WHERE email = ?', [$email])) fail('Email already exists.', 422);

    query('INSERT INTO users (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email !== '' ? $email : null, $role]);
    json_response(['ok' => true, 'message' => 'Account created successfully.'], 201);
}

$id = (int)($input['id'] ?? 0);
$target = fetch_one("SELECT id, is_active FROM users WHERE id = ? AND role <> 'student'", [$id]);
if (!$target) fail('Account not found.', 404);

if ($action === 'toggle') {
    if ($id === (int)$admin['id']) fail('You cannot disable your own account.', 422);
    query('UPDATE users SET is_active = ? WHERE id = ?', [$target['is_active'] ? 0 : 1, $id]);
    json_response(['ok' => true]);
}
if ($action === 'reset') {
    $password = (string)($input['password'] ?? '');
    if (strlen($password) < 8) fail('The password must be at least 8 characters.', 422);
    query('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    json_response(['ok' => true]);
}
fail('Unknown action.', 404);
