<?php
// The document catalog.
//   GET                  every logged-in user: documents with their requirements
//                        (students only see active documents, the admin sees all)
//   POST ?action=save    admin: add (id = 0) or edit a document together with its requirements
//   POST ?action=toggle  admin: activate / deactivate {id}
//   POST ?action=delete  admin: delete {id} (only when no request uses it)
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/students.php';
register_error_handlers();
$user = require_login(['student', 'admin', 'registrar']);          // the cashier does not need the catalog

const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png'];
const RELEASE_METHODS = ['Softcopy', 'Hardcopy'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $isAdmin = in_array($user['role'], STAFF_ROLES, true);
    $documents = fetch_all('SELECT id, name, description, category, price, processing_min_days, processing_max_days, release_methods, is_active
                            FROM documents ' . ($isAdmin ? '' : 'WHERE is_active = 1 ') . 'ORDER BY sort_order, id');
    if ($user['role'] === 'student') {
        $student = fetch_one('SELECT school_level FROM students WHERE user_id = ?', [$user['id']]);
        if (($student['school_level'] ?? '') === 'SENIOR HIGH SCHOOL') {
            $documents = array_values(array_filter($documents, fn($doc) => senior_high_can_request_document($doc['name'])));
        }
    }
    $requirements = fetch_all('SELECT id, document_id, name, description, ai_hint, keywords, is_required, requires_name, allowed_types, max_size_mb
                               FROM document_requirements ORDER BY sort_order, id');
    $pendingDocuments = [];
    if ($user['role'] === 'student') {
        foreach (fetch_all("SELECT i.document_id, r.tracking_no
                            FROM request_items i
                            JOIN requests r ON r.id = i.request_id
                            WHERE r.student_id = ? AND r.status NOT IN ('COMPLETED', 'REJECTED')
                            ORDER BY r.created_at, r.id", [$user['id']]) as $row) {
            $pendingDocuments[(int)$row['document_id']] = $row['tracking_no'];
        }
    }
    $used = [];
    if ($isAdmin) {
        foreach (fetch_all('SELECT document_id, COUNT(*) AS c FROM request_items GROUP BY document_id') as $row) $used[(int)$row['document_id']] = (int)$row['c'];
    }
    foreach ($documents as &$doc) {
        $doc['id'] = (int)$doc['id'];
        $doc['price'] = (float)$doc['price'];
        $doc['processing_min_days'] = (int)$doc['processing_min_days'];
        $doc['processing_max_days'] = (int)$doc['processing_max_days'];
        $doc['is_active'] = (bool)$doc['is_active'];
        $doc['release_methods'] = explode(',', $doc['release_methods']);
        $doc['pending_request'] = isset($pendingDocuments[$doc['id']]);
        $doc['pending_tracking_no'] = $pendingDocuments[$doc['id']] ?? null;
        $doc['requirements'] = array_values(array_filter($requirements, fn($r) => (int)$r['document_id'] === $doc['id']));
        foreach ($doc['requirements'] as &$req) {
            $req['id'] = (int)$req['id'];
            $req['is_required'] = (bool)$req['is_required'];
            $req['requires_name'] = (bool)$req['requires_name'];
            $req['max_size_mb'] = (int)$req['max_size_mb'];
            if (!$isAdmin) { unset($req['ai_hint']); unset($req['keywords']); }
        }
        unset($req);
        if ($isAdmin) $doc['request_count'] = $used[$doc['id']] ?? 0;
    }
    json_response(['ok' => true, 'documents' => $documents]);
}

require_method('POST');
require_login(STAFF_ROLES);
require_csrf();
$action = $_GET['action'] ?? 'save';
$input = read_json_body();

if ($action === 'toggle') {
    $id = (int)($input['id'] ?? 0);
    $doc = fetch_one('SELECT is_active FROM documents WHERE id = ?', [$id]);
    if (!$doc) fail('Document not found.', 404);
    query('UPDATE documents SET is_active = ? WHERE id = ?', [$doc['is_active'] ? 0 : 1, $id]);
    json_response(['ok' => true, 'is_active' => !$doc['is_active']]);
}

if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    $doc = fetch_one('SELECT id, name FROM documents WHERE id = ?', [$id]);
    if (!$doc) fail('Document not found.', 404);
    $count = (int)fetch_one('SELECT COUNT(*) AS c FROM request_items WHERE document_id = ?', [$id])['c'];
    if ($count > 0) fail("\"{$doc['name']}\" is used by $count request(s) and cannot be deleted. Deactivate it instead so the old requests keep their records.", 422);
    query('DELETE FROM documents WHERE id = ?', [$id]);          // its requirements are deleted with it
    json_response(['ok' => true]);
}

if ($action !== 'save') fail('Unknown action.', 404);

// ---------- Add or edit ----------
$id = (int)($input['id'] ?? 0);
$name = clean_text($input['name'] ?? '', 150);
$description = clean_text($input['description'] ?? '', 2000);
$category = clean_text($input['category'] ?? '', 60);
$price = $input['price'] ?? null;
$minDays = $input['processing_min_days'] ?? 0;
$maxDays = $input['processing_max_days'] ?? 0;
$methods = array_values(array_intersect(RELEASE_METHODS, is_array($input['release_methods'] ?? null) ? $input['release_methods'] : []));
$isActive = empty($input['is_active']) ? 0 : 1;

if ($name === '') fail('Please enter the document name.', 422);
if ($category === '') fail('Please choose or type a category.', 422);
if (!is_numeric($price) || $price < 0 || $price > 100000) fail('Enter a valid price (0 to 100000).', 422);
if (!ctype_digit((string)$minDays) || !ctype_digit((string)$maxDays) || (int)$minDays > 365 || (int)$maxDays > 365 || (int)$minDays > (int)$maxDays) {
    fail('Processing days must be whole numbers and the first number cannot be bigger than the second.', 422);
}
if (!$methods) fail('Choose at least one release method.', 422);

// Clean up the requirements list.
$requirements = [];
$names = [];
foreach (is_array($input['requirements'] ?? null) ? $input['requirements'] : [] as $index => $req) {
    $reqName = clean_text($req['name'] ?? '', 150);
    if ($reqName === '') fail('Requirement ' . ($index + 1) . ' needs a name.', 422);
    if (isset($names[mb_strtolower($reqName)])) fail("The requirement \"$reqName\" is listed twice.", 422);
    $names[mb_strtolower($reqName)] = true;
    $types = array_values(array_intersect(FILE_TYPES, is_array($req['allowed_types'] ?? null) ? $req['allowed_types'] : explode(',', (string)($req['allowed_types'] ?? ''))));
    if (!$types) fail("Choose at least one allowed file type for \"$reqName\".", 422);
    $maxMb = (int)($req['max_size_mb'] ?? 3);
    if ($maxMb < 1 || $maxMb > 10) fail("The maximum file size for \"$reqName\" must be 1 to 10 MB.", 422);
    $requirements[] = [
        'id' => (int)($req['id'] ?? 0), 'name' => $reqName,
        'description' => clean_text($req['description'] ?? '', 1000), 'ai_hint' => clean_text($req['ai_hint'] ?? '', 1000),
        'keywords' => clean_text(preg_replace('/\s*,\s*/', ',', (string)($req['keywords'] ?? '')), 255),
        'is_required' => empty($req['is_required']) ? 0 : 1, 'requires_name' => empty($req['requires_name']) ? 0 : 1,
        'allowed_types' => implode(',', $types), 'max_size_mb' => $maxMb,
    ];
}
if (count($requirements) > 12) fail('A document can have at most 12 requirements.', 422);

if (fetch_one('SELECT id FROM documents WHERE name = ? AND id <> ?', [$name, $id])) fail('Another document already has this name.', 422);
if ($id > 0 && !fetch_one('SELECT id FROM documents WHERE id = ?', [$id])) fail('Document not found.', 404);

$pdo = db();
try {
    $pdo->beginTransaction();
    if ($id === 0) {
        $sort = (int)fetch_one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM documents')['n'];
        query('INSERT INTO documents (name, description, category, price, processing_min_days, processing_max_days, release_methods, is_active, sort_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $description, $category, round((float)$price, 2), (int)$minDays, (int)$maxDays, implode(',', $methods), $isActive, $sort]);
        $id = (int)$pdo->lastInsertId();
    } else {
        query('UPDATE documents SET name = ?, description = ?, category = ?, price = ?, processing_min_days = ?, processing_max_days = ?, release_methods = ?, is_active = ? WHERE id = ?',
            [$name, $description, $category, round((float)$price, 2), (int)$minDays, (int)$maxDays, implode(',', $methods), $isActive, $id]);
    }

    // Update the requirements that have an id, add the new ones, remove the ones that were taken out of the list.
    $keepIds = [];
    foreach ($requirements as $order => $req) {
        if ($req['id'] > 0) {
            $owned = fetch_one('SELECT id FROM document_requirements WHERE id = ? AND document_id = ?', [$req['id'], $id]);
            if (!$owned) throw new RuntimeException('One of the requirements does not belong to this document.');
            query('UPDATE document_requirements SET name = ?, description = ?, ai_hint = ?, keywords = ?, is_required = ?, requires_name = ?, allowed_types = ?, max_size_mb = ?, sort_order = ? WHERE id = ?',
                [$req['name'], $req['description'], $req['ai_hint'], $req['keywords'] !== '' ? $req['keywords'] : null, $req['is_required'], $req['requires_name'], $req['allowed_types'], $req['max_size_mb'], $order + 1, $req['id']]);
            $keepIds[] = $req['id'];
        } else {
            query('INSERT INTO document_requirements (document_id, name, description, ai_hint, keywords, is_required, requires_name, allowed_types, max_size_mb, sort_order)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $req['name'], $req['description'], $req['ai_hint'], $req['keywords'] !== '' ? $req['keywords'] : null, $req['is_required'], $req['requires_name'], $req['allowed_types'], $req['max_size_mb'], $order + 1]);
            $keepIds[] = (int)$pdo->lastInsertId();
        }
    }
    foreach (fetch_all('SELECT id, name FROM document_requirements WHERE document_id = ?', [$id]) as $old) {
        if (in_array((int)$old['id'], $keepIds, true)) continue;
        $files = (int)fetch_one('SELECT COUNT(*) AS c FROM request_files WHERE requirement_id = ?', [$old['id']])['c'];
        if ($files > 0) throw new RuntimeException("The requirement \"{$old['name']}\" already has uploaded files and cannot be removed. Mark it as optional instead.");
        query('DELETE FROM document_requirements WHERE id = ?', [$old['id']]);
    }
    $pdo->commit();
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage(), 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    write_log('Saving document failed: ' . $e->getMessage());
    fail('The document could not be saved. Please try again.', 500);
}
json_response(['ok' => true, 'id' => $id]);
