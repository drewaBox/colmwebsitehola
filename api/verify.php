<?php
// Document verification (Stage 3), done right after the student picks a file - before the request is submitted.
//
//   POST (multipart: file, requirement_id, optional replace_check_id)   check one file
//   POST ?action=retry    (json: {id})    check the same stored file again (after "Verification unavailable")
//   POST ?action=discard  (json: {id})    the student removed the file
//
// The answer has a status the screen shows:
//   VERIFIED          the student may continue
//   DECLINED          the document has a problem (reason given) -> upload a corrected file
//   NEEDS_CORRECTION  unclear or incomplete (reason given)      -> upload a clearer / complete file
//   PENDING           the check could not be completed          -> try again (nothing is skipped)
// Only a VERIFIED file can be used to create a request (api/requests.php checks this again on the server).
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
require_once __DIR__ . '/../backend/ai.php';
register_error_handlers();
require_method('POST');
$user = require_login(['student']);
require_csrf();

cleanup_old_checks();

if (($_GET['action'] ?? '') === 'retry') {
    retry_check((int)(read_json_body()['id'] ?? 0), $user);
}

if (($_GET['action'] ?? '') === 'discard') {
    $id = (int)(read_json_body()['id'] ?? 0);
    discard_check($id, (int)$user['id']);
    json_response(['ok' => true]);
}

// When PHP drops the whole upload because it is bigger than post_max_size, $_POST and $_FILES are empty.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail('The file is too big for the server. Please upload a smaller file.', 413, ['title' => 'Invalid File']);
}

// At most 60 files per hour per student (every check may call the paid AI service).
$recent = (int)fetch_one("SELECT COUNT(*) AS c FROM upload_checks WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$user['id']])['c'];
if ($recent >= 60) fail('You uploaded many files in the last hour. Please try again later.', 429, ['title' => 'Too many uploads']);

$requirement = fetch_one('SELECT r.*, d.name AS document_name FROM document_requirements r
                          JOIN documents d ON d.id = r.document_id
                          WHERE r.id = ? AND d.is_active = 1', [(int)($_POST['requirement_id'] ?? 0)]);
if (!$requirement) fail('This requirement is not available.', 404, ['title' => 'Invalid request']);

// 1. Is it a real, allowed file? (type, size, content - never only the file name)
try {
    $file = get_uploaded('file');
    if (!$file) fail('Please choose a file to upload.', 422, ['title' => 'No file selected']);
    $info = validate_file($file['tmp_name'], $file['name'], (int)$requirement['max_size_mb'], $requirement['allowed_types']);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 422, ['title' => 'Invalid File']);
}

// 2. Verify it (quality, then the reader, then the name check).
set_time_limit(120);
$result = verify_document_upload($file['tmp_name'], $info['mime'], $requirement, $requirement['document_name'], $user['full_name']);
[$status, $title, $message] = student_view_of_result($result);

// 3. Keep the file only when it can still be used: VERIFIED, or PENDING (so the check can be tried again).
$stored = null;
if (in_array($status, ['VERIFIED', 'PENDING'], true)) {
    try {
        $stored = store_file($file['tmp_name'], $info['ext']);
    } catch (RuntimeException $e) {
        write_log('verify.php could not store a file: ' . $e->getMessage());
        fail('The file could not be saved. Please try again.', 500, ['title' => 'Upload failed']);
    }
}

// The student picked another file for the same requirement: remove the earlier one.
$replace = (int)($_POST['replace_check_id'] ?? 0);
if ($replace > 0) discard_check($replace, (int)$user['id']);

$originalName = clean_text(basename(str_replace('\\', '/', $file['name'])), 255);
query('INSERT INTO upload_checks (user_id, requirement_id, original_name, stored_name, mime_type, size_bytes, sha256, status, student_message,
                                  result_status, reason, extracted_text, confidence, name_on_document, name_match_score, auto_declined, model, error_message)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$user['id'], $requirement['id'], $originalName, $stored, $info['mime'], $info['size'], $info['sha256'], $status, mb_substr($message, 0, 500),
     $result['status'], $result['reason'], $result['extracted_text'], $result['confidence'], $result['name_on_document'],
     $result['name_match_score'], $result['auto_declined'] ? 1 : 0, $result['model'], $result['error']]);
$checkId = (int)db()->lastInsertId();

json_response(['ok' => true, 'check' => ['id' => $checkId, 'status' => $status, 'title' => $title, 'message' => $message, 'filename' => $originalName]]);

// ---------- helpers ----------

// Runs the check again for a stored file whose first check could not be completed.
function retry_check($id, $user) {
    $check = fetch_one("SELECT c.*, r.name, r.description, r.ai_hint, r.keywords, r.requires_name, d.name AS document_name
                        FROM upload_checks c JOIN document_requirements r ON r.id = c.requirement_id JOIN documents d ON d.id = r.document_id
                        WHERE c.id = ? AND c.user_id = ? AND c.used_file_id IS NULL AND c.status = 'PENDING' AND c.stored_name IS NOT NULL", [$id, $user['id']]);
    if (!$check) fail('This document cannot be checked again. Please upload it again.', 404, ['title' => 'Verification failed']);
    set_time_limit(120);
    $result = verify_document_upload(stored_file_path($check['stored_name']), $check['mime_type'], $check, $check['document_name'], $user['full_name']);
    [$status, $title, $message] = student_view_of_result($result);
    query('UPDATE upload_checks SET status = ?, student_message = ?, result_status = ?, reason = ?, extracted_text = ?, confidence = ?, name_on_document = ?,
                  name_match_score = ?, auto_declined = ?, model = ?, error_message = ?, created_at = NOW() WHERE id = ?',
        [$status, mb_substr($message, 0, 500), $result['status'], $result['reason'], $result['extracted_text'], $result['confidence'],
         $result['name_on_document'], $result['name_match_score'], $result['auto_declined'] ? 1 : 0, $result['model'], $result['error'], $check['id']]);
    if (!in_array($status, ['VERIFIED', 'PENDING'], true)) {          // declined now: the file is not needed any more
        delete_stored_file($check['stored_name']);
        query('UPDATE upload_checks SET stored_name = NULL WHERE id = ?', [$check['id']]);
    }
    json_response(['ok' => true, 'check' => ['id' => (int)$check['id'], 'status' => $status, 'title' => $title, 'message' => $message, 'filename' => $check['original_name']]]);
}

// Delete one unused check of this student, together with its file.
function discard_check($id, $userId) {
    $check = fetch_one('SELECT id, stored_name FROM upload_checks WHERE id = ? AND user_id = ? AND used_file_id IS NULL', [$id, $userId]);
    if (!$check) return;
    query('DELETE FROM upload_checks WHERE id = ?', [$check['id']]);
    if ($check['stored_name']) delete_stored_file($check['stored_name']);
}

// Files that were checked but never used in a request are removed after 3 days (runs now and then, not on every call).
function cleanup_old_checks() {
    if (random_int(1, 25) !== 1) return;
    foreach (fetch_all('SELECT id, stored_name FROM upload_checks WHERE used_file_id IS NULL AND created_at < (NOW() - INTERVAL 3 DAY) LIMIT 100') as $old) {
        query('DELETE FROM upload_checks WHERE id = ?', [$old['id']]);
        if ($old['stored_name']) delete_stored_file($old['stored_name']);
    }
}
