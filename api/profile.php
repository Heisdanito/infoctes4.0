<?php
// ============================================================
//  api/profile/index.php — Student Profile API
//  Rating calculated from per-course attendance percentages
// ============================================================

require_once __DIR__ . '/../db.php';

    
// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, 'Method not allowed.', [], 405);
}

// Get logged-in user from session
$sessionUser = requireAuth();
$studentId = (int) ($sessionUser['student_id'] ?? 0);
$role = $sessionUser['role'] ?? '';

if (!in_array($role, ['student', 'rep'], true) || $studentId === 0) {
    respond(false, 'Access denied.', [], 403);
}

$db = getDB();
$periodId = (int) ($sessionUser['period_id'] ?? getCurrentPeriodId($db));

// Get student's group_id
$groupSql = "SELECT group_id FROM student WHERE student_id = ? LIMIT 1";
$stmt = $db->prepare($groupSql);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$groupResult = $stmt->get_result();
$studentGroup = $groupResult->fetch_assoc();
$stmt->close();
$groupId = $studentGroup['group_id'] ?? 0;

// ============================================================
// 1. GET STUDENT PROFILE WITH FULL DETAILS
// ============================================================
$profileSql = "
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
        sg.group_number,
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

$stmt = $db->prepare($profileSql);
if (!$stmt) {
    respond(false, 'Server error. Please try again.', [], 500);
}

$stmt->bind_param('i', $studentId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    respond(false, 'Student record not found.', [], 404);
}

// ============================================================
// 2. OVERALL ATTENDANCE STATISTICS
// ============================================================
$statsSql = "
    SELECT
        COUNT(DISTINCT ar.record_id) AS total_sessions,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS attended_sessions,
        SUM(CASE WHEN ar.status = 'rejected' THEN 1 ELSE 0 END) AS missed_sessions
    FROM attendance_record ar
    JOIN attendance_session a_s ON a_s.session_id = ar.session_id
    WHERE ar.student_id = ? AND a_s.period_id = ?
";

$stmt = $db->prepare($statsSql);
$stmt->bind_param('ii', $studentId, $periodId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalSessions = (int) ($stats['total_sessions'] ?? 0);
$attended      = (int) ($stats['attended_sessions'] ?? 0);
$missed        = (int) ($stats['missed_sessions'] ?? 0);
$attendancePct = $totalSessions > 0 ? round(($attended / $totalSessions) * 100) : 0;

// ============================================================
// 3. COURSE-BY-COURSE ATTENDANCE WITH RATING BASED ON GROUP SESSIONS
//    Rating = (Student attended / Group total sessions) * 5
// ============================================================
$courseStatsSql = "
    SELECT
        c.course_id,
        c.code AS course_code,
        c.title AS course_name,
        c.credit_hours,
        -- Total sessions the GROUP had for this course (via course rep)
        COALESCE((
            SELECT COUNT(DISTINCT a_s2.session_id)
            FROM attendance_session a_s2
            JOIN course_rep crp2 ON crp2.id = a_s2.course_rep_id
            WHERE crp2.course_id = c.course_id
                AND crp2.group_id = ?
                AND crp2.period_id = ?
                AND a_s2.period_id = ?
                AND a_s2.status != 'closed'
        ), 0) AS group_total_sessions,
        -- Student's attended sessions for this course
        COALESCE((
            SELECT COUNT(DISTINCT ar2.record_id)
            FROM attendance_record ar2
            JOIN attendance_session a_s2 ON a_s2.session_id = ar2.session_id
            JOIN course_rep crp2 ON crp2.id = a_s2.course_rep_id
            WHERE crp2.course_id = c.course_id
                AND ar2.student_id = ?
                AND a_s2.period_id = ?
                AND ar2.status = 'present'
        ), 0) AS student_attended_sessions
    FROM course_registration cr
    JOIN course c ON c.course_id = cr.course_id
    WHERE cr.student_id = ? 
        AND cr.period_id = ?
        AND cr.status = 'registered'
    ORDER BY c.code ASC
";

$stmt = $db->prepare($courseStatsSql);
$stmt->bind_param('iiiiiii', $groupId, $periodId, $periodId, $studentId, $periodId, $studentId, $periodId);
$stmt->execute();
$courseResult = $stmt->get_result();
$courseAttendance = [];
$allCourseRatings = []; // Store each course's rating (1-5 stars)

$colors = ['#3BB4FF', '#BF5FFF', '#FF6B35', '#2DBD72', '#FFB830', '#FF4757', '#00CEC9', '#A29BFE'];

while ($row = $courseResult->fetch_assoc()) {
    $groupTotal = (int) $row['group_total_sessions'];
    $studentAttended = (int) $row['student_attended_sessions'];
    $missed = $groupTotal - $studentAttended;
    
    // Calculate percentage based on group total sessions
    $pct = $groupTotal > 0 ? round(($studentAttended / $groupTotal) * 100) : 0;
    
    // Calculate star rating: (student_attended / group_total_sessions) * 5
    // This gives a 1-5 star rating based on attendance proportion
    if ($groupTotal > 0) {
        $starRatingRaw = ($studentAttended / $groupTotal) * 5;
        $starRating = round($starRatingRaw, 1); // Keep one decimal for more accuracy
        // Ensure rating is between 1 and 5 (if they attended at least 1 session, min 1 star)
        if ($studentAttended > 0 && $starRating < 1) {
            $starRating = 1;
        }
        if ($starRating > 5) $starRating = 5;
        if ($starRating < 0) $starRating = 0;
    } else {
        $starRating = 0;
    }
    
    // Store for overall average calculation
    if ($starRating > 0) {
        $allCourseRatings[] = $starRating;
    }
    
    $status = $pct >= 75 ? 'good' : ($pct >= 50 ? 'warning' : 'critical');
    $colorIndex = abs(crc32($row['course_code'])) % 8;
    
    $courseAttendance[] = [
        'course_code'           => $row['course_code'],
        'course_name'           => $row['course_name'],
        'credit_hours'          => (int) $row['credit_hours'],
        'group_total_sessions'  => $groupTotal,      // Total sessions the group had
        'student_attended'      => $studentAttended, // Student's attended sessions
        'student_missed'        => $missed,          // Student's missed sessions
        'percentage'            => $pct,
        'star_rating'           => $starRating,      // Rating based on proportion
        'status'                => $status,
        'color'                 => $colors[$colorIndex]
    ];
}
$stmt->close();

// ============================================================
// 4. CALCULATE OVERALL RATING FROM COURSE RATINGS
//    Average of all course star ratings (weighted by credit hours optional)
// ============================================================
$totalCourses = count($allCourseRatings);
$overallRating = 0;

if ($totalCourses > 0) {
    $sumRatings = array_sum($allCourseRatings);
    $overallRating = round($sumRatings / $totalCourses, 1);
} else {
    $overallRating = 0;
}

// Calculate rating breakdown (how many 5-star, 4-star, etc. courses)
// For decimal ratings, we need to categorize them
$ratingBreakdown = [
    ['stars' => 5, 'count' => 0],
    ['stars' => 4, 'count' => 0],
    ['stars' => 3, 'count' => 0],
    ['stars' => 2, 'count' => 0],
    ['stars' => 1, 'count' => 0]
];

foreach ($allCourseRatings as $rating) {
    // Round to nearest integer for breakdown categorization
    $roundedRating = round($rating);
    if ($roundedRating >= 5) {
        $ratingBreakdown[0]['count']++;
    } elseif ($roundedRating >= 4) {
        $ratingBreakdown[1]['count']++;
    } elseif ($roundedRating >= 3) {
        $ratingBreakdown[2]['count']++;
    } elseif ($roundedRating >= 2) {
        $ratingBreakdown[3]['count']++;
    } elseif ($roundedRating >= 1) {
        $ratingBreakdown[4]['count']++;
    }
}

// ============================================================
// 5. RECENT ATTENDANCE ACTIVITY (last 15 records)
// ============================================================
$recentSql = "
    SELECT
        ar.record_id,
        ar.method,
        ar.status,
        ar.submitted_at,
        ar.location_valid,
        c.code AS course_code,
        c.title AS course_title,
        a_s.started_at,
        TIMESTAMPDIFF(MINUTE, a_s.started_at, ar.submitted_at) AS minutes_delay
    FROM attendance_record ar
    JOIN attendance_session a_s ON a_s.session_id = ar.session_id
    JOIN course_rep cr ON cr.id = a_s.course_rep_id
    JOIN course c ON c.course_id = cr.course_id
    WHERE ar.student_id = ? AND a_s.period_id = ?
    ORDER BY ar.submitted_at DESC
    LIMIT 15
";

$stmt = $db->prepare($recentSql);
$stmt->bind_param('ii', $studentId, $periodId);
$stmt->execute();
$recentResult = $stmt->get_result();
$recentRecords = [];

while ($row = $recentResult->fetch_assoc()) {
    $submittedAt = new DateTime($row['submitted_at']);
    $now         = new DateTime();
    $diff        = $now->diff($submittedAt);
    
    if ($diff->days == 0) {
        $dateLabel = 'Today';
    } elseif ($diff->days == 1) {
        $dateLabel = 'Yesterday';
    } elseif ($diff->days <= 7) {
        $dateLabel = $submittedAt->format('D, d M');
    } else {
        $dateLabel = $submittedAt->format('d M Y');
    }
    
    $recentRecords[] = [
        'id'            => (string) $row['record_id'],
        'course_code'   => $row['course_code'],
        'course_name'   => $row['course_title'],
        'time'          => $submittedAt->format('h:i A'),
        'date'          => $dateLabel,
        'method'        => strtoupper($row['method']),
        'attended'      => $row['status'] === 'present',
        'location_valid'=> (bool) $row['location_valid'],
        'minutes_delay' => (int) $row['minutes_delay']
    ];
}
$stmt->close();

// ============================================================
// 6. WEEKLY ATTENDANCE TREND (last 6 weeks)
// ============================================================
$weeklyTrendSql = "
    SELECT 
        YEARWEEK(ar.submitted_at, 1) AS week_key,
        DATE_FORMAT(ar.submitted_at, '%d/%m') AS week_label,
        COUNT(*) AS total,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS attended
    FROM attendance_record ar
    JOIN attendance_session a_s ON a_s.session_id = ar.session_id
    WHERE ar.student_id = ? 
        AND a_s.period_id = ?
        AND ar.submitted_at >= DATE_SUB(NOW(), INTERVAL 6 WEEK)
    GROUP BY YEARWEEK(ar.submitted_at, 1)
    ORDER BY week_key ASC
    LIMIT 6
";

$stmt = $db->prepare($weeklyTrendSql);
$stmt->bind_param('ii', $studentId, $periodId);
$stmt->execute();
$trendResult = $stmt->get_result();
$weeklyTrend = [];

while ($row = $trendResult->fetch_assoc()) {
    $total    = (int) $row['total'];
    $attended = (int) $row['attended'];
    $pct      = $total > 0 ? round(($attended / $total) * 100) : 0;
    
    $weeklyTrend[] = [
        'week'      => $row['week_label'],
        'percentage'=> $pct,
        'attended'  => $attended,
        'total'     => $total
    ];
}
$stmt->close();

// ============================================================
// 7. GET COURSE REP STATUS (if user is a rep)
// ============================================================
$isCourseRep = false;
$repCourses = [];

if ($role === 'rep') {
    $repSql = "
        SELECT 
            cr.id,
            c.code AS course_code,
            c.title AS course_name,
            COUNT(DISTINCT a_s.session_id) AS sessions_conducted
        FROM course_rep cr
        JOIN course c ON c.course_id = cr.course_id
        LEFT JOIN attendance_session a_s ON a_s.course_rep_id = cr.id AND a_s.period_id = cr.period_id
        WHERE cr.student_id = ? AND cr.period_id = ?
        GROUP BY cr.id, c.code, c.title
    ";
    
    $stmt = $db->prepare($repSql);
    $stmt->bind_param('ii', $studentId, $periodId);
    $stmt->execute();
    $repResult = $stmt->get_result();
    
    if ($repResult->num_rows > 0) {
        $isCourseRep = true;
        while ($row = $repResult->fetch_assoc()) {
            $repCourses[] = [
                'course_code' => $row['course_code'],
                'course_name' => $row['course_name'],
                'sessions'    => (int) $row['sessions_conducted']
            ];
        }
    }
    $stmt->close();
}

// ============================================================
// 8. PREPARE FINAL RESPONSE
// ============================================================
$userData = [
    'student_id'      => (int) $profile['student_id'],
    'index_number'    => $profile['index_number'],
    'full_name'       => $profile['first_name'] . ' ' . $profile['last_name'],
    'first_name'      => $profile['first_name'],
    'last_name'       => $profile['last_name'],
    'initials'        => strtoupper(substr($profile['first_name'], 0, 1) . substr($profile['last_name'], 0, 1)),
    'email'           => $profile['email'] ?? '',
    'phone'           => $profile['phone'] ?? '',
    'level'           => (int) $profile['level'],
    'programme_name'  => $profile['programme_name'],
    'programme_code'  => $profile['programme_code'],
    'group_number'    => (int) $profile['group_number'],
    'current_period'  => $profile['current_period'],
    'academic_year'   => $profile['academic_year'],
    'semester_number' => (int) $profile['semester_number'],
    'role'            => $role,
    'is_course_rep'   => $isCourseRep
];

$responseData = [
    'user' => $userData,
    'period' => [
        'label'           => $profile['current_period'],
        'academic_year'   => $profile['academic_year'],
        'semester_number' => (int) $profile['semester_number'],
        'start_date'      => $profile['start_date'],
        'end_date'        => $profile['end_date']
    ],
    'stats' => [
        'total_sessions' => $totalSessions,
        'attended'       => $attended,
        'missed'         => $missed,
        'percentage'     => $attendancePct
    ],
    'course_attendance' => $courseAttendance,
    'recent_activity'   => $recentRecords,
    'weekly_trend'      => $weeklyTrend,
    'rating' => [
        'average'        => $overallRating,
        'total_courses'  => $totalCourses,
        'breakdown'      => $ratingBreakdown
    ]
];

// Add course rep info if applicable
if ($isCourseRep && !empty($repCourses)) {
    $responseData['course_rep_info'] = [
        'courses' => $repCourses
    ];
}

respond(true, 'Profile data retrieved successfully.', $responseData);