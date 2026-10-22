<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_once 'includes/attendance_helper.php';
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
            regular_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            ot_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            ot_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            sss DECIMAL(10,2) NOT NULL DEFAULT 0,
            philhealth DECIMAL(10,2) NOT NULL DEFAULT 0,
            pagibig DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_advance DECIMAL(10,2) NOT NULL DEFAULT 0,
            other_deductions DECIMAL(10,2) NOT NULL DEFAULT 0,
            deduction_remarks VARCHAR(255) NULL DEFAULT '',
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

        try { $conn->exec("ALTER TABLE payroll_records ADD COLUMN regular_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_days"); } catch (Exception $e) {}
        try { $conn->exec("ALTER TABLE payroll_records ADD COLUMN ot_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER regular_hours"); } catch (Exception $e) {}
        try { $conn->exec("ALTER TABLE payroll_records ADD COLUMN ot_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER ot_hours"); } catch (Exception $e) {}
        try { $conn->exec("ALTER TABLE payroll_records ADD COLUMN cash_advance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER pagibig"); } catch (Exception $e) {}
        try { $conn->exec("ALTER TABLE payroll_records ADD COLUMN deduction_remarks VARCHAR(255) NULL DEFAULT '' AFTER other_deductions"); } catch (Exception $e) {}
    } catch (Exception $e) {}
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = is_admin();

$actionMsg = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $conn) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'process' || $action === 'save_draft' || $action === 'revert') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $cStart = $_POST['cutoff_start'] ?? '';
            $cEnd = $_POST['cutoff_end'] ?? '';
            if ($empId && $cStart && $cEnd) {
                $rate = (float)($_POST['hourly_rate'] ?? 0);
                $totalD = (int)($_POST['total_days'] ?? 0);
                $regH = max(0, (float)($_POST['regular_hours'] ?? 0));
                $otH = max(0, (float)($_POST['ot_hours'] ?? 0));
                $totalH = round($regH + $otH, 2);
                $otPay = round($otH * $rate, 2);
                $gross = round($totalH * $rate, 2);

                $ca = max(0, (float)($_POST['cash_advance'] ?? 0));
                $sss = max(0, (float)($_POST['sss'] ?? 0));
                $ph = max(0, (float)($_POST['philhealth'] ?? 0));
                $pg = max(0, (float)($_POST['pagibig'] ?? 0));
                $other = max(0, (float)($_POST['other_deductions'] ?? 0));
                $dedRemarks = trim($_POST['deduction_remarks'] ?? '');

                $totalDeductions = $ca + $sss + $ph + $pg + $other;
                $net = max(0, round($gross - $totalDeductions, 2));

                if ($action === 'process') {
                    $newStatus = 'processed';
                    $procBy = $userId;
                    $procAt = date('Y-m-d H:i:s');
                } else {
                    $newStatus = 'draft';
                    $procBy = null;
                    $procAt = null;
                }

                $upsert = $conn->prepare("INSERT INTO payroll_records
                    (employee_id, cutoff_start, cutoff_end, total_hours, total_days, regular_hours, ot_hours, ot_pay, hourly_rate,
                     gross_pay, sss, philhealth, pagibig, cash_advance, other_deductions, deduction_remarks, net_pay, status, processed_by, processed_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        total_hours = VALUES(total_hours),
                        total_days = VALUES(total_days),
                        regular_hours = VALUES(regular_hours),
                        ot_hours = VALUES(ot_hours),
                        ot_pay = VALUES(ot_pay),
                        hourly_rate = VALUES(hourly_rate),
                        gross_pay = VALUES(gross_pay),
                        sss = VALUES(sss),
                        philhealth = VALUES(philhealth),
                        pagibig = VALUES(pagibig),
                        cash_advance = VALUES(cash_advance),
                        other_deductions = VALUES(other_deductions),
                        deduction_remarks = VALUES(deduction_remarks),
                        net_pay = VALUES(net_pay),
                        status = VALUES(status),
                        processed_by = VALUES(processed_by),
                        processed_at = VALUES(processed_at)");
                $upsert->execute([
                    $empId, $cStart, $cEnd, $totalH, $totalD, $regH, $otH, $otPay, $rate,
                    $gross, $sss, $ph, $pg, $ca, $other, $dedRemarks, $net, $newStatus, $procBy, $procAt
                ]);

                if ($newStatus === 'processed') {
                    $notif = $conn->prepare("INSERT INTO payroll_notifications
                        (processed_by, employee_id, cutoff_start, cutoff_end, is_read, created_at)
                        VALUES (?, ?, ?, ?, 0, NOW())
                        ON DUPLICATE KEY UPDATE is_read = 0, created_at = NOW(), processed_by = VALUES(processed_by)");
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

                if ($action === 'process') {
                    $actionMsg = "{$empName}'s payroll processed! Paid Hours: {$totalH}h ({$regH}h reg + {$otH}h OT) | Gross: ₱ " . number_format($gross, 2) . " | Deductions: -₱ " . number_format($totalDeductions, 2) . " | Net: ₱ " . number_format($net, 2) . ". Staff notified 🔔.";
                    $actionType = 'success';
                } elseif ($action === 'save_draft') {
                    $actionMsg = "Draft saved for {$empName}: {$totalH}h ({$regH}h reg + {$otH}h OT), Deductions: ₱ " . number_format($totalDeductions, 2) . ", Net: ₱ " . number_format($net, 2) . ". (Still in Draft).";
                    $actionType = 'warning';
                } else {
                    $actionMsg = "{$empName}'s payroll reverted to Draft. Saved hours and deductions are preserved.";
                    $actionType = 'info';
                }
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

                    // Fetch existing draft payroll record to preserve OT and deductions
                    $prStmt = $conn->prepare("SELECT * FROM payroll_records WHERE employee_id = ? AND cutoff_start = ? AND cutoff_end = ? LIMIT 1");
                    $prStmt->execute([$empId, $cStart, $cEnd]);
                    $existingRec = $prStmt->fetch();

                    $eS = $conn->prepare("SELECT hourly_rate FROM employees WHERE id = ? LIMIT 1");
                    $eS->execute([$empId]);
                    $empR = $eS->fetch();
                    $rate = (float)($empR['hourly_rate'] ?? 0);

                    // Attendance query using regular_hours (with 8h cap and undertime deduction) and summing approved OT
                    $pStmt = $conn->prepare("SELECT 
                        COUNT(DISTINCT attendance_date) AS days,
                        COALESCE(SUM(hours_worked), 0) AS raw_hours,
                        COALESCE(SUM(COALESCE(regular_hours, LEAST(hours_worked, 8.00))), 0) AS regular_hours,
                        COALESCE(SUM(CASE WHEN is_ot_approved = 1 THEN ot_hours ELSE 0 END), 0) AS preapproved_ot_hours
                        FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? AND hours_worked > 0");
                    $pStmt->execute([$empId, $cStart, $cEnd]);
                    $pd = $pStmt->fetch();

                    $d = (int)($pd['days'] ?? 0);
                    $regH = (float)($pd['regular_hours'] ?? 0);
                    $otH = isset($existingRec['ot_hours']) ? (float)$existingRec['ot_hours'] : (float)($pd['preapproved_ot_hours'] ?? 0);
                    $totalH = round($regH + $otH, 2);
                    $otPay = round($otH * $rate, 2);
                    $gross = round($totalH * $rate, 2);

                    $ca = (float)($existingRec['cash_advance'] ?? 0);
                    $sss = (float)($existingRec['sss'] ?? 0);
                    $ph = (float)($existingRec['philhealth'] ?? 0);
                    $pg = (float)($existingRec['pagibig'] ?? 0);
                    $other = (float)($existingRec['other_deductions'] ?? 0);
                    $dedRemarks = $existingRec['deduction_remarks'] ?? '';

                    $totalDeductions = $ca + $sss + $ph + $pg + $other;
                    $net = max(0, round($gross - $totalDeductions, 2));
                    $procAt = date('Y-m-d H:i:s');

                    $upsert = $conn->prepare("INSERT INTO payroll_records
                        (employee_id, cutoff_start, cutoff_end, total_hours, total_days, regular_hours, ot_hours, ot_pay, hourly_rate,
                         gross_pay, sss, philhealth, pagibig, cash_advance, other_deductions, deduction_remarks, net_pay, status, processed_by, processed_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            total_hours=VALUES(total_hours), total_days=VALUES(total_days),
                            regular_hours=VALUES(regular_hours), ot_hours=VALUES(ot_hours), ot_pay=VALUES(ot_pay),
                            hourly_rate=VALUES(hourly_rate), gross_pay=VALUES(gross_pay),
                            sss=VALUES(sss), philhealth=VALUES(philhealth), pagibig=VALUES(pagibig),
                            cash_advance=VALUES(cash_advance), other_deductions=VALUES(other_deductions),
                            deduction_remarks=VALUES(deduction_remarks),
                            net_pay=VALUES(net_pay), status='processed', processed_by=VALUES(processed_by), processed_at=VALUES(processed_at)");
                    $upsert->execute([
                        $empId, $cStart, $cEnd, $totalH, $d, $regH, $otH, $otPay, $rate,
                        $gross, $sss, $ph, $pg, $ca, $other, $dedRemarks, $net, $userId, $procAt
                    ]);

                    $notif = $conn->prepare("INSERT INTO payroll_notifications
                        (processed_by, employee_id, cutoff_start, cutoff_end, is_read, created_at)
                        VALUES (?, ?, ?, ?, 0, NOW())
                        ON DUPLICATE KEY UPDATE is_read = 0, created_at = NOW(), processed_by = VALUES(processed_by)");
                    $notif->execute([($userId ?: 0), $empId, $cStart, $cEnd]);

                    $count++;
                }
            }
            $actionMsg = "Successfully processed payroll for {$count} employee(s) ({$cStart} to {$cEnd}) with 8-hour cap rule, approved OT, and deductions. Notifications sent 🔔.";
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
            $viewEmpId = (int)($_GET['employee_id'] ?? ($_POST['view_employee_id'] ?? 0));
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
$reqStart = $_GET['cutoff_start'] ?? ($_POST['cutoff_start'] ?? '');
$reqEnd = $_GET['cutoff_end'] ?? ($_POST['cutoff_end'] ?? '');
if ($reqStart && $reqEnd) {
    foreach ($availableCutoffs as $c) {
        if ($c['start'] === $reqStart && $c['end'] === $reqEnd) {
            $selectedCutoff = $c;
            break;
        }
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

        $attStmt = $conn->prepare("SELECT attendance_date, time_in, time_out, hours_worked, regular_hours, ot_hours, is_ot_approved, status, source, remarks
            FROM attendance
            WHERE employee_id = ? AND MONTH(attendance_date)=? AND YEAR(attendance_date)=?
            ORDER BY attendance_date DESC");
        $attStmt->execute([$staffEmpId, $currentMonth, $currentYear]);
        $currentMonthAttendance = $attStmt->fetchAll();

        foreach ($currentMonthAttendance as $a) {
            $h = (float)($a['hours_worked'] ?? 0);
            $regH = isset($a['regular_hours']) && $a['regular_hours'] !== null ? (float)$a['regular_hours'] : calculate_regular_hours($h);
            $otH = !empty($a['is_ot_approved']) ? (float)($a['ot_hours'] ?? 0) : 0;
            if ($h > 0) { $totalHoursMonth += ($regH + $otH); $daysPresent++; }
        }

        $rate = (float)($myEmpData['hourly_rate'] ?? 0);
        foreach ($availableCutoffs as $c) {
            $cutoffStart = $c['start'];
            $cutoffEnd = $c['end'];
            $label = $c['label'];

            $pStmt = $conn->prepare("SELECT 
                COUNT(DISTINCT attendance_date) AS days,
                COALESCE(SUM(hours_worked), 0) AS raw_hours,
                COALESCE(SUM(COALESCE(regular_hours, LEAST(hours_worked, 8.00))), 0) AS regular_hours,
                COALESCE(SUM(GREATEST(hours_worked - 8.00, 0)), 0) AS excess_hours,
                COALESCE(SUM(CASE WHEN is_ot_approved = 1 THEN ot_hours ELSE 0 END), 0) AS preapproved_ot_hours
                FROM attendance
                WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? AND hours_worked > 0");
            $pStmt->execute([$staffEmpId, $cutoffStart, $cutoffEnd]);
            $pd = $pStmt->fetch();

            $prStmt = $conn->prepare("SELECT * FROM payroll_records
                WHERE employee_id = ? AND cutoff_start = ? AND cutoff_end = ? LIMIT 1");
            $prStmt->execute([$staffEmpId, $cutoffStart, $cutoffEnd]);
            $prRow = $prStmt->fetch();

            $prStatus = $prRow['status'] ?? 'draft';
            $prProcessedAt = $prRow['processed_at'] ?? null;

            $d = (int)($pd['days'] ?? 0);
            $regH = isset($prRow['regular_hours']) && $prRow['regular_hours'] > 0 ? (float)$prRow['regular_hours'] : (float)($pd['regular_hours'] ?? 0);
            $otH = isset($prRow['ot_hours']) ? (float)$prRow['ot_hours'] : (float)($pd['preapproved_ot_hours'] ?? 0);
            $excessH = max(0, (float)($pd['excess_hours'] ?? 0));
            $totalPaidH = round($regH + $otH, 2);
            $gross = round($totalPaidH * $rate, 2);

            $ca = (float)($prRow['cash_advance'] ?? 0);
            $sss = (float)($prRow['sss'] ?? 0);
            $ph = (float)($prRow['philhealth'] ?? 0);
            $pg = (float)($prRow['pagibig'] ?? 0);
            $other = (float)($prRow['other_deductions'] ?? 0);
            $dedRemarks = $prRow['deduction_remarks'] ?? '';
            $totalDeductions = $ca + $sss + $ph + $pg + $other;
            $net = max(0, round($gross - $totalDeductions, 2));

            $processedStatuses[$cutoffStart . '_' . $cutoffEnd] = ['status' => $prStatus, 'processed_at' => $prProcessedAt];

            $cutoffs[] = [
                'label' => $label,
                'start' => $cutoffStart,
                'end' => $cutoffEnd,
                'days' => $d,
                'regular_hours' => $regH,
                'ot_hours' => $otH,
                'excess_hours' => $excessH,
                'total_hours' => $totalPaidH,
                'raw_hours' => (float)($pd['raw_hours'] ?? 0),
                'gross' => $gross,
                'rate' => $rate,
                'cash_advance' => $ca,
                'sss' => $sss,
                'philhealth' => $ph,
                'pagibig' => $pg,
                'other_deductions' => $other,
                'total_deductions' => $totalDeductions,
                'deduction_remarks' => $dedRemarks,
                'net' => $net,
                'status' => $prStatus,
                'processed_at' => $prProcessedAt,
            ];
        }
    } catch (Exception $e) {}
}

$allEmployees = [];
$batchRows = [];
$batchStats = ['count' => 0, 'total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0, 'processed_count' => 0];
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

                // Query attendance with regular_hours (8h cap and undertime deduction) and preapproved OT
                $pStmt = $conn->prepare("SELECT 
                    COUNT(DISTINCT attendance_date) AS days,
                    COALESCE(SUM(hours_worked), 0) AS raw_hours,
                    COALESCE(SUM(COALESCE(regular_hours, LEAST(hours_worked, 8.00))), 0) AS regular_hours,
                    COALESCE(SUM(GREATEST(hours_worked - 8.00, 0)), 0) AS excess_hours,
                    COALESCE(SUM(CASE WHEN is_ot_approved = 1 THEN ot_hours ELSE 0 END), 0) AS preapproved_ot_hours
                    FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? AND hours_worked > 0");
                $pStmt->execute([$eid, $selectedCutoff['start'], $selectedCutoff['end']]);
                $pd = $pStmt->fetch();

                $prStmt = $conn->prepare("SELECT * FROM payroll_records
                    WHERE employee_id = ? AND cutoff_start = ? AND cutoff_end = ? LIMIT 1");
                $prStmt->execute([$eid, $selectedCutoff['start'], $selectedCutoff['end']]);
                $prRow = $prStmt->fetch();

                $prStatus = $prRow['status'] ?? 'draft';
                $prProcessedAt = $prRow['processed_at'] ?? null;

                $d = (int)($pd['days'] ?? 0);
                $rawH = (float)($pd['raw_hours'] ?? 0);
                $regH = isset($prRow['regular_hours']) && $prRow['regular_hours'] > 0 ? (float)$prRow['regular_hours'] : (float)($pd['regular_hours'] ?? 0);
                $otH = isset($prRow['ot_hours']) ? (float)$prRow['ot_hours'] : (float)($pd['preapproved_ot_hours'] ?? 0);
                $excessH = max(0, round($rawH - $regH, 2));

                $totalPaidH = round($regH + $otH, 2);
                $gross = round($totalPaidH * $rate, 2);

                $ca = (float)($prRow['cash_advance'] ?? 0);
                $sss = (float)($prRow['sss'] ?? 0);
                $ph = (float)($prRow['philhealth'] ?? 0);
                $pg = (float)($prRow['pagibig'] ?? 0);
                $other = (float)($prRow['other_deductions'] ?? 0);
                $dedRemarks = $prRow['deduction_remarks'] ?? '';
                $totalDeductions = $ca + $sss + $ph + $pg + $other;
                $net = max(0, round($gross - $totalDeductions, 2));

                if ($rawH > 0 || $prStatus === 'processed') {
                    $batchRows[] = [
                        'emp' => $e,
                        'days' => $d,
                        'raw_hours' => $rawH,
                        'regular_hours' => $regH,
                        'ot_hours' => $otH,
                        'excess_hours' => $excessH,
                        'total_hours' => $totalPaidH,
                        'gross' => $gross,
                        'cash_advance' => $ca,
                        'sss' => $sss,
                        'philhealth' => $ph,
                        'pagibig' => $pg,
                        'other_deductions' => $other,
                        'total_deductions' => $totalDeductions,
                        'deduction_remarks' => $dedRemarks,
                        'net' => $net,
                        'status' => $prStatus,
                        'processed_at' => $prProcessedAt,
                    ];
                    $batchStats['count']++;
                    $batchStats['total_gross'] += $gross;
                    $batchStats['total_deductions'] += $totalDeductions;
                    $batchStats['total_net'] += $net;
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
        <i class="fas fa-<?php echo $actionType === 'success' ? 'check-circle' : ($actionType === 'danger' ? 'exclamation-triangle' : ($actionType === 'warning' ? 'bell' : 'info-circle')); ?> mr-1"></i>
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
                Standard regular pay is capped at <strong>8 hrs/day</strong>. Admin sets Overtime (OT) and manual deductions (Vale, Uniform, SSS, PhilHealth, Pag-IBIG).
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
                <a href="payroll.php<?php echo $selectedCutoff['start'] ? '?cutoff_start=' . urlencode($selectedCutoff['start']) . '&cutoff_end=' . urlencode($selectedCutoff['end']) : ''; ?>" class="btn btn-sm btn-outline-dark mr-1"><i class="fas fa-arrow-left mr-1"></i>Batch View</a>
                <?php endif; ?>
                <?php if ($allEmployees): ?>
                <select class="form-control form-control-sm" style="width:auto;display:inline-block;margin-right:.5rem;" onchange="window.location='payroll.php?employee_id='+this.value<?php echo $selectedCutoff['start'] ? "+'&cutoff_start=".urlencode($selectedCutoff['start'])."&cutoff_end=".urlencode($selectedCutoff['end'])."'" : "''"; ?>;">
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Net Payroll</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">₱ <?php echo number_format($batchStats['total_net'], 2); ?></div>
                            <div class="mt-1 text-xs text-muted">
                                Gross: ₱ <?php echo number_format($batchStats['total_gross'], 2); ?>
                                <?php if ($batchStats['total_deductions'] > 0): ?>
                                • <span class="text-danger font-weight-bold">Ded: -₱ <?php echo number_format($batchStats['total_deductions'], 2); ?></span>
                                <?php endif; ?>
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
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-clipboard-list mr-1"></i>Cutoff Batch Processing — <span class="text-dark"><?php echo htmlspecialchars($selectedCutoff['label']); ?></span>
            </h6>
            <div>
                <?php if ($batchRows): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Process payroll for ALL <?php echo count($batchRows); ?> employees in <?php echo htmlspecialchars($selectedCutoff['label']); ?>?\n\nRegular hours (8h max), approved OT, and saved manual deductions will be applied. Continue?');">
                    <input type="hidden" name="action" value="process_batch">
                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($selectedCutoff['start']); ?>">
                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($selectedCutoff['end']); ?>">
                    <?php foreach ($batchRows as $br): ?>
                    <input type="hidden" name="emp_ids[]" value="<?php echo (int)$br['emp']['id']; ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm btn-success shadow-sm">
                        <i class="fas fa-bolt mr-1"></i>Process All (<?php echo count($batchRows); ?>)
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 text-nowrap" style="min-width:1080px;">
                    <thead class="bg-light text-center small font-weight-bold text-gray-700">
                        <tr>
                            <th>Employee</th>
                            <th class="text-center">Rate/hr</th>
                            <th class="text-center">Days</th>
                            <th class="text-center">Paid Hours (8h/day + OT)</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right bg-light text-danger">Deductions</th>
                            <th class="text-right">Net Pay</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$batchRows): ?>
                        <tr><td colspan="9" class="text-center text-gray-500 py-4">No employees with attendance for this cutoff yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($batchRows as $br):
                            $e = $br['emp'];
                            $isProcessed = $br['status'] === 'processed';
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="mr-2"><i class="fas fa-user-circle text-gray-400 fa-2x"></i></div>
                                    <div>
                                        <a href="payroll.php?employee_id=<?php echo (int)$e['id']; ?>&cutoff_start=<?php echo urlencode($selectedCutoff['start']); ?>&cutoff_end=<?php echo urlencode($selectedCutoff['end']); ?>" class="font-weight-bold text-primary">
                                            <?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>
                                        </a>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($e['position'] ?? '—'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">₱ <?php echo number_format((float)($e['hourly_rate'] ?? 0), 2); ?></td>
                            <td class="text-center"><?php echo (int)$br['days']; ?></td>
                            <td class="text-center">
                                <strong><?php echo number_format($br['total_hours'], 2); ?> h</strong>
                                <div class="text-xs text-muted">
                                    <?php echo number_format($br['regular_hours'], 2); ?>h reg
                                    <?php if ($br['ot_hours'] > 0): ?>
                                        + <span class="badge badge-success px-1"><i class="fas fa-check mr-1"></i><?php echo number_format($br['ot_hours'], 2); ?>h OT</span>
                                    <?php elseif ($br['excess_hours'] > 0): ?>
                                        <span class="badge badge-warning text-dark px-1" title="<?php echo number_format($br['excess_hours'], 2); ?>h unapproved excess hours">(+<?php echo number_format($br['excess_hours'], 2); ?>h OT Unapproved)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-right"><strong>₱ <?php echo number_format($br['gross'], 2); ?></strong></td>
                            <td class="text-right">
                                <?php if ($br['total_deductions'] > 0): ?>
                                    <span class="badge badge-warning text-dark font-weight-bold px-2 py-1" style="font-size:.82rem;">
                                        - ₱ <?php echo number_format($br['total_deductions'], 2); ?>
                                    </span>
                                    <?php if ($br['deduction_remarks']): ?>
                                    <div class="text-xs text-muted mt-1 text-truncate" style="max-width:150px;display:inline-block;" title="<?php echo htmlspecialchars($br['deduction_remarks']); ?>">
                                        <i class="fas fa-tag mr-1 text-secondary"></i><?php echo htmlspecialchars($br['deduction_remarks']); ?>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">₱ 0.00</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size:.88rem;">
                                    ₱ <?php echo number_format(max($br['net'], 0), 2); ?>
                                </span>
                            </td>
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
                                <button type="button" class="btn btn-sm btn-success shadow-sm btn-open-payroll-modal"
                                    title="Set Overtime & Deductions, then Process"
                                    data-emp-id="<?php echo (int)$e['id']; ?>"
                                    data-emp-name="<?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>"
                                    data-emp-pos="<?php echo htmlspecialchars($e['position'] ?? ''); ?>"
                                    data-cutoff-start="<?php echo htmlspecialchars($selectedCutoff['start']); ?>"
                                    data-cutoff-end="<?php echo htmlspecialchars($selectedCutoff['end']); ?>"
                                    data-cutoff-label="<?php echo htmlspecialchars($selectedCutoff['label']); ?>"
                                    data-rate="<?php echo (float)($e['hourly_rate'] ?? 0); ?>"
                                    data-days="<?php echo (int)$br['days']; ?>"
                                    data-reg-hours="<?php echo number_format($br['regular_hours'], 2, '.', ''); ?>"
                                    data-excess-hours="<?php echo number_format($br['excess_hours'], 2, '.', ''); ?>"
                                    data-ot-hours="<?php echo number_format($br['ot_hours'], 2, '.', ''); ?>"
                                    data-raw-hours="<?php echo number_format($br['raw_hours'], 2, '.', ''); ?>"
                                    data-ca="<?php echo number_format($br['cash_advance'], 2, '.', ''); ?>"
                                    data-sss="<?php echo number_format($br['sss'], 2, '.', ''); ?>"
                                    data-ph="<?php echo number_format($br['philhealth'], 2, '.', ''); ?>"
                                    data-pg="<?php echo number_format($br['pagibig'], 2, '.', ''); ?>"
                                    data-other="<?php echo number_format($br['other_deductions'], 2, '.', ''); ?>"
                                    data-remarks="<?php echo htmlspecialchars($br['deduction_remarks']); ?>"
                                    data-status="<?php echo htmlspecialchars($br['status']); ?>">
                                    <i class="fas fa-check-circle mr-1"></i>Process
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-info btn-view-payslip"
                                    title="View & Print Official Payslip"
                                    data-emp-name="<?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>"
                                    data-emp-pos="<?php echo htmlspecialchars($e['position'] ?? 'Staff'); ?>"
                                    data-cutoff-label="<?php echo htmlspecialchars($selectedCutoff['label']); ?>"
                                    data-rate="<?php echo (float)($e['hourly_rate'] ?? 0); ?>"
                                    data-days="<?php echo (int)$br['days']; ?>"
                                    data-reg-hours="<?php echo number_format($br['regular_hours'], 2, '.', ''); ?>"
                                    data-ot-hours="<?php echo number_format($br['ot_hours'], 2, '.', ''); ?>"
                                    data-total-hours="<?php echo number_format($br['total_hours'], 2, '.', ''); ?>"
                                    data-gross="<?php echo number_format($br['gross'], 2, '.', ''); ?>"
                                    data-ca="<?php echo number_format($br['cash_advance'], 2, '.', ''); ?>"
                                    data-sss="<?php echo number_format($br['sss'], 2, '.', ''); ?>"
                                    data-ph="<?php echo number_format($br['philhealth'], 2, '.', ''); ?>"
                                    data-pg="<?php echo number_format($br['pagibig'], 2, '.', ''); ?>"
                                    data-other="<?php echo number_format($br['other_deductions'], 2, '.', ''); ?>"
                                    data-remarks="<?php echo htmlspecialchars($br['deduction_remarks']); ?>"
                                    data-net="<?php echo number_format($br['net'], 2, '.', ''); ?>"
                                    data-processed-at="<?php echo $br['processed_at'] ? date('M d, Y h:i A', strtotime($br['processed_at'])) : ''; ?>">
                                    <i class="fas fa-receipt mr-1"></i>Payslip
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary ml-1 btn-open-payroll-modal"
                                    title="Adjust OT / deductions & reprocess"
                                    data-emp-id="<?php echo (int)$e['id']; ?>"
                                    data-emp-name="<?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>"
                                    data-emp-pos="<?php echo htmlspecialchars($e['position'] ?? ''); ?>"
                                    data-cutoff-start="<?php echo htmlspecialchars($selectedCutoff['start']); ?>"
                                    data-cutoff-end="<?php echo htmlspecialchars($selectedCutoff['end']); ?>"
                                    data-cutoff-label="<?php echo htmlspecialchars($selectedCutoff['label']); ?>"
                                    data-rate="<?php echo (float)($e['hourly_rate'] ?? 0); ?>"
                                    data-days="<?php echo (int)$br['days']; ?>"
                                    data-reg-hours="<?php echo number_format($br['regular_hours'], 2, '.', ''); ?>"
                                    data-excess-hours="<?php echo number_format($br['excess_hours'], 2, '.', ''); ?>"
                                    data-ot-hours="<?php echo number_format($br['ot_hours'], 2, '.', ''); ?>"
                                    data-raw-hours="<?php echo number_format($br['raw_hours'], 2, '.', ''); ?>"
                                    data-ca="<?php echo number_format($br['cash_advance'], 2, '.', ''); ?>"
                                    data-sss="<?php echo number_format($br['sss'], 2, '.', ''); ?>"
                                    data-ph="<?php echo number_format($br['philhealth'], 2, '.', ''); ?>"
                                    data-pg="<?php echo number_format($br['pagibig'], 2, '.', ''); ?>"
                                    data-other="<?php echo number_format($br['other_deductions'], 2, '.', ''); ?>"
                                    data-remarks="<?php echo htmlspecialchars($br['deduction_remarks']); ?>"
                                    data-status="<?php echo htmlspecialchars($br['status']); ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form method="POST" class="d-inline ml-1" onsubmit="return confirm('Revert this payroll back to DRAFT?\nIt will be hidden from <?php echo htmlspecialchars($e['first_name']); ?> again.');">
                                    <input type="hidden" name="action" value="revert">
                                    <input type="hidden" name="employee_id" value="<?php echo (int)$e['id']; ?>">
                                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($selectedCutoff['start']); ?>">
                                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($selectedCutoff['end']); ?>">
                                    <input type="hidden" name="regular_hours" value="<?php echo number_format($br['regular_hours'], 2, '.', ''); ?>">
                                    <input type="hidden" name="ot_hours" value="<?php echo number_format($br['ot_hours'], 2, '.', ''); ?>">
                                    <input type="hidden" name="total_days" value="<?php echo (int)$br['days']; ?>">
                                    <input type="hidden" name="hourly_rate" value="<?php echo (float)($e['hourly_rate'] ?? 0); ?>">
                                    <input type="hidden" name="cash_advance" value="<?php echo number_format($br['cash_advance'], 2, '.', ''); ?>">
                                    <input type="hidden" name="sss" value="<?php echo number_format($br['sss'], 2, '.', ''); ?>">
                                    <input type="hidden" name="philhealth" value="<?php echo number_format($br['philhealth'], 2, '.', ''); ?>">
                                    <input type="hidden" name="pagibig" value="<?php echo number_format($br['pagibig'], 2, '.', ''); ?>">
                                    <input type="hidden" name="other_deductions" value="<?php echo number_format($br['other_deductions'], 2, '.', ''); ?>">
                                    <input type="hidden" name="deduction_remarks" value="<?php echo htmlspecialchars($br['deduction_remarks']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Revert to Draft"><i class="fas fa-undo"></i></button>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Payable Hours This Month</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($totalHoursMonth, 2); ?> <small class="text-xs text-muted">hrs</small></div>
                            <div class="mt-1 text-xs text-muted">Days present: <strong><?php echo $daysPresent; ?></strong> (8h/day max + OT)</div>
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
                                if ($latestProcessed) { $net = $latestProcessed['net']; }
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
            <div class="small text-muted mt-1 mt-md-0"><i class="fas fa-info-circle mr-1"></i>Only <strong>Processed</strong> records show your final net pay and official payslip.</div>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 text-nowrap" style="min-width:920px;">
                    <thead class="bg-light text-center small font-weight-bold text-gray-700">
                        <tr>
                            <th>Cutoff Period</th>
                            <th class="text-center">Days</th>
                            <th class="text-center">Paid Hours (8h/day + OT)</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right bg-light text-danger">Deductions</th>
                            <th class="text-right">Net Pay</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$cutoffs): ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-4">No payroll records yet. Attendance data needed to compute payroll.</td></tr>
                        <?php else: ?>
                        <?php foreach ($cutoffs as $c):
                            $isProcessed = $c['status'] === 'processed';
                            $staffCanSeeFull = $isProcessed;
                        ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo htmlspecialchars($c['label']); ?></td>
                            <td class="text-center"><?php echo (int)$c['days']; ?></td>
                            <td class="text-center">
                                <strong><?php echo number_format($c['total_hours'], 2); ?> h</strong>
                                <div class="text-xs text-muted">
                                    <?php echo number_format($c['regular_hours'], 2); ?>h reg
                                    <?php if ($c['ot_hours'] > 0): ?>
                                        + <span class="badge badge-success px-1"><i class="fas fa-check mr-1"></i><?php echo number_format($c['ot_hours'], 2); ?>h OT</span>
                                    <?php elseif ($c['excess_hours'] > 0): ?>
                                        <span class="badge badge-warning text-dark px-1">(+<?php echo number_format($c['excess_hours'], 2); ?>h OT Unapproved)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if ($isAdmin || $staffCanSeeFull): ?>
                            <td class="text-right"><strong>₱ <?php echo number_format($c['gross'], 2); ?></strong></td>
                            <td class="text-right">
                                <?php if ($c['total_deductions'] > 0): ?>
                                    <span class="badge badge-warning text-dark font-weight-bold px-2 py-1">- ₱ <?php echo number_format($c['total_deductions'], 2); ?></span>
                                    <?php if ($c['deduction_remarks']): ?>
                                    <div class="text-xs text-muted mt-1 text-truncate" style="max-width:140px;display:inline-block;" title="<?php echo htmlspecialchars($c['deduction_remarks']); ?>">
                                        <i class="fas fa-tag mr-1 text-secondary"></i><?php echo htmlspecialchars($c['deduction_remarks']); ?>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">₱ 0.00</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size:.9rem;">
                                    ₱ <?php echo number_format(max($c['net'], 0), 2); ?>
                                </span>
                            </td>
                            <?php else: ?>
                            <td class="text-right text-muted"><em>Pending</em></td>
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
                            <td class="text-center">
                                <?php if ($isProcessed): ?>
                                <button type="button" class="btn btn-sm btn-outline-info btn-view-payslip"
                                    title="View & Print Official Payslip"
                                    data-emp-name="<?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?>"
                                    data-emp-pos="<?php echo htmlspecialchars($myEmpData['position'] ?? 'Staff'); ?>"
                                    data-cutoff-label="<?php echo htmlspecialchars($c['label']); ?>"
                                    data-rate="<?php echo (float)($c['rate'] ?? 0); ?>"
                                    data-days="<?php echo (int)$c['days']; ?>"
                                    data-reg-hours="<?php echo number_format($c['regular_hours'], 2, '.', ''); ?>"
                                    data-ot-hours="<?php echo number_format($c['ot_hours'], 2, '.', ''); ?>"
                                    data-total-hours="<?php echo number_format($c['total_hours'], 2, '.', ''); ?>"
                                    data-gross="<?php echo number_format($c['gross'], 2, '.', ''); ?>"
                                    data-ca="<?php echo number_format($c['cash_advance'], 2, '.', ''); ?>"
                                    data-sss="<?php echo number_format($c['sss'], 2, '.', ''); ?>"
                                    data-ph="<?php echo number_format($c['philhealth'], 2, '.', ''); ?>"
                                    data-pg="<?php echo number_format($c['pagibig'], 2, '.', ''); ?>"
                                    data-other="<?php echo number_format($c['other_deductions'], 2, '.', ''); ?>"
                                    data-remarks="<?php echo htmlspecialchars($c['deduction_remarks']); ?>"
                                    data-net="<?php echo number_format($c['net'], 2, '.', ''); ?>"
                                    data-processed-at="<?php echo $c['processed_at'] ? date('M d, Y h:i A', strtotime($c['processed_at'])) : ''; ?>">
                                    <i class="fas fa-receipt mr-1"></i>Payslip
                                </button>
                                <?php if ($isAdmin): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary ml-1 btn-open-payroll-modal"
                                    title="Adjust OT & deductions"
                                    data-emp-id="<?php echo (int)$staffEmpId; ?>"
                                    data-emp-name="<?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?>"
                                    data-emp-pos="<?php echo htmlspecialchars($myEmpData['position'] ?? ''); ?>"
                                    data-cutoff-start="<?php echo htmlspecialchars($c['start']); ?>"
                                    data-cutoff-end="<?php echo htmlspecialchars($c['end']); ?>"
                                    data-cutoff-label="<?php echo htmlspecialchars($c['label']); ?>"
                                    data-rate="<?php echo (float)($c['rate'] ?? 0); ?>"
                                    data-days="<?php echo (int)$c['days']; ?>"
                                    data-reg-hours="<?php echo number_format($c['regular_hours'], 2, '.', ''); ?>"
                                    data-excess-hours="<?php echo number_format($c['excess_hours'], 2, '.', ''); ?>"
                                    data-ot-hours="<?php echo number_format($c['ot_hours'], 2, '.', ''); ?>"
                                    data-raw-hours="<?php echo number_format($c['raw_hours'], 2, '.', ''); ?>"
                                    data-ca="<?php echo number_format($c['cash_advance'], 2, '.', ''); ?>"
                                    data-sss="<?php echo number_format($c['sss'], 2, '.', ''); ?>"
                                    data-ph="<?php echo number_format($c['philhealth'], 2, '.', ''); ?>"
                                    data-pg="<?php echo number_format($c['pagibig'], 2, '.', ''); ?>"
                                    data-other="<?php echo number_format($c['other_deductions'], 2, '.', ''); ?>"
                                    data-remarks="<?php echo htmlspecialchars($c['deduction_remarks']); ?>"
                                    data-status="<?php echo htmlspecialchars($c['status']); ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form method="POST" class="d-inline ml-1" onsubmit="return confirm('Revert back to DRAFT?\nIt will be hidden again from the employee.');">
                                    <input type="hidden" name="action" value="revert">
                                    <input type="hidden" name="employee_id" value="<?php echo (int)$staffEmpId; ?>">
                                    <input type="hidden" name="view_employee_id" value="<?php echo (int)$staffEmpId; ?>">
                                    <input type="hidden" name="cutoff_start" value="<?php echo htmlspecialchars($c['start']); ?>">
                                    <input type="hidden" name="cutoff_end" value="<?php echo htmlspecialchars($c['end']); ?>">
                                    <input type="hidden" name="regular_hours" value="<?php echo number_format($c['regular_hours'], 2, '.', ''); ?>">
                                    <input type="hidden" name="ot_hours" value="<?php echo number_format($c['ot_hours'], 2, '.', ''); ?>">
                                    <input type="hidden" name="total_days" value="<?php echo (int)$c['days']; ?>">
                                    <input type="hidden" name="hourly_rate" value="<?php echo (float)$c['rate']; ?>">
                                    <input type="hidden" name="cash_advance" value="<?php echo number_format($c['cash_advance'], 2, '.', ''); ?>">
                                    <input type="hidden" name="sss" value="<?php echo number_format($c['sss'], 2, '.', ''); ?>">
                                    <input type="hidden" name="philhealth" value="<?php echo number_format($c['philhealth'], 2, '.', ''); ?>">
                                    <input type="hidden" name="pagibig" value="<?php echo number_format($c['pagibig'], 2, '.', ''); ?>">
                                    <input type="hidden" name="other_deductions" value="<?php echo number_format($c['other_deductions'], 2, '.', ''); ?>">
                                    <input type="hidden" name="deduction_remarks" value="<?php echo htmlspecialchars($c['deduction_remarks']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Revert to Draft"><i class="fas fa-undo"></i></button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($isAdmin): ?>
                                    <button type="button" class="btn btn-sm btn-success shadow-sm btn-open-payroll-modal"
                                        title="Set Overtime & Deductions, then Process"
                                        data-emp-id="<?php echo (int)$staffEmpId; ?>"
                                        data-emp-name="<?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?>"
                                        data-emp-pos="<?php echo htmlspecialchars($myEmpData['position'] ?? ''); ?>"
                                        data-cutoff-start="<?php echo htmlspecialchars($c['start']); ?>"
                                        data-cutoff-end="<?php echo htmlspecialchars($c['end']); ?>"
                                        data-cutoff-label="<?php echo htmlspecialchars($c['label']); ?>"
                                        data-rate="<?php echo (float)($c['rate'] ?? 0); ?>"
                                        data-days="<?php echo (int)$c['days']; ?>"
                                        data-reg-hours="<?php echo number_format($c['regular_hours'], 2, '.', ''); ?>"
                                        data-excess-hours="<?php echo number_format($c['excess_hours'], 2, '.', ''); ?>"
                                        data-ot-hours="<?php echo number_format($c['ot_hours'], 2, '.', ''); ?>"
                                        data-raw-hours="<?php echo number_format($c['raw_hours'], 2, '.', ''); ?>"
                                        data-ca="<?php echo number_format($c['cash_advance'], 2, '.', ''); ?>"
                                        data-sss="<?php echo number_format($c['sss'], 2, '.', ''); ?>"
                                        data-ph="<?php echo number_format($c['philhealth'], 2, '.', ''); ?>"
                                        data-pg="<?php echo number_format($c['pagibig'], 2, '.', ''); ?>"
                                        data-other="<?php echo number_format($c['other_deductions'], 2, '.', ''); ?>"
                                        data-remarks="<?php echo htmlspecialchars($c['deduction_remarks']); ?>"
                                        data-status="<?php echo htmlspecialchars($c['status']); ?>">
                                        <i class="fas fa-check-circle mr-1"></i>Process
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted small"><i class="fas fa-clock mr-1"></i>Awaiting processing</span>
                                    <?php endif; ?>
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

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-check mr-1"></i>Attendance - <?php echo date('F Y'); ?> (<?php echo count($currentMonthAttendance); ?> records)</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 text-nowrap" style="min-width:820px;">
                    <thead class="bg-light text-center small font-weight-bold">
                        <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Payable Hours</th><th>Status</th><th>Source</th><th>Remarks</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$currentMonthAttendance): ?>
                        <tr><td colspan="7" class="text-center text-gray-500 py-4">No attendance records for this month.</td></tr>
                        <?php else: ?>
                        <?php foreach ($currentMonthAttendance as $a):
                            $h = (float)($a['hours_worked'] ?? 0);
                            $reg = min($h, 8.0);
                            $ot = !empty($a['is_ot_approved']) ? (float)($a['ot_hours'] ?? 0) : 0;
                            $statusClass = strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success');
                        ?>
                        <tr>
                            <td class="text-center"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?></td>
                            <td class="text-center"><?php echo $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '-'; ?></td>
                            <td class="text-center"><?php echo $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '-'; ?></td>
                            <td class="text-center">
                                <strong><?php echo $h > 0 ? number_format($reg + $ot, 2) . ' h' : '-'; ?></strong>
                                <?php if ($ot > 0): ?>
                                    <span class="badge badge-success px-1 ml-1">+<?php echo number_format($ot, 2); ?>h OT</span>
                                <?php elseif ($h > 8.0): ?>
                                    <span class="badge badge-warning text-dark px-1 ml-1">8h reg</span>
                                <?php endif; ?>
                            </td>
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

<!-- ============================================================== -->
<!-- MODAL: SET OVERTIME & MANUAL DEDUCTIONS, THEN PROCESS PAYROLL -->
<!-- ============================================================== -->
<div class="modal fade" id="payrollModal" tabindex="-1" role="dialog" aria-labelledby="payrollModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" id="payrollForm">
                <input type="hidden" name="action" id="payrollFormAction" value="process">
                <input type="hidden" name="employee_id" id="modalEmpId">
                <input type="hidden" name="cutoff_start" id="modalCutoffStart">
                <input type="hidden" name="cutoff_end" id="modalCutoffEnd">
                <input type="hidden" name="hourly_rate" id="modalHourlyRate">
                <input type="hidden" name="total_days" id="modalTotalDays">
                <input type="hidden" name="regular_hours" id="modalRegularHours">
                <?php if ($viewEmpId): ?>
                <input type="hidden" name="view_employee_id" value="<?php echo (int)$viewEmpId; ?>">
                <?php endif; ?>

                <div class="modal-header bg-gradient-success text-white py-3">
                    <h5 class="modal-title font-weight-bold d-flex align-items-center" id="payrollModalLabel">
                        <i class="fas fa-calculator mr-2"></i>Process Payroll, Overtime & Deductions
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity:.8;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body p-4">
                    <!-- Employee & Cutoff Banner -->
                    <div class="row align-items-center bg-light rounded p-3 mb-4 border">
                        <div class="col-md-7 d-flex align-items-center">
                            <div class="rounded-circle bg-white shadow-sm p-3 mr-3 text-success">
                                <i class="fas fa-user-circle fa-2x"></i>
                            </div>
                            <div>
                                <h5 class="mb-0 font-weight-bold text-gray-800" id="modalEmpNameDisplay">Employee Name</h5>
                                <div class="text-xs text-muted" id="modalEmpPosDisplay">Position</div>
                                <div class="text-xs text-primary font-weight-bold mt-1">
                                    <i class="fas fa-calendar-alt mr-1"></i>Cutoff: <span id="modalCutoffLabelDisplay">Oct 1-15, 2026</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5 text-md-right mt-3 mt-md-0 border-left-md pl-md-4">
                            <div class="text-xs text-muted text-uppercase font-weight-bold">Cutoff Attendance</div>
                            <div class="small mt-1 text-gray-700">
                                <span id="modalDaysDisplay" class="font-weight-bold">0</span> days worked • Rate: <strong>₱ <span id="modalRateDisplay">0.00</span></strong>/hr
                            </div>
                            <div class="small text-muted mt-1">
                                Raw Hours: <span id="modalRawHoursDisplay">0.00</span> h
                            </div>
                        </div>
                    </div>

                    <!-- Overtime (OT) Setting Section (8-Hour Rule) -->
                    <div class="card border-left-primary shadow-sm mb-4">
                        <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-clock mr-1"></i>Working Hours & Overtime (OT)
                            </h6>
                            <span class="badge badge-primary">8 hours/day standard</span>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center mb-3">
                                <div class="col-sm-6 mb-2 mb-sm-0">
                                    <div class="border rounded p-2 bg-light">
                                        <div class="small font-weight-bold text-gray-700">Regular Hours (8h/day max):</div>
                                        <div class="h5 mb-0 font-weight-bold text-success">
                                            <span id="modalRegularHoursDisplay">0.00</span> hrs
                                            <small class="text-xs text-muted font-weight-normal">(₱ <span id="modalRegPayDisplay">0.00</span>)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="border rounded p-2 bg-light">
                                        <div class="small font-weight-bold text-gray-700">Detected Excess Hours:</div>
                                        <div class="h5 mb-0 font-weight-bold text-warning">
                                            <span id="modalExcessHoursDisplay">0.00</span> hrs
                                            <small class="text-xs text-muted font-weight-normal">(Potential OT)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label class="small font-weight-bold text-gray-800">
                                    <i class="fas fa-check-circle text-success mr-1"></i>Approved Overtime Hours (OT) to Pay:
                                </label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" name="ot_hours" id="inputOtHours" class="form-control font-weight-bold text-primary payroll-calc-input" placeholder="0.00" value="0.00">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnOtZero">0h (No OT)</button>
                                        <button type="button" class="btn btn-outline-success btn-sm" id="btnOtApproveAll">Approve All (<span id="modalOtExcessBadge">0</span>h)</button>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    Rate: <strong>1.0x (₱ <span id="modalOtRateText">0.00</span>/hr)</strong>. Only approved OT hours will be paid. Unapproved excess hours will not be compensated.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Deductions Form Section -->
                    <div class="card border-left-danger shadow-sm mb-4">
                        <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-danger">
                                <i class="fas fa-minus-circle mr-1"></i>Manual Deductions
                            </h6>
                            <span class="badge badge-light border text-muted">Set deductions before processing</span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Input any deductions applicable for this cutoff period (Cash Advance / Vale, Uniform, Shortage, SSS, PhilHealth, Pag-IBIG).
                            </p>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-gray-700">
                                        <i class="fas fa-hand-holding-usd text-warning mr-1"></i>Cash Advance / Vale (₱)
                                    </label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text font-weight-bold">₱</span></div>
                                        <input type="number" step="0.01" min="0" name="cash_advance" id="inputCashAdvance" class="form-control payroll-calc-input" placeholder="0.00" value="0.00">
                                    </div>
                                    <small class="form-text text-muted">Deduction for cash advance / vale</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-gray-700">
                                        <i class="fas fa-tag text-secondary mr-1"></i>Other Deductions (₱)
                                    </label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text font-weight-bold">₱</span></div>
                                        <input type="number" step="0.01" min="0" name="other_deductions" id="inputOtherDeductions" class="form-control payroll-calc-input" placeholder="0.00" value="0.00">
                                    </div>
                                    <small class="form-text text-muted">Uniform, shortage, spillage, penalties, etc.</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="small font-weight-bold text-gray-700">SSS Contribution (₱)</label>
                                    <div class="input-group input-group-sm">
                                        <div class="input-group-prepend"><span class="input-group-text">₱</span></div>
                                        <input type="number" step="0.01" min="0" name="sss" id="inputSSS" class="form-control payroll-calc-input" placeholder="0.00" value="0.00">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="small font-weight-bold text-gray-700">PhilHealth (₱)</label>
                                    <div class="input-group input-group-sm">
                                        <div class="input-group-prepend"><span class="input-group-text">₱</span></div>
                                        <input type="number" step="0.01" min="0" name="philhealth" id="inputPhilHealth" class="form-control payroll-calc-input" placeholder="0.00" value="0.00">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="small font-weight-bold text-gray-700">Pag-IBIG (₱)</label>
                                    <div class="input-group input-group-sm">
                                        <div class="input-group-prepend"><span class="input-group-text">₱</span></div>
                                        <input type="number" step="0.01" min="0" name="pagibig" id="inputPagIbig" class="form-control payroll-calc-input" placeholder="0.00" value="0.00">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label class="small font-weight-bold text-gray-700">
                                    <i class="fas fa-comment-dots text-secondary mr-1"></i>Deduction Remarks / Reason
                                </label>
                                <input type="text" name="deduction_remarks" id="inputDeductionRemarks" class="form-control form-control-sm" placeholder="e.g. Vale Oct 5 (₱500), Uniform installment (₱200)" maxlength="255">
                                <small class="form-text text-muted">Reason or breakdown to show on employee's payslip breakdown.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Live Calculation Box -->
                    <div class="bg-gradient-light rounded border p-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted font-weight-bold small">Total Paid Hours:</span>
                            <span class="font-weight-bold text-dark"><span id="summaryPaidHours">0.00</span> hrs (<span id="summaryRegHours">0.00</span>h reg + <span id="summaryOtHours">0.00</span>h OT)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted font-weight-bold small">Gross Earnings:</span>
                            <span class="font-weight-bold text-dark">₱ <span id="summaryGross">0.00</span></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-danger font-weight-bold small">Total Manual Deductions:</span>
                            <span class="font-weight-bold text-danger">- ₱ <span id="summaryDeductions">0.00</span></span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0 font-weight-bold text-gray-900">Estimated Net Pay:</h5>
                                <small class="text-muted">Take-home amount for employee</small>
                            </div>
                            <div class="text-right">
                                <div class="h4 mb-0 font-weight-bold text-success">₱ <span id="summaryNetPay">0.00</span></div>
                            </div>
                        </div>
                        <div id="deductionWarning" class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none">
                            <i class="fas fa-exclamation-triangle mr-1"></i>Warning: Total deductions exceed gross pay. Net pay will be ₱ 0.00.
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light d-flex justify-content-between">
                    <div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-warning text-dark font-weight-bold mr-1" onclick="document.getElementById('payrollFormAction').value='save_draft';">
                            <i class="fas fa-save mr-1"></i>Save Draft Deductions
                        </button>
                        <button type="submit" class="btn btn-success font-weight-bold px-4" onclick="document.getElementById('payrollFormAction').value='process';">
                            <i class="fas fa-check-circle mr-1"></i>Confirm & Process Payroll
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: OFFICIAL PAYSLIP & PRINT -->
<!-- ========================================== -->
<div class="modal fade" id="payslipModal" tabindex="-1" role="dialog" aria-labelledby="payslipModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold" id="payslipModalLabel">
                    <i class="fas fa-receipt text-warning mr-2"></i>Official Employee Payslip
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4" id="payslipPrintArea">
                <div class="text-center pb-3 border-bottom mb-3">
                    <h4 class="font-weight-bold text-dark mb-0"><i class="fas fa-mug-hot text-warning mr-1"></i>BIG BREW</h4>
                    <div class="text-xs text-muted text-uppercase tracking-wider font-weight-bold">Payroll & Compensation Voucher</div>
                </div>

                <div class="row small mb-3">
                    <div class="col-6">
                        <div><strong>Employee:</strong> <span id="psEmpName">-</span></div>
                        <div><strong>Position:</strong> <span id="psEmpPos">-</span></div>
                        <div><strong>Rate:</strong> ₱ <span id="psRate">0.00</span> / hr</div>
                    </div>
                    <div class="col-6 text-right">
                        <div><strong>Cutoff:</strong> <span id="psCutoff">-</span></div>
                        <div><strong>Days Worked:</strong> <span id="psDays">0</span> days</div>
                        <div><strong>Total Paid Hours:</strong> <span id="psHours">0.00</span> h</div>
                    </div>
                </div>

                <!-- Earnings Table with 8h and OT Breakdown -->
                <table class="table table-sm table-bordered mb-3 small">
                    <thead class="bg-light">
                        <tr><th>Earnings Breakdown</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Regular Pay (<span id="psRegHours">0.00</span> hrs @ ₱ <span id="psRateReg">0.00</span>/hr)</td>
                            <td class="text-right font-weight-bold">₱ <span id="psRegPay">0.00</span></td>
                        </tr>
                        <tr id="psOtRow">
                            <td>Overtime Pay (<span id="psOtHours">0.00</span> hrs @ ₱ <span id="psRateOt">0.00</span>/hr)</td>
                            <td class="text-right font-weight-bold text-success">₱ <span id="psOtPay">0.00</span></td>
                        </tr>
                        <tr class="bg-light font-weight-bold">
                            <td>Total Gross Earnings</td>
                            <td class="text-right">₱ <span id="psGross">0.00</span></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Deductions Table -->
                <table class="table table-sm table-bordered mb-3 small">
                    <thead class="bg-light">
                        <tr><th>Deductions</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody id="psDeductionsBody">
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td>Total Deductions</td>
                            <td class="text-right text-danger">- ₱ <span id="psTotalDeductions">0.00</span></td>
                        </tr>
                    </tfoot>
                </table>

                <div id="psRemarksContainer" class="alert alert-light border small py-2 px-3 mb-3 d-none">
                    <strong>Deduction Remarks:</strong> <span id="psRemarks"></span>
                </div>

                <!-- Net Take-Home Box -->
                <div class="bg-success text-white rounded p-3 text-center shadow-sm">
                    <div class="text-xs text-uppercase font-weight-bold" style="letter-spacing:1px;opacity:.9;">Total Net Take-Home Pay</div>
                    <div class="h3 mb-0 font-weight-bold mt-1">₱ <span id="psNetPay">0.00</span></div>
                    <div class="text-xs mt-1" style="opacity:.85;" id="psProcessedAt"></div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary font-weight-bold" onclick="printPayslipDoc()">
                    <i class="fas fa-print mr-1"></i>Print Payslip
                </button>
            </div>
        </div>
    </div>
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
?>

<script>
$(document).ready(function() {
    function formatMoney(num) {
        var n = parseFloat(num) || 0;
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalculatePayrollModal() {
        var rate = parseFloat($('#modalHourlyRate').val()) || 0;
        var regH = parseFloat($('#modalRegularHours').val()) || 0;
        var otH = parseFloat($('#inputOtHours').val()) || 0;

        var totalPaidHours = regH + otH;
        var regPay = regH * rate;
        var otPay = otH * rate;
        var gross = totalPaidHours * rate;

        var ca = parseFloat($('#inputCashAdvance').val()) || 0;
        var sss = parseFloat($('#inputSSS').val()) || 0;
        var ph = parseFloat($('#inputPhilHealth').val()) || 0;
        var pg = parseFloat($('#inputPagIbig').val()) || 0;
        var other = parseFloat($('#inputOtherDeductions').val()) || 0;

        var totalDeductions = ca + sss + ph + pg + other;
        var netPay = Math.max(0, gross - totalDeductions);

        $('#modalRegPayDisplay').text(formatMoney(regPay));
        $('#summaryPaidHours').text(formatMoney(totalPaidHours));
        $('#summaryRegHours').text(formatMoney(regH));
        $('#summaryOtHours').text(formatMoney(otH));
        $('#summaryGross').text(formatMoney(gross));
        $('#summaryDeductions').text(formatMoney(totalDeductions));
        $('#summaryNetPay').text(formatMoney(netPay));

        if (totalDeductions > gross) {
            $('#deductionWarning').removeClass('d-none');
        } else {
            $('#deductionWarning').addClass('d-none');
        }
    }

    $('.payroll-calc-input').on('input change keyup', function() {
        recalculatePayrollModal();
    });

    $(document).on('click', '.btn-open-payroll-modal', function() {
        var btn = $(this);
        var empId = btn.data('emp-id');
        var empName = btn.data('emp-name');
        var empPos = btn.data('emp-pos') || 'Staff';
        var cStart = btn.data('cutoff-start');
        var cEnd = btn.data('cutoff-end');
        var cLabel = btn.data('cutoff-label');
        var rate = parseFloat(btn.data('rate')) || 0;
        var days = parseInt(btn.data('days')) || 0;
        var regH = parseFloat(btn.data('reg-hours')) || 0;
        var excessH = parseFloat(btn.data('excess-hours')) || 0;
        var otH = parseFloat(btn.data('ot-hours')) || 0;
        var rawH = parseFloat(btn.data('raw-hours')) || 0;

        var ca = parseFloat(btn.data('ca')) || 0;
        var sss = parseFloat(btn.data('sss')) || 0;
        var ph = parseFloat(btn.data('ph')) || 0;
        var pg = parseFloat(btn.data('pg')) || 0;
        var other = parseFloat(btn.data('other')) || 0;
        var remarks = btn.data('remarks') || '';

        $('#modalEmpId').val(empId);
        $('#modalCutoffStart').val(cStart);
        $('#modalCutoffEnd').val(cEnd);
        $('#modalHourlyRate').val(rate);
        $('#modalTotalDays').val(days);
        $('#modalRegularHours').val(regH);

        $('#modalEmpNameDisplay').text(empName);
        $('#modalEmpPosDisplay').text(empPos);
        $('#modalCutoffLabelDisplay').text(cLabel);
        $('#modalDaysDisplay').text(days);
        $('#modalRateDisplay').text(formatMoney(rate));
        $('#modalOtRateText').text(formatMoney(rate));
        $('#modalRawHoursDisplay').text(formatMoney(rawH));
        $('#modalRegularHoursDisplay').text(formatMoney(regH));
        $('#modalExcessHoursDisplay').text(formatMoney(excessH));
        $('#modalOtExcessBadge').text(formatMoney(excessH));

        // If OT was previously saved, use it; otherwise default to 0.00 (or pre-approved)
        $('#inputOtHours').val(otH > 0 ? otH.toFixed(2) : '0.00');

        $('#btnOtZero').off('click').on('click', function() {
            $('#inputOtHours').val('0.00');
            recalculatePayrollModal();
        });

        $('#btnOtApproveAll').off('click').on('click', function() {
            $('#inputOtHours').val(excessH.toFixed(2));
            recalculatePayrollModal();
        });

        $('#inputCashAdvance').val(ca > 0 ? ca.toFixed(2) : '');
        $('#inputSSS').val(sss > 0 ? sss.toFixed(2) : '');
        $('#inputPhilHealth').val(ph > 0 ? ph.toFixed(2) : '');
        $('#inputPagIbig').val(pg > 0 ? pg.toFixed(2) : '');
        $('#inputOtherDeductions').val(other > 0 ? other.toFixed(2) : '');
        $('#inputDeductionRemarks').val(remarks);

        recalculatePayrollModal();
        $('#payrollModal').modal('show');
    });

    $(document).on('click', '.btn-view-payslip', function() {
        var btn = $(this);
        var empName = btn.data('emp-name');
        var empPos = btn.data('emp-pos') || 'Staff';
        var cLabel = btn.data('cutoff-label');
        var rate = parseFloat(btn.data('rate')) || 0;
        var days = parseInt(btn.data('days')) || 0;
        var regH = parseFloat(btn.data('reg-hours')) || 0;
        var otH = parseFloat(btn.data('ot-hours')) || 0;
        var totalH = parseFloat(btn.data('total-hours')) || (regH + otH);
        var gross = parseFloat(btn.data('gross')) || (totalH * rate);

        var ca = parseFloat(btn.data('ca')) || 0;
        var sss = parseFloat(btn.data('sss')) || 0;
        var ph = parseFloat(btn.data('ph')) || 0;
        var pg = parseFloat(btn.data('pg')) || 0;
        var other = parseFloat(btn.data('other')) || 0;
        var remarks = btn.data('remarks') || '';
        var net = parseFloat(btn.data('net')) || 0;
        var procAt = btn.data('processed-at') || '';

        var regPay = regH * rate;
        var otPay = otH * rate;

        $('#psEmpName').text(empName);
        $('#psEmpPos').text(empPos);
        $('#psCutoff').text(cLabel);
        $('#psRate').text(formatMoney(rate));
        $('#psDays').text(days);
        $('#psHours').text(formatMoney(totalH));

        $('#psRegHours').text(formatMoney(regH));
        $('#psRateReg').text(formatMoney(rate));
        $('#psRegPay').text(formatMoney(regPay));

        if (otH > 0) {
            $('#psOtRow').show();
            $('#psOtHours').text(formatMoney(otH));
            $('#psRateOt').text(formatMoney(rate));
            $('#psOtPay').text(formatMoney(otPay));
        } else {
            $('#psOtRow').hide();
        }

        $('#psGross').text(formatMoney(gross));

        var dedHtml = '';
        var totalDed = 0;
        if (ca > 0) { dedHtml += '<tr><td>Cash Advance / Vale</td><td class="text-right text-danger">- ₱ ' + formatMoney(ca) + '</td></tr>'; totalDed += ca; }
        if (sss > 0) { dedHtml += '<tr><td>SSS Contribution</td><td class="text-right text-danger">- ₱ ' + formatMoney(sss) + '</td></tr>'; totalDed += sss; }
        if (ph > 0) { dedHtml += '<tr><td>PhilHealth</td><td class="text-right text-danger">- ₱ ' + formatMoney(ph) + '</td></tr>'; totalDed += ph; }
        if (pg > 0) { dedHtml += '<tr><td>Pag-IBIG</td><td class="text-right text-danger">- ₱ ' + formatMoney(pg) + '</td></tr>'; totalDed += pg; }
        if (other > 0) { dedHtml += '<tr><td>Other Deductions</td><td class="text-right text-danger">- ₱ ' + formatMoney(other) + '</td></tr>'; totalDed += other; }
        if (totalDed === 0) {
            dedHtml = '<tr><td colspan="2" class="text-muted text-center py-2">No deductions applied</td></tr>';
        }

        $('#psDeductionsBody').html(dedHtml);
        $('#psTotalDeductions').text(formatMoney(totalDed));
        $('#psNetPay').text(formatMoney(net));

        if (remarks) {
            $('#psRemarks').text(remarks);
            $('#psRemarksContainer').removeClass('d-none');
        } else {
            $('#psRemarksContainer').addClass('d-none');
        }

        if (procAt) {
            $('#psProcessedAt').text('Processed: ' + procAt);
        } else {
            $('#psProcessedAt').text('');
        }

        $('#payslipModal').modal('show');
    });
});

function printPayslipDoc() {
    var printContents = document.getElementById('payslipPrintArea').innerHTML;
    var printWindow = window.open('', '', 'height=650,width=800');
    printWindow.document.write('<!DOCTYPE html><html><head><title>Big Brew - Employee Payslip</title>');
    printWindow.document.write('<link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">');
    printWindow.document.write('<link rel="stylesheet" href="assets/vendor/fontawesome-free/css/all.min.css">');
    printWindow.document.write('<style>body{padding:25px;font-family:sans-serif;color:#333;} .badge{border:1px solid #ddd;} @media print{body{padding:0;} .no-print{display:none;}}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(printContents);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() {
        printWindow.print();
        printWindow.close();
    }, 400);
}
</script>
