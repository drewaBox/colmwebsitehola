<?php
// Document verification ("AI pre-verification") and the chatbot connection. Everything here is FREE and runs on your own computer:
//   * Document checking = our own checks + Tesseract OCR (free, open source) that READS the text of the uploaded file.
//   * Chatbot = Ollama (free, runs a small language model on your computer).
// No API key, no credit card, nothing is sent to the internet.
//
// How a file is checked (verify_document_upload):
//   1. Picture quality: too small, too dark, blank, too blurry (PHP + GD)            PDF: damaged or password protected
//   2. Tesseract reads the text of the picture (a PDF with text is read with pdftotext, a scanned PDF is turned into a picture first)
//   3. Is there enough readable text?  Does it contain the words of the required document (the "keywords" of the requirement)?
//      Is the student's name on it?
// Results: PASS (= VERIFIED), FAIL (= DECLINED), REVIEW (= NEEDS CORRECTION), UNAVAILABLE (the tools are not installed / failed: try again).
// The registrar still approves or declines every document after the student submitted the request.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/names.php';

// ---- Message for the registrar when the checking tools cannot be used ----
const AI_UNAVAILABLE_MESSAGE = 'Automatic verification unavailable. Manual verification required.';

// ---- Reasons shown to the student (short; the screen adds "Verification declined." or "Verification needs correction.") ----
const REASON_BLURRY = 'The document is too blurry to read.';
const REASON_DARK = 'The document is too dark to read.';
const REASON_LOW_RESOLUTION = 'The image resolution is too low to read.';
const REASON_BLANK = 'The document appears to be blank.';
const REASON_CORRUPT = 'The file could not be opened. It may be damaged.';
const REASON_PROTECTED = 'The PDF is password protected and could not be read.';
const REASON_UNREADABLE = 'The uploaded document could not be read.';
const REASON_WRONG_DOCUMENT = 'The uploaded document does not appear to be the required document.';
const REASON_INFO_MISSING = 'Some important information could not be detected. Please upload a clearer or complete document.';
const REASON_UNCLEAR = 'The text is not clear enough. Please upload a clearer or complete document.';
const REASON_NAME_MISMATCH = "The name on the document does not match the student's registered name.";
const MSG_VERIFIED = 'Document verified successfully. You may continue.';

function ai_result($status, $reason, $extra = []) {
    return array_merge([
        'status' => $status, 'reason' => $reason, 'extracted_text' => null, 'confidence' => null,
        'name_on_document' => null, 'name_match_score' => null, 'auto_declined' => false,
        'model' => 'Tesseract OCR', 'error' => null,
    ], $extra);
}

function ai_unavailable($error) {
    return ai_result('UNAVAILABLE', AI_UNAVAILABLE_MESSAGE, ['error' => $error]);
}

function ai_declined($reason, $detail = null, $extra = []) {
    return ai_result('FAIL', $reason, array_merge(['extracted_text' => $detail, 'auto_declined' => true], $extra));
}

// ====================================================================
// 1. Picture / PDF quality (no tools needed)
// ====================================================================

// Looks at a picture. Returns null when it is fine (or cannot be judged), otherwise ['message' => for the student, 'detail' => technical].
function check_image_quality($path) {
    if (!function_exists('imagecreatefromstring')) return null;               // GD is not installed: skip this check
    $info = @getimagesize($path);
    if ($info === false) return ['message' => REASON_CORRUPT, 'detail' => 'The image could not be opened.'];
    [$width, $height] = $info;
    if (min($width, $height) < 400) return ['message' => REASON_LOW_RESOLUTION, 'detail' => "Resolution is too low ({$width}x{$height} pixels)."];
    if (!image_fits_in_memory($width, $height)) return null;                   // too big to analyse safely: leave it to the reader

    $data = @file_get_contents($path);
    $img = $data === false ? false : @imagecreatefromstring($data);
    unset($data);
    if (!$img) return ['message' => REASON_CORRUPT, 'detail' => 'The image could not be decoded (damaged file?).'];

    // Work on a smaller copy so the check stays fast.
    $maxSide = 1000;
    if (max($width, $height) > $maxSide) {
        $scale = $maxSide / max($width, $height);
        $small = imagescale($img, max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
        imagedestroy($img);
        if (!$small) return null;
        $img = $small;
        $width = imagesx($img);
        $height = imagesy($img);
    }
    if (function_exists('imagepalettetotruecolor')) imagepalettetotruecolor($img);

    // One pass over the picture: brightness (mean and spread) and sharpness (variance of the Laplacian).
    $sum = 0; $sumSquares = 0; $count = 0;
    $lapSum = 0; $lapSquares = 0; $lapCount = 0;
    $previous = $current = $next = null;
    $readRow = function ($y) use ($img, $width) {
        $row = [];
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $row[$x] = (int)((0.299 * (($rgb >> 16) & 255)) + (0.587 * (($rgb >> 8) & 255)) + (0.114 * ($rgb & 255)));
        }
        return $row;
    };
    for ($y = 0; $y < $height; $y++) {
        $next = $readRow($y);
        foreach ($next as $value) { $sum += $value; $sumSquares += $value * $value; $count++; }
        if ($previous !== null) {
            // $previous = row above, $current = middle row, $next = row below
            for ($x = 1; $x < $width - 1; $x++) {
                $lap = $previous[$x] + $next[$x] + $current[$x - 1] + $current[$x + 1] - 4 * $current[$x];
                $lapSum += $lap; $lapSquares += $lap * $lap; $lapCount++;
            }
        }
        $previous = $current;
        $current = $next;
    }
    imagedestroy($img);
    if ($count === 0 || $lapCount === 0) return null;

    $mean = $sum / $count;
    $spread = sqrt(max(0, $sumSquares / $count - $mean * $mean));
    $sharpness = max(0, $lapSquares / $lapCount - pow($lapSum / $lapCount, 2));
    $blurLimit = (float)env('BLUR_THRESHOLD', '30');
    $darkLimit = (float)env('DARK_THRESHOLD', '40');

    if ($mean < $darkLimit) return ['message' => REASON_DARK, 'detail' => sprintf('Too dark (brightness %.0f of 255, minimum %d).', $mean, $darkLimit)];
    if ($spread < 8) return ['message' => REASON_BLANK, 'detail' => 'Blank image (almost no contrast).'];
    if ($sharpness < $blurLimit) return ['message' => REASON_BLURRY, 'detail' => sprintf('Too blurry (sharpness %.0f, minimum %d).', $sharpness, $blurLimit)];
    return null;
}

// A PDF cannot be "looked at" without extra tools, but we can find damaged and locked files.
function check_pdf_basic($path) {
    $size = filesize($path);
    $head = (string)file_get_contents($path, false, null, 0, 1024 * 1024);
    $tail = (string)file_get_contents($path, false, null, max(0, $size - 2048));
    if (strpos($head, '%PDF-') !== 0) return ['message' => REASON_CORRUPT, 'detail' => 'Not a valid PDF header.'];
    if (strpos($tail, '%%EOF') === false) return ['message' => REASON_CORRUPT, 'detail' => 'The PDF is cut off (no end marker).'];
    if (strpos($head, '/Encrypt') !== false) return ['message' => REASON_PROTECTED, 'detail' => 'The PDF is encrypted.'];
    return null;
}

// ====================================================================
// 2. Reading the text with free tools
// ====================================================================

// Runs a program and returns its output as text. Throws RuntimeException when the program is missing or fails.
function run_tool($command, $name) {
    if (!function_exists('exec')) throw new RuntimeException('The PHP function exec() is turned off, so ' . $name . ' cannot be used.');
    $lines = [];
    $code = 0;
    $full = $command . ' 2>&1';
    exec($full, $lines, $code);
    $output = implode("\n", $lines);
    if ($code !== 0) {
        $missing = $code === 127 || stripos($output, 'not recognized') !== false || stripos($output, 'not found') !== false || stripos($output, 'cannot find') !== false;
        throw new RuntimeException($missing ? "$name is not installed (or TESSERACT_PATH / POPPLER_PATH in .env is wrong)."
                                            : "$name failed: " . mb_substr(trim($output), 0, 200));
    }
    return $output;
}

function tesseract_command() {
    $path = env('TESSERACT_PATH', 'tesseract');
    if ($path === 'tesseract' && DIRECTORY_SEPARATOR === '\\') {
        $windowsPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
        if (is_file($windowsPath)) $path = $windowsPath;
    }
    return strpos($path, ' ') !== false || strpos($path, '\\') !== false ? escapeshellarg($path) : $path;
}

// The poppler tools (pdftotext, pdftoppm). POPPLER_PATH is the folder that contains them, or empty if they are in the PATH.
function poppler_command($tool) {
    $folder = rtrim(env('POPPLER_PATH', ''), '/\\');
    return $folder === '' ? $tool : escapeshellarg($folder . DIRECTORY_SEPARATOR . $tool);
}

// Tesseract on a picture. Returns ['text' => ..., 'confidence' => 0-100, 'words' => number of words].
function ocr_image($path) {
    $tsv = run_tool(tesseract_command() . ' ' . escapeshellarg($path) . ' stdout -l eng tsv', 'Tesseract OCR');
    $lines = [];
    $confidence = [];
    $wordCount = 0;
    foreach (explode("\n", $tsv) as $row) {
        $cols = explode("\t", rtrim($row, "\r"));
        // TSV columns: level, page, block, paragraph, line, word, left, top, width, height, conf, text. Level 5 = one word.
        if (count($cols) < 12 || $cols[0] !== '5' || trim($cols[11]) === '') continue;
        $wordCount++;
        $confidence[] = (float)$cols[10];
        $key = $cols[2] . '-' . $cols[3] . '-' . $cols[4];
        $lines[$key] = ($lines[$key] ?? '') . $cols[11] . ' ';
    }
    return ['text' => trim(implode("\n", $lines)), 'confidence' => $confidence ? array_sum($confidence) / count($confidence) : 0, 'words' => $wordCount];
}

// A PDF: first try the text inside it. If there is almost none (a scanned PDF) turn page 1 into a picture and read that.
function ocr_pdf($path) {
    $text = run_tool(poppler_command('pdftotext') . ' -l 2 -layout ' . escapeshellarg($path) . ' -', 'pdftotext (poppler)');
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) >= 20) return ['text' => $text, 'confidence' => 100, 'words' => count($words)];

    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colm_pdf_' . bin2hex(random_bytes(6));
    try {
        run_tool(poppler_command('pdftoppm') . ' -r 150 -f 1 -l 1 -png ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix), 'pdftoppm (poppler)');
        $pictures = glob($prefix . '*.png') ?: [];
        if (!$pictures) throw new RuntimeException('The PDF could not be turned into a picture.');
        return ocr_image($pictures[0]);
    } finally {
        foreach (glob($prefix . '*.png') ?: [] as $file) @unlink($file);
    }
}

// ====================================================================
// 3. Judging the text
// ====================================================================

// All the words of a text in lowercase letters/digits.
function text_words($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    return array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY)));
}

// How many of the requirement's keywords appear in the text? (A small spelling mistake of the OCR is accepted.)
function count_keywords($keywordList, $textWords) {
    $found = 0;
    foreach (array_filter(array_map('trim', explode(',', mb_strtolower((string)$keywordList)))) as $keyword) {
        foreach ($textWords as $word) {
            $same = $word === $keyword
                || ($keyword === 'id' && in_array($word, ['identity', 'identification'], true))
                || (mb_strlen($keyword) >= 5 && levenshtein($word, $keyword) <= 1)
                || (mb_strlen($keyword) >= 4 && strpos($word, $keyword) !== false);
            if ($same) { $found++; break; }
        }
    }
    return $found;
}

// Generic words such as "request" and "form" are not enough to identify which document was submitted.
function document_type_keywords($keywordList) {
    $generic = ['appropriate', 'applicable', 'certificate', 'certification', 'cleared', 'complete', 'completion',
                'document', 'documents', 'form', 'institutional', 'letter', 'of', 'original', 'proof', 'purpose',
                'other', 'record', 'records', 'request', 'required', 'requirements', 'signature', 'student', 'supporting',
                'verification', 'verified'];
    return array_values(array_filter(
        text_words($keywordList),
        fn($keyword) => $keyword !== '' && !in_array($keyword, $generic, true)
    ));
}

// Does the text contain the student's first and last name? Returns ['first' => bool, 'last' => bool, 'score' => 0-100].
function name_in_text($accountName, $textWords) {
    $words = array_values(array_filter(normalize_name($accountName), fn($w) => mb_strlen($w) > 1));
    if (!$words) return ['first' => false, 'last' => false, 'score' => 0];
    $letterWords = array_values(array_filter($textWords, fn($w) => preg_match('/^\p{L}+$/u', $w)));
    $first = best_word_match($words[0], $letterWords);
    $last = best_word_match($words[count($words) - 1], $letterWords);
    return ['first' => $first >= 0.8, 'last' => $last >= 0.8, 'score' => (int)round(100 * ($first + $last) / 2)];
}

// Decides what to do with the text that was read. $read = ['text', 'confidence', 'words'].
function judge_document_text($read, $requirement, $studentName, $documentName = '') {
    $textWords = text_words($read['text']);
    $confidence = (float)$read['confidence'];
    $base = ['extracted_text' => mb_substr($read['text'], 0, 1500), 'confidence' => round($confidence / 100, 3)];

    // Is there enough readable text at all?
    if ($read['words'] < 5) return ai_declined(REASON_UNREADABLE, $base['extracted_text'], $base);
    if ($confidence < 55) return ai_declined(REASON_BLURRY, $base['extracted_text'], $base);

    // Is it the document the requirement asks for?
    $keywords = trim((string)($requirement['keywords'] ?? ''));
    if ($keywords !== '') {
        $total = count(array_filter(array_map('trim', explode(',', $keywords))));
        if (count_keywords($keywords, $textWords) < min(2, $total)) return ai_declined(REASON_WRONG_DOCUMENT, $base['extracted_text'], $base);
        $typeKeywords = document_type_keywords($keywords);
        if (!$typeKeywords) $typeKeywords = document_type_keywords($documentName);
        if ($typeKeywords && count_keywords(implode(',', $typeKeywords), $textWords) === 0) {
            return ai_declined(REASON_WRONG_DOCUMENT, $base['extracted_text'], $base);
        }
    }

    // Is the student's name on it?
    if (!empty($requirement['requires_name'])) {
        $name = name_in_text($studentName, $textWords);
        $base['name_match_score'] = $name['score'];
        if (!$name['first'] && !$name['last']) {
            // A lot of clear text but no trace of the name = another person's document. Little text = maybe just unclear.
            if ($read['words'] >= 25 && $confidence >= 75) return ai_declined(REASON_NAME_MISMATCH, $base['extracted_text'], $base);
            return ai_result('REVIEW', REASON_INFO_MISSING, $base);
        }
        if (!$name['first'] || !$name['last']) return ai_result('REVIEW', REASON_INFO_MISSING, $base);
    }

    if ($confidence < 75) return ai_result('REVIEW', REASON_UNCLEAR, $base);
    return ai_result('PASS', MSG_VERIFIED, $base);
}

// Checks one file on disk. Never throws: always returns a result array (see ai_result).
//   $requirement = name / keywords / requires_name ..., $studentName = the name on the student's account.
function verify_document_upload($path, $mime, $requirement, $documentName, $studentName) {
    try {
        $problem = $mime === 'application/pdf' ? check_pdf_basic($path) : check_image_quality($path);
        if ($problem !== null) return ai_declined($problem['message'], '[Quality check] ' . $problem['detail']);

        $read = $mime === 'application/pdf' ? ocr_pdf($path) : ocr_image($path);
        return judge_document_text($read, $requirement, $studentName, $documentName);
    } catch (RuntimeException $e) {
        write_log('Document check not possible: ' . $e->getMessage());
        return ai_unavailable($e->getMessage());
    } catch (Throwable $e) {
        write_log('verify_document_upload failed: ' . $e->getMessage());
        return ai_unavailable('Unexpected error: ' . $e->getMessage());
    }
}

// What the student sees. Returns [status, title, message]. The status is what the screen and the database use:
//   VERIFIED / DECLINED / NEEDS_CORRECTION / PENDING (= could not be completed, the student can try again)
function student_view_of_result($result) {
    if ($result['status'] === 'PASS') return ['VERIFIED', 'Verified', MSG_VERIFIED];
    if ($result['status'] === 'FAIL') return ['DECLINED', 'Verification declined', 'Verification declined. ' . $result['reason']];
    if ($result['status'] === 'REVIEW') return ['NEEDS_CORRECTION', 'Needs correction', 'Verification needs correction. ' . $result['reason']];
    $error = (string)($result['error'] ?? '');
    if (stripos($error, 'pdftotext') !== false || stripos($error, 'pdftoppm') !== false) {
        $reason = 'The PDF reader (Poppler) is unavailable or could not read this file. Ask the administrator to install or configure Poppler, then try again.';
    } elseif (stripos($error, 'tesseract') !== false) {
        $reason = 'The document text reader (Tesseract OCR) is unavailable. Ask the administrator to install or configure it, then try again.';
    } else {
        $reason = 'The document pre-check could not be completed because a required document-reading tool is unavailable. Ask the administrator to check the setup, then try again.';
    }
    return ['PENDING', 'Pre-check unavailable', $reason];
}

// ====================================================================
// The free chatbot (Ollama)
// ====================================================================

function ollama_url() {
    return rtrim(env('OLLAMA_URL', 'http://127.0.0.1:11434'), '/');
}

function ollama_model() {
    return env('OLLAMA_MODEL', 'llama3.2:3b');
}

// Small helper for the two Ollama requests. Returns [http status (0 = no connection), decoded JSON or null, curl error].
function ollama_call($path, $body, $timeout) {
    if (!function_exists('curl_init')) return [0, null, 'The PHP curl extension is not enabled.'];
    $ch = curl_init(ollama_url() . $path);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout];
    if ($body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        $options[CURLOPT_HTTPHEADER] = ['content-type: application/json'];
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$response === false ? 0 : $http, $response === false ? null : json_decode($response, true), $error];
}

// Is Ollama running, and is the model downloaded?  Returns ['running' => bool, 'model_ready' => bool, 'models' => [...]].
function ollama_status() {
    [$http, $data] = ollama_call('/api/tags', null, 3);
    if ($http !== 200 || !is_array($data)) return ['running' => false, 'model_ready' => false, 'models' => []];
    $models = array_map(fn($m) => (string)($m['name'] ?? ''), $data['models'] ?? []);
    $wanted = ollama_model();
    $ready = in_array($wanted, $models, true) || in_array($wanted . ':latest', $models, true);
    return ['running' => true, 'model_ready' => $ready, 'models' => $models];
}

// Asks the chatbot model. $messages = [['role' => 'user'|'assistant', 'content' => ...], ...]. Never throws. Returns:
//   ['ok' => bool, 'text' => answer, 'code' => no_service | no_model | timeout | bad_response, 'error' => technical text]
function chat_with_model($system, $messages) {
    $timeout = (int)env('OLLAMA_TIMEOUT', '90');
    [$http, $data, $curlError] = ollama_call('/api/chat', [
        'model'    => ollama_model(),
        'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        'stream'   => false,
        'options'  => ['num_ctx' => 4096, 'temperature' => 0.3],
    ], $timeout);

    if ($http === 0) {
        $timedOut = stripos($curlError, 'timed out') !== false;
        write_log('Chatbot: ' . ($curlError !== '' ? $curlError : 'no connection to Ollama'));
        return ['ok' => false, 'text' => '', 'code' => $timedOut ? 'timeout' : 'no_service', 'error' => $timedOut ? 'The model took too long to answer.' : 'Ollama is not running at ' . ollama_url() . '.'];
    }
    if ($http === 404) {
        write_log('Chatbot: model ' . ollama_model() . ' not found in Ollama');
        return ['ok' => false, 'text' => '', 'code' => 'no_model', 'error' => 'The model "' . ollama_model() . '" is not downloaded. Run: ollama pull ' . ollama_model()];
    }
    $text = is_array($data) ? trim((string)($data['message']['content'] ?? '')) : '';
    if ($http !== 200 || $text === '') {
        write_log('Chatbot: Ollama answered HTTP ' . $http);
        return ['ok' => false, 'text' => '', 'code' => 'bad_response', 'error' => 'Ollama gave no usable answer (HTTP ' . $http . ').'];
    }
    return ['ok' => true, 'text' => $text, 'code' => '', 'error' => ''];
}

// ====================================================================
// Saving results, and the registrar's "verify again" button
// ====================================================================

function save_ai_result($requestId, $fileId, $result) {
    query('INSERT INTO ai_verifications (request_id, file_id, status, reason, extracted_text, confidence, name_on_document, name_match_score, auto_declined, model, error_message)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$requestId, $fileId, $result['status'], $result['reason'], $result['extracted_text'], $result['confidence'],
         $result['name_on_document'], $result['name_match_score'], $result['auto_declined'] ? 1 : 0, $result['model'], $result['error']]);
}

// Admin/registrar: run the checks again for the given files (or all current requirement files of the request).
// A file that fails is declined right away, with the reason. Returns the counts and the list of declined files.
function run_ai_for_request($requestId, $fileIds = null) {
    set_time_limit(300);
    $sql = "SELECT f.*, r.name AS requirement_name, r.description, r.ai_hint, r.keywords, r.requires_name, d.name AS document_name, su.full_name AS student_name
            FROM request_files f
            JOIN requests rq ON rq.id = f.request_id
            JOIN users su ON su.id = rq.student_id
            JOIN document_requirements r ON r.id = f.requirement_id
            JOIN request_items i ON i.id = f.item_id
            JOIN documents d ON d.id = i.document_id
            WHERE f.request_id = ? AND f.kind = 'requirement' AND f.is_current = 1";
    $params = [$requestId];
    if ($fileIds !== null) {
        if (!$fileIds) return ['counts' => ['PASS' => 0, 'REVIEW' => 0, 'FAIL' => 0, 'UNAVAILABLE' => 0], 'declined' => []];
        $sql .= ' AND f.id IN (' . implode(',', array_fill(0, count($fileIds), '?')) . ')';
        $params = array_merge($params, $fileIds);
    }

    $counts = ['PASS' => 0, 'REVIEW' => 0, 'FAIL' => 0, 'UNAVAILABLE' => 0];
    $declined = [];
    foreach (fetch_all($sql, $params) as $file) {
        $requirement = ['name' => $file['requirement_name'], 'description' => $file['description'],
                        'keywords' => $file['keywords'], 'requires_name' => $file['requires_name']];
        try {
            $result = verify_document_upload(stored_file_path($file['stored_name']), $file['mime_type'], $requirement, $file['document_name'], $file['student_name']);
        } catch (Throwable $e) {
            $result = ai_unavailable('Could not read the file: ' . $e->getMessage());
        }
        save_ai_result($requestId, $file['id'], $result);
        $counts[$result['status']]++;

        if ($result['auto_declined']) {
            query("UPDATE request_files SET review_status = 'Rejected', review_remarks = ?, reviewed_by = NULL, reviewed_at = NOW() WHERE id = ?",
                [$result['reason'], $file['id']]);
            $declined[] = ['requirement' => $file['requirement_name'], 'document' => $file['document_name'], 'reason' => $result['reason']];
        }
    }
    return ['counts' => $counts, 'declined' => $declined];
}

// Sentence for the registrar after "verify again".
function ai_summary_text($run) {
    $counts = $run['counts'];
    if (array_sum($counts) === 0) return 'No files were checked.';
    $parts = [];
    foreach ($run['declined'] as $item) $parts[] = "Declined - {$item['requirement']}: {$item['reason']}";
    if ($counts['UNAVAILABLE'] > 0) $parts[] = AI_UNAVAILABLE_MESSAGE;
    if (!$parts) return "Check finished: {$counts['PASS']} verified, {$counts['REVIEW']} need correction.";
    return implode(' ', $parts);
}
