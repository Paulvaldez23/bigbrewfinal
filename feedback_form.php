<?php
require_once 'db_con.php';
require_once 'includes/brand.php';

$message = '';
$messageType = '';
$branch = $_GET['branch'] ?? '';
$questions = [];

// ==================================================================
// AUTO-MIGRATE: Add feedback tracking columns for "who was on-shift"
//   Needed per requirement: "kukunin nya dito yung sa shift kung sino
//   naka shift and anong branch sya bumili"
// ==================================================================
if ($conn) {
    $schemaCols = [
        'on_shift_employee_id' => "ADD COLUMN on_shift_employee_id INT NULL COMMENT 'Employee on-shift when feedback was submitted (FK employees.id)' AFTER employee_id",
        'on_shift_row'         => "ADD COLUMN on_shift_row VARCHAR(50) NULL COMMENT 'Shift row label (Morning/Afternoon/Night/Opening/Mid/Closing)' AFTER on_shift_employee_id",
        'on_shift_week_start'  => "ADD COLUMN on_shift_week_start DATE NULL COMMENT 'Week start of the assigned shift (FK shift_weeks.week_start or employee_shifts.week_start)' AFTER on_shift_row",
        'submitted_at_local'   => "ADD COLUMN submitted_at_local DATETIME NULL COMMENT 'Asia/Manila local time capture (matches uploaded biometric display format)' AFTER submitted_at",
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
}

// =========================================================
// DETERMINE TODAY'S BRANCH + ON-DUTY STAFF (CURRENT SHIFT)
// =========================================================
$onDuty = [];          // list of all on-duty today (any shift) for this branch
$currentOnDuty = [];   // on-duty RIGHT NOW (matched against current hour vs shift time range)
$today = date('Y-m-d');          // PHP Asia/Manila local date
$dow   = (int)date('N');         // 1=Mon ... 7=Sun (ISO, matches employee_shifts.day_of_week 1-7)
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($today)));
$nowHourMin = (int)date('Hi');   // e.g. 1632 = 4:32 PM

// Normalize branch
$validBranches = ['Branch 1', 'Branch 2', 'Branch 3', 'Head Office'];
if (!in_array($branch, $validBranches, true)) {
    // If missing, try to ask user which branch (dropdown at top of form)
    $branch = '';
}
$submittedBranch = $branch;

// Look up shifts for THIS WEEK (employee_shifts.week_start = $weekStart, matching branch, matching dow)
if ($conn && $branch) {
    try {
        $shiftTimeMapStmt = $conn->prepare("SELECT shift_name, start_time, end_time FROM shifts ORDER BY start_time");
        $shiftTimeMapStmt->execute();
        $shiftTimes = [];
        foreach ($shiftTimeMapStmt->fetchAll() as $sh) {
            // Build time-range lookup (HHMM integer keys)
            $s = (int)str_replace(':', '', substr($sh['start_time'], 0, 5));
            $e = (int)str_replace(':', '', substr($sh['end_time'],   0, 5));
            $shiftTimes[$sh['shift_name']] = ['start' => $s, 'end' => $e];
        }

        $onStmt = $conn->prepare("SELECT
            es.employee_id, es.shift_row, es.branch, es.week_start, es.shift_label, es.shift_in, es.shift_out,
            e.first_name, e.last_name, e.position, e.employee_id AS emp_code,
            s.start_time AS std_start, s.end_time AS std_end
            FROM employee_shifts es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN shifts s ON s.shift_name = es.shift_row
            WHERE es.week_start = ?
              AND es.day_of_week = ?
              AND es.branch = ?
              AND es.shift_row <> 'Off'
            ORDER BY CASE es.shift_row
              WHEN 'Morning' THEN 1 WHEN 'Opening' THEN 1
              WHEN 'Mid'     THEN 2 WHEN 'Afternoon' THEN 2 WHEN 'Closing' THEN 2
              WHEN 'Night'   THEN 3 ELSE 5 END ASC");
        $onStmt->execute([$weekStart, $dow, $branch]);
        $onDuty = $onStmt->fetchAll();

        // Filter to "CURRENT ON DUTY NOW" by matching nowHourMin inside shift time range
        foreach ($onDuty as $d) {
            $si = !empty($d['shift_in'])  ? (int)str_replace(':', '', substr($d['shift_in'],  0, 5)) : null;
            $so = !empty($d['shift_out']) ? (int)str_replace(':', '', substr($d['shift_out'], 0, 5)) : null;
            if ($si === null && isset($shiftTimes[$d['shift_row']])) { $si = $shiftTimes[$d['shift_row']]['start']; }
            if ($so === null && isset($shiftTimes[$d['shift_row']])) { $so = $shiftTimes[$d['shift_row']]['end'];   }
            // Handle night shifts where end < start (e.g. 22:00 to 06:00 next day)
            $onNow = false;
            if ($si !== null && $so !== null) {
                if ($so <= $si) { // overnight
                    $onNow = ($nowHourMin >= $si) || ($nowHourMin < $so);
                } else {
                    $onNow = ($nowHourMin >= $si && $nowHourMin < $so);
                }
            }
            $d['_on_now'] = $onNow;
            $d['_display_time'] = ($d['shift_in'] && $d['shift_out'])
                ? date('g:i A', strtotime($d['shift_in'])) . ' – ' . date('g:i A', strtotime($d['shift_out']))
                : (($d['std_start'] && $d['std_end'])
                    ? date('g:i A', strtotime($d['std_start'])) . ' – ' . date('g:i A', strtotime($d['std_end']))
                    : 'As scheduled');
            $currentOnDuty[] = $d;
        }
        // Sort CURRENT ON DUTY first in the list
        usort($currentOnDuty, function($a, $b) { return (int)$b['_on_now'] - (int)$a['_on_now']; });
    } catch (Exception $e) {}
}

if ($conn) {
    try {
        $questions = $conn->query("SELECT * FROM feedback_questions WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {}
}

// Default selected on-duty employee (first on now; else first on today; else null)
$selectedEmpId = null;
$selectedShiftRow = null;
$selectedWeekStart = null;
if (!empty($currentOnDuty)) {
    $top = null;
    foreach ($currentOnDuty as $c) { if ($c['_on_now']) { $top = $c; break; } }
    if (!$top) $top = $currentOnDuty[0];
    $selectedEmpId  = (int)$top['employee_id'];
    $selectedShiftRow = $top['shift_row'];
    $selectedWeekStart = $top['week_start'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rating = $_POST['rating'] ?? null;
        $comment = $_POST['comment'] ?? '';
        $submittedBranch = trim($_POST['branch'] ?? $branch);
        if (!in_array($submittedBranch, $validBranches, true)) {
            $submittedBranch = in_array($branch, $validBranches, true) ? $branch : null;
        }
        $qr = $_POST['qr_code'] ?? null;
        $employeeId = null;  // legacy column — keep null (we now use on_shift_employee_id)

        // =========================================================
        // SERVER-SIDE RE-COMPUTE: Auto-detect on-duty staff NOW
        // (do NOT trust hidden form inputs - prevents tampering
        //  AND corrects if customer had page open since morning!)
        // =========================================================
        $onShiftEmpId = null;
        $onShiftRow   = null;
        $onShiftWeek  = null;
        if ($conn && $submittedBranch) {
            try {
                $nowDow = (int)date('N');
                $nowWeekStart = date('Y-m-d', strtotime('monday this week', strtotime(date('Y-m-d'))));
                $nowHM = (int)date('Hi');
                $shiftMapStmt = $conn->prepare("SELECT shift_name, start_time, end_time FROM shifts");
                $shiftMapStmt->execute();
                $shiftTimes = [];
                foreach ($shiftMapStmt->fetchAll() as $sh) {
                    $s = (int)str_replace(':', '', substr($sh['start_time'], 0, 5));
                    $e = (int)str_replace(':', '', substr($sh['end_time'],   0, 5));
                    $shiftTimes[$sh['shift_name']] = ['s' => $s, 'e' => $e];
                }
                $pickStmt = $conn->prepare("SELECT
                    es.employee_id, es.shift_row, es.week_start, es.shift_in, es.shift_out,
                    s.start_time, s.end_time
                    FROM employee_shifts es
                    LEFT JOIN shifts s ON s.shift_name = es.shift_row
                    WHERE es.week_start = ? AND es.day_of_week = ? AND es.branch = ? AND es.shift_row <> 'Off'");
                $pickStmt->execute([$nowWeekStart, $nowDow, $submittedBranch]);
                $rows = $pickStmt->fetchAll();
                $onNowRow = null; $firstTodayRow = $rows[0] ?? null;
                foreach ($rows as $r) {
                    $si = !empty($r['shift_in'])  ? (int)str_replace(':', '', substr($r['shift_in'],  0, 5)) : null;
                    $so = !empty($r['shift_out']) ? (int)str_replace(':', '', substr($r['shift_out'], 0, 5)) : null;
                    if ($si === null && isset($shiftTimes[$r['shift_row']])) $si = $shiftTimes[$r['shift_row']]['s'];
                    if ($so === null && isset($shiftTimes[$r['shift_row']])) $so = $shiftTimes[$r['shift_row']]['e'];
                    $on = false;
                    if ($si !== null && $so !== null) {
                        if ($so <= $si) $on = ($nowHM >= $si) || ($nowHM < $so);
                        else            $on = ($nowHM >= $si) && ($nowHM < $so);
                    }
                    if ($on && !$onNowRow) $onNowRow = $r;
                }
                $pick = $onNowRow ?: $firstTodayRow;
                if ($pick) {
                    $onShiftEmpId = (int)$pick['employee_id'];
                    $onShiftRow   = trim($pick['shift_row'] ?? '');
                    $onShiftWeek  = trim($pick['week_start'] ?? '');
                    if (!$onShiftEmpId) $onShiftEmpId = null;
                }
            } catch (Exception $e) {}
        }

        if ($conn) {
            $stmt = $conn->prepare("INSERT INTO feedback
                (employee_id, rating, comment, branch, qr_code_scanned,
                 on_shift_employee_id, on_shift_row, on_shift_week_start, submitted_at_local)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $employeeId, $rating, $comment, $submittedBranch, $qr,
                $onShiftEmpId, $onShiftRow ?: null, $onShiftWeek ?: null,
                date('Y-m-d H:i:s')
            ]);
            $fbId = $conn->lastInsertId();

            foreach ($questions as $q) {
                $ansKey = 'q_' . $q['id'];
                $answer = $_POST[$ansKey] ?? null;
                if ($answer !== null && $answer !== '') {
                    $aStmt = $conn->prepare("INSERT INTO feedback_answers (feedback_id, question_id, answer_text) VALUES (?, ?, ?)");
                    $aStmt->execute([$fbId, $q['id'], $answer]);
                }
            }
        }
        $message = 'Thank you! Your feedback has been submitted successfully.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Error submitting feedback: ' . $e->getMessage();
        $messageType = 'danger';
    }
}
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
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,300,400,600,700,800" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <?php bigbrew_brand_css(); ?>
    <style>
        body.bg-gradient-light {
            background:
                radial-gradient(900px 500px at 0% 0%, rgba(212,138,72,0.14) 0%, transparent 60%),
                radial-gradient(700px 400px at 100% 100%, rgba(168,99,42,0.14) 0%, transparent 55%),
                linear-gradient(160deg, #fff8ec 0%, #fff3e3 100%) !important;
            min-height: 100vh;
        }
    </style>
</head>
<body class="bg-gradient-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-6 col-lg-7 col-md-9">
            <div class="text-center mb-4">
                <div class="mb-2" style="display:inline-block;"><?php bigbrew_logo_img(76, false); ?></div>
                <h1 class="h3 mb-1 text-gray-800 font-weight-bold">Big Brew Feedback</h1>
                <div class="small text-uppercase mb-2" style="letter-spacing:.16em;color:#a8632a;">Coffee &middot; Tea &middot; Milktea</div>
                <?php if ($branch): ?>
                    <p class="mb-0 text-gray-600">
                        Branch: <strong><?php echo htmlspecialchars($branch); ?></strong>
                        <span class="mx-2 text-gray-400">·</span>
                        Today: <strong><?php echo date('D, M j, Y'); ?></strong>
                        <span class="mx-2 text-gray-400">·</span>
                        <span id="localClock" style="color:#a8632a;font-weight:600;"><?php echo date('g:i A'); ?></span>
                    </p>
                <?php endif; ?>
            </div>

            <?php if (!$branch): ?>
            <div class="card border-0 shadow-sm mb-4" style="border-radius:18px;background:#fffaf2;">
                <div class="card-body py-3 px-4">
                    <h6 class="m-0 font-weight-bold mb-2" style="color:#8a5019;"><i class="fas fa-store mr-2"></i>Select which branch this feedback is for:</h6>
                    <div class="row">
                        <?php foreach ($validBranches as $b): ?>
                        <div class="col-6 col-md-3 mb-2">
                            <label class="w-100 mb-0 p-2 rounded-lg border cursor-pointer" style="background:rgba(255,255,255,.7);">
                                <input type="radio" name="branch" value="<?php echo htmlspecialchars($b); ?>" class="mr-2" <?php echo $branch === $b ? 'checked' : ''; ?>>
                                <span class="font-weight-bold text-gray-800"><?php echo htmlspecialchars($b); ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card o-hidden border-0 shadow-lg">
                <div class="card-body p-5" style="background:#fffaf2;">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> border-0 rounded-lg shadow-sm" role="alert"><i class="fas fa-<?php echo $messageType==='success'?'check-circle text-success':'exclamation-triangle text-danger'; ?> mr-2"></i><?php echo htmlspecialchars($message); ?></div>
                        <?php if ($messageType === 'success'): ?>
                        <div class="text-center pt-2">
                            <p class="text-gray-600">We appreciate your feedback!</p>
                            <a href="feedback_form.php<?php echo $submittedBranch ? '?branch='.urlencode($submittedBranch) : ''; ?>" class="btn btn-primary btn-user">Submit Another Response</a>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                    <form method="POST">
                        <?php if ($branch): ?>
                            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="qr_code" value="<?php echo htmlspecialchars($_GET['qr'] ?? ($branch ?? '')); ?>">
                        <?php
                        // Server-side only hidden inputs: Auto-populated based on shift lookups on SUBMIT.
                        // Admin-only tracking (customer never sees staff names - per user requirement "admin lang makakakita").
                        ?>
                        <input type="hidden" name="on_shift_employee_id" value="<?php echo (int)($selectedEmpId ?? 0) ?: ''; ?>">
                        <input type="hidden" name="on_shift_row" value="<?php echo htmlspecialchars($selectedShiftRow ?? ''); ?>">
                        <input type="hidden" name="on_shift_week_start" value="<?php echo htmlspecialchars($selectedWeekStart ?? ''); ?>">

                        <div class="form-group">
                            <label class="font-weight-bold">Overall Rating</label>
                            <div class="rate d-flex flex-row-reverse justify-content-start" id="starRating">
                                <?php for ($i=5;$i>=1;$i--): ?>
                                <label class="mx-1" for="star<?php echo $i; ?>" style="cursor:pointer;">
                                    <input type="radio" name="rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" class="d-none">
                                    <i class="fas fa-star fa-2x text-gray-300 star-icon" data-value="<?php echo $i; ?>"></i>
                                </label>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <?php foreach ($questions as $q): ?>
                        <div class="form-group">
                            <label class="font-weight-bold"><?php echo htmlspecialchars($q['question_text']); ?></label>
                            <?php if ($q['question_type'] === 'rating'): ?>
                                <select class="form-control" name="q_<?php echo $q['id']; ?>">
                                    <option value="">-- Select --</option>
                                    <?php for ($i=1;$i<=5;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?> - <?php echo $i===1?'Poor':($i===2?'Fair':($i===3?'Good':($i===4?'Very Good':'Excellent'))); ?></option><?php endfor; ?>
                                </select>
                            <?php else: ?>
                                <textarea class="form-control" name="q_<?php echo $q['id']; ?>" rows="3" placeholder="Write your thoughts..."></textarea>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <div class="form-group">
                            <label class="font-weight-bold">Additional Comments</label>
                            <textarea class="form-control" name="comment" rows="3" placeholder="Tell us more..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-user btn-block btn-lg mt-4"><i class="fas fa-paper-plane mr-2"></i>Submit Feedback</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-center small text-gray-500 mt-3">&copy; <?php echo date('Y'); ?> Big Brew HR Suite &middot; Coffee &middot; Tea &middot; Milktea</p>
        </div>
    </div>
</div>
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
    $('.star-icon').on('click', function(){
        var val = parseInt($(this).data('value'));
        $('.star-icon').removeClass('text-warning').addClass('text-gray-300');
        $('.star-icon').each(function(){
            if(parseInt($(this).data('value')) <= val){
                $(this).removeClass('text-gray-300').addClass('text-warning');
            }
        });
        $('#star' + val).prop('checked', true);
    });

    // Live clock ticker in header
    function tickClock(){
        var el = document.getElementById('localClock');
        if (!el) return;
        var d = new Date();
        var h = d.getHours();
        var m = d.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12; if (!h) h = 12;
        var mm = m < 10 ? '0' + m : String(m);
        el.textContent = h + ':' + mm + ' ' + ampm;
    }
    setInterval(tickClock, 30000); // 30s refresh
});
</script>
</body>
</html>
