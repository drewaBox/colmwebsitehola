<?php
// Request statuses, status changes, history and notifications.
require_once __DIR__ . '/database.php';

// Which status can follow which. The system moves SUBMITTED -> AI_VERIFYING -> FOR_REVIEW by itself.
const NEXT_STATUS = [
    'SUBMITTED'        => ['AI_VERIFYING'],
    'AI_VERIFYING'     => ['FOR_REVIEW', 'NEEDS_CORRECTION'],   // NEEDS_CORRECTION when a file is declined
    'FOR_REVIEW'       => ['PROCESSING', 'NEEDS_CORRECTION', 'REJECTED'],
    'NEEDS_CORRECTION' => ['AI_VERIFYING', 'FOR_REVIEW', 'REJECTED'],   // FOR_REVIEW: the admin overrode a declined file
    'PROCESSING'       => ['READY', 'NEEDS_CORRECTION', 'REJECTED'],
    'READY'            => ['COMPLETED'],
    'COMPLETED'        => [],
    'REJECTED'         => [],
];

const STATUS_LABELS = [
    'SUBMITTED'        => 'Submitted',
    'AI_VERIFYING'     => 'Document Checking',
    'FOR_REVIEW'       => 'For Admin Review',
    'NEEDS_CORRECTION' => 'Needs Correction',
    'PROCESSING'       => 'Processing',
    'READY'            => 'Ready for Release',
    'COMPLETED'        => 'Completed',
    'REJECTED'         => 'Rejected',
];

function estimate_processing_date($submissionDate, $processingDays) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $submissionDate);
    if (!$date || $date->format('Y-m-d') !== $submissionDate) {
        throw new InvalidArgumentException('Submission date must be a valid Y-m-d date.');
    }

    while ((int)$date->format('N') >= 6) $date = $date->modify('+1 day');

    for ($remaining = max(0, (int)$processingDays); $remaining > 0;) {
        $date = $date->modify('+1 day');
        if ((int)$date->format('N') < 6) $remaining--;
    }
    return $date->format('Y-m-d');
}

function add_notification($userId, $requestId, $title, $message, $type = 'info') {
    $title = mb_substr($title, 0, 150);
    $message = mb_substr($message, 0, 500);     // the database column holds 500 characters
    query('INSERT INTO notifications (user_id, request_id, title, message, type) VALUES (?, ?, ?, ?, ?)',
        [$userId, $requestId, $title, $message, $type]);
}

// Send the same notification to every active user with one of the roles (e.g. all admins).
function notify_roles($roles, $requestId, $title, $message, $type = 'info') {
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $users = fetch_all("SELECT id FROM users WHERE is_active = 1 AND role IN ($marks)", $roles);
    foreach ($users as $u) add_notification($u['id'], $requestId, $title, $message, $type);
}

// Add one line to the request history (shown to the student and the registrar).
function add_history($requestId, $oldStatus, $newStatus, $userId, $remarks = '') {
    $remarks = mb_substr((string)$remarks, 0, 500);
    query('INSERT INTO request_history (request_id, old_status, new_status, changed_by, remarks) VALUES (?, ?, ?, ?, ?)',
        [$requestId, $oldStatus, $newStatus, $userId, $remarks]);
}

// Change the status, save the history and tell the student. $userId = who did it (null = the system).
function change_status($requestId, $newStatus, $userId, $remarks = '') {
    $request = fetch_one('SELECT id, tracking_no, student_id, status FROM requests WHERE id = ?', [$requestId]);
    if (!$request) fail('Request not found.', 404);
    $old = $request['status'];
    if (!in_array($newStatus, NEXT_STATUS[$old] ?? [], true)) {
        fail("A request that is \"" . STATUS_LABELS[$old] . "\" cannot be changed to \"" . (STATUS_LABELS[$newStatus] ?? $newStatus) . "\".", 422);
    }

    $completedAt = $newStatus === 'COMPLETED' ? date('Y-m-d H:i:s') : null;
    query('UPDATE requests SET status = ?, registrar_remarks = COALESCE(?, registrar_remarks), completed_at = COALESCE(?, completed_at) WHERE id = ?',
        [$newStatus, $remarks !== '' ? $remarks : null, $completedAt, $requestId]);
    add_history($requestId, $old, $newStatus, $userId, $remarks);

    $tracking = $request['tracking_no'];
    $notes = [
        'NEEDS_CORRECTION' => ['Correction needed', "Request $tracking needs correction. " . $remarks, 'warning'],
        'PROCESSING'       => ['Request processing', "Request $tracking passed review and is now being processed.", 'info'],
        'READY'            => ['Ready for release', "Request $tracking is ready for release.", 'success'],
        'COMPLETED'        => ['Request completed', "Request $tracking is completed.", 'success'],
        'REJECTED'         => ['Request rejected', "Request $tracking was rejected. " . $remarks, 'error'],
    ];
    if (isset($notes[$newStatus])) {
        [$title, $message, $type] = $notes[$newStatus];
        add_notification($request['student_id'], $requestId, $title, trim($message), $type);
    }
}
