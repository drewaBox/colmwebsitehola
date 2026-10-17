<?php
// Soft copy (PDF) documents: secure download, additional downloads and the download history.
// Nobody can open a PDF by its address: every action checks the login, the role and (for students) that the document is theirs.
//
//   Student
//     GET  ?action=mine                      their requests with the soft copy state of every document
//     POST ?action=download      {item_id}   download the PDF (counts as a download; the PDF is sent back)
//     POST ?action=request_more  {item_id, reason_type, reason}   ask for an additional download
//   Admin / Registrar
//     GET  ?action=view&item_id=             look at the PDF (not counted, but written to the history)
//     GET  ?action=requests[&status=&q=&page=]   additional-download requests
//     POST ?action=decide        {id, decision: approve|reject, rejection_reason}
//     GET  ?action=history&item_id=          download history of one document
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/softcopy.php';
register_error_handlers();

$user = require_login(['student', 'admin', 'registrar']);
$action = $_GET['action'] ?? 'mine';
$isStaff = in_array($user['role'], STAFF_ROLES, true);

switch ($action) {
    case 'mine':         require_method('GET');  require_login(['student']); list_mine($user); break;
    case 'download':     require_method('POST'); require_login(['student']); require_csrf(); download_softcopy($user); break;
    case 'request_more': require_method('POST'); require_login(['student']); require_csrf(); request_more($user); break;
    case 'view':         require_method('GET');  require_login(STAFF_ROLES); view_softcopy($user); break;
    case 'requests':     require_method('GET');  require_login(STAFF_ROLES); list_requests_for_staff(); break;
    case 'decide':       require_method('POST'); require_login(STAFF_ROLES); require_csrf(); decide_request($user); break;
    case 'history':      require_method('GET');  require_login(STAFF_ROLES); show_history(); break;
    default: fail('Unknown action.', 404);
}

// ---------- Student: MY DOCUMENT REQUESTS ----------

function list_mine($user) {
    $rows = fetch_all("SELECT i.id AS item_id, i.request_id, i.quantity, i.softcopy_status, i.download_count, i.download_limit, i.last_downloaded_at,
                              d.name AS document_name, r.tracking_no, r.release_method, r.status, r.payment_status, r.total_amount, r.created_at
                       FROM request_items i
                       JOIN requests r ON r.id = i.request_id
                       JOIN documents d ON d.id = i.document_id
                       WHERE r.student_id = ?
                       ORDER BY r.created_at DESC, r.id DESC, i.id LIMIT 100", [$user['id']]);
    foreach ($rows as &$row) {
        $latest = fetch_one('SELECT status, rejection_reason FROM softcopy_download_requests WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$row['item_id']]);
        $count = (int)fetch_one('SELECT COUNT(*) AS c FROM softcopy_download_requests WHERE item_id = ?', [$row['item_id']])['c'];
        $row['softcopy'] = softcopy_state($row, ['release_method' => $row['release_method'], 'status' => $row['status'],
                                                 'payment_status' => $row['payment_status'], 'total_amount' => $row['total_amount']], $latest, $count);
        $row['status_label'] = STATUS_LABELS[$row['status']];
        $row['item_id'] = (int)$row['item_id'];
        $row['request_id'] = (int)$row['request_id'];
        unset($row['softcopy_status'], $row['download_count'], $row['download_limit'], $row['total_amount']);
    }
    json_response(['ok' => true, 'items' => $rows, 'reasons' => SOFTCOPY_REASONS]);
}

// ---------- Student: the secure download ----------

function download_softcopy($user) {
    $itemId = (int)(read_json_body()['item_id'] ?? 0);
    $item = softcopy_item($itemId, $user);                                 // 404 for somebody else's document
    if (!$item) fail('Document not found.', 404);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the document row: two quick clicks (or two windows) can never use one download twice.
        $locked = fetch_one('SELECT softcopy_status, download_count, download_limit FROM request_items WHERE id = ? FOR UPDATE', [$itemId]);
        $item = array_merge($item, $locked);
        $state = softcopy_state_for_item($item);
        if (!$state['can_download']) {
            $pdo->rollBack();
            $messages = ['WAITING' => 'The soft copy is not available yet.', 'REQUEST_PENDING' => 'Your request for an additional download is waiting for approval.',
                         'NONE' => 'This document has no soft copy.'];
            fail($messages[$state['code']] ?? 'You already used your download. Please request an additional download.', 403, ['code' => $state['code']]);
        }
        $file = softcopy_current_file($itemId);
        $path = $file ? stored_file_path($file['stored_name']) : '';
        if (!$file || !is_file($path)) {                                   // checked BEFORE a download is counted
            $pdo->rollBack();
            write_log('Soft copy file is missing for item ' . $itemId);
            fail('The file is missing on the server. Please contact the registrar.', 404);
        }

        $number = (int)$item['download_count'] + 1;
        $extraId = null;
        if ($number > 1) {                                                 // this download was approved: mark the approval as used
            $approved = fetch_one("SELECT id FROM softcopy_download_requests WHERE item_id = ? AND status = 'APPROVED' ORDER BY id LIMIT 1 FOR UPDATE", [$itemId]);
            if ($approved) {
                $extraId = (int)$approved['id'];
                query("UPDATE softcopy_download_requests SET status = 'USED' WHERE id = ?", [$extraId]);
            }
        }
        query('UPDATE request_items SET download_count = ?, last_downloaded_at = NOW() WHERE id = ?', [$number, $itemId]);
        query('INSERT INTO softcopy_downloads (request_id, item_id, student_id, document_id, file_id, download_number, download_type, downloaded_by, ip_address, extra_request_id)
               VALUES (?, ?, ?, (SELECT document_id FROM request_items WHERE id = ?), ?, ?, ?, ?, ?, ?)',
            [$item['request_id'], $itemId, $item['student_id'], $itemId, $file['id'], $number, $number === 1 ? 'ORIGINAL' : 'ADDITIONAL', $user['id'], client_ip(), $extraId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    send_stored_file(['stored_name' => $file['stored_name'], 'original_name' => softcopy_download_name($item), 'mime_type' => 'application/pdf'], false);
}

// ---------- Student: ask for an additional download ----------

function request_more($user) {
    $input = read_json_body();
    $item = softcopy_item((int)($input['item_id'] ?? 0), $user);
    if (!$item) fail('Document not found.', 404);
    $reasonType = (string)($input['reason_type'] ?? '');
    $reason = clean_text($input['reason'] ?? '', 1000);
    if (!in_array($reasonType, SOFTCOPY_REASONS, true)) fail('Please choose a reason for the additional download.', 422);
    if (mb_strlen($reason) < 5) fail('Please write the reason for the additional download.', 422);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $locked = fetch_one('SELECT softcopy_status, download_count, download_limit FROM request_items WHERE id = ? FOR UPDATE', [$item['id']]);
        $item = array_merge($item, $locked);
        $state = softcopy_state_for_item($item);
        if ($state['code'] === 'REQUEST_PENDING') { $pdo->rollBack(); fail('You already have a request waiting for approval.', 422); }
        if (!$state['can_request_more']) {
            $pdo->rollBack();
            fail($state['can_download'] ? 'You still have a download available.' : 'You cannot request an additional download for this document.', 422);
        }
        query('INSERT INTO softcopy_download_requests (request_id, item_id, student_id, reason_type, reason) VALUES (?, ?, ?, ?, ?)',
            [$item['request_id'], $item['id'], $user['id'], $reasonType, $reason]);
        softcopy_log((int)$item['request_id'], (int)$user['id'], "Additional download requested for {$item['document_name']}: $reasonType.");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    notify_roles(STAFF_ROLES, (int)$item['request_id'], 'Additional download requested',
        "{$user['full_name']} asked for another download of {$item['document_name']} ({$item['tracking_no']}): $reasonType.", 'info');
    json_response(['ok' => true, 'message' => 'Your request was sent. The registrar will review it.'], 201);
}

// ---------- Admin / Registrar ----------

// Look at the PDF. It is not a student download, so nothing is counted, but it is written to the history.
function view_softcopy($user) {
    $item = softcopy_item((int)($_GET['item_id'] ?? 0), $user);
    if (!$item) fail('Document not found.', 404);
    $file = softcopy_current_file($item['id']);
    if (!$file || !is_file(stored_file_path($file['stored_name']))) fail('There is no soft copy file for this document.', 404);
    query("INSERT INTO softcopy_downloads (request_id, item_id, student_id, document_id, file_id, download_type, downloaded_by, ip_address)
           VALUES (?, ?, ?, ?, ?, 'STAFF', ?, ?)", [$item['request_id'], $item['id'], $item['student_id'], $item['document_id'], $file['id'], $user['id'], client_ip()]);
    send_stored_file(['stored_name' => $file['stored_name'], 'original_name' => softcopy_download_name($item), 'mime_type' => 'application/pdf'], true);
}

function list_requests_for_staff() {
    $where = ['1 = 1']; $params = [];
    $status = strtoupper((string)($_GET['status'] ?? 'PENDING'));
    if (in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'USED'], true)) { $where[] = 'q.status = ?'; $params[] = $status; }
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(r.tracking_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ? OR d.name LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }
    $from = "FROM softcopy_download_requests q
             JOIN request_items i ON i.id = q.item_id
             JOIN requests r ON r.id = q.request_id
             JOIN documents d ON d.id = i.document_id
             JOIN users u ON u.id = q.student_id
             LEFT JOIN users decision_user ON decision_user.id = q.decided_by
             WHERE " . implode(' AND ', $where);
    $perPage = 20;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = (int)fetch_one("SELECT COUNT(*) AS c $from", $params)['c'];
    $rows = fetch_all("SELECT q.id, q.item_id, q.status, q.reason_type, q.reason, q.rejection_reason, q.created_at, q.decided_at,
                              decision_user.full_name AS decided_by_name, r.tracking_no, r.status AS request_status, d.name AS document_name,
                              u.full_name AS student_name, u.student_no, i.download_count, i.download_limit,
                              (SELECT MIN(x.downloaded_at) FROM softcopy_downloads x WHERE x.item_id = i.id AND x.download_type <> 'STAFF') AS first_download_at
                       $from ORDER BY (q.status = 'PENDING') DESC, q.created_at DESC, q.id DESC LIMIT $perPage OFFSET $offset", $params);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['item_id'] = (int)$row['item_id'];
        $row['download_count'] = (int)$row['download_count'];
        $row['request_status_label'] = STATUS_LABELS[$row['request_status']];
    }
    json_response(['ok' => true, 'requests' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function decide_request($user) {
    $input = read_json_body();
    $decision = $input['decision'] ?? '';
    if (!in_array($decision, ['approve', 'reject'], true)) fail('Choose Approve or Reject.', 422);
    $rejection = clean_text($input['rejection_reason'] ?? '', 500);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $req = fetch_one("SELECT q.*, d.name AS document_name, r.tracking_no FROM softcopy_download_requests q
                          JOIN request_items i ON i.id = q.item_id JOIN documents d ON d.id = i.document_id JOIN requests r ON r.id = q.request_id
                          WHERE q.id = ? FOR UPDATE", [(int)($input['id'] ?? 0)]);
        if (!$req) { $pdo->rollBack(); fail('Request not found.', 404); }
        if ($req['status'] !== 'PENDING') { $pdo->rollBack(); fail('This request was already decided.', 422); }

        if ($decision === 'approve') {
            query("UPDATE softcopy_download_requests SET status = 'APPROVED', decided_by = ?, decided_at = NOW() WHERE id = ?", [$user['id'], $req['id']]);
            query('UPDATE request_items SET download_limit = LEAST(download_limit + 1, 250) WHERE id = ?', [$req['item_id']]);
            softcopy_log((int)$req['request_id'], (int)$user['id'], "Additional download approved for {$req['document_name']} (reason: {$req['reason_type']}).");
        } else {
            query("UPDATE softcopy_download_requests SET status = 'REJECTED', rejection_reason = ?, decided_by = ?, decided_at = NOW() WHERE id = ?",
                [$rejection !== '' ? $rejection : null, $user['id'], $req['id']]);
            softcopy_log((int)$req['request_id'], (int)$user['id'], "Additional download rejected for {$req['document_name']}" . ($rejection !== '' ? ": $rejection" : '.'));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($decision === 'approve') {
        add_notification((int)$req['student_id'], (int)$req['request_id'], 'Additional download approved', "You can download {$req['document_name']} ({$req['tracking_no']}) once more.", 'success');
    } else {
        add_notification((int)$req['student_id'], (int)$req['request_id'], 'Additional download rejected',
            "Your request for another download of {$req['document_name']} was rejected." . ($rejection !== '' ? " Reason: $rejection" : ''), 'warning');
    }
    json_response(['ok' => true]);
}

// DOCUMENT DOWNLOAD HISTORY of one document: who downloaded it when, and why an additional download was allowed.
function show_history() {
    $item = softcopy_item((int)($_GET['item_id'] ?? 0), ['role' => 'admin', 'id' => 0]);
    if (!$item) fail('Document not found.', 404);
    $downloads = fetch_all("SELECT x.id, x.download_number, x.download_type, x.downloaded_at, x.ip_address, u.full_name AS by_name,
                                   q.reason_type, q.reason, q.status AS approval_status, q.decided_at AS approved_at, decision_user.full_name AS approved_by_name
                            FROM softcopy_downloads x
                            LEFT JOIN users u ON u.id = x.downloaded_by
                            LEFT JOIN softcopy_download_requests q ON q.id = x.extra_request_id
                            LEFT JOIN users decision_user ON decision_user.id = q.decided_by
                            WHERE x.item_id = ? ORDER BY x.id", [$item['id']]);
    $requests = fetch_all("SELECT q.id, q.reason_type, q.reason, q.status, q.rejection_reason, q.created_at, q.decided_at, decision_user.full_name AS decided_by_name
                           FROM softcopy_download_requests q LEFT JOIN users decision_user ON decision_user.id = q.decided_by
                           WHERE q.item_id = ? ORDER BY q.id", [$item['id']]);
    json_response(['ok' => true,
        'item' => ['student_name' => $item['student_name'], 'student_no' => $item['student_no'], 'document_name' => $item['document_name'],
                   'tracking_no' => $item['tracking_no'], 'download_count' => (int)$item['download_count'], 'download_limit' => (int)$item['download_limit']],
        'downloads' => $downloads, 'additional_requests' => $requests]);
}
