<?php
// Academic Management (Admin only): school levels, courses, year levels and sections.
//   GET                                  everything: the lists with how many students use each item
//   POST ?action=save    {type, id?, name|label, parent_id?, year_number?}   add (no id) or edit
//   POST ?action=toggle  {type, id}      activate / deactivate
//   POST ?action=remove  {type, id}      delete it if nothing uses it, otherwise only deactivate it (old student records stay valid)
// type = level | course | year_level | section
// Students keep the NAMES as text, so renaming an item that students use also renames it in their records.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/students.php';
register_error_handlers();
$admin = require_login(['admin']);

// One place that knows the table of each type, who its parent is and how to count the students that use an item.
const ACADEMIC_TYPES = ['level', 'course', 'year_level', 'section'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true] + all_academic_lists());
}
require_method('POST');
require_csrf();
$action = $_GET['action'] ?? 'save';
$input = read_json_body();
$type = (string)($input['type'] ?? '');
if (!in_array($type, ACADEMIC_TYPES, true)) fail('Unknown type.', 422);

switch ($action) {
    case 'save':   save_item($type, $input); break;
    case 'toggle': toggle_item($type, (int)($input['id'] ?? 0)); break;
    case 'remove': remove_item($type, (int)($input['id'] ?? 0)); break;
    default: fail('Unknown action.', 404);
}

// ---------- reading ----------

// How many students use each item (students store the names as text).
function all_academic_lists() {
    $levels = []; $courses = []; $years = []; $sections = [];
    $studentsBy = function ($columns) {
        $out = [];
        foreach (fetch_all("SELECT $columns, COUNT(*) AS c FROM students GROUP BY $columns") as $row) $out[] = $row;
        return $out;
    };
    $perLevel = $studentsBy('school_level');
    $perCourse = $studentsBy('school_level, course');
    $perYear = $studentsBy('school_level, course, year_level');
    $perSection = $studentsBy('school_level, course, year_level, section');
    $sum = function ($rows, $match) { $n = 0; foreach ($rows as $r) if ($match($r)) $n += (int)$r['c']; return $n; };

    foreach (load_school_tree(true) as $level) {
        $name = $level['level'];
        $levels[] = ['id' => $level['id'], 'name' => $name, 'is_active' => $level['is_active'], 'children' => count($level['courses']),
                     'students' => $sum($perLevel, fn($r) => $r['school_level'] === $name)];
        foreach ($level['courses'] as $course) {
            $cname = $course['name'];
            $courses[] = ['id' => $course['id'], 'name' => $cname, 'level_id' => $level['id'], 'level' => $name, 'is_active' => $course['is_active'],
                          'children' => count($course['years']), 'students' => $sum($perCourse, fn($r) => $r['school_level'] === $name && $r['course'] === $cname)];
            foreach ($course['years'] as $year) {
                $years[] = ['id' => $year['id'], 'name' => $year['label'], 'year' => $year['year'], 'course_id' => $course['id'], 'course' => $cname, 'level' => $name,
                            'is_active' => $year['is_active'], 'children' => count($year['sections']),
                            'students' => $sum($perYear, fn($r) => $r['school_level'] === $name && $r['course'] === $cname && (int)$r['year_level'] === $year['year'])];
                foreach ($year['sections'] as $section) {
                    $sections[] = ['id' => $section['id'], 'name' => $section['name'], 'year_level_id' => $year['id'], 'year' => $year['label'], 'course' => $cname, 'level' => $name,
                                   'is_active' => $section['is_active'], 'children' => 0,
                                   'students' => $sum($perSection, fn($r) => $r['school_level'] === $name && $r['course'] === $cname && (int)$r['year_level'] === $year['year'] && $r['section'] === $section['name'])];
                }
            }
        }
    }
    return ['levels' => $levels, 'courses' => $courses, 'year_levels' => $years, 'sections' => $sections];
}

// The item with its parents' names, or null.
function find_item($type, $id) {
    switch ($type) {
        case 'level':
            return fetch_one('SELECT id, name, is_active, NULL AS level, NULL AS course, NULL AS year_number FROM school_levels WHERE id = ?', [$id]);
        case 'course':
            return fetch_one('SELECT c.id, c.name, c.is_active, l.name AS level, NULL AS course, NULL AS year_number FROM school_courses c JOIN school_levels l ON l.id = c.level_id WHERE c.id = ?', [$id]);
        case 'year_level':
            return fetch_one('SELECT y.id, y.label AS name, y.is_active, l.name AS level, c.name AS course, y.year_number FROM school_year_levels y
                              JOIN school_courses c ON c.id = y.course_id JOIN school_levels l ON l.id = c.level_id WHERE y.id = ?', [$id]);
        default:
            return fetch_one('SELECT s.id, s.name, s.is_active, l.name AS level, c.name AS course, y.year_number FROM school_sections s
                              JOIN school_year_levels y ON y.id = s.year_level_id JOIN school_courses c ON c.id = y.course_id JOIN school_levels l ON l.id = c.level_id WHERE s.id = ?', [$id]);
    }
}

// How many students use this item, and how many items depend on it (a level with courses cannot be deleted).
function usage_of($type, $item) {
    switch ($type) {
        case 'level':
            $students = fetch_one('SELECT COUNT(*) AS c FROM students WHERE school_level = ?', [$item['name']])['c'];
            $children = fetch_one('SELECT COUNT(*) AS c FROM school_courses WHERE level_id = ?', [$item['id']])['c']; break;
        case 'course':
            $students = fetch_one('SELECT COUNT(*) AS c FROM students WHERE school_level = ? AND course = ?', [$item['level'], $item['name']])['c'];
            $children = fetch_one('SELECT COUNT(*) AS c FROM school_year_levels WHERE course_id = ?', [$item['id']])['c']; break;
        case 'year_level':
            $students = fetch_one('SELECT COUNT(*) AS c FROM students WHERE school_level = ? AND course = ? AND year_level = ?', [$item['level'], $item['course'], $item['year_number']])['c'];
            $children = fetch_one('SELECT COUNT(*) AS c FROM school_sections WHERE year_level_id = ?', [$item['id']])['c']; break;
        default:
            $students = fetch_one('SELECT COUNT(*) AS c FROM students WHERE school_level = ? AND course = ? AND year_level = ? AND section = ?',
                                  [$item['level'], $item['course'], $item['year_number'], $item['name']])['c'];
            $children = 0;
    }
    return ['students' => (int)$students, 'children' => (int)$children];
}

// ---------- add / edit ----------

function save_item($type, $input) {
    $id = (int)($input['id'] ?? 0);
    $maxLength = ['level' => 40, 'course' => 80, 'year_level' => 40, 'section' => 20][$type];
    $name = clean_text($input['name'] ?? '', 500);
    if ($name === '') fail('Please enter a name.', 422);
    if (mb_strlen($name) > $maxLength) fail("The name is too long (maximum $maxLength characters).", 422);
    $table = ['level' => 'school_levels', 'course' => 'school_courses', 'year_level' => 'school_year_levels', 'section' => 'school_sections'][$type];
    $nameColumn = $type === 'year_level' ? 'label' : 'name';

    if ($id > 0) {                                          // ----- edit (rename) -----
        $old = find_item($type, $id);
        if (!$old) fail('Item not found.', 404);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            query("UPDATE $table SET $nameColumn = ? WHERE id = ?", [$name, $id]);
            // Students keep the name as text: rename it in their records too, so nobody is left with an old name.
            if ($type === 'level') query('UPDATE students SET school_level = ? WHERE school_level = ?', [$name, $old['name']]);
            if ($type === 'course') query('UPDATE students SET course = ? WHERE school_level = ? AND course = ?', [$name, $old['level'], $old['name']]);
            if ($type === 'section') query('UPDATE students SET section = ? WHERE school_level = ? AND course = ? AND year_level = ? AND section = ?',
                                           [$name, $old['level'], $old['course'], $old['year_number'], $old['name']]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') fail(duplicate_message($type), 422);
            throw $e;
        }
        json_response(['ok' => true]);
    }

    // ----- add -----
    $parent = (int)($input['parent_id'] ?? 0);
    $createdId = null;
    try {
        switch ($type) {
            case 'level':
                $order = (int)fetch_one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM school_levels')['n'];
                query('INSERT INTO school_levels (name, sort_order) VALUES (?, ?)', [$name, $order]);
                break;
            case 'course':
                $parentLevel = find_item('level', $parent);
                if (!$parentLevel) fail('Choose the school level of the course.', 422);
                $order = (int)fetch_one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM school_courses WHERE level_id = ?', [$parent])['n'];
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    query('INSERT INTO school_courses (level_id, name, sort_order) VALUES (?, ?, ?)', [$parent, $name, $order]);
                    $courseId = (int)$pdo->lastInsertId();
                    $createdId = $courseId;
                    if ($parentLevel['name'] === 'COLLEGE') ensure_college_course_structure($pdo, $courseId);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                break;
            case 'year_level':
                if (!find_item('course', $parent)) fail('Choose the course of the year level.', 422);
                $number = $input['year_number'] ?? null;
                if (!is_numeric($number) || (int)$number < 1 || (int)$number > 12 || (string)(int)$number !== (string)$number) fail('The year number must be a whole number from 1 to 12 (for example 1 for First Year, 11 for Grade 11).', 422);
                query('INSERT INTO school_year_levels (course_id, year_number, label) VALUES (?, ?, ?)', [$parent, (int)$number, $name]);
                break;
            default:
                if (!find_item('year_level', $parent)) fail('Choose the year level of the section.', 422);
                query('INSERT INTO school_sections (year_level_id, name) VALUES (?, ?)', [$parent, $name]);
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail(duplicate_message($type), 422);
        throw $e;
    }
    json_response(['ok' => true, 'id' => $createdId ?? (int)db()->lastInsertId()], 201);
}

function duplicate_message($type) {
    return ['level' => 'This school level already exists.', 'course' => 'A course with this name already exists.',
            'year_level' => 'This year number already exists for the course.', 'section' => 'This section already exists for the year level.'][$type];
}

// ---------- deactivate / remove ----------

function toggle_item($type, $id) {
    $item = find_item($type, $id);
    if (!$item) fail('Item not found.', 404);
    $table = ['level' => 'school_levels', 'course' => 'school_courses', 'year_level' => 'school_year_levels', 'section' => 'school_sections'][$type];
    query("UPDATE $table SET is_active = ? WHERE id = ?", [$item['is_active'] ? 0 : 1, $id]);
    json_response(['ok' => true, 'is_active' => !$item['is_active']]);
}

function remove_item($type, $id) {
    $item = find_item($type, $id);
    if (!$item) fail('Item not found.', 404);
    $table = ['level' => 'school_levels', 'course' => 'school_courses', 'year_level' => 'school_year_levels', 'section' => 'school_sections'][$type];
    $usage = usage_of($type, $item);
    if ($usage['students'] > 0 || $usage['children'] > 0) {
        // Used by existing records: never delete it, only make it inactive.
        query("UPDATE $table SET is_active = 0 WHERE id = ?", [$id]);
        json_response(['ok' => true, 'result' => 'deactivated', 'message' => 'This item is currently being used by existing records. It was deactivated instead of permanently deleted.']);
    }
    query("DELETE FROM $table WHERE id = ?", [$id]);
    json_response(['ok' => true, 'result' => 'deleted', 'message' => 'Removed.']);
}
