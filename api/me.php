<?php
// GET  ->  who is logged in? The pages call this on load. Also returns the CSRF token.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
$user = require_login();
json_response(['ok' => true, 'user' => user_for_browser($user), 'csrf' => csrf_token()]);
