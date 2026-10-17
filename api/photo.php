<?php
// Student profile photo.
//   GET  ?id=USER_ID           show the photo (the student themselves or an admin/registrar)
//   POST (field "photo", and "user_id" when an admin uploads for a student)   save a new photo
//        (the browser only sends it after the student pressed Save; Cancel never calls this file)
//   POST ?action=remove (json {user_id})                                       remove the photo
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
register_error_handlers();

$user = require_login(['student', 'admin', 'registrar']);

// Which student does this photo belong to? A student can only use their own.
function photo_owner($user, $requested) {
    $id = $user['role'] === 'student' ? (int)$user['id'] : (int)$requested;
    $student = fetch_one("SELECT u.id FROM users u WHERE u.id = ? AND u.role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = photo_owner($user, $_GET['id'] ?? 0);
    $photo = fetch_one('SELECT photo_stored, photo_mime FROM students WHERE user_id = ?', [$id]);
    if (!$photo || !$photo['photo_stored']) fail('No photo.', 404);
    send_stored_file(['stored_name' => $photo['photo_stored'], 'original_name' => 'photo', 'mime_type' => $photo['photo_mime']], true);
}

require_method('POST');
require_csrf();

if (($_GET['action'] ?? '') === 'remove') {
    $id = photo_owner($user, read_json_body()['user_id'] ?? 0);
    $old = fetch_one('SELECT photo_stored FROM students WHERE user_id = ?', [$id]);
    query('UPDATE students SET photo_stored = NULL, photo_mime = NULL WHERE user_id = ?', [$id]);
    if ($old && $old['photo_stored']) delete_stored_file($old['photo_stored']);
    json_response(['ok' => true]);
}

if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) fail('The photo is too large. Use a picture of 2 MB or less.', 413);
$id = photo_owner($user, $_POST['user_id'] ?? 0);
try {
    $file = get_uploaded('photo');
    if (!$file) fail('Please choose a photo first.', 422);
    $info = validate_file($file['tmp_name'], $file['name'], 2, 'jpg,jpeg,png,webp');
    validate_photo($file['tmp_name']);                      // real picture? not damaged? sensible size?
    $stored = store_file($file['tmp_name'], $info['ext']);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 422);
}
$old = fetch_one('SELECT photo_stored FROM students WHERE user_id = ?', [$id]);
if ($old) {
    query('UPDATE students SET photo_stored = ?, photo_mime = ? WHERE user_id = ?', [$stored, $info['mime'], $id]);
} else {
    query('INSERT INTO students (user_id, photo_stored, photo_mime) VALUES (?, ?, ?)', [$id, $stored, $info['mime']]);
}
if ($old && $old['photo_stored']) delete_stored_file($old['photo_stored']);
json_response(['ok' => true]);
