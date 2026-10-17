<?php
// The QR code of a request (Stage 5). GET ?request_id=ID
//   - If the request already has a QR code it is returned.
//   - If not, one is created and saved first. So the QR code always exists and never changes.
// The browser draws the picture from the text (frontend/js/qr.js). The student can only get the QR of their own request.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
require_method('GET');
$user = require_login(['student', 'admin', 'registrar']);

$request = fetch_one('SELECT id, tracking_no, student_id, qr_code, created_at FROM requests WHERE id = ?', [(int)($_GET['request_id'] ?? 0)]);
if (!$request || ($user['role'] === 'student' && (int)$request['student_id'] !== (int)$user['id'])) fail('Request not found.', 404);

$created = false;
if (!$request['qr_code']) {
    // The text in the QR code: tracking number + a secret-looking token that belongs to this request only.
    $text = 'COLM|' . $request['tracking_no'] . '|' . bin2hex(random_bytes(6));
    // "AND qr_code IS NULL": if two pages ask at the same moment only the first one saves, the other one reads it again.
    $saved = query('UPDATE requests SET qr_code = ? WHERE id = ? AND qr_code IS NULL', [$text, $request['id']])->rowCount();
    $created = $saved > 0;
    $request['qr_code'] = fetch_one('SELECT qr_code FROM requests WHERE id = ?', [$request['id']])['qr_code'];
    if (!$request['qr_code']) fail('QR code generation failed. Please try again.', 500);
}
json_response(['ok' => true, 'qr_text' => $request['qr_code'], 'tracking_no' => $request['tracking_no'], 'created' => $created]);
