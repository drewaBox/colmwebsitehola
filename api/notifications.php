<?php
// GET                     -> latest notifications + unread count
// POST ?action=read       {id}   -> mark one as read
// POST ?action=read_all          -> mark all as read
// POST ?action=delete     {id}   -> delete one
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
$user = require_login();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $afterId = isset($_GET['after_id']) ? max(0, (int)$_GET['after_id']) : null;
    if ($afterId !== null) {
        $rows = fetch_all('SELECT id, request_id, title, message, type, is_read, created_at FROM notifications
                           WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 50', [$userId, $afterId]);
    } else {
        $rows = fetch_all('SELECT id, request_id, title, message, type, is_read, created_at FROM notifications
                           WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$userId]);
    }
    foreach ($rows as &$row) { $row['id'] = (int)$row['id']; $row['is_read'] = (bool)$row['is_read']; }
    $unread = (int)fetch_one('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$userId])['c'];
    json_response(['ok' => true, 'notifications' => $rows, 'unread' => $unread]);
}

require_method('POST');
require_csrf();
$action = $_GET['action'] ?? '';
$id = (int)(read_json_body()['id'] ?? 0);

// "AND user_id = ?" makes sure nobody can touch another person's notifications.
if ($action === 'read') {
    query('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $userId]);
} elseif ($action === 'read_all') {
    query('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$userId]);
} elseif ($action === 'delete') {
    query('DELETE FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
} else {
    fail('Unknown action.', 404);
}
$unread = (int)fetch_one('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$userId])['c'];
json_response(['ok' => true, 'unread' => $unread]);
