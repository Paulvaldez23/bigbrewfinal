<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

$page_title = 'Big Brew - My Schedule';
$active_nav = 'schedule';

$userId = (int)($_SESSION['user_id'] ?? 0);
$staffEmpId = null;
$myEmpData = null;
$isAdmin = is_admin();

if ($conn) {
    try {
        if ($isAdmin) {
            $viewEmpId = (int)($_GET['employee_id'] ?? 0);
            if ($viewEmpId) {
                $empStmt = $conn->prepare("SELECT e.* FROM employees e
                    LEFT JOIN admin_users au ON au.employee_id = e.id
                    WHERE e.id = ?
                      AND (au.role IS NULL OR au.role != 'Admin')
                      AND LOWER(COALESCE(e.position, '')) NOT IN ('owner', 'admin')
                    LIMIT 1");
                $empStmt->execute([$viewEmpId]);
                $myEmpData = $empStmt->fetch();
                if ($myEmpData) { $staffEmpId = (int)$myEmpData['id']; }
            }
        }
        if (!$isAdmin && !$staffEmpId) {
            $stmt = $conn->prepare("SELECT employee_id FROM admin_users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $link = $stmt->fetch();
            if ($link && !empty($link['employee_id'])) {
                $staffEmpId = (int)$link['employee_id'];
                $empStmt = $conn->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
                $empStmt->execute([$staffEmpId]);
                $myEmpData = $empStmt->fetch();
            }
        }
    } catch (Exception $e) {}
}

$weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
}
$dt = DateTime::createFromFormat('Y-m-d', $weekStart);
if (!$dt || (int)$dt->format('N') !== 1) {
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($weekStart ?: 'now')));
}
$prevWeek = date('Y-m-d', strtotime('-1 week', strtotime($weekStart)));
$nextWeek = date('Y-m-d', strtotime('+1 week', strtotime($weekStart)));
$thisWeek = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('+6 days', strtotime($weekStart)));

// Ensure schedule_requests table exists
if ($conn) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS schedule_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            user_id INT NULL,
            week_start DATE NOT NULL,
            request_type VARCHAR(100) NOT NULL,
            target_date DATE NULL,
            message TEXT NOT NULL,
            status ENUM('pending', 'approved', 'declined', 'resolved') DEFAULT 'pending',
            admin_reply TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            is_staff_read TINYINT DEFAULT 0,
            is_admin_read TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_emp (employee_id),
            INDEX idx_week (week_start),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

// Handle POST actions (submit request, respond request, cancel request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $action = $_POST['action'] ?? '';
    $redirectUrl = 'schedule.php?week=' . urlencode($weekStart);
    if ($isAdmin && $staffEmpId) {
        $redirectUrl .= '&employee_id=' . (int)$staffEmpId;
    }

    if ($action === 'submit_request') {
        $reqEmpId = $staffEmpId;
        $reqType = trim($_POST['request_type'] ?? 'Shift Swap');
        $targetDate = !empty($_POST['target_date']) ? trim($_POST['target_date']) : null;
        $reqMessage = trim($_POST['message'] ?? '');

        if (!$reqEmpId) {
            $_SESSION['flash_msg'] = ['type' => 'danger', 'text' => 'Walang linked employee record na nahanap para sa account na ito.'];
        } elseif ($reqMessage === '') {
            $_SESSION['flash_msg'] = ['type' => 'warning', 'text' => 'Pakilagay ang detalye o dahilan ng iyong concern/request.'];
        } else {
            try {
                $ins = $conn->prepare("INSERT INTO schedule_requests
                    (employee_id, user_id, week_start, request_type, target_date, message, status, is_staff_read, is_admin_read)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, 0)");
                $ins->execute([$reqEmpId, ($userId ?: null), $weekStart, $reqType, $targetDate, $reqMessage]);
                $_SESSION['flash_msg'] = ['type' => 'success', 'text' => '✅ Naipadala na ang iyong schedule concern / request sa Admin!'];
            } catch (Exception $e) {
                $_SESSION['flash_msg'] = ['type' => 'danger', 'text' => 'Error sa pagpapadala: ' . $e->getMessage()];
            }
        }
        header("Location: $redirectUrl#schedule-requests-section");
        exit;
    } elseif ($action === 'respond_request' && $isAdmin) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'resolved');
        $adminReply = trim($_POST['admin_reply'] ?? '');
        if (!in_array($newStatus, ['pending', 'approved', 'declined', 'resolved'], true)) {
            $newStatus = 'resolved';
        }
        try {
            $upd = $conn->prepare("UPDATE schedule_requests SET
                status = ?, admin_reply = ?, reviewed_by = ?, reviewed_at = NOW(), is_staff_read = 0
                WHERE id = ?");
            $upd->execute([$newStatus, $adminReply, ($userId ?: null), $reqId]);
            $_SESSION['flash_msg'] = ['type' => 'success', 'text' => '✅ Na-update ang request status at na-save ang tugon para sa staff.'];
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = ['type' => 'danger', 'text' => 'Error sa pag-update: ' . $e->getMessage()];
        }
        header("Location: $redirectUrl#schedule-requests-section");
        exit;
    } elseif ($action === 'cancel_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        try {
            if ($isAdmin) {
                $del = $conn->prepare("DELETE FROM schedule_requests WHERE id = ?");
                $del->execute([$reqId]);
            } else {
                $del = $conn->prepare("DELETE FROM schedule_requests WHERE id = ? AND employee_id = ? AND status = 'pending'");
                $del->execute([$reqId, (int)$staffEmpId]);
            }
            $_SESSION['flash_msg'] = ['type' => 'info', 'text' => 'Kinansela ang request.'];
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = ['type' => 'danger', 'text' => 'Error: ' . $e->getMessage()];
        }
        header("Location: $redirectUrl#schedule-requests-section");
        exit;
    }
}

if (!$isAdmin && $staffEmpId && $conn && !empty($_GET['mark_read'])) {
    try {
        $mr = $conn->prepare("UPDATE shift_notifications SET is_read = 1
            WHERE employee_id = ? AND is_read = 0 AND week_start = ?");
        $mr->execute([$staffEmpId, $_GET['mark_read']]);
    } catch (Exception $e) {}
}

// Fetch schedule requests for this employee and week
$scheduleRequests = [];
$pendingRequestsCount = 0;
if ($conn && $staffEmpId) {
    try {
        $srStmt = $conn->prepare("SELECT sr.*, au.name AS reviewer_name,
            e.first_name, e.last_name
            FROM schedule_requests sr
            LEFT JOIN admin_users au ON sr.reviewed_by = au.id
            LEFT JOIN employees e ON sr.employee_id = e.id
            WHERE sr.employee_id = ? AND sr.week_start = ?
            ORDER BY sr.created_at DESC");
        $srStmt->execute([$staffEmpId, $weekStart]);
        $scheduleRequests = $srStmt->fetchAll();
        foreach ($scheduleRequests as $sq) {
            if ($sq['status'] === 'pending') {
                $pendingRequestsCount++;
            }
        }

        // If staff view and has unread replies from admin, mark as read
        if (!$isAdmin) {
            $conn->prepare("UPDATE schedule_requests SET is_staff_read = 1
                WHERE employee_id = ? AND week_start = ? AND is_staff_read = 0")
                ->execute([$staffEmpId, $weekStart]);
        }
    } catch (Exception $e) {}
}

$flashMsg = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);

$dayHeaders = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i days", strtotime($weekStart)));
    $dow = (int)date('N', strtotime($d));
    $dayHeaders[$dow] = [
        'date' => $d,
        'dow' => $dow,
        'label' => date('D', strtotime($d)),
        'full' => date('l', strtotime($d)),
        'sub' => date('M j, Y', strtotime($d)),
        'isToday' => $d === date('Y-m-d'),
    ];
}

$weekPublish = ['published' => false, 'published_at' => null, 'published_by' => null];
if ($conn) {
    try {
        $sw = $conn->prepare("SELECT sw.*, au.name AS publisher FROM shift_weeks sw
            LEFT JOIN admin_users au ON sw.published_by = au.id
            WHERE sw.week_start = ? LIMIT 1");
        $sw->execute([$weekStart]);
        $wr = $sw->fetch();
        if ($wr) {
            $weekPublish['published'] = !empty($wr['published_at']);
            $weekPublish['published_at'] = $wr['published_at'];
            $weekPublish['published_by'] = $wr['publisher'] ?? null;
        }
    } catch (Exception $e) {}
}

$shiftsData = []; // $shiftsData[(int)day_of_week] = ARRAY of shift objects (1+ per day = morning/afternoon/night double-shift on same day)
if ($conn && $staffEmpId) {
    try {
        if ($isAdmin || $weekPublish['published']) {
            $hasCol = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_shifts' AND COLUMN_NAME = 'shift_row'")->fetchColumn();
            $hasBrCol = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_shifts' AND COLUMN_NAME = 'branch'")->fetchColumn();
            $sql = $hasCol
                ? ($hasBrCol
                    ? "SELECT day_of_week, shift_row, shift_label, shift_in, shift_out, notes, branch FROM employee_shifts WHERE employee_id = ? AND week_start = ? ORDER BY CASE shift_row WHEN 'Morning' THEN 1 WHEN 'Opening' THEN 1 WHEN 'Mid' THEN 2 WHEN 'Afternoon' THEN 2 WHEN 'Closing' THEN 2 WHEN 'Night' THEN 3 WHEN 'Off' THEN 99 ELSE 5 END ASC"
                    : "SELECT day_of_week, shift_row, shift_label, shift_in, shift_out, notes FROM employee_shifts WHERE employee_id = ? AND week_start = ? ORDER BY CASE shift_row WHEN 'Morning' THEN 1 WHEN 'Opening' THEN 1 WHEN 'Mid' THEN 2 WHEN 'Afternoon' THEN 2 WHEN 'Closing' THEN 2 WHEN 'Night' THEN 3 WHEN 'Off' THEN 99 ELSE 5 END ASC")
                : "SELECT day_of_week, shift_label, shift_in, shift_out, notes FROM employee_shifts WHERE employee_id = ? AND week_start = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$staffEmpId, $weekStart]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) {
                $sr = $r['shift_row'] ?? $r['shift_label'] ?? 'Mid';
                if (empty($sr)) { $sr = 'Mid'; }
                $r['shift_row'] = $sr;
                $r['shift_label'] = $r['shift_label'] ?? $sr;
                $r['branch'] = in_array(($r['branch'] ?? 'Branch 1'), ['Branch 1','Branch 2'], true) ? ($r['branch'] ?? 'Branch 1') : 'Branch 1';
                $dowKey = (int)$r['day_of_week'];
                if (!isset($shiftsData[$dowKey])) $shiftsData[$dowKey] = [];
                $shiftsData[$dowKey][] = $r; // ARRAY PUSH — morning, afternoon, night all preserved! no overwrite ✨
            }
        }
    } catch (Exception $e) {}
}

$shiftColors = [];
if ($conn) {
    try {
        $ss = $conn->query("SELECT shift_name, color FROM shifts")->fetchAll();
        foreach ($ss as $s) { $shiftColors[$s['shift_name']] = $s['color']; }
    } catch (Exception $e) {}
}

$allEmployees = [];
if ($isAdmin && $conn) {
    try {
        $allEmployees = $conn->query("SELECT e.id, e.first_name, e.last_name 
            FROM employees e
            LEFT JOIN admin_users au ON au.employee_id = e.id
            WHERE (au.role IS NULL OR au.role != 'Admin')
              AND LOWER(COALESCE(e.position, '')) NOT IN ('owner', 'admin')
            ORDER BY e.first_name")->fetchAll();
    } catch (Exception $e) {}
}

$empParamUrl = function($wk) use ($isAdmin, $staffEmpId) {
    $q = '?week=' . urlencode($wk);
    if ($isAdmin && $staffEmpId) { $q .= '&employee_id=' . (int)$staffEmpId; }
    return $q;
};

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <?php if (!empty($flashMsg)): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashMsg['type'] ?? 'info'); ?> alert-dismissible fade show py-2 mb-3 shadow-sm" role="alert">
        <i class="fas fa-<?php echo ($flashMsg['type'] ?? '') === 'success' ? 'check-circle text-success' : (($flashMsg['type'] ?? '') === 'danger' ? 'exclamation-circle text-danger' : 'info-circle text-info'); ?> mr-2"></i>
        <?php echo htmlspecialchars($flashMsg['text'] ?? ''); ?>
        <button type="button" class="close" data-dismiss="alert"><span>×</span></button>
    </div>
    <?php endif; ?>
    <?php if (!$isAdmin && $weekPublish['published']): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 mb-3" role="alert">
        <i class="fas fa-paper-plane mr-2"></i>
        <b>Sent by Admin:</b> Your schedule for
        <b><?php echo date('M j', strtotime($weekStart)); ?> - <?php echo date('M j, Y', strtotime($weekEnd)); ?></b>
        was published on <?php echo date('M j, g:i A', strtotime($weekPublish['published_at'])); ?>
        <?php if (!empty($weekPublish['published_by'])): ?>by <b><?php echo htmlspecialchars($weekPublish['published_by']); ?></b><?php endif; ?>.
        <a href="schedule.php<?php echo $empParamUrl($weekStart); ?>&mark_read=<?php echo urlencode($weekStart); ?>"
           class="btn btn-sm btn-light ml-3 border"><i class="fas fa-check-double mr-1"></i>Mark as Read</a>
        <button type="button" class="close" data-dismiss="alert"><span>×</span></button>
    </div>
    <?php elseif (!$isAdmin && !$weekPublish['published']): ?>
    <div class="alert alert-info alert-dismissible fade show py-2 mb-3" role="alert">
        <i class="fas fa-clock mr-2"></i>
        <b>Pending:</b> Admin hasn't published the schedule for week
        <b><?php echo date('M j', strtotime($weekStart)); ?> - <?php echo date('M j, Y', strtotime($weekEnd)); ?></b> yet.
        You'll see it here as soon as it's sent.
        <button type="button" class="close" data-dismiss="alert"><span>×</span></button>
    </div>
    <?php endif; ?>

    <div class="d-sm-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800 d-flex align-items-center">
                <i class="fas fa-calendar-week text-primary mr-2"></i>
                <?php echo $isAdmin && $myEmpData ? 'Viewing Employee Schedule' : 'My Weekly Schedule'; ?>
                <span class="ml-2 badge <?php echo $weekPublish['published'] ? 'badge-success' : 'badge-secondary'; ?>" style="font-size:.5em;vertical-align:middle;">
                    <?php if ($weekPublish['published']): ?>
                    <i class="fas fa-check-circle mr-1"></i>Published
                    <?php else: ?>
                    <i class="far fa-clock mr-1"></i><?php echo $isAdmin ? 'Draft / Unsent' : 'Pending Admin'; ?>
                    <?php endif; ?>
                </span>
            </h1>
            <?php if ($myEmpData): ?>
            <p class="mb-0 text-muted small mt-1">
                <i class="fas fa-user-circle mr-1"></i>
                <strong><?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?></strong>
                <?php if (!empty($myEmpData['position'])): ?>• <?php echo htmlspecialchars($myEmpData['position']); ?><?php endif; ?>
                • Week: <b><?php echo date('l, M j', strtotime($weekStart)); ?></b> to
                <b><?php echo date('l, M j, Y', strtotime($weekEnd)); ?></b>
            </p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap align-items-center mt-2">
            <?php if ($isAdmin && $allEmployees): ?>
            <select class="form-control form-control-sm mr-2" style="width:auto;"
                    onchange="window.location='schedule.php?week=<?php echo urlencode($weekStart); ?>&employee_id='+this.value;">
                <option value="">(Select employee)</option>
                <?php foreach ($allEmployees as $e): ?>
                <option value="<?php echo (int)$e['id']; ?>" <?php echo $e['id'] == $staffEmpId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="btn-group btn-group-sm mr-2">
                <a class="btn btn-outline-secondary" href="schedule.php<?php echo $empParamUrl($prevWeek); ?>"><i class="fas fa-chevron-left"></i> Prev Week</a>
                <a class="btn btn-outline-primary <?php echo $weekStart === $thisWeek ? 'active' : ''; ?>" href="schedule.php<?php echo $empParamUrl($thisWeek); ?>"><i class="fas fa-calendar-day mr-1"></i>This Week</a>
                <a class="btn btn-outline-secondary" href="schedule.php<?php echo $empParamUrl($nextWeek); ?>">Next Week <i class="fas fa-chevron-right"></i></a>
            </div>
            <?php if ($isAdmin): ?>
            <a class="btn btn-sm btn-primary shadow-sm" href="shifts.php?week=<?php echo urlencode($weekStart); ?>"><i class="fas fa-edit mr-1"></i>Edit in Scheduler</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$staffEmpId || !$myEmpData): ?>
        <?php if ($isAdmin): ?>
        <div class="alert alert-info shadow-sm"><i class="fas fa-hand-pointer mr-2"></i>Please select a staff member from the <strong>(Select employee)</strong> dropdown above to view their weekly schedule, or go to <a href="shifts.php" class="font-weight-bold alert-link">Shift Schedule</a> to assign shifts.</div>
        <?php else: ?>
        <div class="alert alert-info shadow-sm"><i class="fas fa-info-circle mr-1"></i>No linked employee record found for this account. Please contact the administrator.</div>
        <?php endif; ?>
    <?php else: ?>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-sm-flex align-items-center justify-content-between flex-wrap">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="far fa-calendar-alt mr-1"></i>
                Weekly Roster — <b><?php echo date('M j', strtotime($weekStart)); ?> – <?php echo date('M j, Y', strtotime($weekEnd)); ?></b>
            </h6>
            <div class="mt-1 mt-sm-0">
            <?php if ($weekPublish['published']): ?>
                <span class="badge badge-success badge-pill px-3 py-1"><i class="fas fa-paper-plane mr-1"></i>Sent to Staff</span>
            <?php elseif ($isAdmin): ?>
                <span class="badge badge-warning badge-pill px-3 py-1"><i class="fas fa-pen mr-1"></i>Draft (not sent)</span>
            <?php else: ?>
                <span class="badge badge-light text-muted border px-3 py-1"><i class="fas fa-hourglass-half mr-1"></i>Awaiting schedule from Admin</span>
            <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" style="min-width:860px;">
                    <thead class="bg-light">
                        <tr>
                            <th style="width:140px;" class="text-center small">Day</th>
                            <th class="text-center small">Shift</th>
                            <th style="width:180px;" class="text-center small">Time In / Out</th>
                            <th style="width:140px;" class="text-center small">Hours</th>
                            <th class="text-center small">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dayHeaders as $dow => $d):
                            // NEW FORMAT: $shiftsList is ARRAY of shifts per day (can have Morning + Afternoon on same day = double shift)
                            $shiftsList = !empty($shiftsData[$dow]) ? $shiftsData[$dow] : [];
                            $hasAny = !empty($shiftsList);
                            // If staff view + not published → mask as empty
                            if (!$weekPublish['published'] && !$isAdmin) { $shiftsList = []; $hasAny = false; }

                            $rowspan = max(1, count($shiftsList));
                            $firstRow = true;

                            if (!$hasAny):
                                // Single-row placeholder (no assignments for this day)
                        ?>
                        <tr class="<?php echo $d['isToday'] ? 'table-primary' : ''; ?>">
                            <td rowspan="1" class="text-nowrap text-center align-middle">
                                <div class="font-weight-bold <?php echo $d['isToday'] ? 'text-primary' : 'text-gray-700'; ?>"><?php echo $d['label']; ?></div>
                                <div class="text-xs text-muted"><?php echo $d['sub']; ?></div>
                                <?php if ($d['isToday']): ?><span class="badge badge-primary mt-1">Today</span><?php endif; ?>
                            </td>
                            <td class="text-center align-middle">
                                <?php if (!$weekPublish['published'] && !$isAdmin): ?>
                                <span class="text-gray-400 small"><i class="fas fa-lock mr-1"></i>Not published yet</span>
                                <?php else: ?>
                                <span class="text-gray-400 small"><i class="far fa-calendar-times mr-1"></i>No shift</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle"><span class="text-gray-400 small">-</span></td>
                            <td class="text-center align-middle"><span class="text-gray-400 small">-</span></td>
                            <td class="align-middle"><span class="text-gray-400 small">-</span></td>
                        </tr>
                        <?php
                            else:
                                // One row per shift (morning, afternoon, night all rendered; day label only on first with rowspan=total)
                                foreach ($shiftsList as $rowIdx => $s):
                                    $label = $s['shift_label'] ?? '';
                                    $rowKey = $s['shift_row'] ?? '';
                                    $color = $shiftColors[$rowKey] ?? '#858796';
                                    $sin = $s['shift_in'] ?? null;
                                    $sout = $s['shift_out'] ?? null;
                                    $hoursStr = '-';
                                    if ($sin && $sout) {
                                        $h = (strtotime($sout) - strtotime($sin)) / 3600;
                                        if ($h < 0) { $h += 24; }
                                        $hoursStr = number_format($h, 2) . ' hrs';
                                    }
                                    $isOff = (strcasecmp($label ?? '', 'Off') === 0 || strcasecmp($rowKey ?? '', 'Off') === 0);
                        ?>
                        <tr class="<?php echo $d['isToday'] ? 'table-primary' : ''; ?>">
                            <?php if ($firstRow): $firstRow = false; ?>
                            <td rowspan="<?php echo $rowspan; ?>" class="text-nowrap text-center align-middle">
                                <div class="font-weight-bold <?php echo $d['isToday'] ? 'text-primary' : 'text-gray-700'; ?>"><?php echo $d['label']; ?></div>
                                <div class="text-xs text-muted"><?php echo $d['sub']; ?></div>
                                <?php if ($d['isToday']): ?><span class="badge badge-primary mt-1">Today</span><?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center align-middle">
                                <?php if ($label):
                                    $branch = in_array(($s['branch'] ?? 'Branch 1'), ['Branch 1','Branch 2'], true) ? ($s['branch'] ?? 'Branch 1') : 'Branch 1';
                                    $brColor = $branch === 'Branch 2' ? '#6f42c1' : '#ca7d2a';
                                ?>
                                <span class="badge px-3 py-2 text-sm"
                                      style="background:<?php echo $isOff ? '#e74a3b' : htmlspecialchars($color); ?>;color:#fff;">
                                    <?php if ($isOff): ?><i class="fas fa-umbrella-beach mr-1"></i><?php endif; ?>
                                    <?php echo htmlspecialchars($label); ?>
                                </span>
                                <?php if (!$isOff): ?>
                                <span class="d-block mt-1">
                                    <span class="badge badge-pill text-white" style="background:<?php echo $brColor; ?>;">
                                        <i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($branch); ?>
                                    </span>
                                </span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle text-nowrap">
                                <?php if (!$isOff && $sin): ?>
                                <div class="font-weight-bold">
                                    <?php echo $sin ? date('g:i A', strtotime($sin)) : 'TBA'; ?>
                                    <small class="text-gray-500 mx-1">→</small>
                                    <?php echo $sout ? date('g:i A', strtotime($sout)) : 'TBA'; ?>
                                </div>
                                <?php elseif ($isOff): ?>
                                <div class="text-danger small">🌴 Rest Day</div>
                                <?php else: ?>
                                <div class="text-gray-400 small">-</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle text-nowrap">
                                <?php if (!$isOff && $hoursStr !== '-'): ?>
                                <strong class="text-gray-800"><?php echo $hoursStr; ?></strong>
                                <?php elseif ($isOff): ?>
                                <span class="text-danger small">0.00 hrs</span>
                                <?php else: ?>
                                <span class="text-gray-400 small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <?php if (!empty($s['notes'])): ?>
                                <div class="small"><i class="fas fa-sticky-note text-warning mr-1"></i><?php echo htmlspecialchars($s['notes']); ?></div>
                                <?php else: ?>
                                <span class="text-gray-400 small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                                endforeach;
                            endif;
                            endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Quick Summary</div>
                            <?php
                            // Quick Summary: now ITERATES ALL SHIFT ENTRIES per day (not just 1/day!)
                            $workDays = 0; $restDays = 0; $totalHours = 0; $shiftsTotal = 0;
                            foreach ($dayHeaders as $dow => $d) {
                                if (empty($shiftsData[$dow])) { continue; }
                                $list = $shiftsData[$dow];
                                $dayHadWork = false;
                                $dayHadRest = false;
                                foreach ($list as $s) {
                                    $lbl = $s['shift_label'] ?? '';
                                    $rk = $s['shift_row'] ?? '';
                                    $off = (strcasecmp($lbl, 'Off') === 0 || strcasecmp($rk, 'Off') === 0);
                                    if ($off) { $dayHadRest = true; continue; }
                                    $shiftsTotal++;
                                    if (!empty($s['shift_in']) && !empty($s['shift_out'])) {
                                        $h = (strtotime($s['shift_out']) - strtotime($s['shift_in'])) / 3600;
                                        if ($h < 0) $h += 24;
                                        $totalHours += $h;
                                        $dayHadWork = true;
                                    } else {
                                        $dayHadWork = true;
                                    }
                                }
                                if ($dayHadRest && !$dayHadWork) $restDays++;
                                if ($dayHadWork) $workDays++;
                            }
                            ?>
                            <div class="h6 mb-0 font-weight-bold text-gray-800">
                                <i class="fas fa-briefcase mr-1"></i><?php echo $workDays; ?> working days
                                <small class="mx-2 text-gray-400">|</small>
                                <i class="fas fa-umbrella-beach mr-1 text-danger"></i><?php echo $restDays; ?> rest
                                <small class="mx-2 text-gray-400">|</small>
                                <i class="fas fa-clock mr-1"></i><?php echo number_format($totalHours, 1); ?> hrs
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-clipboard-list fa-2x text-info opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1 d-flex align-items-center justify-content-between">
                                <span><i class="fas fa-exchange-alt mr-1"></i> Need to swap / request change?</span>
                                <?php if ($pendingRequestsCount > 0): ?>
                                <span class="badge badge-warning badge-pill"><i class="fas fa-clock mr-1"></i><?php echo $pendingRequestsCount; ?> Pending</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isAdmin): ?>
                            <p class="text-gray-700 small mb-2">
                                May concern sa schedule o gustong makipag-palit ng shift? Magpadala ng request message direkta sa Admin.
                            </p>
                            <div class="d-flex flex-wrap align-items-center">
                                <button type="button" class="btn btn-sm btn-success shadow-sm mr-2 mb-1" data-toggle="modal" data-target="#sendScheduleRequestModal">
                                    <i class="fas fa-paper-plane mr-1"></i> Send Request / Message
                                </button>
                                <?php if (!empty($scheduleRequests)): ?>
                                <a href="#schedule-requests-section" class="btn btn-sm btn-outline-info mr-2 mb-1">
                                    <i class="fas fa-history mr-1"></i> My Requests (<?php echo count($scheduleRequests); ?>)
                                </a>
                                <?php endif; ?>
                                <a href="index.php" class="btn btn-sm btn-light border mb-1">
                                    <i class="fas fa-home mr-1"></i> Dashboard
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-gray-700 small mb-2">
                                <?php if ($pendingRequestsCount > 0): ?>
                                <span class="badge badge-warning px-2 py-1"><i class="fas fa-exclamation-circle mr-1"></i><?php echo $pendingRequestsCount; ?> Pending Request(s)</span> para sa empleyadong ito ngayong linggo.
                                <?php else: ?>
                                Walang bagong request o shift concern mula sa empleyadong ito para sa linggong ito.
                                <?php endif; ?>
                            </p>
                            <div class="d-flex flex-wrap align-items-center">
                                <?php if (!empty($scheduleRequests)): ?>
                                <a href="#schedule-requests-section" class="btn btn-sm btn-info shadow-sm mr-2 mb-1">
                                    <i class="fas fa-eye mr-1"></i> Review Requests (<?php echo count($scheduleRequests); ?>)
                                </a>
                                <?php endif; ?>
                                <a href="shifts.php?week=<?php echo urlencode($weekStart); ?>" class="btn btn-sm btn-primary shadow-sm mr-2 mb-1">
                                    <i class="fas fa-edit mr-1"></i> Open Scheduler
                                </a>
                                <a href="index.php" class="btn btn-sm btn-light border mb-1">
                                    <i class="fas fa-home mr-1"></i> Dashboard
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-comments fa-2x text-success opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SCHEDULE CONCERNS & REQUESTS LOG -->
    <div id="schedule-requests-section" class="card shadow mb-4 border-left-info">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between bg-white">
            <h6 class="m-0 font-weight-bold text-primary d-flex align-items-center">
                <i class="fas fa-comments text-info mr-2"></i>
                Shift Concerns &amp; Requests History
                <span class="ml-2 badge badge-pill badge-secondary small font-weight-normal">
                    <?php echo count($scheduleRequests); ?> record(s)
                </span>
            </h6>
            <div class="mt-2 mt-sm-0">
                <?php if (!$isAdmin): ?>
                <button type="button" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#sendScheduleRequestModal">
                    <i class="fas fa-paper-plane mr-1"></i> New Request / Message
                </button>
                <?php else: ?>
                <span class="text-xs text-muted">Weekly shift requests and concerns for this staff</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($scheduleRequests)): ?>
            <div class="text-center py-4 px-3 text-muted">
                <i class="far fa-comments fa-2x mb-2 text-gray-300 d-block"></i>
                <div class="font-weight-bold text-gray-600">Walang request o message para sa linggong ito.</div>
                <div class="small mt-1 text-gray-500">
                    <?php if (!$isAdmin): ?>
                    Kung may kailangan kang i-swap o concern sa schedule, i-click ang <b>Send Request / Message</b> sa taas para ma-notify ang Admin.
                    <?php else: ?>
                    Wala pang isinusumiteng shift swap o schedule concern ang empleyadong ito para sa linggong ito.
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-gray-700 small">
                        <tr>
                            <th style="width: 140px;">Date Sent</th>
                            <th style="width: 180px;">Type / Target</th>
                            <th>Staff Concern / Message</th>
                            <th style="width: 130px;" class="text-center">Status</th>
                            <th>Admin Response</th>
                            <th style="width: 110px;" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduleRequests as $req):
                            $st = $req['status'] ?? 'pending';
                            $badgeClass = 'badge-warning';
                            $badgeIcon = 'fa-hourglass-half';
                            $badgeText = 'Pending';
                            if ($st === 'approved') {
                                $badgeClass = 'badge-success';
                                $badgeIcon = 'fa-check-circle';
                                $badgeText = 'Approved';
                            } elseif ($st === 'declined') {
                                $badgeClass = 'badge-danger';
                                $badgeIcon = 'fa-times-circle';
                                $badgeText = 'Declined';
                            } elseif ($st === 'resolved') {
                                $badgeClass = 'badge-info';
                                $badgeIcon = 'fa-check-double';
                                $badgeText = 'Resolved';
                            }
                            $typeIcon = 'fa-exchange-alt';
                            if (stripos($req['request_type'], 'Rest') !== false || stripos($req['request_type'], 'Leave') !== false) {
                                $typeIcon = 'fa-umbrella-beach';
                            } elseif (stripos($req['request_type'], 'Time') !== false || stripos($req['request_type'], 'Adjust') !== false) {
                                $typeIcon = 'fa-clock';
                            } elseif (stripos($req['request_type'], 'Emergency') !== false) {
                                $typeIcon = 'fa-exclamation-triangle';
                            }
                        ?>
                        <tr>
                            <td class="small text-nowrap align-middle">
                                <div class="font-weight-bold text-gray-800"><?php echo date('M j, Y', strtotime($req['created_at'])); ?></div>
                                <div class="text-xs text-muted"><?php echo date('g:i A', strtotime($req['created_at'])); ?></div>
                            </td>
                            <td class="align-middle">
                                <span class="badge badge-light border text-primary px-2 py-1 font-weight-bold text-nowrap">
                                    <i class="fas <?php echo $typeIcon; ?> mr-1"></i><?php echo htmlspecialchars($req['request_type']); ?>
                                </span>
                                <?php if (!empty($req['target_date'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="far fa-calendar-alt mr-1"></i><?php echo date('D, M j', strtotime($req['target_date'])); ?>
                                </div>
                                <?php else: ?>
                                <div class="small text-muted mt-1"><i class="far fa-calendar-week mr-1"></i>Whole Week</div>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <div class="text-gray-800" style="white-space: pre-wrap; font-size:.9rem;"><?php echo htmlspecialchars($req['message']); ?></div>
                            </td>
                            <td class="text-center align-middle text-nowrap">
                                <span class="badge <?php echo $badgeClass; ?> px-2 py-1 font-weight-bold" style="font-size:.82rem;">
                                    <i class="fas <?php echo $badgeIcon; ?> mr-1"></i><?php echo $badgeText; ?>
                                </span>
                            </td>
                            <td class="align-middle">
                                <?php if (!empty($req['admin_reply'])): ?>
                                <div class="small p-2 bg-light rounded border text-gray-800">
                                    <i class="fas fa-comment-dots text-primary mr-1"></i>
                                    <b><?php echo htmlspecialchars($req['reviewer_name'] ?: 'Admin'); ?>:</b>
                                    <span class="text-muted small">(<?php echo !empty($req['reviewed_at']) ? date('M j, g:i A', strtotime($req['reviewed_at'])) : ''; ?>)</span>
                                    <div class="mt-1"><?php echo nl2br(htmlspecialchars($req['admin_reply'])); ?></div>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small font-italic">Awaiting response</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle text-nowrap">
                                <?php if ($isAdmin): ?>
                                <button type="button" class="btn btn-sm btn-primary shadow-sm" data-toggle="modal" data-target="#respondReqModal_<?php echo $req['id']; ?>">
                                    <i class="fas fa-reply mr-1"></i> Respond
                                </button>
                                <?php elseif ($st === 'pending'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Sigurado ka bang nais mong kanselahin ang request na ito?');">
                                    <input type="hidden" name="action" value="cancel_request">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel request">
                                        <i class="fas fa-times mr-1"></i> Cancel
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL: SEND SCHEDULE REQUEST / MESSAGE -->
    <div class="modal fade" id="sendScheduleRequestModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <form method="POST">
                    <input type="hidden" name="action" value="submit_request">
                    <input type="hidden" name="week_start" value="<?php echo htmlspecialchars($weekStart); ?>">
                    <div class="modal-header bg-success text-white py-3">
                        <h5 class="modal-title font-weight-bold">
                            <i class="fas fa-paper-plane mr-2"></i>Send Schedule Concern / Request
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-light border small text-muted mb-3 py-2">
                            <i class="fas fa-info-circle text-success mr-1"></i>
                            Direktang matatanggap ng Admin ang mensaheng ito para sa linggo ng
                            <b><?php echo date('M j', strtotime($weekStart)); ?> – <?php echo date('M j, Y', strtotime($weekEnd)); ?></b>.
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-gray-700">Uri ng Concern / Request <span class="text-danger">*</span></label>
                            <select name="request_type" class="form-control" required>
                                <option value="Shift Swap">🔄 Shift Swap (Makipagpalit ng shift sa kasama)</option>
                                <option value="Schedule Adjustment">⏰ Schedule Adjustment (Pabago ng oras o araw ng pasok)</option>
                                <option value="Rest Day / Leave">🌴 Rest Day / Day-off Request (Hiling na day-off)</option>
                                <option value="Emergency Leave">🚨 Emergency / Urgent Leave</option>
                                <option value="Other Concern">💬 Iba pang Concern / Tanong sa Schedule</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-gray-700">Apektadong Araw / Shift (Opsyonal)</label>
                            <select name="target_date" class="form-control">
                                <option value="">(Buong Linggo / General Concern)</option>
                                <?php foreach ($dayHeaders as $dh): ?>
                                <option value="<?php echo $dh['date']; ?>">
                                    <?php echo $dh['full']; ?> — <?php echo $dh['sub']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group mb-0">
                            <label class="font-weight-bold small text-gray-700">Mensahe / Dahilan <span class="text-danger">*</span></label>
                            <textarea name="message" class="form-control" rows="4" required
                                placeholder="Halimbawa: Gusto ko po sanang makipag-swap ng shift kay [Pangalan] sa Biyernes dahil may emergency lakad..." style="resize:vertical;"></textarea>
                            <small class="form-text text-muted">Pakisabi kung sino ang posibleng kapalitan mo kung shift swap, o ang dahilan ng adjustment.</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-2">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Kanselahin</button>
                        <button type="submit" class="btn btn-success font-weight-bold shadow-sm">
                            <i class="fas fa-paper-plane mr-1"></i> Ipadala sa Admin
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- ADMIN RESPONSE MODALS -->
    <?php foreach ($scheduleRequests as $req): ?>
    <div class="modal fade" id="respondReqModal_<?php echo $req['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <form method="POST">
                    <input type="hidden" name="action" value="respond_request">
                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                    <div class="modal-header bg-primary text-white py-3">
                        <h5 class="modal-title font-weight-bold">
                            <i class="fas fa-reply mr-2"></i>Respond to Staff Request
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3 p-3 bg-light rounded border">
                            <div class="text-xs text-muted mb-1 font-weight-bold text-uppercase">Employee Request Details</div>
                            <div class="font-weight-bold text-primary mb-1">
                                <?php echo htmlspecialchars($req['request_type']); ?>
                                <?php if (!empty($req['target_date'])): ?>
                                <span class="text-muted font-weight-normal">• Target: <?php echo date('D, M j, Y', strtotime($req['target_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-gray-800 small"><?php echo nl2br(htmlspecialchars($req['message'])); ?></div>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-gray-700">Update Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-control" required>
                                <option value="approved" <?php echo $req['status'] === 'approved' ? 'selected' : ''; ?>>✅ Approved</option>
                                <option value="declined" <?php echo $req['status'] === 'declined' ? 'selected' : ''; ?>>❌ Declined</option>
                                <option value="resolved" <?php echo $req['status'] === 'resolved' ? 'selected' : ''; ?>>ℹ️ Resolved / Noted</option>
                                <option value="pending" <?php echo $req['status'] === 'pending' ? 'selected' : ''; ?>>⏳ Keep as Pending</option>
                            </select>
                        </div>

                        <div class="form-group mb-0">
                            <label class="font-weight-bold small text-gray-700">Admin Response / Note</label>
                            <textarea name="admin_reply" class="form-control" rows="3"
                                placeholder="Halimbawa: Approved. Na-adjust na ang shift mo sa scheduler."><?php echo htmlspecialchars($req['admin_reply'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">Makikita ito ng empleyado sa kanyang My Schedule page.</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-2">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold shadow-sm">
                            <i class="fas fa-check mr-1"></i> Save Response
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
