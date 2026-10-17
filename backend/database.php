<?php
// One shared database connection (PDO) for the whole project.
require_once __DIR__ . '/helpers.php';

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306')
         . ';dbname=' . env('DB_NAME', 'colm_registrar') . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        write_log('Database connection failed: ' . $e->getMessage());
        fail('Database connection failed. Check the DB_* values in your .env file.', 500);
    }
    return $pdo;
}

// Run a query with ? placeholders and return the statement.
function query($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_one($sql, $params = []) {
    $row = query($sql, $params)->fetch();
    return $row ?: null;
}

function fetch_all($sql, $params = []) {
    return query($sql, $params)->fetchAll();
}
