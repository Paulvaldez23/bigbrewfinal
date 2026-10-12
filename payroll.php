<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

if ($conn) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS payroll_notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            processed_by INT UNSIGNED NOT NULL DEFAULT 0,
            employee_id INT UNSIGNED NOT NULL,
            cutoff_start DATE NOT NULL,
            cutoff_end DATE NOT NULL,
            is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_cutoff_notif (employee_id, cutoff_start, cutoff_end),
            KEY idx_emp_read (employee_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS payroll_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            cutoff_start DATE NOT NULL,
            cutoff_end DATE NOT NULL,
            total_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_days INT UNSIGNED NOT NULL DEFAULT 0,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            sss DECIMAL(10,2) NOT NULL DEFAULT 0,
            philhealth DECIMAL(10,2) NOT NULL DEFAULT 0,
            pagibig DECIMAL(10,2) NOT NULL DEFAULT 0,
            other_deductions DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('draft','processed') NOT NULL DEFAULT 'draft',
            processed_by INT UNSIGNED NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_cutoff (employee_id, cutoff_start, cutoff_end),
            KEY idx_status (status),
            KEY idx_cutoff (cutoff_start, cutoff_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("UPDATE payroll_records SET sss = 0, philhealth = 0, pagibig = 0, other_deductions = 0, net_pay = gross_pay WHERE sss > 0 OR philhealth > 0 OR pagibig > 0");
    } catch (Exception $e) {}
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = is_admin();

$actionMsg = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $conn) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'process' || $action === 'revert') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $cStart = $_POST['cutoff_start'] ?? '';
            $cEnd = $_POST['cutoff_end'] ?? '';
            if ($empId && $cStart && $cEnd) {
                $newStatus = $action === 'process' ? 'processed' : 'draft';
                $totalH = (float)($_POST['total_hours'] ?? 0);
                $totalD = (int)($_POST['total_days'] ?? 0);
                $rate = (float)($_POST['hourly_rate'] ?? 0);
                $gross = (float)($_POST['gross_pay'] ?? 0);
                $sss = 0;
                $ph = 0;
                $pg = 0;
                $net = $gross;
                $procBy = $newStatus === 'processed' ? $userId : null;
                $procAt = $newStatus === 'processed' ? date('Y-m-d H:i:s') : null;

                $upsert = $conn->prepare("INSERT INTO payroll_records
                    (employee_id, cutoff_start, cutoff_end, total_hours, total_days, hourly_rate,
                     gross_pay, sss, philhealth, pagibig, other_deductions, net_pay, status, processed_by, processed_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        total_hours = VALUES(total_hours),
                        total_days = VALUES(total_days),
                        hourly_rate = VALUES(hourly_rate),
                        gross_pay = VALUES(gross_pay),
                        sss = VALUES(sss),
                        philhealth = VALUES(philhealth),
                        pagibig = VALUES(pagibig),
                        other_deductions = VALUES(other_deductions),
                        net_pay = VALUES(net_pay),
                        status = VALUES(status),
                        processed_by = VALUES(processed_by),
                        processed_at = VALUES(processed_at)");
                $upsert->execute([$empId, $cStart, $cEnd, $totalH, $totalD, $rate, $gross, $sss, $ph, $pg, 0, $net, $newStatus, $procBy, $procAt]);

                if ($newStatus === 'processed') {
                    $notif = $conn->prepare("INSERT IGNORE INTO payroll_notifications
                        (processed_by, employee_id, cutoff_start, cutoff_end, is_read, created_at)
                        VALUES (?, ?, ?, ?, 0, NOW())");
                    $notif->execute([($userId ?: 0), $empId, $cStart, $cEnd]);
                } else {
                    $delNotif = $conn->prepare("DELETE FROM payroll_notifications WHERE employee_id=? AND cutoff_start=? AND cutoff_end=?");
                    $delNotif->execute([$empId, $cStart, $cEnd]);
                }

                $empName = '';
                $eS = $conn->prepare("SELECT first_name, last_name FROM employees WHERE id = ? LIMIT 1");
                $eS->execute([$empId]);
                $eD = $eS->fetch();
                if ($eD) { $empName = $eD['first_name'] . ' ' . $eD['last_name']; }
                $actionMsg = $newStatus === 'processed'
                    ? "{$empName}'s payroll for {$cStart} to {$cEnd} has been processed! Notification sent to staff."
                    : "{$empName}'s payroll has been reverted to Draft. Notification removed.";
                $actionType = $newStatus === 'processed' ? 'success' : 'info';
            }
        } elseif ($action === 'process_batch') {
            $cStart = $_POST['cutoff_start'] ?? '';
            $cEnd = $_POST['cutoff_end'] ?? '';
            $empIds = $_POST['emp_ids'] ?? [];
            $count = 0;
            if ($cStart && $cEnd && is_array($empIds)) {
                foreach ($empIds as $empId) {
                    $empId = (int)$empId;
                    if (!$empId) continue;

                    $eS = $conn->prepare("SELECT hourly_rate FROM employees WHERE id = ? LIMIT 1");
                    $eS->execute([$empId]);
                    $empR = $eS->fetch();
                    $rate = (float)($empR['hourly_rate'] ?? 0);

                    $pStmt = $conn->prepare("SELECT COALESCE(SUM(hours_worked),0) AS total_h, COUNT(DISTINCT attendance_date) AS days
                        FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? AND hours_worked > 0");
                    $pStmt->execute([$empId, $cStart, $cEnd]);
                    $pd = $pStmt->fetch();
                    $h = (float)($pd['total_h'] ?? 0);
                    $d = (int)($pd['days'] ?? 0);
                    $gross = $h * $rate;
                    $sss = 0;
                    $ph = 0;
                    $pg = 0;
                    $net = $gross;
                    $procAt = date('Y-m-d H:i:s');
                    $upsert = $conn->prepare("INSERT INTO payroll_records
                        (employee_id, cutoff_start, cutoff_end, total_hours, total_days, hourly_rate,
                         gross_pay, sss, philhealth, pagibig, other_deductions, net_pay, status, processed_by, processed_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            total_hours=VALUES(total_hours), total_days=VALUES(total_days),
                            hourly_rate=VALUES(hourly_rate), gross_pay=VALUES(gross_pay),
                            sss=VALUES(sss), philhealth=VALUES(philhealth), pagibig=VALUES(pagibig),
                            net_pay=VALUES(net_pay), status='processed', processed_by=VALUES(processed_by), processed_at=VALUES(processed_at)");
                    $upsert->execute([$empId, $cStart, $cEnd, $h, $d, $rate, $gross, $sss, $ph, $pg, 0, $net, 'processed', $userId, $procAt]);

                    $notif = $conn->prepare("INSERT IGNORE INTO payroll_notifications
                        (processed_by, employee_id, cutoff_start, cutoff_end, is_read, created_at)
                        VALUES (?, ?, ?, ?, 0, NOW())");
                    $notif->execute([($userId ?: 0), $empId, $cStart, $cEnd]);

                    $count++;
                }
            }
            $actionMsg = "Successfully processed payroll for {$count} employee(s) ({$cStart} to {$cEnd}). Each staff received a notification 🔔.";
            $actionType = 'success';
        }
    } catch (Exception $e) {
        $actionMsg = 'An error occurred: ' . $e->getMessage();
        $actionType = 'danger';
    }
}

$active_nav = 'payroll';
$page_title = $isAdmin ? 'Big Brew - Payroll Processing' : 'Big Brew - My Payroll';

$staffEmpId = null;
$myEmpData = null;
$viewEmpId = 0;

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
                else { $viewEmpId = 0; }
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

        if (!$isAdmin && $staffEmpId && !empty($_GET['mark_read'])) {
            try {
                $mr = $conn->prepare("UPDATE payroll_notifications SET is_read = 1 WHERE employee_id = ? AND is_read = 0");
                $mr->execute([$staffEmpId]);
            } catch (Exception $e) {}
        } elseif (!$isAdmin && $staffEmpId) {
            try {
                $mr = $conn->prepare("UPDATE payroll_notifications SET is_read = 1 WHERE employee_id = ? AND is_read = 0");
                $mr->execute([$staffEmpId]);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}
}

$availableCutoffs = [];
$yearNow = (int)date('Y');
$monthNow = (int)date('m');
for ($m = 0; $m < 6; $m++) {
    $refMonth = $monthNow - $m;
    $refYear = $yearNow;
    if ($refMonth <= 0) { $refMonth += 12; $refYear--; }
    foreach ([1, 16] as $dayStart) {
        $cutoffStart = sprintf('%04d-%02d-%02d', $refYear, $refMonth, $dayStart);
        if ($dayStart === 1) {
            $cutoffEnd = sprintf('%04d-%02d-15', $refYear, $refMonth);
            $label = date('M 1-15, Y', strtotime($cutoffStart));
        } else {
            $lastDay = date('t', strtotime("$refYear-$refMonth-01"));
            $cutoffEnd = sprintf('%04d-%02d-%02d', $refYear, $refMonth, $lastDay);
            $label = date('M 16-', strtotime($cutoffStart)) . $lastDay . date(', Y', strtotime($cutoffStart));
        }
        if (strtotime($cutoffStart) > time()) { continue; }
        $availableCutoffs[] = ['label' => $label, 'start' => $cutoffStart, 'end' => $cutoffEnd];
    }
}

$selectedCutoff = $availableCutoffs[0] ?? ['start' => '', 'end' => '', 'label' => ''];
if (isset($_GET['cutoff_start']) && isset($_GET['cutoff_end'])) {
    $cs = $_GET['cutoff_start'];
    $ce = $_GET['cutoff_end'];
    foreach ($availableCutoffs as $c) {
        if ($c['start'] === $cs && $c['end'] === $ce) { $selectedCutoff = $c; break; }
    }
}

$cutoffs = [];
$currentMonthAttendance = [];
$totalHoursMonth = 0;
$daysPresent = 0;
$processedStatuses = [];

if ($conn && $staffEmpId) {
    try {
        $currentMonth = date('m');
        $currentYear = date('Y');

        $attStmt = $conn->prepare("SELECT attendance_date, time_in, time_out, hours_worked, status, source, remarks
            FROM attendance
            WHERE employee_id = ? AND MONTH(attendance_date)=? AND YEAR(attendance_date)=?
            ORDER BY attendance_date DESC");
        $attStmt->execute([$staffEmpId, $currentMonth, $currentYear]);
        $currentMonthAttendance = $attStmt->fetchAll();

        foreach ($currentMonthAttendance as $a) {
            $h = (float)($a['hours_worked'] ?? 0);
            if ($h > 0) { $totalHoursMonth += $h; $daysPresent++; }
        }

        $rate = (float)($myEmpData['hourly_rate'] ?? 0);
        foreach ($availableCutoffs as $c) {
            $cutoffStart = $c['start'];
            $cutoffEnd = $c['end'];
            $label = $c['label'];

            $pStmt = $conn->prepare("SELECT COALESCE(SUM(hours_worked),0) AS total_h, COUNT(DISTINCT attendance_date) AS days
                FROM attendance
                WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? AND hours_worked > 0");
            $pStmt->execute([$staffEmpId, $cutoffStart, $cutoffEnd]);
            $pd = $pStmt->fetch();
            $gross = (float)($pd['total_h'] ?? 0) * $rate;
            $sss = 0;
            $ph = 0;
            $pg = 0;

            $prStmt = $conn->prepare("SELECT status, processed_at FROM payroll_records
                WHERE employee_id = ? AND cutoff_start = ? AND cutoff_end = ? LIMIT 1");
            $prStmt->execute([$staffEmpId, $cutoffStart, $cutoffEnd]);
            $prRow = $prStmt->fetch();
            $prStatus = $prRow['status'] ?? 'draft';
            $prProcessedAt = $prRow['processed_at'] ?? null;
            $processedStatuses[$cutoffStart . '_' . $cutoffEnd] = ['status' => $prStatus, 'processed_at' => $prProcessedAt];

            $cutoffs[] = [
                'label' => $label,
                'start' => $cutoffStart,
                'end' => $cutoffEnd,
                'hours' => (float)($pd['total_h'] ?? 0),
                'days' => (int)($pd['days'] ?? 0),
                'gross' => $gross,
                'rate' => $rate,
                'sss' => 0,
                'philhealth' => 0,
                'pagibig' => 0,
                'status' => $prStatus,
                'processed_at' => $prProcessedAt,
            ];
        }
    } catch (Exception $e) {}
}

$allEmployees = [];
$batchRows = [];
$batchStats = ['count' => 0, 'total_gross' => 0, 'total_net' => 0, 'processed_count' => 0];
if ($isAdmin && $conn) {
    try {
        $allEmployees = $conn->query("SELECT e.id, e.first_name, e.last_name, e.position, e.hourly_rate, e.status 
            FROM employees e 
            LEFT JOIN admin_users au ON au.employee_id = e.id 
            WHERE (au.role IS NULL OR au.role != 'Admin')
              AND LOWER(COALESCE(e.position, '')) NOT IN ('owner', 'admin')
            ORDER BY e.first_name")->fetchAll();
        if (!$staffEmpId && $selectedCutoff['start']) {
            foreach ($allEmployees as $e) {
                $eid = (int)$e['id'];
                $rate = (float)($e['hourly_rate'] ?? 0);
                $pStmt = $conn->prepare("SELECT COALESCE(SUM(hours_worked),0) AS total_h, COUNT(DISTINCT attendance_date) AS days
                    FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? AND hours_worked > 0");
                $pStmt->execute([$eid, $selectedCutoff['start'], $selectedCutoff['end']]);
                $pd = $pStmt->fetch();
                $h = (float)($pd['total_h'] ?? 0);
                $d = (int)($pd['days'] ?? 0);
                $gross = $h * $rate;
                $sss = 0;
                $ph = 0;
                $pg = 0;
                $net = $gross;

                $prStmt = $conn->prepare("SELECT status, processed_at FROM payroll_records
                    WHERE employee_id = ? AND cutoff_start = ? AND cutoff_end = ? LIMIT 1");
                $prStmt->execute([$eid, $selectedCutoff['start'], $selectedCutoff['end']]);
                $prRow = $prStmt->fetch();
                $prStatus = $prRow['status'] ?? 'draft';
                $prProcessedAt = $prRow['processed_at'] ?? null;

                if ($h > 0 || $prStatus === 'processed') {
                    $batchRows[] = [
                        'emp' => $e,
                        'hours' => $h,
                        'days' => $d,
                        'gross' => $gross,
                        'sss' => $sss,
                        'philhealth' => $ph,
                        'pagibig' => $pg,
                        'net' => $net,
                        'status' => $prStatus,
                        'processed_at' => $prProcessedAt,
                    ];
                    $batchStats['count']++;
                    $batchStats['total_gross'] += $gross;
                    $batchStats['total_net'] += max($net, 0);
                    if ($prStatus === 'processed') { $batchStats['processed_count']++; }
                }
            }
        }
    } catch (Exception $e) {}
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <?php if ($actionMsg): ?>
    <div class="alert alert-<?php echo htmlspecialchars($actionType); ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-<?php echo $actionType === 'success' ? 'check-circle' : ($actionType === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> mr-1"></i>
        <?php echo htmlspecialchars($actionMsg); ?>
        <button type="button" class="close" data-dismiss-vanilla aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <?php endif; ?>

    <div class="d-sm-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800 d-flex align-items-center">
                <i class="fas fa-money-bill-wave text-success mr-2"></i>
                <?php
                if ($isAdmin && !$staffEmpId) { echo 'Payroll Processing Center'; }
                elseif ($isAdmin && $myEmpData) { echo 'Employee Payroll Details'; }
                else { echo 'My Payroll'; }
                ?>
            </h1>
            <?php if ($myEmpData): ?>
            <p class="mb-0 text-muted small mt-1">
                <i class="fas fa-user-circle mr-1"></i>
                <strong><?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?></strong>
                <?php if (!empty($myEmpData['position'])): ?> • <?php echo htmlspecialchars($myEmpData['position']); ?><?php endif; ?>
                • Rate: <strong>₱ <?php echo number_format((float)($myEmpData['hourly_rate'] ?? 0), 2); ?></strong>/hr
                • Emp ID: <strong><?php echo htmlspecialchars($myEmpData['employee_id'] ?? '-'); ?></strong>
            </p>
            <?php elseif ($isAdmin && !$staffEmpId): ?>
            <p class="mb-0 text-muted small mt-1">
                <i class="fas fa-users mr-1"></i>
                Batch process payroll per cutoff period. Mark records as <strong>Processed</strong> to make them visible to staff.
            </p>
            <?php endif; ?>
        </div>
        <div class="mt-2">
            <?php if ($isAdmin): ?>
                <?php if (!$staffEmpId): ?>
                <select class="form-control form-control-sm" style="width:auto;display:inline-block;margin-right:.5rem;" onchange="window.location='payroll.php?cutoff_start='+this.options[this.selectedIndex].dataset.start+'&cutoff_end='+this.options[this.selectedIndex].dataset.end;">
                    <?php foreach ($availableCutoffs as $c): ?>
                    <option data-start="<?php echo htmlspecialchars($c['start']); ?>" data-end="<?php echo htmlspecialchars($c['end']); ?>" <?php echo ($c['start'] === $selectedCutoff['start'] && $c['end'] === $selectedCutoff['end']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <a href="payroll.php" class="btn btn-sm btn-outline-dark mr-1"><i class="fas fa-arrow-left mr-1"></i>Batch View</a>
                <?php endif; ?>
                <?php if ($allEmployees): ?>
                <select class="form-control form-control-sm" style="width:auto;display:inline-block;margin-right:.5rem;" onchange="window.location='payroll.php?employee_id='+this.value;">
                    <option value="">(View per employee)</option>
                    <?php foreach ($allEmployees as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo $e['id'] == $staffEmpId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin && !$staffEmpId): ?>

    <div class="row mb-4">
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Active Employees</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $batchStats['count']; ?></div>
                            <div class="mt-1 text-xs text-muted">With attendance this cutoff</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-users fa-2x text-primary opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Processed</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $batchStats['processed_count']; ?> / <?php echo $batchStats['count']; ?></div>
                            <div class="mt-1 text-xs text-muted">
                                <?php echo $batchStats['count'] ? round(($batchStats['processed_count'] / $batchStats['count']) * 100) : 0; ?>% complete
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-check-double fa-2x text-success opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Payroll</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">₱ <?php echo number_format($batchStats['total_net'], 2); ?></div>
                            <div class="mt-1 text-xs text-muted">Estimated cutoff payout</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-wallet fa-2x text-warning opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-clipboard-list mr-1"></i>Cutoff Batch Processing — <span class="text-dark"><?php echo htmlspecialchars($selectedCutoff['label']); ?></span></h6>
            <div>
                <?php if ($batchRows): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Process payroll for ALL visible employees in <?php echo htmlspecialchars($selectedCutoff['label']); ?>?\nThis will mark them as Processed and visible to staff.');">
                    <input type="hidden" name="action" value="process_batch">
                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($selectedCutoff['start']); ?>">
                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($selectedCutoff['end']); ?>">
                    <?php foreach ($batchRows as $br): ?>
                    <input type="hidden" name="emp_ids[]" value="<?php echo (int)$br['emp']['id']; ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-bolt mr-1"></i>Process All (<?php echo count($batchRows); ?>)</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 text-nowrap" style="min-width:960px;">
                    <thead class="bg-light text-center small">
                        <tr>
                            <th>Employee</th>
                            <th class="text-center">Rate/hr</th>
                            <th class="text-center">Days</th>
                            <th class="text-center">Hours</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right">Net Pay</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$batchRows): ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-4">No employees with attendance for this cutoff yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($batchRows as $br):
                            $e = $br['emp'];
                            $isProcessed = $br['status'] === 'processed';
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="mr-2"><i class="fas fa-user-circle text-gray-400 fa-lg"></i></div>
                                    <div>
                                        <a href="payroll.php?employee_id=<?php echo (int)$e['id']; ?>" class="font-weight-bold"><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></a>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($e['position'] ?? '—'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">₱ <?php echo number_format((float)($e['hourly_rate'] ?? 0), 2); ?></td>
                            <td class="text-center"><?php echo (int)$br['days']; ?></td>
                            <td class="text-center"><?php echo number_format($br['hours'], 2); ?> h</td>
                            <td class="text-right"><strong>₱ <?php echo number_format($br['gross'], 2); ?></strong></td>
                            <td class="text-right"><span class="badge badge-success px-3 py-2" style="font-size:.85rem;">₱ <?php echo number_format(max($br['net'], 0), 2); ?></span></td>
                            <td class="text-center">
                                <?php if ($isProcessed): ?>
                                <span class="badge badge-success px-3 py-2" style="font-size:.78rem;"><i class="fas fa-check-circle mr-1"></i>Processed</span>
                                <div class="text-xs text-muted mt-1"><?php echo $br['processed_at'] ? date('M d, h:i A', strtotime($br['processed_at'])) : ''; ?></div>
                                <?php else: ?>
                                <span class="badge badge-secondary px-3 py-2" style="font-size:.78rem;"><i class="fas fa-file-alt mr-1"></i>Draft</span>
                                <div class="text-xs text-muted mt-1">Staff cannot see yet</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!$isProcessed): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Mark this employee\\'s payroll as PROCESSED?\n\nOnce processed, this will be visible to <?php echo htmlspecialchars($e['first_name']); ?>.');">
                                    <input type="hidden" name="action" value="process">
                                    <input type="hidden" name="employee_id" value="<?php echo (int)$e['id']; ?>">
                                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($selectedCutoff['start']); ?>">
                                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($selectedCutoff['end']); ?>">
                                    <input type="hidden" name="total_hours" value="<?php echo number_format($br['hours'], 2); ?>">
                                    <input type="hidden" name="total_days" value="<?php echo (int)$br['days']; ?>">
                                    <input type="hidden" name="hourly_rate" value="<?php echo (float)($e['hourly_rate'] ?? 0); ?>">
                                    <input type="hidden" name="gross_pay" value="<?php echo number_format($br['gross'], 2); ?>">
                                    <input type="hidden" name="sss" value="<?php echo number_format($br['sss'], 2); ?>">
                                    <input type="hidden" name="philhealth" value="<?php echo number_format($br['philhealth'], 2); ?>">
                                    <input type="hidden" name="pagibig" value="<?php echo number_format($br['pagibig'], 2); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-check mr-1"></i>Process</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Revert this payroll back to DRAFT?\nIt will be hidden from <?php echo htmlspecialchars($e['first_name']); ?> again.');">
                                    <input type="hidden" name="action" value="revert">
                                    <input type="hidden" name="employee_id" value="<?php echo (int)$e['id']; ?>">
                                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($selectedCutoff['start']); ?>">
                                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($selectedCutoff['end']); ?>">
                                    <input type="hidden" name="total_hours" value="<?php echo number_format($br['hours'], 2); ?>">
                                    <input type="hidden" name="total_days" value="<?php echo (int)$br['days']; ?>">
                                    <input type="hidden" name="hourly_rate" value="<?php echo (float)($e['hourly_rate'] ?? 0); ?>">
                                    <input type="hidden" name="gross_pay" value="<?php echo number_format($br['gross'], 2); ?>">
                                    <input type="hidden" name="sss" value="<?php echo number_format($br['sss'], 2); ?>">
                                    <input type="hidden" name="philhealth" value="<?php echo number_format($br['philhealth'], 2); ?>">
                                    <input type="hidden" name="pagibig" value="<?php echo number_format($br['pagibig'], 2); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo mr-1"></i>Revert</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php else: ?>

    <?php if (!$staffEmpId || !$myEmpData): ?>
    <div class="alert alert-info shadow-sm"><i class="fas fa-info-circle mr-1"></i>No linked employee record found for this account. Please contact the administrator.</div>
    <?php else: ?>

    <div class="row">
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Hours This Month</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($totalHoursMonth, 2); ?> <small class="text-xs text-muted">hrs</small></div>
                            <div class="mt-1 text-xs text-muted">Days present: <strong><?php echo $daysPresent; ?></strong></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-clock fa-2x text-success opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Hourly Rate</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">₱ <?php echo number_format((float)($myEmpData['hourly_rate'] ?? 0), 2); ?></div>
                            <div class="mt-1 text-xs text-muted"><?php echo !empty($myEmpData['status']) ? ucfirst($myEmpData['status']) : 'Active'; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-hand-holding-usd fa-2x text-info opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Latest Processed Net</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php
                                $latestProcessed = null;
                                foreach ($cutoffs as $c) {
                                    if ($c['status'] === 'processed') { $latestProcessed = $c; break; }
                                }
                                if (!$latestProcessed) { $latestProcessed = $cutoffs[0] ?? null; }
                                $net = 0;
                                if ($latestProcessed) { $net = $latestProcessed['gross']; }
                                echo '₱ ' . number_format(max($net, 0), 2);
                                ?>
                            </div>
                            <div class="mt-1 text-xs text-muted">
                                <?php
                                if ($latestProcessed && $latestProcessed['status'] === 'processed') {
                                    echo $latestProcessed['label'];
                                } elseif ($latestProcessed) {
                                    echo '<span class="text-warning">Draft - Pending processing</span>';
                                } else {
                                    echo 'No data';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-wallet fa-2x text-warning opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-receipt mr-1"></i>Cutoff Summary (Last 6 Periods)</h6>
            <?php if (!$isAdmin): ?>
            <div class="small text-muted mt-1 mt-md-0"><i class="fas fa-info-circle mr-1"></i>Only <strong>Processed</strong> records show your final net pay.</div>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 text-nowrap" style="min-width:760px;">
                    <thead class="bg-light text-center small">
                        <tr>
                            <th>Cutoff Period</th>
                            <th class="text-center">Days</th>
                            <th class="text-center">Hours</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right">Net Pay</th>
                            <th class="text-center">Status</th>
                            <?php if ($isAdmin): ?><th class="text-center">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$cutoffs): ?>
                        <tr><td colspan="<?php echo $isAdmin ? 7 : 6; ?>" class="text-center text-gray-500 py-4">No payroll records yet. Attendance data needed to compute payroll.</td></tr>
                        <?php else: ?>
                        <?php foreach ($cutoffs as $c):
                            $net = $c['gross'];
                            $isProcessed = $c['status'] === 'processed';
                            $staffCanSeeFull = $isProcessed;
                        ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo htmlspecialchars($c['label']); ?></td>
                            <td class="text-center"><?php echo (int)$c['days']; ?></td>
                            <td class="text-center"><?php echo number_format($c['hours'], 2); ?> h</td>
                            <?php if ($isAdmin || $staffCanSeeFull): ?>
                            <td class="text-right"><strong>₱ <?php echo number_format($c['gross'], 2); ?></strong></td>
                            <td class="text-right"><span class="badge badge-success px-3 py-2" style="font-size:.9rem;">₱ <?php echo number_format(max($net, 0), 2); ?></span></td>
                            <?php else: ?>
                            <td class="text-right text-muted"><em>Pending</em></td>
                            <td class="text-right"><span class="badge badge-secondary px-3 py-2" style="font-size:.9rem;"><i class="fas fa-lock mr-1"></i>Not yet processed</span></td>
                            <?php endif; ?>
                            <td class="text-center">
                                <?php if ($isProcessed): ?>
                                <span class="badge badge-success px-3 py-2" style="font-size:.78rem;"><i class="fas fa-check-circle mr-1"></i>Processed</span>
                                <div class="text-xs text-muted mt-1"><?php echo $c['processed_at'] ? date('M d, h:i A', strtotime($c['processed_at'])) : ''; ?></div>
                                <?php else: ?>
                                <span class="badge badge-secondary px-3 py-2" style="font-size:.78rem;"><i class="fas fa-file-alt mr-1"></i>Draft</span>
                                <?php if (!$isAdmin): ?><div class="text-xs text-muted mt-1">Admin still processing</div><?php else: ?><div class="text-xs text-muted mt-1">Hidden from staff</div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($isAdmin): ?>
                            <td class="text-center">
                                <?php if (!$isProcessed): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Mark this cutoff as PROCESSED?\nThis will make it visible to the employee.');">
                                    <input type="hidden" name="action" value="process">
                                    <input type="hidden" name="employee_id" value="<?php echo (int)$staffEmpId; ?>">
                                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($c['start']); ?>">
                                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($c['end']); ?>">
                                    <input type="hidden" name="total_hours" value="<?php echo number_format($c['hours'], 2); ?>">
                                    <input type="hidden" name="total_days" value="<?php echo (int)$c['days']; ?>">
                                    <input type="hidden" name="hourly_rate" value="<?php echo number_format($c['rate'], 2); ?>">
                                    <input type="hidden" name="gross_pay" value="<?php echo number_format($c['gross'], 2); ?>">
                                    <input type="hidden" name="sss" value="<?php echo number_format($c['sss'], 2); ?>">
                                    <input type="hidden" name="philhealth" value="<?php echo number_format($c['philhealth'], 2); ?>">
                                    <input type="hidden" name="pagibig" value="<?php echo number_format($c['pagibig'], 2); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-check mr-1"></i>Process</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Revert back to DRAFT?\nIt will be hidden again from the employee.');">
                                    <input type="hidden" name="action" value="revert">
                                    <input type="hidden" name="employee_id" value="<?php echo (int)$staffEmpId; ?>">
                                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($c['start']); ?>">
                                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($c['end']); ?>">
                                    <input type="hidden" name="total_hours" value="<?php echo number_format($c['hours'], 2); ?>">
                                    <input type="hidden" name="total_days" value="<?php echo (int)$c['days']; ?>">
                                    <input type="hidden" name="hourly_rate" value="<?php echo number_format($c['rate'], 2); ?>">
                                    <input type="hidden" name="gross_pay" value="<?php echo number_format($c['gross'], 2); ?>">
                                    <input type="hidden" name="sss" value="<?php echo number_format($c['sss'], 2); ?>">
                                    <input type="hidden" name="philhealth" value="<?php echo number_format($c['philhealth'], 2); ?>">
                                    <input type="hidden" name="pagibig" value="<?php echo number_format($c['pagibig'], 2); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo mr-1"></i>Revert</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-check mr-1"></i>Attendance - <?php echo date('F Y'); ?> (<?php echo count($currentMonthAttendance); ?> records)</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 text-nowrap" style="min-width:820px;">
                    <thead class="bg-light text-center small">
                        <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th><th>Source</th><th>Remarks</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$currentMonthAttendance): ?>
                        <tr><td colspan="7" class="text-center text-gray-500 py-4">No attendance records for this month.</td></tr>
                        <?php else: ?>
                        <?php foreach ($currentMonthAttendance as $a):
                            $h = $a['hours_worked'] ?? null;
                            $statusClass = strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success');
                        ?>
                        <tr>
                            <td class="text-center"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?></td>
                            <td class="text-center"><?php echo $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '-'; ?></td>
                            <td class="text-center"><?php echo $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '-'; ?></td>
                            <td class="text-center"><strong><?php echo $h !== null ? number_format((float)$h, 2) . ' h' : '-'; ?></strong></td>
                            <td class="text-center">
                                <span class="badge badge-<?php echo $statusClass; ?>">
                                    <?php echo ucfirst($a['status'] ?? 'present'); ?>
                                </span>
                            </td>
                            <td class="text-center text-xs text-muted"><?php echo ucfirst($a['source'] ?? '-'); ?></td>
                            <td class="text-xs"><?php echo htmlspecialchars($a['remarks'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
(function() {
    var btns = document.querySelectorAll('[data-dismiss-vanilla]');
    btns.forEach(function(b) {
        b.addEventListener('click', function() {
            var el = this.closest('.alert');
            if (el) { el.style.display = 'none'; }
        });
    });
})();
</script>

<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
