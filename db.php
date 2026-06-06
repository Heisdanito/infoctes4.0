<?php
// ============================================================
//  config/db.php — Database connection
//  Uses mysqli (NOT PDO) with prepared statements
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'infoctes');
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_SSL_CA', getenv('DB_SSL_CA') ?: __DIR__ . '/aiven-ca.pem');

define('DB_SSL_MODE', getenv('DB_SSL_MODE') ?: 'REQUIRED');

/**
 * Returns a live mysqli connection.
 */
function getDB(): mysqli {
    static $conn = null;

    if ($conn !== null) return $conn;

    $conn = mysqli_init();
    if ($conn === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to initialize MySQL connection.',
        ]);
        exit;
    }

    if (DB_SSL_MODE !== 'DISABLED') {
        if (!file_exists(DB_SSL_CA)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'SSL CA certificate not found: ' . DB_SSL_CA,
            ]);
            exit;
        }

        mysqli_ssl_set($conn, null, null, DB_SSL_CA, null, null);
        mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    }

    if (!mysqli_real_connect($conn, DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . mysqli_connect_error(),
        ]);
        exit;
    }

    if (!$conn->set_charset('utf8mb4')) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to set database charset: ' . $conn->error,
        ]);
        exit;
    }

    return $conn;
}

/**
 * Send a JSON response and exit.
 */
function respond(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

/**
 * Start session and check if user is logged in.
 * Returns the stored session user array.
 */
function requireAuth(): array {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Debug: Log session data (remove in production)
    error_log("Session data: " . print_r($_SESSION, true));

    // Check if user is logged in
    if (empty($_SESSION['user']) || empty($_SESSION['user']['student_id'])) {
        respond(false, 'Unauthorized. Please log in.', [], 401);
    }

    return $_SESSION['user'];
}

/**
 * Set the session user after successful login
 */
function setSessionUser(array $user): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user'] = $user;
    error_log("Session user set: " . print_r($_SESSION['user'], true));
}

/**
 * Check if logged in
 */
function isLoggedIn(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['student_id']);
}

/**
 * Get current period ID
 */
function getCurrentPeriodId(mysqli $db): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user']['period_id']) && $_SESSION['user']['period_id'] > 0) {
        return (int) $_SESSION['user']['period_id'];
    }
    
    $sql = "SELECT period_id FROM academic_period WHERE is_active = 1 LIMIT 1";
    $result = $db->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        return (int) $row['period_id'];
    }
    
    return 0;
}
