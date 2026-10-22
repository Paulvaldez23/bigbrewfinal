<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_once 'includes/attendance_helper.php';
require_login();

$isAdmin = is_admin();
$userId = (int)($_SESSION['user_id'] ?? 0);
$staffEmpId = null;
$myEmpData = null;

$actionMsg = '';
$actionType = '';

// Ensure attendance_requests table exists
if ($conn) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS attendance_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attendance_id INT NULL,
            employee_id INT UNSIGNED NOT NULL,
            attendance_date DATE NOT NULL,
            requested_time_in TIME NULL,
            requested_time_out TIME NULL,
            current_time_in TIME NULL,
            current_time_out TIME NULL,
            request_type ENUM('time_in', 'time_out', 'both') NOT NULL DEFAULT 'time_out',
            reason VARCHAR(255) NULL,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            admin_notes VARCHAR(255) NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_emp (employee_id),
            KEY idx_status (status),
            KEY idx_date (attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

if (!$isAdmin && $conn) {
    try {
        $stmt = $conn->prepare("SELECT employee_id FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $link = $stmt->fetch();
        if ($link && !empty($link['employee_id'])) {
            $staffEmpId = (int)$link['employee_id'];
            $empStmt = $conn->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
            $empStmt->execute([$staffEmpId]);
            $myEmpData = $empStmt->fetch();
        }
    } catch (Exception $e) {}
}

// -------------------------------------------------------------
// POST HANDLERS: STAFF PUNCH REQUESTS & ADMIN LOGBOOK APPROVAL
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $action = $_POST['action'] ?? '';

    // 1. Staff requests Missing Time In or Time Out
    if ($action === 'request_punch' && !$isAdmin && $staffEmpId) {
        $attId = !empty($_POST['attendance_id']) ? (int)$_POST['attendance_id'] : null;
        $attDate = trim($_POST['attendance_date'] ?? '');
        $reqType = trim($_POST['request_type'] ?? 'time_out');
        $reqIn = !empty($_POST['requested_time_in']) ? trim($_POST['requested_time_in']) : null;
        $reqOut = !empty($_POST['requested_time_out']) ? trim($_POST['requested_time_out']) : null;
        $curIn = !empty($_POST['current_time_in']) ? trim($_POST['current_time_in']) : null;
        $curOut = !empty($_POST['current_time_out']) ? trim($_POST['current_time_out']) : null;
        $reason = trim($_POST['reason'] ?? '');

        if ($attDate && ($reqIn || $reqOut)) {
            try {
                // Cancel any previous pending request for the same attendance/date
                if ($attId) {
                    $delStmt = $conn->prepare("DELETE FROM attendance_requests WHERE attendance_id = ? AND status = 'pending'");
                    $delStmt->execute([$attId]);
                } else {
                    $delStmt = $conn->prepare("DELETE FROM attendance_requests WHERE employee_id = ? AND attendance_date = ? AND status = 'pending'");
                    $delStmt->execute([$staffEmpId, $attDate]);
                }

                $insStmt = $conn->prepare("INSERT INTO attendance_requests 
                    (attendance_id, employee_id, attendance_date, requested_time_in, requested_time_out, current_time_in, current_time_out, request_type, reason, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $insStmt->execute([$attId, $staffEmpId, $attDate, $reqIn, $reqOut, $curIn, $curOut, $reqType, $reason]);

                $actionMsg = "Time request submitted! Admin will verify against the logbook before approving.";
                $actionType = 'success';
            } catch (Exception $e) {
                $actionMsg = "Error submitting request: " . $e->getMessage();
                $actionType = 'danger';
            }
        } else {
            $actionMsg = "Please specify a valid time.";
            $actionType = 'warning';
        }
    }

    // 2. Admin Approves Staff Punch Request (Verified via Logbook Modal)
    elseif ($action === 'approve_attendance_request' && $isAdmin) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        try {
            $rStmt = $conn->prepare("SELECT ar.*, e.first_name, e.last_name FROM attendance_requests ar JOIN employees e ON ar.employee_id = e.id WHERE ar.id = ? AND ar.status = 'pending' LIMIT 1");
            $rStmt->execute([$reqId]);
            $req = $rStmt->fetch();

            if ($req) {
                $empId = (int)$req['employee_id'];
                $attDate = $req['attendance_date'];
                $attId = $req['attendance_id'];
                $reqIn = !empty($_POST['verified_time_in']) ? trim($_POST['verified_time_in']) : $req['requested_time_in'];
                $reqOut = !empty($_POST['verified_time_out']) ? trim($_POST['verified_time_out']) : $req['requested_time_out'];
                $empName = $req['first_name'] . ' ' . $req['last_name'];

                // Check existing attendance row
                if ($attId) {
                    $currStmt = $conn->prepare("SELECT * FROM attendance WHERE id = ? LIMIT 1");
                    $currStmt->execute([$attId]);
                    $currAtt = $currStmt->fetch();
                } else {
                    $currStmt = $conn->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
                    $currStmt->execute([$empId, $attDate]);
                    $currAtt = $currStmt->fetch();
                }

                $finalIn = $reqIn ?: ($currAtt['time_in'] ?? null);
                $finalOut = $reqOut ?: ($currAtt['time_out'] ?? null);
                $finalHours = calculate_hours_worked($finalIn, $finalOut);
                $finalReg = $finalHours !== null ? min((float)$finalHours, 8.0) : null;
                $rem = !empty($currAtt['remarks']) ? $currAtt['remarks'] . ' • Logbook Verified' : 'Logbook Verified';

                if ($currAtt) {
                    $upd = $conn->prepare("UPDATE attendance SET time_in = ?, time_out = ?, hours_worked = ?, regular_hours = ?, source = 'logbook', remarks = ? WHERE id = ?");
                    $upd->execute([$finalIn, $finalOut, $finalHours, $finalReg, $rem, $currAtt['id']]);
                } else {
                    $ins = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, hours_worked, regular_hours, source, remarks, status) VALUES (?, ?, ?, ?, ?, ?, 'logbook', ?, 'present')");
                    $ins->execute([$empId, $attDate, $finalIn, $finalOut, $finalHours, $finalReg, $rem]);
                }

                // Mark request and any duplicates approved
                $updReq = $conn->prepare("UPDATE attendance_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE (id = ? OR (employee_id = ? AND attendance_date = ?)) AND status = 'pending'");
                $updReq->execute([$userId, $reqId, $empId, $attDate]);

                $timeDisplay = ($finalOut ? "Time Out: " . date('h:i A', strtotime($finalOut)) : '') . ($finalIn ? " Time In: " . date('h:i A', strtotime($finalIn)) : '');
                $actionMsg = "Accepted & Verified! {$empName}'s attendance for " . date('M d, Y', strtotime($attDate)) . " updated ({$timeDisplay}). Hours: " . number_format((float)$finalHours, 2) . " hrs.";
                $actionType = 'success';
            }
        } catch (Exception $e) {
            $actionMsg = "Error approving request: " . $e->getMessage();
            $actionType = 'danger';
        }
    }

    // 3. Admin Rejects Staff Punch Request (From Decline Modal)
    elseif ($action === 'reject_attendance_request' && $isAdmin) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? 'Declined by Admin based on physical logbook check');
        try {
            $rStmt = $conn->prepare("SELECT ar.*, e.first_name, e.last_name FROM attendance_requests ar JOIN employees e ON ar.employee_id = e.id WHERE ar.id = ? LIMIT 1");
            $rStmt->execute([$reqId]);
            $req = $rStmt->fetch();
            $empName = $req ? ($req['first_name'] . ' ' . $req['last_name']) : 'Staff';

            $updReq = $conn->prepare("UPDATE attendance_requests SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $updReq->execute([$adminNotes, $userId, $reqId]);
            $actionMsg = "Declined punch request for {$empName}. Reason: {$adminNotes}";
            $actionType = 'info';
        } catch (Exception $e) {
            $actionMsg = "Error rejecting request: " . $e->getMessage();
            $actionType = 'danger';
        }
    }

    // 4. Admin Directly Sets Time In / Time Out from Logbook
    elseif ($action === 'admin_direct_update_time' && $isAdmin) {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $tin = !empty($_POST['time_in']) ? trim($_POST['time_in']) : null;
        $tout = !empty($_POST['time_out']) ? trim($_POST['time_out']) : null;
        try {
            $currStmt = $conn->prepare("SELECT * FROM attendance WHERE id = ? LIMIT 1");
            $currStmt->execute([$attId]);
            $curr = $currStmt->fetch();
            if ($curr) {
                $finalHours = calculate_hours_worked($tin, $tout);
                $finalReg = $finalHours !== null ? min((float)$finalHours, 8.0) : null;
                $rem = !empty($curr['remarks']) ? $curr['remarks'] : 'Admin Logbook Entry';

                $upd = $conn->prepare("UPDATE attendance SET time_in = ?, time_out = ?, hours_worked = ?, regular_hours = ?, source = 'logbook', remarks = ? WHERE id = ?");
                $upd->execute([$tin, $tout, $finalHours, $finalReg, $rem, $attId]);

                // Also auto-approve any pending request for this attendance record
                try {
                    $conn->prepare("UPDATE attendance_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE (attendance_id = ? OR (employee_id = ? AND attendance_date = ?)) AND status = 'pending'")
                         ->execute([$userId, $attId, $curr['employee_id'], $curr['attendance_date']]);
                } catch (Exception $_re) {}

                $actionMsg = "Attendance time updated successfully! Total hours: " . number_format((float)$finalHours, 2) . " hrs.";
                $actionType = 'success';
            }
        } catch (Exception $e) {
            $actionMsg = "Error updating attendance time: " . $e->getMessage();
            $actionType = 'danger';
        }
    }

    // 5. Admin Sets Daily Overtime
    elseif ($action === 'set_ot' && $isAdmin) {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $otH = max(0, (float)($_POST['ot_hours'] ?? 0));
        $isApp = ($otH > 0) ? 1 : 0;
        try {
            $upd = $conn->prepare("UPDATE attendance SET ot_hours = ?, is_ot_approved = ? WHERE id = ?");
            $upd->execute([$otH, $isApp, $attId]);
            $actionMsg = "Daily Overtime updated to " . number_format($otH, 2) . " hrs (" . ($isApp ? 'Approved' : 'No OT') . ").";
            $actionType = 'success';
        } catch (Exception $e) {
            $actionMsg = "Error updating overtime: " . $e->getMessage();
            $actionType = 'danger';
        }
    }
}

// ---------- Weekly archive / filter logic ----------
$todayObj = new DateTime('today');
$todayDow = (int)$todayObj->format('N'); // 1=Mon ... 7=Sun
$thisWeekStart = (clone $todayObj)->sub(new DateInterval('P' . ($todayDow - 1) . 'D'));
$thisWeekStartStr = $thisWeekStart->format('Y-m-d');

$weekStartStr = $_GET['week_start'] ?? $thisWeekStartStr;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartStr)) $weekStartStr = $thisWeekStartStr;
try {
    $weekStartObj = new DateTime($weekStartStr);
    $dow = (int)$weekStartObj->format('N');
    if ($dow !== 1) $weekStartObj->sub(new DateInterval('P' . ($dow - 1) . 'D'));
} catch (Exception $e) {
    $weekStartObj = clone $thisWeekStart;
}
$weekStartStr = $weekStartObj->format('Y-m-d');
$weekEndObj = (clone $weekStartObj)->add(new DateInterval('P6D'));
$weekEndStr = $weekEndObj->format('Y-m-d');

$weekStartDisplay = $weekStartObj->format('F j, Y');
$weekEndDisplay = $weekEndObj->format('F j, Y');
$weekLabel = $weekStartObj->format('M j') . ' – ' . $weekEndObj->format('M j, Y');

$isThisWeek = ($weekStartStr === $thisWeekStartStr);
$isPastWeek = $weekEndObj < $todayObj;
$isFutureWeek = $weekStartObj > $todayObj;

$prevWeekObj = (clone $weekStartObj)->sub(new DateInterval('P7D'));
$nextWeekObj = (clone $weekStartObj)->add(new DateInterval('P7D'));
$selfUrl = 'attendance.php';

$page_title = $isAdmin ? 'Big Brew - Attendance' : 'Big Brew - My Attendance';
$active_nav = 'attendance';
$extra_css = ['assets/vendor/datatables/dataTables.bootstrap4.min.css'];
$extra_js = [
    'assets/vendor/datatables/jquery.dataTables.min.js',
    'assets/vendor/datatables/dataTables.bootstrap4.min.js'
];
$colDefs = $isAdmin
    ? "columnDefs:[{width:'120px',targets:0},{width:'180px',targets:1},{width:'125px',targets:2},{width:'125px',targets:3},{width:'150px',targets:4},{width:'90px',targets:5},{width:'95px',targets:6},{width:'220px',targets:7}]"
    : "columnDefs:[{width:'130px',targets:0},{width:'125px',targets:1},{width:'125px',targets:2},{width:'135px',targets:3},{width:'95px',targets:4},{width:'90px',targets:5},{width:'220px',targets:6}]";
$orderCol = 0;
$extra_script = "$(function(){ if (typeof $.fn.DataTable === 'function') { try { $('#dataTable').DataTable({order:[[$orderCol,'asc']],scrollX:true,autoWidth:false,pageLength:25,$colDefs}); } catch(e) {} } });";

$attendance = [];
$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'total_hours' => 0, 'days_with_records' => 0, 'total_days_in_week' => 7, 'total_emp_days' => 0, 'records' => 0];
$weekBadge = '';
if ($isThisWeek) $weekBadge = '<span class="badge badge-success ml-2"><i class="fas fa-star mr-1"></i>Current Week</span>';
elseif ($isPastWeek) $weekBadge = '<span class="badge badge-secondary ml-2"><i class="fas fa-archive mr-1"></i>Archived Week</span>';
elseif ($isFutureWeek) $weekBadge = '<span class="badge badge-warning ml-2"><i class="fas fa-calendar-plus mr-1"></i>Upcoming Week</span>';

$pendingRequests = [];
$staffPendingMap = [];

if ($conn) {
    try {
        $params = [$weekStartStr, $weekEndStr];
        if ($isAdmin) {
            $sql = "SELECT a.*, e.employee_id as emp_code, e.first_name, e.last_name
                FROM attendance a
                LEFT JOIN employees e ON a.employee_id = e.id
                WHERE a.attendance_date BETWEEN ? AND ?
                ORDER BY a.attendance_date ASC, e.first_name ASC, a.id ASC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            // Auto-sync: approve any pending request where attendance record is already complete with time in and out
            try {
                $conn->exec("UPDATE attendance_requests ar 
                    JOIN attendance a ON (ar.attendance_id = a.id OR (ar.employee_id = a.employee_id AND ar.attendance_date = a.attendance_date))
                    SET ar.status = 'approved', ar.reviewed_at = NOW()
                    WHERE ar.status = 'pending' AND a.time_in IS NOT NULL AND a.time_out IS NOT NULL");
            } catch (Exception $_se) {}

            // Fetch all pending requests for admin review
            $pReqStmt = $conn->query("SELECT ar.*, e.employee_id as emp_code, e.first_name, e.last_name
                FROM attendance_requests ar
                JOIN employees e ON ar.employee_id = e.id
                WHERE ar.status = 'pending'
                ORDER BY ar.attendance_date DESC, ar.created_at ASC");
            $pendingRequests = $pReqStmt->fetchAll() ?: [];
        } elseif ($staffEmpId) {
            $sql = "SELECT a.*
                FROM attendance a
                WHERE a.employee_id = ? AND a.attendance_date BETWEEN ? AND ?
                ORDER BY a.attendance_date ASC, a.id ASC";
            $stmt = $conn->prepare($sql);
            array_unshift($params, $staffEmpId);
            $stmt->execute($params);

            // Fetch staff's pending requests
            $spStmt = $conn->prepare("SELECT * FROM attendance_requests WHERE employee_id = ? AND status = 'pending'");
            $spStmt->execute([$staffEmpId]);
            $rawSP = $spStmt->fetchAll() ?: [];
            foreach ($rawSP as $sp) {
                if (!empty($sp['attendance_id'])) {
                    $staffPendingMap['att_' . $sp['attendance_id']] = $sp;
                }
                $staffPendingMap['date_' . $sp['attendance_date']] = $sp;
            }
        } else {
            $sql = null;
        }
        if (isset($stmt)) $attendance = $stmt->fetchAll();
    } catch (Exception $e) {}
}

if ($attendance) {
    $seenKeys = [];
    foreach ($attendance as $a) {
        $st = strtolower($a['status'] ?? 'present');
        if (strpos($st, 'late') !== false) $summary['late']++;
        elseif ($st === 'absent') $summary['absent']++;
        else $summary['present']++;

        $raw = (float)($a['hours_worked'] ?? 0);
        $reg = min($raw, 8.0);
        $ot = !empty($a['is_ot_approved']) ? (float)($a['ot_hours'] ?? 0) : 0;
        $summary['total_hours'] += ($reg + $ot);

        $key = ($a['employee_id'] ?? '0') . '|' . ($a['attendance_date'] ?? '');
        if (!isset($seenKeys[$key])) {
            $seenKeys[$key] = true;
            $summary['total_emp_days']++;
        }
    }
    $summary['records'] = count($attendance);
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <?php if ($actionMsg): ?>
    <div class="alert alert-<?php echo htmlspecialchars($actionType); ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-<?php echo $actionType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> mr-1"></i>
        <?php echo htmlspecialchars($actionMsg); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <?php endif; ?>

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="fas fa-clipboard-check text-primary mr-2"></i>
                <?php echo $isAdmin ? 'Attendance Records' : 'My Attendance'; ?>
                <?php echo $weekBadge; ?>
            </h1>
            <p class="text-xs text-muted mb-0 mt-1">
                <i class="fas fa-info-circle mr-1"></i>
                <?php if ($isAdmin): ?>
                Verify staff missing punch requests against physical logbook before accepting. Regular hours capped at 8h/day.
                <?php else: ?>
                If your Time In or Time Out is missing, click <strong>Input Time Out / In</strong> to request verification via physical logbook.
                <?php endif; ?>
            </p>
        </div>
        <div>
            <?php if (!$isAdmin && $myEmpData): ?>
            <div class="text-sm text-muted mr-3 d-inline-block"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?></div>
            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" data-toggle="modal" data-target="#newPunchRequestModal">
                <i class="fas fa-plus-circle mr-1"></i>Request Missing Punch
            </button>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <a href="upload_attendance.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm mr-2"><i class="fas fa-upload fa-sm text-white-50"></i> Upload CSV</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- ADMIN: PENDING ATTENDANCE LOGBOOK VERIFICATION BANNER / TABLE -->
    <!-- ============================================================== -->
    <?php if ($isAdmin && $pendingRequests): ?>
    <div class="card border-left-warning shadow mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-warning">
                <i class="fas fa-book-open mr-2"></i>Pending Punch Corrections — For Logbook Verification
                <span class="badge badge-warning text-dark font-weight-bold ml-1"><?php echo count($pendingRequests); ?> pending</span>
            </h6>
            <small class="text-muted"><i class="fas fa-check-double mr-1"></i>Check physical store logbook before accepting staff punch requests.</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0 text-nowrap">
                    <thead class="bg-light text-center small font-weight-bold text-gray-700">
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Request Type</th>
                            <th>Current Times</th>
                            <th class="bg-warning text-dark">Requested Time</th>
                            <th>Reason / Note</th>
                            <th class="text-center">Action (Verify with Logbook)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $pr):
                            $curInDisp = $pr['current_time_in'] ? date('h:i A', strtotime($pr['current_time_in'])) : '-';
                            $curOutDisp = $pr['current_time_out'] ? date('h:i A', strtotime($pr['current_time_out'])) : '-';
                            $reqTimeDisp = '';
                            if ($pr['requested_time_out']) $reqTimeDisp .= 'Out: <strong>' . date('h:i A', strtotime($pr['requested_time_out'])) . '</strong>';
                            if ($pr['requested_time_in']) $reqTimeDisp .= ($reqTimeDisp ? ' • ' : '') . 'In: <strong>' . date('h:i A', strtotime($pr['requested_time_in'])) . '</strong>';
                        ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo date('M d, Y (D)', strtotime($pr['attendance_date'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name']); ?></strong>
                                <span class="text-xs text-muted ml-1">(<?php echo htmlspecialchars($pr['emp_code'] ?? 'ID:'.$pr['employee_id']); ?>)</span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $pr['request_type'] === 'time_out' ? 'primary' : 'info'; ?>">
                                    <?php echo $pr['request_type'] === 'time_out' ? 'Missing Time Out' : ($pr['request_type'] === 'time_in' ? 'Missing Time In' : 'In & Out'); ?>
                                </span>
                            </td>
                            <td class="small text-muted">In: <?php echo $curInDisp; ?> | Out: <?php echo $curOutDisp; ?></td>
                            <td class="text-primary"><?php echo $reqTimeDisp; ?></td>
                            <td class="small text-truncate" style="max-width:200px;" title="<?php echo htmlspecialchars($pr['reason'] ?? ''); ?>">
                                <?php echo htmlspecialchars($pr['reason'] ?: 'No notes provided'); ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <button type="button" 
                                    class="btn btn-xs btn-success font-weight-bold px-2 py-1 shadow-sm btn-open-accept-modal"
                                    data-id="<?php echo (int)$pr['id']; ?>"
                                    data-emp="<?php echo htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name']); ?>"
                                    data-date="<?php echo date('M d, Y (D)', strtotime($pr['attendance_date'])); ?>"
                                    data-type="<?php echo htmlspecialchars($pr['request_type']); ?>"
                                    data-cur-in="<?php echo htmlspecialchars($curInDisp); ?>"
                                    data-cur-out="<?php echo htmlspecialchars($curOutDisp); ?>"
                                    data-req-in="<?php echo htmlspecialchars($pr['requested_time_in'] ? date('H:i', strtotime($pr['requested_time_in'])) : ''); ?>"
                                    data-req-in-fmt="<?php echo htmlspecialchars($pr['requested_time_in'] ? date('h:i A', strtotime($pr['requested_time_in'])) : '-'); ?>"
                                    data-req-out="<?php echo htmlspecialchars($pr['requested_time_out'] ? date('H:i', strtotime($pr['requested_time_out'])) : ''); ?>"
                                    data-req-out-fmt="<?php echo htmlspecialchars($pr['requested_time_out'] ? date('h:i A', strtotime($pr['requested_time_out'])) : '-'); ?>"
                                    data-reason="<?php echo htmlspecialchars($pr['reason'] ?? ''); ?>">
                                    <i class="fas fa-check-circle mr-1"></i>Accept (Logbook Checked)
                                </button>
                                <button type="button" 
                                    class="btn btn-xs btn-outline-danger px-2 py-1 ml-1 btn-open-decline-modal"
                                    data-id="<?php echo (int)$pr['id']; ?>"
                                    data-emp="<?php echo htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name']); ?>"
                                    data-date="<?php echo date('M d, Y (D)', strtotime($pr['attendance_date'])); ?>"
                                    data-type-desc="<?php echo $pr['request_type'] === 'time_out' ? 'Missing Time Out' : ($pr['request_type'] === 'time_in' ? 'Missing Time In' : 'Punch Correction'); ?>"
                                    data-reason="<?php echo htmlspecialchars($pr['reason'] ?? ''); ?>">
                                    <i class="fas fa-times-circle mr-1"></i>Decline
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isAdmin && !$staffEmpId): ?>
    <div class="alert alert-info shadow-sm"><i class="fas fa-info-circle mr-1"></i>No linked employee record found. Please contact the administrator.</div>
    <?php endif; ?>

    <!-- Week Navigation -->
    <div class="card shadow mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="d-flex align-items-center mb-2 mb-sm-0">
                    <div class="btn-group mr-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo $selfUrl; ?>?week_start=<?php echo $prevWeekObj->format('Y-m-d'); ?>"><i class="fas fa-chevron-left mr-1"></i>Prev</a>
                        <a class="btn btn-sm btn-primary" href="<?php echo $selfUrl; ?>?week_start=<?php echo $thisWeekStartStr; ?>">This Week</a>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo $selfUrl; ?>?week_start=<?php echo $nextWeekObj->format('Y-m-d'); ?>">Next<i class="fas fa-chevron-right ml-1"></i></a>
                    </div>
                    <div class="input-group" style="max-width:210px;">
                        <input type="week" id="week_picker" class="form-control form-control-sm"
                            value="<?php echo $weekStartObj->format('o-\WW'); ?>"
                            title="Jump to any week">
                        <div class="input-group-append">
                            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="goWeek()">Go</button>
                        </div>
                    </div>
                    <script>
                        function goWeek() {
                            var v = document.getElementById('week_picker').value;
                            if (!v) return;
                            var parts = v.split('-W');
                            var y = parseInt(parts[0],10);
                            var w = parseInt(parts[1],10);
                            var d = new Date(Date.UTC(y, 0, 1 + (w - 1) * 7));
                            var dow = d.getUTCDay() || 7;
                            if (dow > 4) d.setUTCDate(d.getUTCDate() + 4 - dow);
                            else d.setUTCDate(d.getUTCDate() + 4 - dow - 7);
                            var yyyy = d.getUTCFullYear();
                            var mm = String(d.getUTCMonth()+1).padStart(2,'0');
                            var dd = String(d.getUTCDate()).padStart(2,'0');
                            location.href = '<?php echo $selfUrl; ?>?week_start=' + yyyy + '-' + mm + '-' + dd;
                        }
                    </script>
                </div>
                <div class="text-lg font-weight-bold text-gray-800">
                    <i class="fas fa-calendar-week text-primary mr-2"></i>Week of <?php echo $weekStartDisplay; ?> <span class="text-gray-500 mx-1">–</span> <?php echo $weekEndDisplay; ?>
                </div>
            </div>
            <?php if ($isPastWeek): ?>
            <div class="alert alert-secondary mt-3 mb-0 py-2 small"><i class="fas fa-info-circle mr-1"></i><strong>Archived week:</strong> This week is already over. Records are kept here permanently for payroll reference.</div>
            <?php elseif ($isThisWeek): ?>
            <div class="alert alert-success mt-3 mb-0 py-2 small"><i class="fas fa-info-circle mr-1"></i><strong>Current week:</strong> New attendance records will appear here automatically.</div>
            <?php elseif ($isFutureWeek): ?>
            <div class="alert alert-warning mt-3 mb-0 py-2 small"><i class="fas fa-info-circle mr-1"></i><strong>Upcoming week:</strong> This week hasn't started yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Weekly Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Present</div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo (int)$summary['present']; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Late</div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo (int)$summary['late']; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-clock fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-danger shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Absent</div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo (int)$summary['absent']; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-times fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Payable Hours</div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)$summary['total_hours'], 2); ?><span class="text-sm font-weight-normal text-gray-500 ml-1">hrs</span></div>
                            <div class="text-xs text-muted">8h/day max + approved OT</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-hourglass-half fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Attendance Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list mr-1"></i>
                <?php echo $isAdmin ? 'Weekly Attendance — ' : 'My Weekly Attendance — '; ?>
                <?php echo $weekLabel; ?>
                <?php if (!$attendance): ?>
                    <span class="text-gray-500 font-weight-normal ml-1 small">(No records)</span>
                <?php else: ?>
                    <span class="text-gray-500 font-weight-normal ml-1 small">(<?php echo count($attendance); ?> record<?php echo count($attendance)===1?'':'s'; ?>)</span>
                <?php endif; ?>
            </h6>
            <?php if (!$isThisWeek): ?>
                <a href="<?php echo $selfUrl; ?>?week_start=<?php echo $thisWeekStartStr; ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-arrow-up mr-1"></i>Jump to Current Week</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <?php if ($isAdmin): ?>
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:1120px;">
                    <thead><tr><th>Date</th><th>Employee</th><th>Time In</th><th>Time Out</th><th>Payable Hours / OT</th><th>Status</th><th>Source</th><th>Remarks / Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($attendance as $a):
                            $rawH = (float)($a['hours_worked'] ?? 0);
                            $regH = min($rawH, 8.0);
                            $excessH = max(0, round($rawH - 8.0, 2));
                            $otH = (float)($a['ot_hours'] ?? 0);
                            $isApp = !empty($a['is_ot_approved']);
                            $paidH = $regH + ($isApp ? $otH : 0);
                            $isToday = (new DateTime($a['attendance_date']))->format('Y-m-d') === $todayObj->format('Y-m-d');
                        ?>
                        <tr class="<?php echo $isToday ? 'table-info' : ''; ?>">
                            <td class="text-nowrap"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); if ($isToday) echo ' <span class="badge badge-primary ml-1">Today</span>'; ?></td>
                            <td><?php echo htmlspecialchars(($a['emp_code'] ?? 'ID:'.$a['employee_id']) . ' - ' . ($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')); ?></td>
                            <td class="text-nowrap">
                                <?php if ($a['time_in']): ?>
                                    <strong><?php echo date('h:i A', strtotime($a['time_in'])); ?></strong>
                                <?php else: ?>
                                    <span class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i>Missing In</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($a['time_out']): ?>
                                    <strong><?php echo date('h:i A', strtotime($a['time_out'])); ?></strong>
                                <?php else: ?>
                                    <span class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i>Missing Out</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <strong><?php echo ($a['time_in'] && $a['time_out'] && $rawH > 0) ? number_format($paidH, 2) . ' hrs' : '-'; ?></strong>
                                <?php if ($rawH > 8.0): ?>
                                    <div class="text-xs text-muted mt-1">
                                        <?php echo number_format($regH, 2); ?>h reg
                                        <?php if ($isApp && $otH > 0): ?>
                                            + <span class="badge badge-success px-1" title="Approved Overtime"><i class="fas fa-check mr-1"></i><?php echo number_format($otH, 2); ?>h OT</span>
                                        <?php else: ?>
                                            + <span class="badge badge-warning text-dark px-1" title="Unapproved excess hours beyond 8h"><i class="fas fa-clock mr-1"></i><?php echo number_format($excessH, 2); ?>h OT</span>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn btn-xs btn-outline-primary mt-1 btn-set-daily-ot"
                                        data-id="<?php echo (int)$a['id']; ?>"
                                        data-emp="<?php echo htmlspecialchars(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')); ?>"
                                        data-date="<?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?>"
                                        data-raw="<?php echo number_format($rawH, 2, '.', ''); ?>"
                                        data-reg="<?php echo number_format($regH, 2, '.', ''); ?>"
                                        data-excess="<?php echo number_format($excessH, 2, '.', ''); ?>"
                                        data-ot="<?php echo number_format($otH, 2, '.', ''); ?>"
                                        data-approved="<?php echo $isApp ? '1' : '0'; ?>">
                                        <i class="fas fa-clock mr-1"></i><?php echo $isApp ? 'Edit OT' : 'Set OT'; ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><span class="badge badge-<?php echo strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success'); ?>"><?php echo ucfirst($a['status'] ?? 'present'); ?></span></td>
                            <td class="text-nowrap">
                                <span class="badge badge-<?php echo ($a['source'] === 'logbook') ? 'info' : 'secondary'; ?>">
                                    <?php echo ucfirst($a['source'] ?? '-'); ?>
                                </span>
                            </td>
                            <td class="text-break">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span class="small"><?php echo htmlspecialchars($a['remarks'] ?? ''); ?></span>
                                    <button type="button" class="btn btn-xs btn-outline-secondary ml-2 btn-admin-edit-time"
                                        title="Direct Logbook Edit"
                                        data-id="<?php echo (int)$a['id']; ?>"
                                        data-emp="<?php echo htmlspecialchars(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')); ?>"
                                        data-date="<?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?>"
                                        data-in="<?php echo htmlspecialchars($a['time_in'] ?? ''); ?>"
                                        data-out="<?php echo htmlspecialchars($a['time_out'] ?? ''); ?>">
                                        <i class="fas fa-pen mr-1"></i>Edit Time
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$attendance): ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-5"><i class="fas fa-inbox fa-2x mb-2 d-block text-gray-300"></i>No attendance records for this week. Upload a CSV/XLSX file or wait for staff to punch in.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <!-- STAFF VIEW -->
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:880px;">
                    <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Payable Hours</th><th>Status</th><th>Source</th><th>Remarks / Requests</th></tr></thead>
                    <tbody>
                        <?php foreach ($attendance as $a):
                            $rawH = (float)($a['hours_worked'] ?? 0);
                            $regH = min($rawH, 8.0);
                            $otH = !empty($a['is_ot_approved']) ? (float)($a['ot_hours'] ?? 0) : 0;
                            $paidH = $regH + $otH;
                            $isToday = (new DateTime($a['attendance_date']))->format('Y-m-d') === $todayObj->format('Y-m-d');

                            // Check if staff has a pending request for this row
                            $pendingReq = $staffPendingMap['att_' . $a['id']] ?? ($staffPendingMap['date_' . $a['attendance_date']] ?? null);
                        ?>
                        <tr class="<?php echo $isToday ? 'table-info' : ''; ?>">
                            <td class="text-nowrap"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); if ($isToday) echo ' <span class="badge badge-primary ml-1">Today</span>'; ?></td>
                            
                            <!-- Time In Column -->
                            <td class="text-nowrap">
                                <?php if ($a['time_in']): ?>
                                    <strong><?php echo date('h:i A', strtotime($a['time_in'])); ?></strong>
                                <?php elseif ($pendingReq && $pendingReq['requested_time_in']): ?>
                                    <span class="badge badge-warning text-dark p-1" title="Pending Admin verification against logbook">
                                        <i class="fas fa-clock mr-1"></i>Req: <?php echo date('h:i A', strtotime($pendingReq['requested_time_in'])); ?>
                                    </span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-xs btn-outline-primary btn-open-punch-req"
                                        data-id="<?php echo (int)$a['id']; ?>"
                                        data-date="<?php echo $a['attendance_date']; ?>"
                                        data-date-formatted="<?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?>"
                                        data-type="time_in"
                                        data-cur-in="<?php echo htmlspecialchars($a['time_in'] ?? ''); ?>"
                                        data-cur-out="<?php echo htmlspecialchars($a['time_out'] ?? ''); ?>">
                                        <i class="fas fa-sign-in-alt mr-1"></i>Input In
                                    </button>
                                <?php endif; ?>
                            </td>

                            <!-- Time Out Column -->
                            <td class="text-nowrap">
                                <?php if ($a['time_out']): ?>
                                    <strong><?php echo date('h:i A', strtotime($a['time_out'])); ?></strong>
                                <?php elseif ($pendingReq && $pendingReq['requested_time_out']): ?>
                                    <span class="badge badge-warning text-dark p-1" title="Pending Admin verification against logbook">
                                        <i class="fas fa-clock mr-1"></i>Req: <?php echo date('h:i A', strtotime($pendingReq['requested_time_out'])); ?>
                                    </span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-xs btn-outline-primary btn-open-punch-req"
                                        data-id="<?php echo (int)$a['id']; ?>"
                                        data-date="<?php echo $a['attendance_date']; ?>"
                                        data-date-formatted="<?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?>"
                                        data-type="time_out"
                                        data-cur-in="<?php echo htmlspecialchars($a['time_in'] ?? ''); ?>"
                                        data-cur-out="<?php echo htmlspecialchars($a['time_out'] ?? ''); ?>">
                                        <i class="fas fa-sign-out-alt mr-1"></i>Input Out
                                    </button>
                                <?php endif; ?>
                            </td>

                            <!-- Payable Hours Column -->
                            <td class="text-nowrap">
                                <?php if ($a['time_in'] && $a['time_out'] && $rawH > 0): ?>
                                    <strong><?php echo number_format($paidH, 2); ?> hrs</strong>
                                    <?php if ($otH > 0): ?>
                                        <div class="text-xs text-success font-weight-bold mt-1">(Includes <?php echo number_format($otH, 2); ?>h approved OT)</div>
                                    <?php elseif ($rawH > 8.0): ?>
                                        <div class="text-xs text-muted mt-1">(8.00h reg, OT pending approval)</div>
                                    <?php endif; ?>
                                <?php elseif ($pendingReq): ?>
                                    <span class="badge badge-light border text-warning"><i class="fas fa-hourglass-start mr-1"></i>Awaiting Approval</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-nowrap"><span class="badge badge-<?php echo strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success'); ?>"><?php echo ucfirst($a['status'] ?? 'present'); ?></span></td>
                            <td class="text-nowrap text-muted small">
                                <span class="badge badge-<?php echo ($a['source'] === 'logbook') ? 'info' : 'light border'; ?>">
                                    <?php echo ucfirst($a['source'] ?? '-'); ?>
                                </span>
                            </td>
                            <td class="text-break small">
                                <?php if ($pendingReq): ?>
                                    <span class="text-warning font-weight-bold"><i class="fas fa-clock mr-1"></i>Req: <?php echo $pendingReq['request_type'] === 'time_out' ? 'Time Out (' . date('h:i A', strtotime($pendingReq['requested_time_out'])) . ')' : 'Time In'; ?> pending admin verification.</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($a['remarks'] ?? '-'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$attendance): ?>
                        <tr><td colspan="7" class="text-center text-gray-500 py-5"><i class="fas fa-inbox fa-2x mb-2 d-block text-gray-300"></i>No attendance records for this week. Check back later or contact your admin.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: STAFF INPUT MISSING PUNCH (TIME OUT / TIME IN REQUEST) -->
<!-- ============================================================== -->
<?php if (!$isAdmin && $staffEmpId): ?>
<div class="modal fade" id="punchRequestModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="action" value="request_punch">
                <input type="hidden" name="attendance_id" id="modalPunchAttId">
                <input type="hidden" name="attendance_date" id="modalPunchDate">
                <input type="hidden" name="request_type" id="modalPunchType" value="time_out">
                <input type="hidden" name="current_time_in" id="modalPunchCurIn">
                <input type="hidden" name="current_time_out" id="modalPunchCurOut">

                <div class="modal-header bg-gradient-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold" id="modalPunchTitle">
                        <i class="fas fa-business-time mr-2"></i>Input Missing Punch
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="d-flex justify-content-between">
                            <div><strong>Date:</strong> <span id="modalPunchDateDisplay">-</span></div>
                            <div><strong>Recorded In:</strong> <span id="modalPunchInDisplay" class="text-primary font-weight-bold">-</span></div>
                        </div>
                    </div>

                    <div class="form-group mb-3" id="groupReqTimeOut">
                        <label class="font-weight-bold text-gray-800 small">
                            <i class="fas fa-sign-out-alt text-danger mr-1"></i>Your Actual Time Out (Logbook Time):
                        </label>
                        <input type="time" name="requested_time_out" id="modalInputTimeOut" class="form-control form-control-lg font-weight-bold text-primary">
                        <small class="form-text text-muted">Enter the exact time you left/timed out in the physical store logbook.</small>
                    </div>

                    <div class="form-group mb-3 d-none" id="groupReqTimeIn">
                        <label class="font-weight-bold text-gray-800 small">
                            <i class="fas fa-sign-in-alt text-success mr-1"></i>Your Actual Time In (Logbook Time):
                        </label>
                        <input type="time" name="requested_time_in" id="modalInputTimeIn" class="form-control form-control-lg font-weight-bold text-primary">
                        <small class="form-text text-muted">Enter the exact time you arrived in the physical store logbook.</small>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-gray-800 small">Reason / Note for Admin:</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. Forgot biometric out, verified in store logbook" maxlength="255">
                        <small class="form-text text-muted">Admin will cross-check this entry with the physical logbook before approving.</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4">
                        <i class="fas fa-paper-plane mr-1"></i>Submit for Verification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for New Punch Request (Manual Date Entry) -->
<div class="modal fade" id="newPunchRequestModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="request_punch">
                <input type="hidden" name="request_type" value="both">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-calendar-plus mr-2"></i>Request Missing Attendance Punch</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-gray-800 small">Work Date:</label>
                        <input type="date" name="attendance_date" class="form-control font-weight-bold" max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="font-weight-bold text-gray-800 small">Time In:</label>
                            <input type="time" name="requested_time_in" class="form-control" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="font-weight-bold text-gray-800 small">Time Out:</label>
                            <input type="time" name="requested_time_out" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-gray-800 small">Reason / Logbook Note:</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. Biometrics offline, recorded in logbook" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-paper-plane mr-1"></i>Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- MODAL: ADMIN DIRECT TIME EDIT (VERIFIED FROM LOGBOOK)          -->
<!-- ============================================================== -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="adminEditTimeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="action" value="admin_direct_update_time">
                <input type="hidden" name="attendance_id" id="adminEditAttId">
                <div class="modal-header bg-dark text-white py-3">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit text-warning mr-2"></i>Logbook Attendance Edit</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="font-weight-bold text-gray-800" id="adminEditEmpName">-</div>
                        <div class="small text-muted" id="adminEditDate">-</div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="font-weight-bold text-gray-800 small">Time In:</label>
                            <input type="time" name="time_in" id="adminEditTimeIn" class="form-control font-weight-bold">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="font-weight-bold text-gray-800 small">Time Out:</label>
                            <input type="time" name="time_out" id="adminEditTimeOut" class="form-control font-weight-bold">
                        </div>
                    </div>
                    <p class="text-muted small mb-0"><i class="fas fa-info-circle mr-1"></i>Total hours and 8-hour cap will be recomputed automatically.</p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-save mr-1"></i>Save Logbook Time</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Daily Overtime Modal -->
<div class="modal fade" id="dailyOtModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="set_ot">
                <input type="hidden" name="attendance_id" id="modalDailyAttId">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-clock mr-2"></i>Set / Approve Daily Overtime</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="font-weight-bold text-gray-800" id="modalDailyEmpName">-</div>
                        <div class="small text-muted" id="modalDailyDate">-</div>
                    </div>

                    <div class="row text-center mb-3">
                        <div class="col-4">
                            <div class="border rounded p-2 bg-light">
                                <div class="text-xs text-muted">Total Worked</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><span id="modalDailyRaw">0.00</span> h</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 bg-light">
                                <div class="text-xs text-muted">Regular (Max 8h)</div>
                                <div class="h5 mb-0 font-weight-bold text-success"><span id="modalDailyReg">8.00</span> h</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 bg-light">
                                <div class="text-xs text-muted">Excess Hours</div>
                                <div class="h5 mb-0 font-weight-bold text-warning"><span id="modalDailyExcess">0.00</span> h</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-gray-800 small">Approved Overtime Hours (OT):</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" name="ot_hours" id="modalDailyOtInput" class="form-control font-weight-bold text-primary" placeholder="0.00" value="0.00">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" onclick="$('#modalDailyOtInput').val('0.00');">0h None</button>
                                <button type="button" class="btn btn-outline-success" id="btnApproveAllDaily">Approve All</button>
                            </div>
                        </div>
                        <small class="form-text text-muted">Overtime rate is 1.0x (regular hourly rate). Set to 0 if OT is not approved.</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-save mr-1"></i>Save Overtime</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Admin Accept Punch Request Modal (Logbook Verification) -->
<div class="modal fade" id="adminAcceptPunchModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="action" value="approve_attendance_request">
                <input type="hidden" name="request_id" id="modalAcceptReqId">
                <div class="modal-header bg-success text-white py-3">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-clipboard-check mr-2"></i>Verify & Accept Punch Request
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="font-weight-bold text-gray-800" style="font-size: 1.05rem;" id="modalAcceptEmpName">-</span>
                            <span class="badge badge-primary px-2 py-1" id="modalAcceptTypeBadge">-</span>
                        </div>
                        <div class="small text-muted"><i class="far fa-calendar-alt mr-1"></i>Date: <strong id="modalAcceptDate">-</strong></div>
                        <div class="small text-muted mt-1"><i class="fas fa-clock mr-1"></i>Current Recorded: <span id="modalAcceptCurTimes">-</span></div>
                    </div>

                    <div class="card border-left-info shadow-sm mb-3">
                        <div class="card-body py-2 px-3">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Staff Reason / Logbook Reference</div>
                            <div class="text-gray-800 small font-italic" id="modalAcceptReason">"No notes provided"</div>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded border mb-3">
                        <label class="font-weight-bold text-gray-800 small d-block mb-2">
                            <i class="fas fa-book-reader mr-1 text-primary"></i>Check & Confirm Time against Physical Logbook:
                        </label>
                        <div class="row" id="rowAcceptTimeOut">
                            <div class="col-12 mb-2">
                                <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-sign-out-alt text-primary mr-1"></i>Verified Time Out (from Store Logbook):</label>
                                <input type="time" name="verified_time_out" id="modalAcceptTimeOut" class="form-control form-control-lg font-weight-bold text-primary">
                            </div>
                        </div>
                        <div class="row" id="rowAcceptTimeIn">
                            <div class="col-12 mb-2">
                                <label class="small text-muted mb-1 font-weight-bold"><i class="fas fa-sign-in-alt text-info mr-1"></i>Verified Time In (from Store Logbook):</label>
                                <input type="time" name="verified_time_in" id="modalAcceptTimeIn" class="form-control form-control-lg font-weight-bold text-info">
                            </div>
                        </div>
                        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i>Kung may discrepancy sa sinubmit ng staff vs logbook, i-adjust ang oras sa itaas. Awtomatikong 8 hours max ang regular hours.</small>
                    </div>

                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="acceptLogbookConfirmCheck" required checked>
                        <label class="custom-control-label small font-weight-bold text-gray-800" for="acceptLogbookConfirmCheck">
                            Nacheck ko na sa physical store logbook at tugma ang oras at pirma ng staff.
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold px-4">
                        <i class="fas fa-check-circle mr-1"></i>Confirm & Accept
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Admin Decline Punch Request Modal -->
<div class="modal fade" id="adminDeclinePunchModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="action" value="reject_attendance_request">
                <input type="hidden" name="request_id" id="modalDeclineReqId">
                <div class="modal-header bg-danger text-white py-3">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-times-circle mr-2"></i>Decline Punch Request
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="font-weight-bold text-gray-800" style="font-size: 1.05rem;" id="modalDeclineEmpName">-</div>
                        <div class="small text-muted"><i class="far fa-calendar-alt mr-1"></i>Date: <strong id="modalDeclineDate">-</strong> | Request: <span id="modalDeclineType" class="badge badge-secondary">-</span></div>
                        <div class="small text-muted mt-1 font-italic"><i class="fas fa-comment-alt mr-1"></i>Staff Note: <span id="modalDeclineReason">"-"</span></div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-gray-800 small">Quick Reason (Pumili ng dahilan):</label>
                        <select id="modalDeclineQuickReason" class="form-control form-control-sm mb-2">
                            <option value="Walang pirma o record na nakita sa physical store logbook.">Walang pirma o record sa physical store logbook</option>
                            <option value="Hindi tugma ang oras na hiniling sa nakasulat sa store logbook.">Hindi tugma ang oras sa nakasulat sa logbook</option>
                            <option value="Maling petsa o duplicate request.">Maling petsa o duplicate request</option>
                            <option value="Hindi pinayagan o hindi na-verify ng Branch Supervisor.">Hindi na-verify ng Branch Supervisor</option>
                            <option value="custom">Iba pang dahilan (Custom reason)...</option>
                        </select>
                    </div>

                    <div class="form-group mb-2">
                        <label class="font-weight-bold text-gray-800 small">Admin Remarks / Dahilan ng Pag-decline:</label>
                        <textarea name="admin_notes" id="modalDeclineNotes" rows="3" class="form-control" placeholder="Ilagay ang dahilan kung bakit na-decline..." required>Walang pirma o record na nakita sa physical store logbook.</textarea>
                        <small class="form-text text-muted mt-1"><i class="fas fa-info-circle mr-1"></i>Makikita ng staff ang dahilang ito sa kanilang attendance summary.</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger font-weight-bold px-4">
                        <i class="fas fa-times-circle mr-1"></i>Decline Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
?>

<script>
$(document).ready(function() {
    // Staff click to input missing Time Out or Time In
    $('.btn-open-punch-req').on('click', function() {
        var btn = $(this);
        var attId = btn.data('id');
        var dt = btn.data('date');
        var dtFmt = btn.data('date-formatted');
        var reqType = btn.data('type') || 'time_out';
        var curIn = btn.data('cur-in') || '';
        var curOut = btn.data('cur-out') || '';

        $('#modalPunchAttId').val(attId);
        $('#modalPunchDate').val(dt);
        $('#modalPunchDateDisplay').text(dtFmt);
        $('#modalPunchCurIn').val(curIn);
        $('#modalPunchCurOut').val(curOut);
        $('#modalPunchType').val(reqType);

        if (curIn) {
            $('#modalPunchInDisplay').text(curIn);
        } else {
            $('#modalPunchInDisplay').text('None');
        }

        if (reqType === 'time_out') {
            $('#modalPunchTitle').html('<i class="fas fa-sign-out-alt mr-2"></i>Input Missing Time Out');
            $('#groupReqTimeOut').removeClass('d-none');
            $('#groupReqTimeIn').addClass('d-none');
            $('#modalInputTimeOut').prop('required', true);
            $('#modalInputTimeIn').prop('required', false);
        } else {
            $('#modalPunchTitle').html('<i class="fas fa-sign-in-alt mr-2"></i>Input Missing Time In');
            $('#groupReqTimeIn').removeClass('d-none');
            $('#groupReqTimeOut').addClass('d-none');
            $('#modalInputTimeIn').prop('required', true);
            $('#modalInputTimeOut').prop('required', false);
        }

        $('#punchRequestModal').modal('show');
    });

    // Admin direct edit time modal
    $('.btn-admin-edit-time').on('click', function() {
        var btn = $(this);
        var id = btn.data('id');
        var emp = btn.data('emp');
        var dt = btn.data('date');
        var tin = btn.data('in') || '';
        var tout = btn.data('out') || '';

        $('#adminEditAttId').val(id);
        $('#adminEditEmpName').text(emp);
        $('#adminEditDate').text(dt);
        $('#adminEditTimeIn').val(tin);
        $('#adminEditTimeOut').val(tout);

        $('#adminEditTimeModal').modal('show');
    });

    // Admin set daily overtime modal
    $('.btn-set-daily-ot').on('click', function() {
        var btn = $(this);
        var id = btn.data('id');
        var emp = btn.data('emp');
        var dt = btn.data('date');
        var raw = btn.data('raw');
        var reg = btn.data('reg');
        var excess = btn.data('excess');
        var ot = btn.data('ot');

        $('#modalDailyAttId').val(id);
        $('#modalDailyEmpName').text(emp);
        $('#modalDailyDate').text(dt);
        $('#modalDailyRaw').text(raw);
        $('#modalDailyReg').text(reg);
        $('#modalDailyExcess').text(excess);
        $('#modalDailyOtInput').val(parseFloat(ot) > 0 ? ot : excess);

        $('#btnApproveAllDaily').off('click').on('click', function() {
            $('#modalDailyOtInput').val(excess);
        });

        $('#dailyOtModal').modal('show');
    });

    // Admin Open Accept Punch Modal (Logbook Check)
    $('.btn-open-accept-modal').on('click', function() {
        var btn = $(this);
        var id = btn.data('id');
        var emp = btn.data('emp');
        var dt = btn.data('date');
        var type = btn.data('type');
        var curIn = btn.data('cur-in') || '-';
        var curOut = btn.data('cur-out') || '-';
        var reqIn = btn.data('req-in') || '';
        var reqOut = btn.data('req-out') || '';
        var reason = btn.data('reason') || 'No notes provided';

        $('#modalAcceptReqId').val(id);
        $('#modalAcceptEmpName').text(emp);
        $('#modalAcceptDate').text(dt);
        $('#modalAcceptCurTimes').text('In: ' + curIn + ' | Out: ' + curOut);
        $('#modalAcceptReason').text('"' + reason + '"');

        if (type === 'time_out') {
            $('#modalAcceptTypeBadge').removeClass('badge-info').addClass('badge-primary').text('Missing Time Out');
            $('#rowAcceptTimeOut').show();
            $('#modalAcceptTimeOut').val(reqOut).prop('required', true);
            $('#rowAcceptTimeIn').hide();
            $('#modalAcceptTimeIn').val('').prop('required', false);
        } else if (type === 'time_in') {
            $('#modalAcceptTypeBadge').removeClass('badge-primary').addClass('badge-info').text('Missing Time In');
            $('#rowAcceptTimeIn').show();
            $('#modalAcceptTimeIn').val(reqIn).prop('required', true);
            $('#rowAcceptTimeOut').hide();
            $('#modalAcceptTimeOut').val('').prop('required', false);
        } else {
            $('#modalAcceptTypeBadge').removeClass('badge-info').addClass('badge-primary').text('Punch Correction');
            $('#rowAcceptTimeIn').show();
            $('#rowAcceptTimeOut').show();
            $('#modalAcceptTimeIn').val(reqIn).prop('required', !!reqIn);
            $('#modalAcceptTimeOut').val(reqOut).prop('required', !!reqOut);
        }

        $('#acceptLogbookConfirmCheck').prop('checked', true);
        $('#adminAcceptPunchModal').modal('show');
    });

    // Admin Open Decline Punch Modal
    $('.btn-open-decline-modal').on('click', function() {
        var btn = $(this);
        var id = btn.data('id');
        var emp = btn.data('emp');
        var dt = btn.data('date');
        var typeDesc = btn.data('type-desc') || 'Punch Request';
        var reason = btn.data('reason') || 'No notes provided';

        $('#modalDeclineReqId').val(id);
        $('#modalDeclineEmpName').text(emp);
        $('#modalDeclineDate').text(dt);
        $('#modalDeclineType').text(typeDesc);
        $('#modalDeclineReason').text('"' + reason + '"');

        var defReason = 'Walang pirma o record na nakita sa physical store logbook.';
        $('#modalDeclineQuickReason').val(defReason);
        $('#modalDeclineNotes').val(defReason);

        $('#adminDeclinePunchModal').modal('show');
    });

    // Quick Reason dropdown helper for Decline Modal
    $('#modalDeclineQuickReason').on('change', function() {
        var val = $(this).val();
        if (val === 'custom') {
            $('#modalDeclineNotes').val('').focus();
        } else {
            $('#modalDeclineNotes').val(val);
        }
    });
});
</script>
