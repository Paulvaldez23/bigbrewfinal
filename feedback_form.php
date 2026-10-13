<?php
require_once 'db_con.php';
require_once 'includes/brand.php';

$message = '';
$messageType = '';
$branch = trim($_GET['branch'] ?? $_POST['branch'] ?? '');

$validBranches = ['Branch 1', 'Branch 2', 'Branch 3', 'Head Office'];
if ($branch !== '' && !in_array($branch, $validBranches, true)) {
    // If unknown branch, default to Branch 1
    $branch = 'Branch 1';
}

// ==================================================================
// AUTO-MIGRATE: Schema upgrades for Feedback & Questions
// ==================================================================
if ($conn) {
    $schemaCols = [
        'on_shift_employee_id' => "ADD COLUMN on_shift_employee_id INT NULL COMMENT 'Primary employee on-shift' AFTER employee_id",
        'on_shift_staff_list'  => "ADD COLUMN on_shift_staff_list VARCHAR(255) NULL COMMENT 'Names of all staff on-shift' AFTER on_shift_employee_id",
        'on_shift_row'         => "ADD COLUMN on_shift_row VARCHAR(50) NULL COMMENT 'Shift row (Morning/Afternoon/Night)' AFTER on_shift_staff_list",
        'on_shift_week_start'  => "ADD COLUMN on_shift_week_start DATE NULL COMMENT 'Week start date of shift' AFTER on_shift_row",
        'submitted_at_local'   => "ADD COLUMN submitted_at_local DATETIME NULL COMMENT 'Asia/Manila local time capture' AFTER submitted_at",
        'q1_rating'            => "ADD COLUMN q1_rating TINYINT NULL COMMENT 'Question 1 Drink Quality rating (1-5)' AFTER rating",
        'q2_rating'            => "ADD COLUMN q2_rating TINYINT NULL COMMENT 'Question 2 Staff Service rating (1-5)' AFTER q1_rating",
        'q3_rating'            => "ADD COLUMN q3_rating TINYINT NULL COMMENT 'Question 3 Cleanliness rating (1-5)' AFTER q2_rating",
    ];
    try {
        $db = $conn->query("SELECT DATABASE()")->fetchColumn();
        foreach ($schemaCols as $colName => $alterSql) {
            $exists = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = " . $conn->quote($db) . " AND TABLE_NAME = 'feedback' AND COLUMN_NAME = " . $conn->quote($colName))->fetchColumn();
            if (!$exists) {
                try { $conn->exec("ALTER TABLE feedback $alterSql"); } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) {}

    // Setup the 3 customer questions in feedback_questions table
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS feedback_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question_text VARCHAR(255) NOT NULL,
            question_type VARCHAR(20) DEFAULT 'rating',
            sort_order INT DEFAULT 0,
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Deactivate legacy / duplicate internal employee survey questions
        $conn->exec("UPDATE feedback_questions SET is_active = 0 
            WHERE question_text LIKE '%working area%' 
               OR question_text LIKE '%manager support%' 
               OR question_text LIKE '%training%' 
               OR question_text LIKE '%additional comments%'");

        // Ensure exactly 3 active customer questions exist
        $qCount = (int)$conn->query("SELECT COUNT(*) FROM feedback_questions WHERE is_active = 1")->fetchColumn();
        if ($qCount < 3) {
            $checkQ = $conn->query("SELECT question_text FROM feedback_questions")->fetchAll(PDO::FETCH_COLUMN);
            $targetQuestions = [
                ['text' => 'Drink Quality & Taste (Sarap at Kalidad ng Inumin)', 'sort' => 1],
                ['text' => 'Staff Service & Speed (Bilis at Pakikitungo ng Staff)', 'sort' => 2],
                ['text' => 'Store Cleanliness & Atmosphere (Kalinisan ng Tindahan)', 'sort' => 3],
            ];
            foreach ($targetQuestions as $tq) {
                $found = false;
                foreach ($checkQ as $cq) {
                    if (stripos($cq, substr($tq['text'], 0, 15)) !== false) {
                        $found = true;
                        $conn->prepare("UPDATE feedback_questions SET is_active = 1, sort_order = ? WHERE question_text = ?")->execute([$tq['sort'], $cq]);
                        break;
                    }
                }
                if (!$found) {
                    $ins = $conn->prepare("INSERT INTO feedback_questions (question_text, question_type, sort_order, is_active) VALUES (?, 'rating', ?, 1)");
                    $ins->execute([$tq['text'], $tq['sort']]);
                }
            }
        }
    } catch (Exception $e) {}
}

// Helper function to resolve on-duty staff at a given time and branch
function resolve_on_duty_staff($conn, $branchName) {
    if (!$conn) return ['emp_id' => null, 'staff_list' => '', 'shift_row' => '', 'week_start' => ''];
    try {
        $nowDow = (int)date('N'); // 1=Mon ... 7=Sun
        $nowWeekStart = date('Y-m-d', strtotime('-' . ($nowDow - 1) . ' days', strtotime(date('Y-m-d'))));
        $nowHM = (int)date('Hi'); // e.g. 2243 (10:43 PM)

        // Known default shift hours
        $shiftTimes = [
            'morning'   => ['s' => 600,  'e' => 1400, 'label' => 'Morning Shift'],
            'opening'   => ['s' => 600,  'e' => 1400, 'label' => 'Morning Shift'],
            'afternoon' => ['s' => 1400, 'e' => 2200, 'label' => 'Afternoon Shift'],
            'mid'       => ['s' => 1400, 'e' => 2200, 'label' => 'Afternoon Shift'],
            'night'     => ['s' => 2200, 'e' => 600,  'label' => 'Night Shift'],
            'closing'   => ['s' => 2200, 'e' => 600,  'label' => 'Night Shift'],
        ];

        // Query shifts table to get exact configured hours
        try {
            $stRows = $conn->query("SELECT shift_name, start_time, end_time FROM shifts")->fetchAll();
            foreach ($stRows as $sh) {
                $nameKey = strtolower(trim(str_replace('shift', '', $sh['shift_name'])));
                $s = (int)str_replace(':', '', substr($sh['start_time'], 0, 5));
                $e = (int)str_replace(':', '', substr($sh['end_time'],   0, 5));
                $shiftTimes[$nameKey] = ['s' => $s, 'e' => $e, 'label' => $sh['shift_name']];
                $shiftTimes[strtolower(trim($sh['shift_name']))] = ['s' => $s, 'e' => $e, 'label' => $sh['shift_name']];
            }
        } catch (Exception $_e) {}

        // Check candidate days:
        // 1. Current day
        // 2. If before 08:00 AM, also check previous day for overnight shifts (22:00 - 06:00) that cross midnight
        $candidates = [
            ['wk' => $nowWeekStart, 'dow' => $nowDow, 'is_prev' => false]
        ];
        if ($nowHM < 800) {
            $prevDow = $nowDow - 1;
            $prevWk = $nowWeekStart;
            if ($prevDow < 1) {
                $prevDow = 7;
                $prevWk = date('Y-m-d', strtotime('-7 days', strtotime($nowWeekStart)));
            }
            $candidates[] = ['wk' => $prevWk, 'dow' => $prevDow, 'is_prev' => true];
        }

        $querySql = "SELECT es.employee_id, es.shift_row, es.branch, es.week_start, es.day_of_week, es.shift_in, es.shift_out,
                            e.first_name, e.last_name, e.employee_id AS emp_code
                     FROM employee_shifts es
                     JOIN employees e ON es.employee_id = e.id
                     WHERE es.week_start = ? AND es.day_of_week = ? AND es.shift_row <> 'Off'";
        
        $allRows = [];
        foreach ($candidates as $cand) {
            $sql = $querySql;
            $params = [$cand['wk'], $cand['dow']];
            if (!empty($branchName)) {
                $sql .= " AND es.branch = ?";
                $params[] = $branchName;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $dayRows = $stmt->fetchAll();
            foreach ($dayRows as $dr) {
                $dr['_is_prev'] = $cand['is_prev'];
                $allRows[] = $dr;
            }
        }

        // Fallback: If no shifts found in this specific branch, check ANY branch today/yesterday
        if (empty($allRows)) {
            foreach ($candidates as $cand) {
                $stmt = $conn->prepare($querySql);
                $stmt->execute([$cand['wk'], $cand['dow']]);
                $dayRows = $stmt->fetchAll();
                foreach ($dayRows as $dr) {
                    $dr['_is_prev'] = $cand['is_prev'];
                    $allRows[] = $dr;
                }
            }
        }

        if (empty($allRows)) {
            return ['emp_id' => null, 'staff_list' => '', 'shift_row' => '', 'week_start' => $nowWeekStart];
        }

        // Determine which shift row matches the current time
        $matchedRows = [];
        $matchedShiftRow = '';
        $matchedWk = $nowWeekStart;

        foreach ($allRows as $r) {
            $srRaw = strtolower(trim($r['shift_row']));
            $si = !empty($r['shift_in']) ? (int)str_replace(':', '', substr($r['shift_in'], 0, 5)) : null;
            $so = !empty($r['shift_out']) ? (int)str_replace(':', '', substr($r['shift_out'], 0, 5)) : null;

            if ($si === null || $so === null) {
                // Match by keyword in shiftTimes
                foreach ($shiftTimes as $k => $conf) {
                    if (strpos($srRaw, $k) !== false) {
                        $si = $conf['s'];
                        $so = $conf['e'];
                        break;
                    }
                }
            }

            $isOnNow = false;
            $isOvernight = ($so !== null && $si !== null && $so <= $si);

            if ($r['_is_prev']) {
                // From previous day: Only matches if overnight shift and current time is before shift ends
                if ($isOvernight && $nowHM < $so) {
                    $isOnNow = true;
                }
            } else {
                // From current day
                if ($isOvernight) {
                    $isOnNow = ($nowHM >= $si) || ($nowHM < $so);
                } elseif ($si !== null && $so !== null) {
                    $isOnNow = ($nowHM >= $si) && ($nowHM < $so);
                }
            }

            if ($isOnNow) {
                $matchedRows[] = $r;
                if (!$matchedShiftRow) {
                    $matchedShiftRow = $r['shift_row'];
                    $matchedWk = $r['week_start'];
                }
            }
        }

        // If no rows matched the exact hour, take the first row scheduled
        if (empty($matchedRows)) {
            $matchedRows = [$allRows[0]];
            $matchedShiftRow = $allRows[0]['shift_row'];
            $matchedWk = $allRows[0]['week_start'];
        }

        // Collect names of all staff on this shift
        $staffNames = [];
        $primaryEmpId = null;
        foreach ($matchedRows as $m) {
            if (!$primaryEmpId) $primaryEmpId = (int)$m['employee_id'];
            $full = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            if ($full && !in_array($full, $staffNames, true)) {
                $staffNames[] = $full;
            }
        }

        return [
            'emp_id'     => $primaryEmpId,
            'staff_list' => implode(', ', $staffNames),
            'shift_row'  => $matchedShiftRow,
            'week_start' => $matchedWk
        ];


    } catch (Exception $e) {
        return ['emp_id' => null, 'staff_list' => '', 'shift_row' => '', 'week_start' => ''];
    }
}

// Process POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedBranch = trim($_POST['branch'] ?? $branch);
        if (!in_array($submittedBranch, $validBranches, true)) {
            $submittedBranch = 'Branch 1';
        }

        // The 3 questions ratings (1 to 5)
        $q1 = isset($_POST['q1']) ? (int)$_POST['q1'] : 0;
        $q2 = isset($_POST['q2']) ? (int)$_POST['q2'] : 0;
        $q3 = isset($_POST['q3']) ? (int)$_POST['q3'] : 0;
        $comment = trim($_POST['comment'] ?? '');
        $qr = trim($_POST['qr_code'] ?? ($branch ?: ''));

        // Validate ratings
        if ($q1 < 1 || $q1 > 5 || $q2 < 1 || $q2 > 5 || $q3 < 1 || $q3 > 5) {
            throw new Exception('Pakisagutan ang tatlong tanong gamit ang mga bituin (1 hanggang 5 stars). Salamat!');
        }

        // Calculate overall rating: average of the 3 questions (1 to 5)
        $overallRating = (int)round(($q1 + $q2 + $q3) / 3);
        if ($overallRating < 1) $overallRating = 1;
        if ($overallRating > 5) $overallRating = 5;

        // Auto-detect on-duty staff at this moment
        $duty = resolve_on_duty_staff($conn, $submittedBranch);

        if ($conn) {
            $stmt = $conn->prepare("INSERT INTO feedback
                (rating, q1_rating, q2_rating, q3_rating, comment, branch, qr_code_scanned,
                 on_shift_employee_id, on_shift_staff_list, on_shift_row, on_shift_week_start, submitted_at_local)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $overallRating,
                $q1,
                $q2,
                $q3,
                $comment,
                $submittedBranch,
                $qr ?: null,
                $duty['emp_id'] ?: null,
                $duty['staff_list'] ?: null,
                $duty['shift_row'] ?: null,
                $duty['week_start'] ?: null,
                date('Y-m-d H:i:s')
            ]);
            $fbId = $conn->lastInsertId();

            // Save into feedback_answers for backwards compatibility
            try {
                $qRows = $conn->query("SELECT id, sort_order FROM feedback_questions WHERE is_active = 1 ORDER BY sort_order LIMIT 3")->fetchAll();
                $ansMap = [1 => $q1, 2 => $q2, 3 => $q3];
                foreach ($qRows as $idx => $qRow) {
                    $order = (int)($qRow['sort_order'] ?? ($idx + 1));
                    $ansVal = $ansMap[$order] ?? $q1;
                    $conn->prepare("INSERT INTO feedback_answers (feedback_id, question_id, answer_text) VALUES (?, ?, ?)")
                         ->execute([$fbId, $qRow['id'], (string)$ansVal]);
                }
            } catch (Exception $_ea) {}
        }

        $message = 'Maraming salamat! Matagumpay na naipadala ang iyong feedback.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Current active branch display
$activeBranch = $submittedBranch ?? ($branch ?: 'Branch 1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Big Brew — Customer Feedback</title>
    <link rel="icon" type="image/jpeg" sizes="32x32" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link rel="icon" type="image/jpeg" sizes="64x64" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link href="assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <?php bigbrew_brand_css(); ?>
    <style>
        :root {
            --bb-primary: #a8632a;
            --bb-primary-dark: #874e1d;
            --bb-accent: #f59e0b;
            --bb-cream: #fffaf2;
        }
        body {
            font-family: 'Nunito', sans-serif;
            background:
                radial-gradient(900px 500px at 0% 0%, rgba(212,138,72,0.18) 0%, transparent 60%),
                radial-gradient(700px 400px at 100% 100%, rgba(168,99,42,0.18) 0%, transparent 55%),
                linear-gradient(160deg, #fff9f0 0%, #fff2e0 100%) !important;
            min-height: 100vh;
            color: #2b241c;
        }
        .feedback-card {
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 16px 45px rgba(168, 99, 42, 0.12);
            border: 1px solid rgba(212, 138, 72, 0.18);
            overflow: hidden;
        }
        .feedback-header {
            background: linear-gradient(135deg, #a8632a 0%, #ca7d2a 60%, #e09443 100%);
            padding: 2.2rem 2rem 2rem;
            color: #fff;
            text-align: center;
            position: relative;
        }
        .feedback-header h1 {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .branch-badge {
            background: rgba(255, 255, 255, 0.22);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.35);
            padding: 0.35rem 0.9rem;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            margin-top: 0.5rem;
        }
        .q-card {
            background: #fffdfa;
            border: 1px solid #fae8d4;
            border-radius: 16px;
            padding: 1.25rem 1.4rem;
            margin-bottom: 1.25rem;
            transition: all 0.2s ease;
        }
        .q-card:hover {
            border-color: #ca7d2a;
            box-shadow: 0 4px 14px rgba(202, 125, 42, 0.08);
        }
        .q-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #ca7d2a;
            color: #fff;
            font-size: 0.82rem;
            font-weight: 800;
            margin-right: 0.6rem;
        }
        .q-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: #3f2a15;
            margin-bottom: 0.2rem;
        }
        .q-desc {
            font-size: 0.82rem;
            color: #8c735c;
            margin-bottom: 0.75rem;
        }
        /* Interactive Star Rating */
        .stars-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 320px;
            padding: 0.2rem 0;
        }
        .star-btn {
            background: none;
            border: none;
            outline: none;
            cursor: pointer;
            padding: 6px;
            font-size: 1.9rem;
            color: #e2d7cc;
            transition: transform 0.15s cubic-bezier(0.175, 0.885, 0.32, 1.275), color 0.15s ease;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .star-btn:hover, .star-btn:focus {
            outline: none;
            transform: scale(1.22);
        }
        .star-btn.active {
            color: #f59e0b;
            text-shadow: 0 2px 8px rgba(245, 158, 11, 0.35);
        }
        .rating-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #a8632a;
            margin-top: 0.35rem;
            min-height: 1.2rem;
        }
        .comment-box {
            border: 1.5px solid #e8d9cc;
            border-radius: 14px;
            padding: 0.85rem 1rem;
            font-size: 0.95rem;
            background: #fff;
            transition: all 0.2s ease;
        }
        .comment-box:focus {
            border-color: #ca7d2a;
            box-shadow: 0 0 0 4px rgba(202, 125, 42, 0.12);
            background: #fff;
            outline: none;
        }
        .btn-submit-feedback {
            background: linear-gradient(135deg, #a8632a 0%, #ca7d2a 100%);
            border: none;
            border-radius: 50px;
            padding: 0.95rem 2rem;
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #fff;
            box-shadow: 0 8px 20px rgba(168, 99, 42, 0.28);
            transition: all 0.2s ease;
        }
        .btn-submit-feedback:hover {
            background: linear-gradient(135deg, #8f501e 0%, #b36c20 100%);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(168, 99, 42, 0.35);
        }

    </style>
</head>
<body>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-xl-6 col-lg-7 col-md-9">

            <div class="feedback-card">
                <!-- Header Banner -->
                <div class="feedback-header">
                    <div class="mb-2" style="display:inline-block;filter:drop-shadow(0 4px 10px rgba(0,0,0,0.18));">
                        <?php bigbrew_logo_img(76, false); ?>
                    </div>
                    <h1 class="h3 mb-1 text-white">Big Brew Feedback</h1>
                    <div class="small text-uppercase font-weight-bold" style="letter-spacing:.16em;color:#ffe3c4;">
                        Coffee &middot; Tea &middot; Milktea
                    </div>
                    <div class="branch-badge">
                        <i class="fas fa-store mr-1"></i> <span id="displayBranchName"><?php echo htmlspecialchars($activeBranch); ?></span>
                        <span class="mx-2 opacity-50">&middot;</span>
                        <i class="far fa-clock mr-1"></i> <?php echo date('D, M j'); ?>
                    </div>
                </div>

                <div class="p-4 p-md-5" style="background:#fffcf8;">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> border-0 rounded-lg shadow-sm text-center py-4 mb-4" role="alert">
                            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle text-success fa-3x' : 'exclamation-triangle text-danger fa-2x'; ?> mb-3 d-block"></i>
                            <h5 class="font-weight-bold mb-2"><?php echo htmlspecialchars($message); ?></h5>
                            <?php if ($messageType === 'success'): ?>
                                <p class="text-gray-600 small mb-4">Mahalaga sa amin ang iyong opinyon upang mapaganda pa ang aming serbisyo!</p>
                                <a href="feedback_form.php<?php echo $activeBranch ? '?branch='.urlencode($activeBranch) : ''; ?>" class="btn btn-primary px-4 py-2 font-weight-bold" style="border-radius:50px;">
                                    <i class="fas fa-redo mr-2"></i>Mag-submit Ulit
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$message || $messageType !== 'success'): ?>
                    <form method="POST" id="feedbackForm" autocomplete="off">
                        <!-- Branch selection -->
                        <input type="hidden" name="branch" id="branchInput" value="<?php echo htmlspecialchars($activeBranch); ?>">
                        <input type="hidden" name="qr_code" value="<?php echo htmlspecialchars($_GET['qr'] ?? ($branch ?? '')); ?>">

                        <!-- Branch Display (Naka-lock mula sa QR Code, walang pipiliin) -->
                        <div class="mb-4 text-center">
                            <span class="d-inline-flex align-items-center px-3 py-2 rounded-pill shadow-sm" style="background:#fff6eb;border:1px solid #fed7aa;color:#a8632a;font-weight:700;font-size:0.92rem;">
                                <i class="fas fa-store mr-2 text-primary"></i> Branch: <?php echo htmlspecialchars($activeBranch); ?>
                            </span>
                        </div>

                        <!-- ============================================== -->
                        <!-- 3 QUESTIONS ONLY (TATLONG TANONG LANG)          -->
                        <!-- ============================================== -->

                        <!-- TANONG 1: Drink Quality & Taste -->
                        <div class="q-card">
                            <div class="d-flex align-items-center mb-1">
                                <span class="q-num">1</span>
                                <div>
                                    <div class="q-title">Sarap at Kalidad ng Inumin</div>
                                    <div class="q-desc mb-0">Kumusta ang timpla, lasa, at lamig/init ng iyong order?</div>
                                </div>
                            </div>
                            <div class="stars-container mt-2" data-question="q1">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" class="star-btn" data-value="<?php echo $s; ?>" aria-label="<?php echo $s; ?> star">
                                        <i class="fas fa-star"></i>
                                    </button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="q1" id="q1_val" value="" required>
                            <div class="rating-label" id="q1_label">Pumili ng 1 - 5 stars</div>
                        </div>

                        <!-- TANONG 2: Staff Service & Speed -->
                        <div class="q-card">
                            <div class="d-flex align-items-center mb-1">
                                <span class="q-num">2</span>
                                <div>
                                    <div class="q-title">Bilis at Pakikitungo ng Staff</div>
                                    <div class="q-desc mb-0">Mabilis ba ang paggawa at magalang ba ang nag-asikaso sayo?</div>
                                </div>
                            </div>
                            <div class="stars-container mt-2" data-question="q2">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" class="star-btn" data-value="<?php echo $s; ?>" aria-label="<?php echo $s; ?> star">
                                        <i class="fas fa-star"></i>
                                    </button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="q2" id="q2_val" value="" required>
                            <div class="rating-label" id="q2_label">Pumili ng 1 - 5 stars</div>
                        </div>

                        <!-- TANONG 3: Store Cleanliness -->
                        <div class="q-card">
                            <div class="d-flex align-items-center mb-1">
                                <span class="q-num">3</span>
                                <div>
                                    <div class="q-title">Kalinisan at Kaayusan ng Tindahan</div>
                                    <div class="q-desc mb-0">Malinis, maayos, at komportable ba ang paligid ng store?</div>
                                </div>
                            </div>
                            <div class="stars-container mt-2" data-question="q3">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" class="star-btn" data-value="<?php echo $s; ?>" aria-label="<?php echo $s; ?> star">
                                        <i class="fas fa-star"></i>
                                    </button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="q3" id="q3_val" value="" required>
                            <div class="rating-label" id="q3_label">Pumili ng 1 - 5 stars</div>
                        </div>

                        <!-- ============================================== -->
                        <!-- COMMENT AGAD (KOMENTO O MUNGKAHI)              -->
                        <!-- ============================================== -->
                        <div class="form-group mb-4">
                            <label class="font-weight-bold" style="font-family:'Outfit',sans-serif;color:#3f2a15;font-size:1.05rem;">
                                <i class="far fa-comment-dots mr-1 text-primary"></i> Komento o Mungkahi <small class="text-muted font-weight-normal">(Optional)</small>
                            </label>
                            <textarea class="form-control comment-box" name="comment" rows="3" placeholder="Ibahagi ang iyong karanasan, paboritong inumin, o mensahe para sa aming staff..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-submit-feedback btn-block btn-lg" id="submitBtn">
                            <i class="fas fa-paper-plane mr-2"></i> Ipasa ang Feedback
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <p class="text-center small text-gray-500 mt-4">&copy; <?php echo date('Y'); ?> Big Brew HR Suite &middot; Coffee &middot; Tea &middot; Milktea</p>
        </div>
    </div>
</div>

<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
var starDescriptions = {
    q1: {
        1: '😞 1 Star — Hindi Masarap / May Mali sa Timpla',
        2: '😐 2 Stars — Pwede Na / Katamtaman',
        3: '🙂 3 Stars — Masarap at Tama ang Timpla',
        4: '😊 4 Stars — Napakasarap / Nagustuhan Ko!',
        5: '🤩 5 Stars — Sobrang Sarap! Paborito Ko!'
    },
    q2: {
        1: '😞 1 Star — Masungit / Mabagal ang Serbisyo',
        2: '😐 2 Stars — Katamtaman ang Bilis',
        3: '🙂 3 Stars — Maayos at Magalang ang Staff',
        4: '😊 4 Stars — Mabilis at Mabait ang Nag-asikaso',
        5: '🤩 5 Stars — Napakabait, Masayahin, at Mabilis!'
    },
    q3: {
        1: '😞 1 Star — Marumi o Magulo ang Area',
        2: '😐 2 Stars — Pwede Na',
        3: '🙂 3 Stars — Malinis at Maayos',
        4: '😊 4 Stars — Napakalinis at Presko',
        5: '🤩 5 Stars — Sobrang Linis at Kaaya-aya!'
    }
};



$(function(){
    // Star click interaction
    $('.star-btn').on('click', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $container = $btn.closest('.stars-container');
        var qKey = $container.data('question');
        var val = parseInt($btn.data('value'), 10);

        // Update hidden input
        $('#' + qKey + '_val').val(val);

        // Update active stars
        $container.find('.star-btn').each(function(){
            var starVal = parseInt($(this).data('value'), 10);
            if (starVal <= val) {
                $(this).addClass('active');
            } else {
                $(this).removeClass('active');
            }
        });

        // Update description label
        var desc = (starDescriptions[qKey] && starDescriptions[qKey][val]) ? starDescriptions[qKey][val] : (val + ' Stars');
        $('#' + qKey + '_label').html(desc).css('color', '#a8632a');
    });

    // Hover effect
    $('.star-btn').on('mouseenter', function(){
        var $btn = $(this);
        var $container = $btn.closest('.stars-container');
        var val = parseInt($btn.data('value'), 10);
        $container.find('.star-btn').each(function(){
            var starVal = parseInt($(this).data('value'), 10);
            if (starVal <= val) {
                $(this).css('color', '#f59e0b');
            } else {
                $(this).css('color', '#e2d7cc');
            }
        });
    }).on('mouseleave', function(){
        var $container = $(this).closest('.stars-container');
        var qKey = $container.data('question');
        var currentVal = parseInt($('#' + qKey + '_val').val(), 10) || 0;
        $container.find('.star-btn').each(function(){
            var starVal = parseInt($(this).data('value'), 10);
            if (starVal <= currentVal) {
                $(this).css('color', '').addClass('active');
            } else {
                $(this).css('color', '').removeClass('active');
            }
        });
    });

    // Form submission validation
    $('#feedbackForm').on('submit', function(e){
        var q1 = $('#q1_val').val();
        var q2 = $('#q2_val').val();
        var q3 = $('#q3_val').val();

        if (!q1 || !q2 || !q3) {
            e.preventDefault();
            alert('Pakibigyan ng star rating ang tatlong tanong bago mag-submit. Maraming salamat!');
            if (!q1) { $('[data-question="q1"]').closest('.q-card').css('border-color', '#e74a3b'); }
            if (!q2) { $('[data-question="q2"]').closest('.q-card').css('border-color', '#e74a3b'); }
            if (!q3) { $('[data-question="q3"]').closest('.q-card').css('border-color', '#e74a3b'); }
            return false;
        }

        $('#submitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Ipinapasa...');
    });
});
</script>
</body>
</html>
