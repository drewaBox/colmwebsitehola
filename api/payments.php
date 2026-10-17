<?php
// Payments - this is the ONLY file the cashier can use (an admin can use it too).
//   GET  ?action=summary                  numbers for the Payment Dashboard
//   GET  ?action=pending[&q=]             requests that are not paid yet
//   GET  ?action=history[&q=&page=]       recorded payments (receipts)
//   GET  ?action=student&id=REQUEST_ID    payment information of the student who made that request
//   GET  ?action=receipt&id=PAYMENT_ID    one receipt
//   POST ?action=record  {request_id, amount, method}   record a payment
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/workflow.php';
register_error_handlers();
$user = require_login(['cashier', 'admin']);
$action = $_GET['action'] ?? 'summary';

switch ($action) {
    case 'summary': require_method('GET'); payment_summary(); break;
    case 'pending': require_method('GET'); pending_payments(); break;
    case 'history': require_method('GET'); payment_history(); break;
    case 'student': require_method('GET'); student_payment_info(); break;
    case 'receipt': require_method('GET'); show_receipt(); break;
    case 'record':  require_method('POST'); require_csrf(); record_payment($user); break;
    default: fail('Unknown action.', 404);
}

function payment_summary() {
    $unpaid = fetch_one("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS total FROM requests
                         WHERE payment_status = 'Unpaid' AND status NOT IN ('REJECTED', 'COMPLETED')");
    $today = fetch_one("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total FROM payments WHERE DATE(paid_at) = CURDATE()");
    $all = fetch_one("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total FROM payments");
    json_response(['ok' => true, 'summary' => [
        'unpaid_count' => (int)$unpaid['c'], 'unpaid_total' => (float)$unpaid['total'],
        'today_count' => (int)$today['c'], 'today_total' => (float)$today['total'],
        'all_count' => (int)$all['c'], 'all_total' => (float)$all['total'],
    ]]);
}

// Only what the cashier needs: who pays, how much, for which request. Never the uploaded documents.
function pending_payments() {
    $where = ["r.payment_status = 'Unpaid'", "r.status NOT IN ('REJECTED', 'COMPLETED')"];
    $params = [];
    if (in_array($_GET['method'] ?? '', ['Cash', 'GCash'], true)) { $where[] = 'r.payment_method = ?'; $params[] = $_GET['method']; }
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(r.tracking_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $rows = fetch_all("SELECT r.id, r.tracking_no, r.total_amount, r.payment_method, r.status, r.created_at,
                              u.full_name, u.student_no, s.school_level, s.course, s.year_level, s.section
                       FROM requests r JOIN users u ON u.id = r.student_id LEFT JOIN students s ON s.user_id = u.id
                       WHERE " . implode(' AND ', $where) . " ORDER BY r.created_at LIMIT 100", $params);
    foreach ($rows as &$row) { $row['id'] = (int)$row['id']; $row['total_amount'] = (float)$row['total_amount']; $row['status_label'] = STATUS_LABELS[$row['status']]; }
    json_response(['ok' => true, 'payments' => $rows]);
}

function payment_history() {
    $where = ['1 = 1']; $params = [];
    if (in_array($_GET['method'] ?? '', ['Cash', 'GCash'], true)) { $where[] = 'p.method = ?'; $params[] = $_GET['method']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) { $where[] = 'p.paid_at >= ?'; $params[] = $_GET['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) { $where[] = 'p.paid_at <= ?'; $params[] = $_GET['to'] . ' 23:59:59'; }
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(p.receipt_no LIKE ? OR r.tracking_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }
    $perPage = 25;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $from = "FROM payments p JOIN requests r ON r.id = p.request_id JOIN users u ON u.id = r.student_id LEFT JOIN users c ON c.id = p.received_by WHERE " . implode(' AND ', $where);
    $total = (int)fetch_one("SELECT COUNT(*) AS c $from", $params)['c'];
    $rows = fetch_all("SELECT p.id, p.receipt_no, p.amount, p.method, p.paid_at, r.tracking_no, u.full_name, u.student_no, c.full_name AS cashier_name
                       $from ORDER BY p.paid_at DESC, p.id DESC LIMIT $perPage OFFSET $offset", $params);
    foreach ($rows as &$row) { $row['id'] = (int)$row['id']; $row['amount'] = (float)$row['amount']; }
    json_response(['ok' => true, 'payments' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

// Student Payment Information: the student's basic details and all of their requests with the payment status.
function student_payment_info() {
    $request = fetch_one('SELECT student_id FROM requests WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
    if (!$request) fail('Request not found.', 404);
    $student = fetch_one('SELECT u.id, u.full_name, u.student_no, s.school_level, s.course, s.year_level, s.section
                          FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ?', [$request['student_id']]);
    $requests = fetch_all("SELECT r.id, r.tracking_no, r.total_amount, r.payment_method, r.payment_status, r.status, r.created_at,
                                  p.receipt_no, p.paid_at
                           FROM requests r LEFT JOIN payments p ON p.request_id = r.id
                           WHERE r.student_id = ? ORDER BY r.created_at DESC LIMIT 50", [$request['student_id']]);
    foreach ($requests as &$r) { $r['id'] = (int)$r['id']; $r['total_amount'] = (float)$r['total_amount']; $r['status_label'] = STATUS_LABELS[$r['status']]; }
    json_response(['ok' => true, 'student' => $student, 'requests' => $requests]);
}

function show_receipt() {
    $row = fetch_one("SELECT p.id, p.receipt_no, p.amount, p.method, p.paid_at, r.tracking_no, u.full_name, u.student_no, c.full_name AS cashier_name
                      FROM payments p JOIN requests r ON r.id = p.request_id JOIN users u ON u.id = r.student_id
                      LEFT JOIN users c ON c.id = p.received_by WHERE p.id = ?", [(int)($_GET['id'] ?? 0)]);
    if (!$row) fail('Receipt not found.', 404);
    $row['amount'] = (float)$row['amount'];
    $row['items'] = fetch_all("SELECT d.name, i.quantity, i.unit_price FROM request_items i JOIN documents d ON d.id = i.document_id
                               JOIN payments p ON p.request_id = i.request_id WHERE p.id = ?", [$row['id']]);
    json_response(['ok' => true, 'receipt' => $row]);
}

function record_payment($user) {
    $input = read_json_body();
    $request = fetch_one('SELECT id, tracking_no, student_id, status, payment_status, total_amount FROM requests WHERE id = ?', [(int)($input['request_id'] ?? 0)]);
    if (!$request) fail('Request not found.', 404);
    if ($request['payment_status'] === 'Paid') fail('This request is already paid.', 422);
    if (in_array($request['status'], ['REJECTED', 'COMPLETED'], true)) fail('This request cannot be paid any more.', 422);

    $method = $input['method'] ?? '';
    if (!in_array($method, ['Cash', 'GCash'], true)) fail('Choose Cash or GCash.', 422);
    $amount = $input['amount'] ?? null;
    if (!is_numeric($amount)) fail('Enter the amount received.', 422);
    if (round((float)$amount, 2) !== round((float)$request['total_amount'], 2)) {
        fail('The amount must be exactly ' . number_format((float)$request['total_amount'], 2) . '.', 422);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $receipt = make_receipt_number();
        query('INSERT INTO payments (request_id, receipt_no, amount, method, received_by) VALUES (?, ?, ?, ?, ?)',
            [$request['id'], $receipt, round((float)$amount, 2), $method, $user['id']]);
        $paymentId = (int)$pdo->lastInsertId();
        query("UPDATE requests SET payment_status = 'Paid', payment_method = ? WHERE id = ?", [$method, $request['id']]);
        add_history((int)$request['id'], $request['status'], $request['status'], $user['id'], "Payment received ($receipt).");
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') fail('This request was just paid by someone else.', 422);   // duplicate key
        throw $e;
    }
    add_notification((int)$request['student_id'], (int)$request['id'], 'Payment confirmed', "Payment for request {$request['tracking_no']} was received. Receipt no. $receipt.", 'success');
    json_response(['ok' => true, 'payment_id' => $paymentId, 'receipt_no' => $receipt], 201);
}

function make_receipt_number() {
    for ($i = 0; $i < 10; $i++) {
        $number = 'OR-' . date('Ymd') . '-' . random_int(1000, 9999);
        if (!fetch_one('SELECT id FROM payments WHERE receipt_no = ?', [$number])) return $number;
    }
    throw new RuntimeException('Could not create a receipt number.');
}
