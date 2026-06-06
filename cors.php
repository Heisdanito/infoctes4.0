<?php
// ============================================================
//  cors.php — Shared CORS + session start helper
//  Use this file in every API entrypoint before any output.
// ============================================================

// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,  # Set to true if using HTTPS in production
        'httponly' => true,
        'samesite' => 'Lax',  # Allows cross-origin requests while still permitting cookies in many cases
    ]);
    session_start();
}

// Allow any origin dynamically so the API works from any development frontend.
// When using credentials, returning the exact Origin header is required.
header_remove('Access-Control-Allow-Origin');
header_remove('Access-Control-Allow-Methods');
header_remove('Access-Control-Allow-Headers');
header_remove('Access-Control-Allow-Credentials');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header("Access-Control-Allow-Origin: $origin", true);
    header('Vary: Origin', true);
} else {
    header('Access-Control-Allow-Origin: *', true);
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS', true);
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization', true);
header('Access-Control-Allow-Credentials: true', true);
header('Content-Type: application/json', true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
