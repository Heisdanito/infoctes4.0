<?php
// ============================================================
//  web/auth/logout.php — Web Logout
//  Route: POST /web/auth/logout.php
//  Destroys only the web session — mobile session untouched.
// ============================================================
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

// Only clear the web user — leave mobile session intact
unset($_SESSION['web_user']);

// If nothing else left in session, destroy it fully
if (empty($_SESSION)) {
    session_destroy();
}

respond(true, 'Logged out of web portal successfully.');