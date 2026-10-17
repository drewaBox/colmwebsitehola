<?php
// Soft copy (PDF) release: the rules for who may download a document and how many times.
//
//  * The registrar uploads the PDF for a document of a request (api/requests.php, attach_release) and marks it READY.
//  * The student can download it ONCE (download_limit = 1). Every download is written to softcopy_downloads.
//  * Another download needs a reason (softcopy_download_requests). When the registrar/admin approves it the limit goes up by one.
//  * The PDF is never deleted and never public: it is only sent by api/softcopy.php after these checks.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/workflow.php';

const SOFTCOPY_MAX_MB = 10;
const SOFTCOPY_MAX_EXTRA_REQUESTS = 5;          // a student can ask at most 5 times for the same document
const SOFTCOPY_REASONS = [
    'PDF file was corrupted', 'System error - unable to download', 'Wrong name or data',
];

// Did the student choose a soft copy ("Softcopy") or both ("Both")?
function format_wants_softcopy($format) {
    return $format === 'Softcopy' || $format === 'Both';
}

// What may this student do with the soft copy of ONE document? (no database access here, so it can be tested)
//   $item    = softcopy_status, download_count, download_limit
//   $request = release_method, status, payment_status, total_amount
//   $latest  = the newest additional-download request of this document (status, rejection_reason) or null
//   $extraCount = how many additional-download requests this document already has
function softcopy_state($item, $request, $latest = null, $extraCount = 0) {
    $state = ['wanted' => format_wants_softcopy($request['release_method']), 'code' => 'NONE', 'label' => '-', 'can_download' => false,
              'can_request_more' => false, 'downloads_used' => (int)$item['download_count'], 'downloads_allowed' => (int)$item['download_limit'],
              'rejection_reason' => null];
    if (!$state['wanted']) return $state;

    $paid = $request['payment_status'] === 'Paid' || (float)$request['total_amount'] == 0.0;
    $released = in_array($request['status'], ['READY', 'COMPLETED'], true);
    if ($item['softcopy_status'] !== 'READY' || !$released || !$paid) {
        $state['code'] = 'WAITING';
        $state['label'] = 'Waiting for Registrar';
        return $state;
    }
    if ($state['downloads_used'] < $state['downloads_allowed']) {
        $approved = $state['downloads_used'] > 0;                        // a second download only exists after an approval
        $state['code'] = $approved ? 'APPROVED' : 'AVAILABLE';
        $state['label'] = $approved ? 'Additional download approved' : 'Soft Copy Available';
        $state['can_download'] = true;
        return $state;
    }
    // The downloads are used up.
    if ($latest && $latest['status'] === 'PENDING') {
        $state['code'] = 'REQUEST_PENDING';
        $state['label'] = 'Additional download requested';
        return $state;
    }
    $state['code'] = 'USED';
    $state['label'] = 'Download Used';
    if ($latest && $latest['status'] === 'REJECTED') {
        $state['code'] = 'REJECTED';
        $state['label'] = 'Additional download rejected';
        $state['rejection_reason'] = $latest['rejection_reason'];
    }
    $state['can_request_more'] = $extraCount < SOFTCOPY_MAX_EXTRA_REQUESTS;
    return $state;
}

// The current PDF of a document (the older ones stay in the database and on the disk, they are only marked not current).
function softcopy_current_file($itemId) {
    return fetch_one("SELECT * FROM request_files WHERE item_id = ? AND kind = 'release' AND is_current = 1 ORDER BY id DESC LIMIT 1", [$itemId]);
}

// Loads one document of a request together with the request. A student only gets their own (anything else = null, so ids cannot be guessed).
function softcopy_item($itemId, $user) {
    $row = fetch_one("SELECT i.*, d.name AS document_name, r.tracking_no, r.student_id, r.release_method, r.status AS request_status,
                             r.payment_status, r.total_amount, u.full_name AS student_name, u.student_no
                      FROM request_items i
                      JOIN requests r ON r.id = i.request_id
                      JOIN documents d ON d.id = i.document_id
                      JOIN users u ON u.id = r.student_id
                      WHERE i.id = ?", [$itemId]);
    if (!$row) return null;
    if ($user['role'] === 'student' && (int)$row['student_id'] !== (int)$user['id']) return null;
    return $row;
}

function softcopy_state_for_item($row) {
    $latest = fetch_one('SELECT status, rejection_reason FROM softcopy_download_requests WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$row['id']]);
    $count = (int)fetch_one('SELECT COUNT(*) AS c FROM softcopy_download_requests WHERE item_id = ?', [$row['id']])['c'];
    return softcopy_state($row, ['release_method' => $row['release_method'], 'status' => $row['request_status'],
                                 'payment_status' => $row['payment_status'], 'total_amount' => $row['total_amount']], $latest, $count);
}

// A line in the history of the request ("Soft copy uploaded ...", "Additional download approved ..."). Shown to the staff.
function softcopy_log($requestId, $userId, $text) {
    $status = fetch_one('SELECT status FROM requests WHERE id = ?', [$requestId])['status'];
    add_history($requestId, $status, $status, $userId, $text);
}

// A safe file name for the download. The name the registrar uploaded is NEVER used.
function softcopy_download_name($row) {
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', $row['tracking_no'] . '-' . $row['document_name']);
    return trim($name, '-') . '.pdf';
}

function client_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}
