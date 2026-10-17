<?php
// Small helper functions used by every API file.

// ---- Settings from the .env file ----
function env($key, $default = '') {
    static $values = null;
    if ($values === null) {
        $values = [];
        $file = __DIR__ . '/../.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                [$name, $value] = explode('=', $line, 2);
                $values[trim($name)] = trim($value, " \t\"'");
            }
        }
    }
    if (isset($values[$key]) && $values[$key] !== '') return $values[$key];
    $fromServer = getenv($key);
    return ($fromServer !== false && $fromServer !== '') ? $fromServer : $default;
}

function is_production() {
    return env('APP_ENV', 'development') === 'production';
}

// ---- JSON answers ----
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Stop and send an error message to the browser.
function fail($message, $status = 400, $extra = []) {
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

// Read a JSON request body (for POST requests that are not file uploads).
function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) fail('Invalid JSON.', 422);
    return $data;
}

function require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) fail('Method not allowed.', 405);
}

// Trim text and cut it to a maximum length.
function clean_text($value, $max = 255) {
    $value = trim((string)$value);
    return mb_substr($value, 0, $max);
}

function write_log($message) {
    $line = date('Y-m-d H:i:s') . ' ' . $message . "\n";
    @file_put_contents(__DIR__ . '/../storage/error.log', $line, FILE_APPEND);
}

// Turn unexpected PHP errors into a clean JSON 500 (no secrets shown in production).
function register_error_handlers() {
    ini_set('display_errors', '0');
    set_exception_handler(function ($e) {
        write_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        // A missing table or column means the database was not updated after new files were copied.
        if ($e instanceof PDOException && in_array((string)$e->getCode(), ['42S02', '42S22'], true)) {
            fail('The database is out of date. Ask the administrator to run: php database/install.php', 500, ['code' => 'DATABASE_OUTDATED']);
        }
        fail(is_production() ? 'Server error. Please try again.' : 'Server error: ' . $e->getMessage(), 500);
    });
    // Fatal PHP errors (for example running out of memory) would otherwise give a blank page.
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            write_log('Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'The server could not process this request. Please try again with a smaller file.']);
        }
    });
}
