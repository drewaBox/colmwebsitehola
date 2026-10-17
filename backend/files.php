<?php
// Checking, saving and sending uploaded files.
require_once __DIR__ . '/helpers.php';

const ALLOWED_TYPES = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',     // only used for profile photos; documents list their own allowed types
];

// Friendly list for messages: "PDF, JPG, JPEG or PNG".
function types_for_message($allowedList) {
    $types = array_values(array_intersect(array_map('trim', explode(',', strtolower($allowedList))), array_keys(ALLOWED_TYPES)));
    $types = array_map('strtoupper', $types);
    if (count($types) > 1) { $last = array_pop($types); return implode(', ', $types) . ' or ' . $last; }
    return $types ? $types[0] : 'PDF';
}

// How much memory PHP may use, in bytes (-1 = no limit).
function memory_limit_bytes() {
    $value = trim((string)ini_get('memory_limit'));
    if ($value === '' || $value === '-1') return -1;
    $number = (float)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $number *= 1024;
        case 'm': $number *= 1024;
        case 'k': $number *= 1024;
    }
    return (int)$number;
}

// Can GD safely open a picture of this size? A huge picture would crash PHP with a blank page.
function image_fits_in_memory($width, $height) {
    $limit = memory_limit_bytes();
    if ($limit < 0) return true;
    $needed = $width * $height * 5 + 8 * 1024 * 1024;
    return $needed < ($limit - memory_get_usage(true)) * 0.8;
}

function upload_dir() {
    $dir = env('UPLOAD_DIR', '');
    if ($dir === '') $dir = __DIR__ . '/../storage/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload folder is missing and could not be created.');
    }
    return rtrim($dir, '/\\');
}

// Check one file. Returns ['ext', 'mime', 'size', 'sha256'] or throws RuntimeException with a clear message.
// We check the real file content, not only the file name.
function validate_file($path, $originalName, $maxMb, $allowedList) {
    if (!is_file($path)) throw new RuntimeException('The file could not be read.');
    $size = filesize($path);
    if ($size === 0) throw new RuntimeException('The file is empty.');
    if ($size > $maxMb * 1024 * 1024) throw new RuntimeException("The file is too large (maximum $maxMb MB).");

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = array_intersect(array_map('trim', explode(',', strtolower($allowedList))), array_keys(ALLOWED_TYPES));
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Please upload a supported ' . types_for_message($allowedList) . ' file.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($mime !== ALLOWED_TYPES[$ext]) throw new RuntimeException('The file content does not match its extension.');
    if ($mime === 'application/pdf') {
        $start = file_get_contents($path, false, null, 0, 5);
        if ($start !== '%PDF-') throw new RuntimeException('The PDF file is not valid.');
    } elseif (@getimagesize($path) === false) {
        throw new RuntimeException('The image file is not valid.');
    }

    return ['ext' => $ext, 'mime' => $mime, 'size' => $size, 'sha256' => hash_file('sha256', $path)];
}

// Extra checks for profile photos: a real, readable picture of a sensible size. Throws RuntimeException.
function validate_photo($path) {
    $size = @getimagesize($path);
    if ($size === false) throw new RuntimeException('The image is corrupted or could not be read.');
    [$width, $height] = $size;
    if ($width < 200 || $height < 200) throw new RuntimeException('The photo is too small. Use a picture of at least 200 x 200 pixels.');
    if ($width > 8000 || $height > 8000) throw new RuntimeException('The photo is too large. Use a picture of at most 8000 x 8000 pixels.');
    // Really decode it when we can: a damaged file often has a good header but a broken body.
    $canDecode = function_exists('imagecreatefromstring') && ($size['mime'] !== 'image/webp' || function_exists('imagecreatefromwebp'));
    if ($canDecode && image_fits_in_memory($width, $height)) {
        $image = @imagecreatefromstring((string)file_get_contents($path));
        if (!$image) throw new RuntimeException('The image is corrupted or could not be read.');
        imagedestroy($image);
    }
}

// Save the file with a random name. Returns the stored name (relative to the upload folder).
function store_file($tmpPath, $ext) {
    $sub = date('Y/m');
    $dir = upload_dir() . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the upload folder.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = $dir . '/' . $name;
    $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $target) : copy($tmpPath, $target);
    if (!$moved) throw new RuntimeException('The file could not be saved.');
    chmod($target, 0640);
    return $sub . '/' . $name;
}

function stored_file_path($storedName) {
    // The stored name is created by us, but we still refuse anything that tries to leave the folder.
    if (strpos($storedName, '..') !== false) throw new RuntimeException('Invalid file path.');
    return upload_dir() . '/' . $storedName;
}

function delete_stored_file($storedName) {
    $path = stored_file_path($storedName);
    if (is_file($path)) @unlink($path);
}

// Send a stored file to the browser. $inline = show in the browser (preview), otherwise download.
function send_stored_file($row, $inline) {
    $path = stored_file_path($row['stored_name']);
    if (!is_file($path)) fail('The file is missing on the server.', 404);
    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $row['original_name']);
    header('Content-Type: ' . $row['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    // Pictures and other files get a strict policy. A PDF is left alone: the browser's PDF viewer does not work with "sandbox".
    if ($row['mime_type'] !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// PHP puts uploaded files in $_FILES. This returns one of them or null if nothing was chosen.
function get_uploaded($fieldName) {
    $f = $_FILES[$fieldName] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('The file is larger than the server allows.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed. Please try again.');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Invalid upload.');
    return $f;
}
