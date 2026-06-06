<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================================
//  api/student/dashboard.php
//  Protected endpoint - requires valid JWT token
//  Returns all data needed for StudentDashboard UI
// ============================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_helper.php';

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, 'Method not allowed.', [], 405);
}

// Verify JWT token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($authHeader)) {
    respond(false, 'Authorization token required.', [], 401);
}

// Extract Bearer token
if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    respond(false, 'Invalid authorization format. Use Bearer token.', [], 401);
}

$token = $matches[1];

// Verify and decode the JWT
$payload = verifyJWT($token);
if (!$payload) {
    respond(false, 'Invalid or expired token. Please login again.', [], 401);
}

// Check if user role is student or rep
if (!in_array($payload['role'], ['student', 'rep'])) {
    respond(false, 'Access denied. Student privileges required.', [], 403);
}

$studentId = $payload['student_id'];
$db = getDB();

// ────────────────────────────────────────────────────────────
// 1. Get student basic information with current period
// ────────────────────────────────────────────────────────────
$studentSql = "
    SELECT
        s.student_id,
        s.index_number,
        s.first_name,
        s.last_name,
        s.email,
        s.phone,
        s.level,
        p.programme_id,
        p.name AS programme_name,
        p.code AS programme_code,
        sg.group_id,
        sg.group_number,
        ap.period_id,
        ap.label AS current_period,
        ap.academic_year,
        ap.semester_number,
        ap.start_date,
        ap.end_date
    FROM student s
    JOIN programme p ON p.programme_id = s.programme_id
    JOIN student_group sg ON sg.group_id = s.group_id
    JOIN academic_period ap ON ap.period_id = sg.period_id AND ap.is_active = 1
    WHERE s.student_id = ?
    LIMIT 1
";

$stmt = $db->prepare($studentSql);
if (!$stmt) {
    respond(false, 'Server error. Please try again.', [], 500);
}

$stmt->bind_param('i', $studentId);
$stmt->execute();
$studentResult = $stmt->get_result();
$student = $studentResult->fetch_assoc();
$stmt->close();

if (!$student) {
    respond(false, 'Student record not found.', [], 404);
}

// ────────────────────────────────────────────────────────────
// 2. Get attendance statistics for current period
// ────────────────────────────────────────────────────────────
$statsSql = "
    SELECT
        COUNT(DISTINCT ar.session_id) AS total_sessions,
        SUM(CASE WHEN ar.status = 'present' AND ar.location_valid = 1 THEN 1 ELSE 0 END) AS attended_sessions,
        SUM(CASE WHEN ar.status = 'rejected' OR ar.location_valid = 0 THEN 1 ELSE 0 END) AS missed_sessions,
        ROUND(
            (SUM(CASE WHEN ar.status = 'present' AND ar.location_valid = 1 THEN 1 ELSE 0 END) * 100.0) / 
            NULLIF(COUNT(DISTINCT ar.session_id), 0), 
            1
        ) AS attendance_percentage
    FROM attendance_record ar
    JOIN attendance_session a_s ON a_s.session_id = ar.session_id
    WHERE ar.student_id = ? AND a_s.period_id = ?
";

$stmt = $db->prepare($statsSql);
$stmt->bind_param('ii', $studentId, $student['period_id']);
$stmt->execute();
$statsResult = $stmt->get_result();
$stats = $statsResult->fetch_assoc();
$stmt->close();

$totalSessions = (int)($stats['total_sessions'] ?? 0);
$attended = (int)($stats['attended_sessions'] ?? 0);
$missed = (int)($stats['missed_sessions'] ?? 0);
$attendancePct = $totalSessions > 0 ? round(($attended / $totalSessions) * 100) : 0;

// ────────────────────────────────────────────────────────────
// 3. Get recent attendance records (last 10)
// ────────────────────────────────────────────────────────────
$recentSql = "
    SELECT
        ar.record_id,
        c.course_id,
        c.code AS course_code,
        c.title AS course_name,
        ar.submitted_at,
        ar.method,
        ar.status,
        ar.location_valid,
        a_s.started_at,
        CASE 
            WHEN ar.status = 'present' AND ar.location_valid = 1 THEN 'present'
            ELSE 'absent'
        END AS attendance_status
    FROM attendance_record ar
    JOIN attendance_session a_s ON a_s.session_id = ar.session_id
    JOIN course_rep cr ON cr.id = a_s.course_rep_id
    JOIN course c ON c.course_id = cr.course_id
    WHERE ar.student_id = ? AND a_s.period_id = ?
    ORDER BY ar.submitted_at DESC
    LIMIT 10
";

$stmt = $db->prepare($recentSql);
$stmt->bind_param('ii', $studentId, $student['period_id']);
$stmt->execute();
$recentResult = $stmt->get_result();
$recentRecords = [];

while ($row = $recentResult->fetch_assoc()) {
    $submittedDate = new DateTime($row['submitted_at']);
    $now = new DateTime();
    $diff = $submittedDate->diff($now);
    
    $dateLabel = 'Today';
    if ($diff->days == 1) {
        $dateLabel = 'Yesterday';
    } elseif ($diff->days > 1 && $diff->days <= 7) {
        $dateLabel = $submittedDate->format('D, d M');
    } elseif ($diff->days > 7) {
        $dateLabel = $submittedDate->format('d M Y');
    }
    
    $recentRecords[] = [
        'id' => (string)$row['record_id'],
        'courseId' => $row['course_code'],
        'courseName' => $row['course_name'],
        'time' => date('h:i A', strtotime($row['submitted_at'])),
        'date' => $dateLabel,
        'method' => strtoupper($row['method']),
        'attended' => $row['attendance_status'] === 'present'
    ];
}

$stmt->close();

// ────────────────────────────────────────────────────────────
// 4. Get course-wise attendance breakdown
// ────────────────────────────────────────────────────────────
$courseStatsSql = "
    SELECT
        c.course_id,
        c.code AS course_code,
        c.title AS course_name,
        COUNT(DISTINCT a_s.session_id) AS total_sessions,
        SUM(CASE WHEN ar.status = 'present' AND ar.location_valid = 1 THEN 1 ELSE 0 END) AS attended_sessions
    FROM course_registration cr
    JOIN course c ON c.course_id = cr.course_id
    LEFT JOIN course_rep crp ON crp.course_id = c.course_id AND crp.period_id = ?
    LEFT JOIN attendance_session a_s ON a_s.course_rep_id = crp.id
    LEFT JOIN attendance_record ar ON ar.session_id = a_s.session_id AND ar.student_id = ?
    WHERE cr.student_id = ? AND cr.period_id = ? AND cr.status = 'registered'
    GROUP BY c.course_id, c.code, c.title
    HAVING total_sessions > 0
    ORDER BY c.code
";

$stmt = $db->prepare($courseStatsSql);
$stmt->bind_param('iiii', $student['period_id'], $studentId, $studentId, $student['period_id']);
$stmt->execute();
$courseStatsResult = $stmt->get_result();
$courseAttendance = [];

while ($row = $courseStatsResult->fetch_assoc()) {
    $percentage = $row['total_sessions'] > 0 
        ? round(($row['attended_sessions'] / $row['total_sessions']) * 100) 
        : 0;
    
    $courseAttendance[] = [
        'course_code' => $row['course_code'],
        'course_name' => $row['course_name'],
        'total_sessions' => (int)$row['total_sessions'],
        'attended' => (int)$row['attended_sessions'],
        'percentage' => $percentage,
        'status' => $percentage >= 75 ? 'good' : ($percentage >= 50 ? 'warning' : 'critical')
    ];
}

$stmt->close();

// ────────────────────────────────────────────────────────────
// 5. Get upcoming timetable for today/week
// ────────────────────────────────────────────────────────────
$today = date('l'); // Monday, Tuesday, etc.
$timetableSql = "
    SELECT
        t.timetable_id,
        c.code AS course_code,
        c.title AS course_name,
        CONCAT(l.first_name, ' ', l.last_name) AS lecturer_name,
        v.name AS venue,
        t.start_time,
        t.end_time,
        t.day_of_week
    FROM timetable t
    JOIN course c ON c.course_id = t.course_id
    JOIN lecturer l ON l.lecturer_id = t.lecturer_id
    JOIN venue v ON v.venue_id = t.venue_id
    JOIN timetable_group tg ON tg.timetable_id = t.timetable_id
    WHERE tg.group_id = ? 
        AND t.period_id = ?
        AND t.day_of_week = ?
    ORDER BY t.start_time
    LIMIT 5
";

$stmt = $db->prepare($timetableSql);
$stmt->bind_param('iis', $student['group_id'], $student['period_id'], $today);
$stmt->execute();
$timetableResult = $stmt->get_result();
$todayClasses = [];

while ($row = $timetableResult->fetch_assoc()) {
    $todayClasses[] = [
        'course_code' => $row['course_code'],
        'course_name' => $row['course_name'],
        'lecturer' => $row['lecturer_name'],
        'venue' => $row['venue'],
        'start_time' => date('h:i A', strtotime($row['start_time'])),
        'end_time' => date('h:i A', strtotime($row['end_time']))
    ];
}
$stmt->close();

// ────────────────────────────────────────────────────────────
// 6. Prepare final response
// ────────────────────────────────────────────────────────────
$userData = [
    'student_id' => (int)$student['student_id'],
    'index_number' => $student['index_number'],
    'full_name' => $student['first_name'] . ' ' . $student['last_name'],
    'first_name' => $student['first_name'],
    'last_name' => $student['last_name'],
    'email' => $student['email'],
    'phone' => $student['phone'],
    'level' => (int)$student['level'],
    'programme_name' => $student['programme_name'],
    'programme_code' => $student['programme_code'],
    'group_number' => (int)$student['group_number'],
    'current_period' => $student['current_period'],
    'semester_number' => (int)$student['semester_number'],
    'role' => $payload['role']
];

$dashboardData = [
    'user' => $userData,
    'statistics' => [
        'total_sessions' => $totalSessions,
        'attended' => $attended,
        'missed' => $missed,
        'attendance_percentage' => $attendancePct
    ],
    'recent_attendance' => $recentRecords,
    'course_attendance' => $courseAttendance,
    'today_classes' => $todayClasses,
    'week_days' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
    'target_percentage' => 75
];

respond(true, 'Dashboard data retrieved successfully.', $dashboardData);