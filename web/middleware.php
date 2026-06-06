<?php
// ============================================================
//  web/auth/middleware.php — Web Auth Guard
//
//  Include at the top of every PROTECTED web API file:
//    require_once __DIR__ . '/../auth/middleware.php';
//
//  Checks $_SESSION['web_user'] and optionally enforces role.
//
//  Usage:
//    $webUser = requireWebAuth();           // any web user
//    $webUser = requireWebAuth('admin');    // admin only
//    $webUser = requireWebAuth('lecturer'); // lecturer only
// ============================================================

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../db.php';

/**
 * Validates the web session and optionally checks the role.
 * Kills with 401/403 if not authenticated or wrong role.
 *
 * @param string|null $requiredRole  'admin' | 'lecturer' | null (any)
 * @return array  The stored web user array
 */
function requireWebAuth(?string $requiredRole = null): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Not logged in
    if (empty($_SESSION['web_user'])) {
        respond(false, 'Unauthorized. Please log in to the web portal.', [], 401);
    }

    $webUser = $_SESSION['web_user'];

    // Wrong role
    if ($requiredRole !== null && ($webUser['role'] ?? '') !== $requiredRole) {
        respond(false, 'Access denied. Insufficient permissions.', [], 403);
    }

    return $webUser;
}
