<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, 'Method not allowed.', [], 405);
}

// Get student_id from query parameter instead of JWT
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$role = isset($_GET['role']) ? $_GET['role'] : 'student';

if ($studentId === 0) {
    respond(false, 'Student ID is required.', [], 400);
}

// Lecturers have a different dashboard — reject here
if (!in_array($role, ['student', 'rep'], true)) {
    respond(false, 'Access denied. Student token required.', [], 403);
}

$db = getDB();

// ── 2. Student profile + active period ───────────────────────
$profileSql = "
    SELECT
        s.student_id,
        s.index_number,
        s.first_name,
        s.last_name,
        s.email,
        s.phone,
        s.level,
        p.name          AS programme_name,
        p.code          AS programme_code,
        sg.group_number,
        ap.period_id,
        ap.label        AS period_label,
        ap.academic_year,
        ap.semester_number,
        ap.start_date,
        ap.end_date
    FROM student s
    JOIN programme       p  ON p.programme_id = s.programme_id
    JOIN student_group   sg ON sg.group_id    = s.group_id
    JOIN academic_period ap ON ap.period_id   = sg.period_id
                           AND ap.is_active   = 1
    WHERE s.student_id = ?
    LIMIT 1
";

$stmt = $db->prepare($profileSql);
if (!$stmt) respond(false, 'Server error.', [], 500);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    respond(false, 'Student record not found.', [], 404);
}

$periodId = (int) $profile['period_id'];

// ── 3. Attendance stats for current semester ─────────────────
$statsSql = "
    SELECT
        COUNT(ar.record_id)                     AS total_sessions,
        SUM(ar.status = 'present')              AS attended,
        SUM(ar.status = 'rejected')             AS missed
    FROM attendance_record ar
    JOIN attendance_session ases ON ases.session_id = ar.session_id
    WHERE ar.student_id = ?
      AND ases.period_id = ?
";

$stmt = $db->prepare($statsSql);
if (!$stmt) respond(false, 'Server error.', [], 500);
$stmt->bind_param('ii', $studentId, $periodId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalSessions = (int) ($stats['total_sessions'] ?? 0);
$attended      = (int) ($stats['attended']       ?? 0);
$missed        = (int) ($stats['missed']         ?? 0);
$percentage    = $totalSessions > 0
                    ? (int) round(($attended / $totalSessions) * 100)
                    : 0;

// ── 4. Today's timetable for this student's group ────────────
$today        = date('l');  // e.g. "Monday"
$todayScheduleSql = "
    SELECT DISTINCT
        c.code          AS course_code,
        c.title         AS course_title,
        v.name          AS venue,
        t.day_of_week,
        t.start_time,
        t.end_time,
        CONCAT(l.first_name, ' ', l.last_name) AS lecturer
    FROM timetable t
    JOIN course          c   ON c.course_id   = t.course_id
    JOIN venue           v   ON v.venue_id    = t.venue_id
    JOIN lecturer        l   ON l.lecturer_id = t.lecturer_id
    LEFT JOIN timetable_group tg     ON tg.timetable_id = t.timetable_id
    LEFT JOIN timetable_programme tp ON tp.timetable_id = t.timetable_id
    JOIN student s ON s.student_id = ?
    WHERE t.period_id   = ?
      AND t.day_of_week = ?
      AND (
          tg.group_id    = s.group_id
          OR tp.programme_id = s.programme_id
      )
    ORDER BY t.start_time ASC
";

$stmt = $db->prepare($todayScheduleSql);
if (!$stmt) respond(false, 'Server error.', [], 500);
$stmt->bind_param('iis', $studentId, $periodId, $today);
$stmt->execute();
$timetableResult = $stmt->get_result();
$timetable = [];
while ($row = $timetableResult->fetch_assoc()) {
    $timetable[] = [
        'course_code'  => $row['course_code'],
        'course_title' => $row['course_title'],
        'venue'        => $row['venue'],
        'day'          => $row['day_of_week'],
        'start_time'   => $row['start_time'],
        'end_time'     => $row['end_time'],
        'lecturer'     => $row['lecturer'],
    ];
}
$stmt->close();

// ── 5. Recent attendance records (last 10) ───────────────────
$recentSql = "
    SELECT
        ar.record_id,
        ar.method,
        ar.status,
        ar.submitted_at,
        c.code   AS course_code,
        c.title  AS course_title
    FROM attendance_record  ar
    JOIN attendance_session ases ON ases.session_id  = ar.session_id
    JOIN course_rep         cr   ON cr.id            = ases.course_rep_id
    JOIN course             c    ON c.course_id      = cr.course_id
    WHERE ar.student_id  = ?
      AND ases.period_id = ?
    ORDER BY ar.submitted_at DESC
    LIMIT 10
";

$stmt = $db->prepare($recentSql);
if (!$stmt) respond(false, 'Server error.', [], 500);
$stmt->bind_param('ii', $studentId, $periodId);
$stmt->execute();
$recentResult = $stmt->get_result();
$recentRecords = [];

while ($row = $recentResult->fetch_assoc()) {
    $submittedAt = new DateTime($row['submitted_at']);
    $today       = new DateTime('today');
    $yesterday   = new DateTime('yesterday');

    if ($submittedAt >= $today) {
        $dateLabel = 'Today';
    } elseif ($submittedAt >= $yesterday) {
        $dateLabel = 'Yesterday';
    } else {
        $dateLabel = $submittedAt->format('D, d M');
    }

    $recentRecords[] = [
        'id'         => (string) $row['record_id'],
        'courseId'   => $row['course_code'],
        'courseName' => $row['course_title'],
        'time'       => $submittedAt->format('h:i A'),
        'date'       => $dateLabel,
        'method'     => strtoupper($row['method']),
        'attended'   => $row['status'] === 'present',
    ];
}
$stmt->close();

// ── 6. Return everything in one response ─────────────────────
respond(true, 'Dashboard data loaded.', [
    'student' => [
        'student_id'     => (int) $profile['student_id'],
        'index_number'   => $profile['index_number'],
        'full_name'      => $profile['first_name'] . ' ' . $profile['last_name'],
        'first_name'     => $profile['first_name'],
        'last_name'      => $profile['last_name'],
        'initials'       => strtoupper(substr($profile['first_name'], 0, 1) . substr($profile['last_name'], 0, 1)),
        'level'          => (int) $profile['level'],
        'programme_name' => $profile['programme_name'],
        'programme_code' => $profile['programme_code'],
        'group_number'   => (int) $profile['group_number'],
        'role'           => $role,
    ],
    'period' => [
        'label'           => $profile['period_label'],
        'academic_year'   => $profile['academic_year'],
        'semester_number' => (int) $profile['semester_number'],
        'start_date'      => $profile['start_date'],
        'end_date'        => $profile['end_date'],
    ],
    'stats' => [
        'total_sessions' => $totalSessions,
        'attended'       => $attended,
        'missed'         => $missed,
        'percentage'     => $percentage,
    ],
    'timetable' => $timetable,
    'recent'    => $recentRecords,
]);