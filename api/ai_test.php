<?php
// Admin only. POST -> checks that the FREE tools are installed and working:
//   Tesseract (reads the uploaded documents), Poppler (reads and renders PDFs), GD (picture quality) and Ollama (the chatbot).
// Used by the "Check AI tools" button on the Settings page.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/ai.php';
register_error_handlers();
require_method('POST');
require_login(['admin']);
require_csrf();

$tools = [];

// Tesseract
try {
    $out = run_tool(tesseract_command() . ' --version', 'Tesseract OCR');
    $tools[] = ['name' => 'Tesseract OCR (reads documents)', 'ok' => true, 'message' => trim(strtok($out, "\n"))];
} catch (RuntimeException $e) {
    $tools[] = ['name' => 'Tesseract OCR (reads documents)', 'ok' => false, 'message' => $e->getMessage()];
}
// Poppler: text extraction and page rendering (rendering is needed for scanned PDFs).
try {
    run_tool(poppler_command('pdftotext') . ' -v', 'pdftotext (poppler)');
    $tools[] = ['name' => 'pdftotext (reads PDF files)', 'ok' => true, 'message' => 'Installed.'];
} catch (RuntimeException $e) {
    $tools[] = ['name' => 'pdftotext (reads PDF files)', 'ok' => false, 'message' => $e->getMessage() . ' Pictures (JPG/PNG) still work.'];
}
try {
    run_tool(poppler_command('pdftoppm') . ' -v', 'pdftoppm (poppler)');
    $tools[] = ['name' => 'pdftoppm (scanned PDF support)', 'ok' => true, 'message' => 'Installed.'];
} catch (RuntimeException $e) {
    $tools[] = ['name' => 'pdftoppm (scanned PDF support)', 'ok' => false, 'message' => $e->getMessage() . ' Text-based PDFs may still work.'];
}
// GD
$tools[] = ['name' => 'PHP GD (blur / dark check)', 'ok' => function_exists('imagecreatefromstring'),
            'message' => function_exists('imagecreatefromstring') ? 'Enabled.' : 'Turn on extension=gd in php.ini.'];
// Ollama
$status = ollama_status();
if (!$status['running']) {
    $tools[] = ['name' => 'Ollama (chatbot)', 'ok' => false, 'message' => 'Ollama is not running at ' . ollama_url() . '. Install it from ollama.com and start it.'];
} elseif (!$status['model_ready']) {
    $tools[] = ['name' => 'Ollama (chatbot)', 'ok' => false, 'message' => 'Ollama is running, but the model is missing. Run:  ollama pull ' . ollama_model()];
} else {
    $tools[] = ['name' => 'Ollama (chatbot)', 'ok' => true, 'message' => 'Running with the model ' . ollama_model() . '.'];
}

$all = count(array_filter($tools, fn($t) => !$t['ok'])) === 0;
json_response(['ok' => true, 'all_ready' => $all, 'tools' => $tools]);
