<?php
// GET ?id=FILE_ID[&inline=1]  ->  preview or download an uploaded file (only for people who may see it).
//   Admin/registrar: every file.   Student: only files of their own request.
//   Cashier cannot open student documents.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
register_error_handlers();
require_method('GET');
$user = require_login(['student', 'admin', 'registrar']);

$file = fetch_one('SELECT f.*, r.student_id, r.status, r.payment_status, r.total_amount
                   FROM request_files f JOIN requests r ON r.id = f.request_id
                   WHERE f.id = ? AND f.is_current = 1', [(int)($_GET['id'] ?? 0)]);
if (!$file) fail('File not found.', 404);

// The finished soft copy (PDF) is never sent from here: api/softcopy.php counts the downloads and checks the permission.
if ($file['kind'] === 'release') fail('Soft copies are opened with the Download PDF button.', 403);

if ($user['role'] === 'student') {
    if ((int)$file['student_id'] !== (int)$user['id']) fail('File not found.', 404);
}

send_stored_file($file, !empty($_GET['inline']));
