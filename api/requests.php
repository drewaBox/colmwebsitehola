<?php
// All document-request actions. The action is given in the URL:  api/requests.php?action=...
//
//   GET  list, detail, stats                         (any logged-in user; students only see their own)
//   POST create, resubmit                            (student)
//   POST review_file, set_status, run_ai, attach_release   (admin = registrar)
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
require_once __DIR__ . '/../backend/workflow.php';
require_once __DIR__ . '/../backend/ai.php';
require_once __DIR__ . '/../backend/softcopy.php';
require_once __DIR__ . '/../backend/students.php';
register_error_handlers();

// The cashier has no access here (payments have their own file, api/payments.php).
$user = require_login(['student', 'admin', 'registrar']);
$action = $_GET['action'] ?? 'list';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    require_csrf();
    // When the upload is bigger than post_max_size in php.ini, PHP drops the whole form.
    $isMultipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
    if ($isMultipart && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('The files are too big for the server. Use smaller files or ask the admin to raise post_max_size in php.ini.', 413);
    }
}

switch ($action) {
    case 'list':   require_method('GET');  list_requests($user); break;
    case 'stats':  require_method('GET');  request_stats($user); break;
    case 'detail': require_method('GET');  request_detail($user); break;
    case 'create':   require_method('POST'); require_login(['student']);   create_request($user); break;
    case 'resubmit': require_method('POST'); require_login(['student']);   resubmit_request($user); break;
    case 'review_file':    require_method('POST'); require_login(STAFF_ROLES); review_file($user); break;
    case 'set_status':     require_method('POST'); require_login(STAFF_ROLES); set_request_status($user); break;
    case 'run_ai':         require_method('POST'); require_login(STAFF_ROLES); run_ai_action($user); break;
    case 'attach_release': require_method('POST'); require_login(STAFF_ROLES); attach_release($user); break;
    case 'softcopy_ready': require_method('POST'); require_login(STAFF_ROLES); softcopy_ready($user); break;
    default: fail('Unknown action.', 404);
}

// ---------- Reading ----------

function list_requests($user) {
    $where = [];
    $params = [];
    if ($user['role'] === 'student') { $where[] = 'r.student_id = ?'; $params[] = $user['id']; }
    $status = $_GET['status'] ?? '';
    if ($status !== '' && isset(STATUS_LABELS[$status])) { $where[] = 'r.status = ?'; $params[] = $status; }
    $payment = $_GET['payment'] ?? '';
    if (in_array($payment, ['Paid', 'Unpaid'], true)) { $where[] = 'r.payment_status = ?'; $params[] = $payment; }
    $documentId = (int)($_GET['document'] ?? 0);                 // "request type": requests that contain this document
    if ($documentId > 0) { $where[] = 'EXISTS (SELECT 1 FROM request_items i WHERE i.request_id = r.id AND i.document_id = ?)'; $params[] = $documentId; }
    $from = $_GET['from'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'r.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    $to = $_GET['to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'r.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(r.tracking_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $perPage = 20;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = (int)fetch_one("SELECT COUNT(*) AS c FROM requests r JOIN users u ON u.id = r.student_id $whereSql", $params)['c'];
    $rows = fetch_all("SELECT r.id, r.tracking_no, r.status, r.payment_status, r.payment_method, r.release_method,
                              r.total_amount, r.target_start, r.target_end, r.completed_at, r.created_at, u.full_name, u.student_no,
                              (SELECT h.created_at FROM request_history h WHERE h.request_id = r.id AND h.new_status = 'READY' ORDER BY h.id DESC LIMIT 1) AS released_at,
                              (SELECT COUNT(*) FROM request_items x WHERE x.request_id = r.id) AS item_count,
                              (SELECT COUNT(*) FROM request_items x WHERE x.request_id = r.id AND x.softcopy_status = 'READY') AS soft_ready
                       FROM requests r JOIN users u ON u.id = r.student_id $whereSql
                       ORDER BY r.created_at DESC, r.id DESC LIMIT $perPage OFFSET $offset", $params);

    $items = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (fetch_all("SELECT i.request_id, d.name, i.quantity, i.unit_price
                            FROM request_items i JOIN documents d ON d.id = i.document_id
                            WHERE i.request_id IN ($marks) ORDER BY i.id", $ids) as $item) {
            $items[$item['request_id']][] = ['name' => $item['name'], 'quantity' => (int)$item['quantity'],
                                             'line_total' => (float)$item['unit_price'] * $item['quantity']];
        }
    }
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['total_amount'] = (float)$r['total_amount'];
        $r['items'] = $items[$r['id']] ?? [];
        $r['status_label'] = STATUS_LABELS[$r['status']];
        // "Soft Copy Available" = the registrar uploaded the PDF of every document and marked it ready
        $r['softcopy_label'] = '';
        if (format_wants_softcopy($r['release_method'])) {
            $r['softcopy_label'] = ((int)$r['soft_ready'] === (int)$r['item_count'] && (int)$r['item_count'] > 0) ? 'Soft Copy Available' : 'Waiting for Registrar';
        }
        unset($r['item_count'], $r['soft_ready']);
        $r['is_overdue'] = !in_array($r['status'], ['COMPLETED', 'REJECTED'], true) && $r['target_end'] && $r['target_end'] < $today;
    }
    json_response(['ok' => true, 'requests' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function request_stats($user) {
    $where = $user['role'] === 'student' ? 'WHERE student_id = ?' : 'WHERE 1 = ?';
    $row = fetch_one("SELECT COUNT(*) AS total,
        COALESCE(SUM(status IN ('AI_VERIFYING','FOR_REVIEW','NEEDS_CORRECTION','PROCESSING')), 0) AS processing,
        COALESCE(SUM(status = 'FOR_REVIEW'), 0) AS for_review,
        COALESCE(SUM(payment_status = 'Unpaid' AND status NOT IN ('REJECTED','COMPLETED')), 0) AS pending_payment,
        COALESCE(SUM(status = 'READY'), 0) AS ready,
        COALESCE(SUM(status = 'COMPLETED'), 0) AS completed
        FROM requests $where", [$user['role'] === 'student' ? $user['id'] : 1]);
    json_response(['ok' => true, 'stats' => array_map('intval', $row)]);
}

// Loads a request. A student can only open their own (others get 404, so ids cannot be guessed).
function load_request($id, $user) {
    $request = fetch_one('SELECT r.*, u.full_name, u.student_no FROM requests r JOIN users u ON u.id = r.student_id WHERE r.id = ?', [$id]);
    if (!$request || ($user['role'] === 'student' && (int)$request['student_id'] !== (int)$user['id'])) fail('Request not found.', 404);
    return $request;
}

function request_detail($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    $id = (int)$request['id'];
    // Only the owner student and the admin/registrar may see uploaded files.
    $isAdmin = in_array($user['role'], STAFF_ROLES, true);       // admin and registrar
    $canSeeFiles = $isAdmin || $user['role'] === 'student';

    $files = [];
    $ai = [];
    if ($canSeeFiles) {
        foreach (fetch_all('SELECT * FROM request_files WHERE request_id = ? AND is_current = 1', [$id]) as $f) $files[] = $f;
        if ($files) {
            $marks = implode(',', array_fill(0, count($files), '?'));
            foreach (fetch_all("SELECT * FROM ai_verifications WHERE file_id IN ($marks) ORDER BY id", array_column($files, 'id')) as $a) {
                $ai[$a['file_id']] = $a; // the newest result wins because of ORDER BY id
            }
        }
    }

    $items = fetch_all('SELECT i.id, i.document_id, i.quantity, i.unit_price, d.name, i.softcopy_status, i.softcopy_uploaded_by, i.softcopy_uploaded_at,
                               i.softcopy_ready_at, i.download_count, i.download_limit, i.last_downloaded_at FROM request_items i
                        JOIN documents d ON d.id = i.document_id WHERE i.request_id = ? ORDER BY i.id', [$id]);
    $needsFix = false;
    foreach ($items as &$item) {
        $item['requirements'] = [];
        foreach (fetch_all('SELECT id, name, description, is_required, allowed_types, max_size_mb FROM document_requirements
                            WHERE document_id = ? ORDER BY sort_order, id', [$item['document_id']]) as $req) {
            $req['file'] = null;
            foreach ($files as $f) {
                if ($f['kind'] === 'requirement' && (int)$f['item_id'] === (int)$item['id'] && (int)$f['requirement_id'] === (int)$req['id']) {
                    $req['file'] = file_for_browser($f, $ai[$f['id']] ?? null, $isAdmin);
                }
            }
            $req['needs_fix'] = !$req['file'] || in_array($req['file']['review_status'], ['Rejected', 'Resubmit'], true);
            if ($req['needs_fix']) $needsFix = true;
            $item['requirements'][] = $req;
        }
        // Soft copy (PDF). The student only gets the state (the file is only sent by api/softcopy.php); the staff also see the file details.
        $item['softcopy'] = null;
        $item['release_file'] = null;
        if (format_wants_softcopy($request['release_method'])) {
            $latest = fetch_one('SELECT id, status, reason_type, reason, rejection_reason, created_at FROM softcopy_download_requests WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$item['id']]);
            $extraCount = (int)fetch_one('SELECT COUNT(*) AS c FROM softcopy_download_requests WHERE item_id = ?', [$item['id']])['c'];
            $item['softcopy'] = softcopy_state($item, $request, $latest, $extraCount);
            if ($isAdmin) {
                $file = softcopy_current_file($item['id']);
                $uploader = $item['softcopy_uploaded_by'] ? fetch_one('SELECT full_name FROM users WHERE id = ?', [$item['softcopy_uploaded_by']]) : null;
                $item['softcopy'] += [
                    'status' => $item['softcopy_status'], 'file_name' => $file ? $file['original_name'] : null,
                    'file_size' => $file ? (int)$file['size_bytes'] : null, 'uploaded_at' => $item['softcopy_uploaded_at'],
                    'uploaded_by' => $uploader ? $uploader['full_name'] : null, 'ready_at' => $item['softcopy_ready_at'],
                    'last_downloaded_at' => $item['last_downloaded_at'],
                    'pending_request' => ($latest && $latest['status'] === 'PENDING') ? $latest : null,
                ];
                $item['release_file'] = $file ? ['id' => (int)$file['id'], 'original_name' => $file['original_name']] : null;
            }
        }
        unset($item['softcopy_status'], $item['softcopy_uploaded_by'], $item['softcopy_uploaded_at'], $item['softcopy_ready_at'],
              $item['download_count'], $item['download_limit'], $item['last_downloaded_at']);
    }

    $history = fetch_all('SELECT h.old_status, h.new_status, h.remarks, h.created_at, u.full_name AS by_name
                          FROM request_history h LEFT JOIN users u ON u.id = h.changed_by
                          WHERE h.request_id = ? ORDER BY h.id', [$id]);
    $request['status_label'] = STATUS_LABELS[$request['status']];
    $request['can_see_files'] = $canSeeFiles;
    $request['needs_fix'] = $needsFix;
    json_response(['ok' => true, 'request' => $request, 'items' => $items, 'history' => $history]);
}

// Only send the browser what that role may see. Students get the file and its status/reason only:
// the details of the automatic check are for the admin/registrar.
function file_for_browser($f, $ai, $isAdmin) {
    $out = [
        'id' => (int)$f['id'], 'original_name' => $f['original_name'], 'mime_type' => $f['mime_type'],
        'size_bytes' => (int)$f['size_bytes'], 'review_status' => $f['review_status'],
        'review_remarks' => $f['review_remarks'], 'uploaded_at' => $f['uploaded_at'], 'ai' => null,
    ];
    if ($ai && $isAdmin) {
        $out['ai'] = [
            'status' => $ai['status'], 'reason' => $ai['reason'], 'auto_declined' => (bool)$ai['auto_declined'],
            'extracted_text' => $ai['extracted_text'], 'confidence' => $ai['confidence'],
            'name_on_document' => $ai['name_on_document'], 'name_match_score' => $ai['name_match_score'],
            'model' => $ai['model'], 'error_message' => $ai['error_message'], 'created_at' => $ai['created_at'],
        ];
    }
    return $out;
}

// ---------- Creating a request ----------
// The files were already uploaded and checked by api/verify.php. The browser sends the check ids in the "payload":
//   payload.checks = { "ROW:REQUIREMENT_ID": CHECK_ID, ... }

// A VERIFIED upload of this student that was not used yet. Returns the row or null.
// This is the server-side rule: a file that is declined, needs correction, is still pending or was never checked is refused here,
// no matter what the browser sends.
function load_usable_check($userId, $checkId, $requirementId) {
    if ($checkId <= 0) return null;
    $check = fetch_one("SELECT * FROM upload_checks WHERE id = ? AND user_id = ? AND requirement_id = ? AND used_file_id IS NULL
                        AND status = 'VERIFIED' AND stored_name IS NOT NULL AND created_at > (NOW() - INTERVAL 3 DAY)",
                       [$checkId, $userId, $requirementId]);
    if ($check && !is_file(stored_file_path($check['stored_name']))) return null;
    return $check;
}

function active_request_for_document($userId, $documentId) {
    return fetch_one("SELECT r.tracking_no FROM requests r
                      JOIN request_items i ON i.request_id = r.id
                      WHERE r.student_id = ? AND i.document_id = ?
                        AND r.status NOT IN ('COMPLETED', 'REJECTED')
                      ORDER BY r.created_at, r.id LIMIT 1", [$userId, $documentId]);
}

function duplicate_document_request_error($documentName, $trackingNo) {
    fail("You already have a request for \"$documentName\" in progress ($trackingNo). You can request it again after it is completed or rejected.", 422);
}

// Puts a verified upload into the request: file row, link back to the check, and the result for the registrar.
function attach_check($requestId, $itemId, $requirementId, $check) {
    $fileId = save_file_row($requestId, $itemId, $requirementId, 'requirement', $check['original_name'], $check['stored_name'],
                            ['mime' => $check['mime_type'], 'size' => $check['size_bytes'], 'sha256' => $check['sha256']]);
    query('UPDATE upload_checks SET used_file_id = ? WHERE id = ?', [$fileId, $check['id']]);
    save_ai_result($requestId, $fileId, [
        'status' => $check['result_status'], 'reason' => $check['reason'], 'extracted_text' => $check['extracted_text'],
        'confidence' => $check['confidence'], 'name_on_document' => $check['name_on_document'], 'name_match_score' => $check['name_match_score'],
        'auto_declined' => $check['auto_declined'], 'model' => $check['model'], 'error' => $check['error_message'],
    ]);
    return $fileId;
}

function create_request($user) {
    $payload = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($payload)) fail('Invalid request data.', 422);
    $student = fetch_one('SELECT school_level FROM students WHERE user_id = ?', [$user['id']]);
    $isSeniorHigh = ($student['school_level'] ?? '') === 'SENIOR HIGH SCHOOL';

    $purpose = clean_text($payload['purpose'] ?? '', 255);
    if (mb_strlen($purpose) < 3 || !preg_match('/[A-Za-z]{2}/', $purpose)) fail('Please enter a meaningful purpose.', 422);
    $releaseMethod = $payload['release_method'] ?? '';
    $paymentMethod = $payload['payment_method'] ?? '';
    if (!in_array($releaseMethod, ['Hardcopy', 'Softcopy', 'Both'], true)) fail('Choose a document format.', 422);
    if (!in_array($paymentMethod, ['Cash', 'GCash'], true)) fail('Choose a payment method.', 422);

    $rows = $payload['items'] ?? [];
    if (!is_array($rows) || !$rows || count($rows) > 15) fail('Choose at least one document.', 422);
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];

    // 1. Check every chosen document and its quantity.
    $items = [];
    $seen = [];
    foreach ($rows as $row) {
        $documentId = (int)($row['document_id'] ?? 0);
        $quantity = (int)($row['quantity'] ?? 0);
        $rowKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['row'] ?? ''));
        $doc = fetch_one('SELECT * FROM documents WHERE id = ? AND is_active = 1', [$documentId]);
        if (!$doc) fail('One of the selected documents is not available.', 422);
        if ($isSeniorHigh && !senior_high_can_request_document($doc['name'])) {
            fail('Senior High School students can only request COR, COE, Form 137, Form 138, Good Moral, or Certificate of Graduation.', 422);
        }
        if (isset($seen[$documentId])) fail('Each document can only be selected once.', 422);
        $activeRequest = active_request_for_document($user['id'], $documentId);
        if ($activeRequest) duplicate_document_request_error($doc['name'], $activeRequest['tracking_no']);
        $seen[$documentId] = true;
        if ($quantity < 1 || $quantity > 5) fail('You can request 1 to 5 copies of a document.', 422);
        // "Both" needs a document that can be released as hard copy AND as soft copy
        $needed = $releaseMethod === 'Both' ? ['Hardcopy', 'Softcopy'] : [$releaseMethod];
        if (array_diff($needed, explode(',', $doc['release_methods']))) {
            fail("\"{$doc['name']}\" is not available as $releaseMethod. Submit it in a separate request.", 422);
        }
        $doc['requirements'] = fetch_all('SELECT * FROM document_requirements WHERE document_id = ? ORDER BY sort_order, id', [$documentId]);
        $items[] = ['row' => $rowKey, 'doc' => $doc, 'quantity' => $quantity];
    }

    // 2. Required document check: every required document must be uploaded AND have passed the verification.
    $missing = []; $errors = []; $uploads = []; $hashes = [];
    foreach ($items as $index => $item) {
        foreach ($item['doc']['requirements'] as $req) {
            $checkId = (int)($checks[$item['row'] . ':' . $req['id']] ?? 0);
            if ($checkId === 0) {
                if ($req['is_required']) $missing[] = ['document' => $item['doc']['name'], 'requirement' => $req['name']];
                continue;
            }
            $check = load_usable_check($user['id'], $checkId, (int)$req['id']);
            if (!$check) {
                $errors[] = "{$item['doc']['name']} - {$req['name']}: this document is not verified yet. Please upload it and wait for the verification.";
                continue;
            }
            if (isset($hashes[$check['sha256']])) {
                $errors[] = "{$item['doc']['name']} - {$req['name']}: this is the same file you uploaded for \"{$hashes[$check['sha256']]}\". Upload a different file.";
                continue;
            }
            $hashes[$check['sha256']] = $req['name'];
            $uploads[$index][$req['id']] = $check;
        }
    }
    if ($missing) fail('Required document missing. Please upload all required documents before continuing.', 422, ['missing' => $missing]);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    // 3. Save everything in one transaction.
    $total = 0; $minDays = 0; $maxDays = 0;
    foreach ($items as $item) {
        $total += $item['doc']['price'] * $item['quantity'];
        $minDays = max($minDays, (int)$item['doc']['processing_min_days']);
        $maxDays = max($maxDays, (int)$item['doc']['processing_max_days']);
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user['id']]);
        foreach ($items as $item) {
            $activeRequest = active_request_for_document($user['id'], (int)$item['doc']['id']);
            if ($activeRequest) {
                $pdo->rollBack();
                duplicate_document_request_error($item['doc']['name'], $activeRequest['tracking_no']);
            }
        }
        $tracking = make_tracking_number();
        $submittedOn = date('Y-m-d');
        query('INSERT INTO requests (tracking_no, student_id, purpose, release_method, payment_method, total_amount, target_start, target_end)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tracking, $user['id'], $purpose, $releaseMethod, $paymentMethod, $total,
             estimate_processing_date($submittedOn, $minDays), estimate_processing_date($submittedOn, $maxDays)]);
        $requestId = (int)$pdo->lastInsertId();
        foreach ($items as $index => $item) {
            query('INSERT INTO request_items (request_id, document_id, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$requestId, $item['doc']['id'], $item['quantity'], $item['doc']['price']]);
            $itemId = (int)$pdo->lastInsertId();
            foreach ($uploads[$index] ?? [] as $requirementId => $check) attach_check($requestId, $itemId, (int)$requirementId, $check);
        }
        add_history($requestId, null, 'SUBMITTED', $user['id'], '');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();       // the verified files stay, so the student can simply try again
        write_log('create_request failed: ' . $e->getMessage());
        fail('Could not save your request. Please try again.', 500);
    }

    add_notification($user['id'], $requestId, 'Request submitted', "Your request $tracking was submitted. Your documents passed the first checks and are now waiting for the registrar.", 'success');
    finish_submission($requestId);
    notify_roles(STAFF_ROLES, $requestId, 'New request for review', "Request $tracking from {$user['full_name']} is ready for review.", 'info');

    json_response(['ok' => true, 'request' => ['id' => $requestId, 'tracking_no' => $tracking, 'total' => $total, 'status' => 'FOR_REVIEW',
        'message' => 'Your documents were verified and your request is now waiting for the registrar.']], 201);
}

function make_tracking_number() {
    for ($i = 0; $i < 10; $i++) {
        $number = 'COLM-' . date('Ymd') . '-' . random_int(10000, 99999);
        if (!fetch_one('SELECT id FROM requests WHERE tracking_no = ?', [$number])) return $number;
    }
    throw new RuntimeException('Could not create a tracking number.');
}

function save_file_row($requestId, $itemId, $requirementId, $kind, $originalName, $storedName, $info) {
    $name = clean_text(basename(str_replace('\\', '/', $originalName)), 255);
    query('INSERT INTO request_files (request_id, item_id, requirement_id, kind, original_name, stored_name, mime_type, size_bytes, sha256)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$requestId, $itemId, $requirementId, $kind, $name, $storedName, $info['mime'], $info['size'], $info['sha256']]);
    return (int)db()->lastInsertId();
}

// SUBMITTED / NEEDS_CORRECTION -> "Document Checking" -> For Admin Review. The checks themselves already
// happened when the files were uploaded, so this only moves the request forward.
function finish_submission($requestId) {
    change_status($requestId, 'AI_VERIFYING', null);
    change_status($requestId, 'FOR_REVIEW', null);
}

// ---------- Student fixes the documents the registrar asked for ----------

function resubmit_request($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    if ($request['status'] !== 'NEEDS_CORRECTION') fail('This request does not need correction.', 422);
    $student = fetch_one('SELECT school_level FROM students WHERE user_id = ?', [$user['id']]);
    if (($student['school_level'] ?? '') === 'SENIOR HIGH SCHOOL') {
        foreach (fetch_all('SELECT d.name FROM request_items i JOIN documents d ON d.id = i.document_id WHERE i.request_id = ?', [$request['id']]) as $item) {
            if (!senior_high_can_request_document($item['name'])) {
                fail('Senior High School students can only request COR, COE, Form 137, Form 138, Good Moral, or Certificate of Graduation.', 422);
            }
        }
    }
    $requestId = (int)$request['id'];
    $payload = json_decode($_POST['payload'] ?? '', true);
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];

    $wanted = fetch_all("SELECT i.id AS item_id, d.name AS document_name, r.*
                         FROM request_items i
                         JOIN documents d ON d.id = i.document_id
                         JOIN document_requirements r ON r.document_id = d.id
                         LEFT JOIN request_files f ON f.item_id = i.id AND f.requirement_id = r.id AND f.is_current = 1 AND f.kind = 'requirement'
                         WHERE i.request_id = ? AND (f.id IS NULL OR f.review_status IN ('Rejected','Resubmit'))", [$requestId]);
    if (!$wanted) fail('There is nothing to correct.', 422);

    $hashes = array_column(fetch_all("SELECT sha256 FROM request_files WHERE request_id = ? AND is_current = 1", [$requestId]), 'sha256');
    $missing = []; $errors = []; $uploads = [];
    foreach ($wanted as $w) {
        $check = load_usable_check($user['id'], (int)($checks[$w['item_id'] . ':' . $w['id']] ?? 0), (int)$w['id']);
        if (!$check) { $missing[] = ['document' => $w['document_name'], 'requirement' => $w['name']]; continue; }
        if (in_array($check['sha256'], $hashes, true)) { $errors[] = "{$w['name']}: this file was already uploaded. Upload the corrected file."; continue; }
        $hashes[] = $check['sha256'];
        $uploads[] = [$w, $check];
    }
    if ($missing) fail('Required document missing. Please upload all the documents that need correction.', 422, ['missing' => $missing]);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($uploads as [$w, $check]) {
            query("UPDATE request_files SET is_current = 0 WHERE item_id = ? AND requirement_id = ? AND kind = 'requirement'", [$w['item_id'], $w['id']]);
            attach_check($requestId, (int)$w['item_id'], (int)$w['id'], $check);
        }
        add_history($requestId, 'NEEDS_CORRECTION', 'NEEDS_CORRECTION', $user['id'], 'Student uploaded corrected documents.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        write_log('resubmit failed: ' . $e->getMessage());
        fail('Could not save your corrections. Please try again.', 500);
    }

    finish_submission($requestId);
    add_notification($user['id'], $requestId, 'Corrections received', "Your corrected documents for {$request['tracking_no']} passed the first checks and are waiting for the registrar.", 'success');
    notify_roles(STAFF_ROLES, $requestId, 'Corrections submitted', "{$user['full_name']} sent corrected documents for {$request['tracking_no']}.", 'info');
    json_response(['ok' => true, 'message' => 'Your corrected documents were verified and sent to the registrar.']);
}

// ---------- Admin / registrar actions ----------

function review_file($user) {
    $input = read_json_body();
    $decision = $input['decision'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($decision, ['Verified', 'Rejected', 'Resubmit'], true)) fail('Invalid decision.', 422);
    if ($decision !== 'Verified' && $remarks === '') fail('Please write a remark so the student knows what to fix.', 422);

    $file = fetch_one("SELECT f.id, f.request_id, r.status FROM request_files f JOIN requests r ON r.id = f.request_id
                       WHERE f.id = ? AND f.kind = 'requirement' AND f.is_current = 1", [(int)($input['file_id'] ?? 0)]);
    if (!$file) fail('File not found.', 404);
    if (!in_array($file['status'], ['FOR_REVIEW', 'NEEDS_CORRECTION'], true)) fail('Documents can only be reviewed while the request is waiting for review or correction.', 422);

    query('UPDATE request_files SET review_status = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
        [$decision, $remarks !== '' ? $remarks : null, $user['id'], $file['id']]);

    // Nothing is declined any more (for example the admin approved an AI-declined file): back to review.
    if ($file['status'] === 'NEEDS_CORRECTION') {
        $stillBad = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1
                               AND review_status IN ('Rejected','Resubmit')", [$file['request_id']])['c'];
        if ($stillBad == 0) change_status((int)$file['request_id'], 'FOR_REVIEW', $user['id'], 'All documents are accepted.');
    }
    json_response(['ok' => true]);
}

function set_request_status($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    $id = (int)$request['id'];
    $new = $input['status'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($new, ['FOR_REVIEW', 'PROCESSING', 'NEEDS_CORRECTION', 'READY', 'COMPLETED', 'REJECTED'], true)) {
        fail('That status is set by the system, not by hand.', 422);
    }
    if (in_array($new, ['NEEDS_CORRECTION', 'REJECTED'], true) && $remarks === '') fail('Please write a remark for the student.', 422);

    if ($new === 'PROCESSING') {
        $notVerified = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1 AND review_status <> 'Verified'", [$id])['c'];
        if ($notVerified > 0) fail('Please verify every uploaded document first.', 422);
        if ((float)$request['total_amount'] > 0 && $request['payment_status'] !== 'Paid') {
            fail('The request cannot start processing until the cashier confirms full payment.', 422);
        }
    }
    if ($new === 'READY') {
        if ($request['total_amount'] > 0 && $request['payment_status'] !== 'Paid') fail('The payment has not been confirmed yet.', 422);
        if (format_wants_softcopy($request['release_method'])) {
            $notReady = (int)fetch_one("SELECT COUNT(*) AS c FROM request_items WHERE request_id = ? AND softcopy_status <> 'READY'", [$id])['c'];
            if ($notReady > 0) fail('Upload the soft copy (PDF) of every document and mark it ready first.', 422);
        }
    }
    change_status($id, $new, $user['id'], $remarks);
    json_response(['ok' => true]);
}

function run_ai_action($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    if (!in_array($request['status'], ['FOR_REVIEW', 'NEEDS_CORRECTION'], true)) fail('The documents can only be checked again while the request is waiting for review or correction.', 422);
    $fileIds = null;
    if (!empty($input['file_id'])) {
        $file = fetch_one("SELECT id FROM request_files WHERE id = ? AND request_id = ? AND kind = 'requirement' AND is_current = 1", [(int)$input['file_id'], $request['id']]);
        if (!$file) fail('File not found.', 404);
        $fileIds = [(int)$file['id']];
    }
    $run = run_ai_for_request((int)$request['id'], $fileIds);
    json_response(['ok' => true, 'counts' => $run['counts'], 'summary' => ai_summary_text($run)]);
}

// The registrar uploads (or replaces) the PDF of ONE document of a request. The PDF belongs to that document of that request only.
function attach_release($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    if (!format_wants_softcopy($request['release_method'])) fail('The student did not ask for a soft copy.', 422);
    if (!in_array($request['status'], ['PROCESSING', 'READY'], true)) fail('A soft copy can only be uploaded after the payment was confirmed, while the request is processing.', 422);
    if ((float)$request['total_amount'] > 0 && $request['payment_status'] !== 'Paid') fail('The payment has not been confirmed yet.', 422);
    // the document must be part of THIS request (never trust the number the browser sends)
    $item = fetch_one('SELECT id, softcopy_status FROM request_items WHERE id = ? AND request_id = ?', [(int)($_POST['item_id'] ?? 0), $request['id']]);
    if (!$item) fail('Document not found.', 404);
    try {
        $file = get_uploaded('file');
        if (!$file) fail('Choose a PDF file to upload.', 422);
        $info = validate_file($file['tmp_name'], $file['name'], SOFTCOPY_MAX_MB, 'pdf');          // PDF only: the content is checked, not only the name
        if (check_pdf_basic($file['tmp_name']) !== null) throw new RuntimeException('The PDF is damaged or password protected. Please upload another file.');
        $stored = store_file($file['tmp_name'], 'pdf');                                            // random name: the uploaded name is never used on the disk
    } catch (RuntimeException $e) { fail($e->getMessage(), 422); }

    $pdo = db();
    $replaced = $item['softcopy_status'] !== 'NONE';
    try {
        $pdo->beginTransaction();
        query("UPDATE request_files SET is_current = 0 WHERE item_id = ? AND kind = 'release'", [$item['id']]);   // the old PDF is kept, only not current
        save_file_row((int)$request['id'], (int)$item['id'], null, 'release', $file['name'], $stored, $info);
        // A new PDF starts again: not ready, one download. Open additional-download requests of the old PDF are closed.
        query("UPDATE request_items SET softcopy_status = 'UPLOADED', softcopy_uploaded_by = ?, softcopy_uploaded_at = NOW(), softcopy_ready_at = NULL,
                      download_limit = 1, download_count = 0, last_downloaded_at = NULL WHERE id = ?", [$user['id'], $item['id']]);
        query("UPDATE softcopy_download_requests SET status = 'REJECTED', rejection_reason = 'The document was replaced by the registrar.', decided_by = ?, decided_at = NOW()
               WHERE item_id = ? AND status = 'PENDING'", [$user['id'], $item['id']]);
        query("UPDATE softcopy_download_requests SET status = 'USED' WHERE item_id = ? AND status = 'APPROVED'", [$item['id']]);
        softcopy_log((int)$request['id'], (int)$user['id'], ($replaced ? 'Soft copy replaced' : 'Soft copy uploaded') . ' (' . clean_text(basename(str_replace('\\', '/', $file['name'])), 120) . ').' . ($replaced ? ' Downloads start again.' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        delete_stored_file($stored);
        write_log('attach_release failed: ' . $e->getMessage());
        fail('The PDF could not be saved. Please try again.', 500);
    }
    json_response(['ok' => true]);
}

// The registrar marks the uploaded PDF as ready: from now on the student can download it (once).
function softcopy_ready($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    if (!in_array($request['status'], ['PROCESSING', 'READY'], true)) fail('The request must be processing first.', 422);
    if ((float)$request['total_amount'] > 0 && $request['payment_status'] !== 'Paid') fail('The payment has not been confirmed yet.', 422);
    $item = fetch_one('SELECT i.id, i.softcopy_status, d.name FROM request_items i JOIN documents d ON d.id = i.document_id WHERE i.id = ? AND i.request_id = ?', [(int)($input['item_id'] ?? 0), $request['id']]);
    if (!$item) fail('Document not found.', 404);
    if ($item['softcopy_status'] === 'NONE' || !softcopy_current_file($item['id'])) fail('Upload the PDF first.', 422);
    if ($item['softcopy_status'] === 'READY') fail('This soft copy is already marked ready.', 422);

    query("UPDATE request_items SET softcopy_status = 'READY', softcopy_ready_at = NOW() WHERE id = ?", [$item['id']]);
    softcopy_log((int)$request['id'], (int)$user['id'], "Soft copy of {$item['name']} marked ready.");
    $notReady = (int)fetch_one("SELECT COUNT(*) AS c FROM request_items WHERE request_id = ? AND softcopy_status <> 'READY'", [$request['id']])['c'];
    if ($notReady === 0 && in_array($request['status'], ['READY', 'COMPLETED'], true)) {
        add_notification((int)$request['student_id'], (int)$request['id'], 'Soft copy available', "The soft copy (PDF) of request {$request['tracking_no']} is ready. You can download it from your dashboard.", 'success');
    }
    json_response(['ok' => true, 'all_ready' => $notReady === 0]);
}
