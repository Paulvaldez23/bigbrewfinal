<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

$page_title = is_admin() ? 'Big Brew System - Dashboard' : 'Big Brew - My Dashboard';
$active_nav = 'dashboard';

$user = current_user();
$isAdmin = is_admin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$staffEmpId = null;
$myEmpData = null;

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

$totalEmployees = 0;
$todayAttendance = 0;
$activeEmployees = 0;
$pendingFeedback = 0;

// Chart Analytics Data Defaults
$chartAttDates = [];
$chartAttHours = [];
$chartAttPunches = [];
$totalTrendHours = 0;
$dowLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$chartDowHours = array_fill(0, 7, 0);
$chartDowPunches = array_fill(0, 7, 0);
$chartPayrollLabels = [];
$chartPayrollGross = [];
$chartPayrollHours = [];
$totalPayrollSpent = 0;
$chartEmpNames = [];
$chartEmpHours = [];
$chartEmpPay = [];
$chartRatingCounts = [0, 0, 0, 0, 0];
$avgRating = 5.0;
$totalFeedbackCount = 0;
$chartBranchLabels = [];
$chartBranchCounts = [];
$chartBranchAvgRating = [];

$myTotalHoursThisMonth = 0;
$myDaysPresentThisMonth = 0;
$myHoursToday = 0;
$myRecentAttendance = [];
$myPayThisPeriod = 0;
$myCutoffLabel = '';
$myTodayShift = null;
$myHasShiftThisWeek = false;
$shift_colors_cache = [];
$myWeekPublished = false;
$myWeekPublishedAt = null;
$myWeekPreview = [];
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('+6 days', strtotime($weekStart)));
$todayDow = (int)date('N');

if ($conn) {
    try {
        if ($isAdmin) {
            $totalEmployees = $conn->query("SELECT COUNT(*) FROM employees")->fetchColumn();
            $todayAttendance = $conn->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()")->fetchColumn();
            $activeEmployees = $conn->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
            $pendingFeedback = $conn->query("SELECT COUNT(*) FROM feedback WHERE DATE(submitted_at) = CURDATE()")->fetchColumn();

            // 1. Attendance Daily Trend (Last 14 active days)
            try {
                $attTrend = $conn->query("
                    SELECT attendance_date, 
                           COUNT(*) as punches, 
                           ROUND(COALESCE(SUM(hours_worked), 0), 2) as total_hours 
                    FROM attendance 
                    WHERE attendance_date >= DATE_SUB((SELECT MAX(attendance_date) FROM attendance), INTERVAL 14 DAY)
                    GROUP BY attendance_date 
                    ORDER BY attendance_date ASC
                ")->fetchAll();
                foreach ($attTrend as $row) {
                    $chartAttDates[] = date('M d', strtotime($row['attendance_date']));
                    $chartAttHours[] = (float)$row['total_hours'];
                    $chartAttPunches[] = (int)$row['punches'];
                    $totalTrendHours += (float)$row['total_hours'];
                }
            } catch (Exception $e) {}

            // 2. Attendance Hours by Day of Week (Monday to Sunday)
            $dowMap = ['Monday'=>0, 'Tuesday'=>1, 'Wednesday'=>2, 'Thursday'=>3, 'Friday'=>4, 'Saturday'=>5, 'Sunday'=>6];
            try {
                $dowData = $conn->query("
                    SELECT DAYNAME(attendance_date) as day_name,
                           COUNT(*) as punches, 
                           ROUND(COALESCE(SUM(hours_worked), 0), 2) as total_hours
                    FROM attendance
                    GROUP BY DAYNAME(attendance_date)
                ")->fetchAll();
                foreach ($dowData as $row) {
                    if (isset($dowMap[$row['day_name']])) {
                        $idx = $dowMap[$row['day_name']];
                        $chartDowHours[$idx] = (float)$row['total_hours'];
                        $chartDowPunches[$idx] = (int)$row['punches'];
                    }
                }
            } catch (Exception $e) {}

            // 3. Payroll & Labor Cost by Cutoff Period
            try {
                $pCutoffs = $conn->query("
                    SELECT 
                        CASE 
                            WHEN DAY(a.attendance_date) <= 15 THEN CONCAT(DATE_FORMAT(a.attendance_date, '%b 01 - 15'))
                            ELSE CONCAT(DATE_FORMAT(a.attendance_date, '%b 16 - '), DATE_FORMAT(LAST_DAY(a.attendance_date), '%d'))
                        END as cutoff_label,
                        ROUND(SUM(a.hours_worked), 2) as total_hours,
                        ROUND(SUM(a.hours_worked * COALESCE(e.hourly_rate, 40)), 2) as estimated_gross
                    FROM attendance a
                    LEFT JOIN employees e ON a.employee_id = e.id
                    GROUP BY cutoff_label
                    ORDER BY MIN(a.attendance_date) ASC
                    LIMIT 6
                ")->fetchAll();
                foreach ($pCutoffs as $pc) {
                    $chartPayrollLabels[] = $pc['cutoff_label'];
                    $chartPayrollGross[] = (float)$pc['estimated_gross'];
                    $chartPayrollHours[] = (float)$pc['total_hours'];
                    $totalPayrollSpent += (float)$pc['estimated_gross'];
                }
            } catch (Exception $e) {}

            // 4. Staff Labor Hours & Compensation
            try {
                $staffData = $conn->query("
                    SELECT CONCAT(e.first_name, ' ', e.last_name) as emp_name,
                           ROUND(COALESCE(SUM(a.hours_worked), 0), 1) as total_hours,
                           ROUND(COALESCE(SUM(a.hours_worked * COALESCE(e.hourly_rate, 0)), 0), 2) as estimated_pay
                    FROM employees e
                    LEFT JOIN attendance a ON e.id = a.employee_id
                    GROUP BY e.id, e.first_name, e.last_name
                    ORDER BY total_hours DESC
                    LIMIT 6
                ")->fetchAll();
                foreach ($staffData as $row) {
                    $chartEmpNames[] = ucwords(trim($row['emp_name']));
                    $chartEmpHours[] = (float)$row['total_hours'];
                    $chartEmpPay[] = (float)$row['estimated_pay'];
                }
            } catch (Exception $e) {}

            // 5. Customer Feedback Ratings (1 to 5 Stars)
            try {
                $fbRatings = $conn->query("
                    SELECT rating, COUNT(*) as cnt 
                    FROM feedback 
                    WHERE rating IS NOT NULL AND rating >= 1 AND rating <= 5
                    GROUP BY rating
                ")->fetchAll();
                $ratingMap = [5 => 0, 4 => 1, 3 => 2, 2 => 3, 1 => 4];
                foreach ($fbRatings as $r) {
                    $rt = (int)$r['rating'];
                    if (isset($ratingMap[$rt])) {
                        $chartRatingCounts[$ratingMap[$rt]] = (int)$r['cnt'];
                    }
                }
                $avgRating = (float)($conn->query("SELECT ROUND(COALESCE(AVG(rating), 5), 1) FROM feedback WHERE rating > 0")->fetchColumn() ?: 5.0);
                $totalFeedbackCount = (int)($conn->query("SELECT COUNT(*) FROM feedback")->fetchColumn() ?: 0);
            } catch (Exception $e) {}

            // 6. Feedback by Branch
            try {
                $branchFb = $conn->query("
                    SELECT COALESCE(NULLIF(branch, ''), 'Main Branch') as b_name,
                           COUNT(*) as cnt,
                           ROUND(COALESCE(AVG(rating), 5), 1) as avg_r
                    FROM feedback
                    GROUP BY branch
                ")->fetchAll();
                foreach ($branchFb as $b) {
                    $chartBranchLabels[] = $b['b_name'];
                    $chartBranchCounts[] = (int)$b['cnt'];
                    $chartBranchAvgRating[] = (float)$b['avg_r'];
                }
            } catch (Exception $e) {}
        } elseif ($staffEmpId) {
            $hrsStmt = $conn->prepare("SELECT COALESCE(SUM(hours_worked),0) FROM attendance WHERE employee_id=? AND MONTH(attendance_date)=MONTH(CURDATE()) AND YEAR(attendance_date)=YEAR(CURDATE())");
            $hrsStmt->execute([$staffEmpId]);
            $myTotalHoursThisMonth = (float)$hrsStmt->fetchColumn();

            $daysStmt = $conn->prepare("SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE employee_id=? AND MONTH(attendance_date)=MONTH(CURDATE()) AND YEAR(attendance_date)=YEAR(CURDATE()) AND hours_worked > 0");
            $daysStmt->execute([$staffEmpId]);
            $myDaysPresentThisMonth = (int)$daysStmt->fetchColumn();

            $todayStmt = $conn->prepare("SELECT COALESCE(SUM(hours_worked),0) FROM attendance WHERE employee_id=? AND attendance_date = CURDATE()");
            $todayStmt->execute([$staffEmpId]);
            $myHoursToday = (float)$todayStmt->fetchColumn();

            $recentStmt = $conn->prepare("SELECT * FROM attendance WHERE employee_id=? ORDER BY attendance_date DESC LIMIT 5");
            $recentStmt->execute([$staffEmpId]);
            $myRecentAttendance = $recentStmt->fetchAll();

            $dayNow = (int)date('j');
            $currentMonth = date('m');
            $currentYear = date('Y');
            if ($dayNow <= 15) {
                $cutoffStart = "$currentYear-$currentMonth-01";
                $cutoffEnd = "$currentYear-$currentMonth-15";
                $myCutoffLabel = date('M 1-15, Y');
            } else {
                $cutoffStart = "$currentYear-$currentMonth-16";
                $lastDay = date('t', strtotime("$currentYear-$currentMonth-01"));
                $cutoffEnd = "$currentYear-$currentMonth-$lastDay";
                $myCutoffLabel = date('M 16-t, Y');
            }
            $periodStmt = $conn->prepare("SELECT COALESCE(SUM(hours_worked),0) FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
            $periodStmt->execute([$staffEmpId, $cutoffStart, $cutoffEnd]);
            $periodHours = (float)$periodStmt->fetchColumn();
            $rate = (float)($myEmpData['hourly_rate'] ?? 0);
            $myPayThisPeriod = $periodHours * $rate;

            $shift_colors_cache = [];
            try {
                $scs = $conn->query("SELECT shift_name, color FROM shifts")->fetchAll();
                foreach ($scs as $sc) { $shift_colors_cache[$sc['shift_name']] = $sc['color']; }
            } catch (Exception $e) {}

            $myTodayShift = null;
            $myHasShiftThisWeek = false;
            $myWeekPublished = false;
            $myWeekPublishedAt = null;
            $myWeekPreview = [];

            try {
                $pw = $conn->prepare("SELECT published_at FROM shift_weeks WHERE week_start = ? LIMIT 1");
                $pw->execute([$weekStart]);
                $wr = $pw->fetch();
                if ($wr && !empty($wr['published_at'])) {
                    $myWeekPublished = true;
                    $myWeekPublishedAt = $wr['published_at'];
                }
            } catch (Exception $e) {}

            $useShifts = $myWeekPublished;
            $shiftStmt = $conn->prepare("SELECT day_of_week, shift_row, shift_in, shift_out, shift_label, notes FROM employee_shifts WHERE employee_id = ? AND week_start = ?");
            $shiftStmt->execute([$staffEmpId, $weekStart]);
            $weekShifts = $shiftStmt->fetchAll() ?: [];
            foreach ($weekShifts as $ws) {
                $sr = $ws['shift_row'] ?? $ws['shift_label'] ?? 'Mid';
                if (empty($sr)) { $sr = 'Mid'; }
                $ws['shift_row'] = $sr;
                $ws['shift_label'] = $ws['shift_label'] ?? $sr;
                $myWeekPreview[(int)$ws['day_of_week']] = $ws;
            }
            if ($myWeekPreview) {
                $myHasShiftThisWeek = true;
                if ($useShifts && isset($myWeekPreview[$todayDow])) {
                    $myTodayShift = $myWeekPreview[$todayDow];
                }
            }
        }
    } catch (Exception $e) {
    }
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <?php if ($isAdmin): ?>Dashboard<?php else: ?>My Workspace<?php endif; ?>
                        </h1>
                        <?php if (!$isAdmin): ?><div class="text-sm text-muted"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($myEmpData['first_name'] ?? $user['name'] ?? $user['username']); ?></div><?php endif; ?>
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="row">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Employees</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalEmployees; ?></div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Today's Attendance</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $todayAttendance; ?></div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-clipboard-check fa-2x text-gray-300"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Active Employees</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $activeEmployees; ?></div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Today's Feedback</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingFeedback; ?></div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-comments fa-2x text-gray-300"></i></div>
                                    </div>
                                </div>
                            </div>
                    </div>

                    <!-- Analytics Section Header -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-3 mt-1">
                        <h5 class="mb-0 text-gray-800 font-weight-bold">
                            <i class="fas fa-chart-line mr-2 text-primary"></i>Big Brew Analytics &amp; Operations Dashboard
                        </h5>
                        <div class="small text-muted mt-1 mt-sm-0">
                            <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-clock mr-1 text-primary"></i>Attendance</span>
                            <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-money-bill-wave mr-1 text-warning"></i>Payroll</span>
                            <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-star mr-1 text-danger"></i>Feedback</span>
                        </div>
                    </div>

                    <!-- Row 1: Attendance Analytics (2 Graphs) -->
                    <div class="row">
                        <!-- Graph 1: Attendance Daily Trend -->
                        <div class="col-xl-8 col-lg-7 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-chart-area mr-1"></i> 1. Attendance &amp; Work Hours Trend (Daily)
                                    </h6>
                                    <span class="badge badge-primary badge-pill px-2 py-1">Last 14 Days</span>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 320px; width: 100%;">
                                        <canvas id="chartAttendanceTrend"></canvas>
                                    </div>
                                    <div class="small text-muted mt-2 d-flex justify-content-between flex-wrap">
                                        <span><i class="fas fa-info-circle text-primary mr-1"></i>Work hours logged across staff clock-ins.</span>
                                        <span>Total: <strong class="text-primary"><?php echo number_format($totalTrendHours, 2); ?> hrs</strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graph 2: Attendance Activity by Day of Week -->
                        <div class="col-xl-4 col-lg-5 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-success">
                                        <i class="fas fa-calendar-week mr-1"></i> 2. Attendance by Day of Week
                                    </h6>
                                    <span class="badge badge-success badge-pill px-2 py-1">Mon - Sun</span>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 320px; width: 100%;">
                                        <canvas id="chartAttendanceDow"></canvas>
                                    </div>
                                    <div class="small text-muted mt-2 text-center">
                                        <i class="fas fa-business-time text-success mr-1"></i>Operational load comparison across weekdays and weekends.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Payroll Analytics (2 Graphs) -->
                    <div class="row">
                        <!-- Graph 3: Payroll Labor Cost by Cutoff -->
                        <div class="col-xl-6 col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-warning">
                                        <i class="fas fa-money-bill-wave mr-1"></i> 3. Payroll Expense by Cutoff Period
                                    </h6>
                                    <span class="badge badge-warning badge-pill px-2 py-1">Labor Cost (₱)</span>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 300px; width: 100%;">
                                        <canvas id="chartPayrollCutoff"></canvas>
                                    </div>
                                    <div class="small text-muted mt-2 d-flex justify-content-between flex-wrap">
                                        <span><i class="fas fa-coins text-warning mr-1"></i>Labor expense calculated per payroll cycle.</span>
                                        <span>Total Spent: <strong class="text-warning">₱ <?php echo number_format($totalPayrollSpent, 2); ?></strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graph 4: Staff Work Hours & Labor Share -->
                        <div class="col-xl-6 col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-info">
                                        <i class="fas fa-user-clock mr-1"></i> 4. Top Staff Labor Hours &amp; Share
                                    </h6>
                                    <span class="badge badge-info badge-pill px-2 py-1">Staff Hours</span>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 300px; width: 100%;">
                                        <canvas id="chartStaffLabor"></canvas>
                                    </div>
                                    <div class="small text-muted mt-2 text-center">
                                        <i class="fas fa-users text-info mr-1"></i>Top staff members ranked by recorded shift hours worked.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 3: Customer Feedback Analytics (2 Graphs) -->
                    <div class="row">
                        <!-- Graph 5: Customer Rating Distribution -->
                        <div class="col-xl-5 col-lg-5 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-danger">
                                        <i class="fas fa-star mr-1"></i> 5. Customer Rating Breakdown
                                    </h6>
                                    <span class="badge badge-danger badge-pill px-2 py-1">⭐ <?php echo number_format($avgRating, 1); ?> / 5.0</span>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 260px; width: 100%;">
                                        <canvas id="chartFeedbackRating"></canvas>
                                    </div>
                                    <div class="mt-3 text-center small d-flex justify-content-around flex-wrap">
                                        <span class="mr-2"><i class="fas fa-circle text-success"></i> 5★ (<?php echo $chartRatingCounts[0]; ?>)</span>
                                        <span class="mr-2"><i class="fas fa-circle text-info"></i> 4★ (<?php echo $chartRatingCounts[1]; ?>)</span>
                                        <span class="mr-2"><i class="fas fa-circle text-warning"></i> 3★ (<?php echo $chartRatingCounts[2]; ?>)</span>
                                        <span class="mr-2"><i class="fas fa-circle" style="color:#fd7e14;"></i> 2★ (<?php echo $chartRatingCounts[3]; ?>)</span>
                                        <span class="mr-2"><i class="fas fa-circle text-danger"></i> 1★ (<?php echo $chartRatingCounts[4]; ?>)</span>
                                    </div>
                                    <div class="text-center text-muted small mt-2">
                                        Total Reviews: <strong><?php echo $totalFeedbackCount; ?></strong> &bull; Avg Satisfaction: <strong><?php echo number_format($avgRating, 1); ?> ★</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graph 6: Branch Feedback & Satisfaction Rating -->
                        <div class="col-xl-7 col-lg-7 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-secondary">
                                        <i class="fas fa-store mr-1"></i> 6. Feedback &amp; Ratings by Branch
                                    </h6>
                                    <span class="badge badge-secondary badge-pill px-2 py-1">Store Performance</span>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 260px; width: 100%;">
                                        <canvas id="chartBranchFeedback"></canvas>
                                    </div>
                                    <div class="small text-muted mt-3 text-center">
                                        <i class="fas fa-store-alt text-secondary mr-1"></i>Review submissions count &amp; customer satisfaction score per store.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6></div>
                                <div class="card-body">
                                    <a href="employees.php" class="btn btn-primary btn-icon-split m-1">
                                        <span class="icon text-white-50"><i class="fas fa-user-plus"></i></span>
                                        <span class="text">Manage Employees</span>
                                    </a>
                                    <a href="attendance.php" class="btn btn-success btn-icon-split m-1">
                                        <span class="icon text-white-50"><i class="fas fa-calendar-check"></i></span>
                                        <span class="text">View Attendance</span>
                                    </a>
                                    <a href="upload_attendance.php" class="btn btn-info btn-icon-split m-1">
                                        <span class="icon text-white-50"><i class="fas fa-upload"></i></span>
                                        <span class="text">Upload CSV</span>
                                    </a>
                                    <a href="feedback_qr.php" class="btn btn-warning btn-icon-split m-1">
                                        <span class="icon text-white-50"><i class="fas fa-qrcode"></i></span>
                                        <span class="text">Generate QR</span>
                                    </a>
                                    <a href="table.php" class="btn btn-secondary btn-icon-split m-1">
                                        <span class="icon text-white-50"><i class="fas fa-table"></i></span>
                                        <span class="text">View Employee Data</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">System Info</h6></div>
                                <div class="card-body">
                                    <p>Welcome to the <strong>Big Brew HR Suite</strong> — Coffee &middot; Tea &middot; Milktea. Use the sidebar to navigate through different modules.</p>
                                    <p class="mb-0"><i class="fas fa-info-circle text-info"></i> Need to setup the database first? Go to <a href="install.php">Install Page</a>.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php else: ?>

                    <?php
                    $myLatestPayrollNotif = null;
                    if ($staffEmpId && $conn) {
                        try {
                            $lp = $conn->prepare("SELECT pn.*, pr.processed_at, pr.net_pay, pr.cutoff_start, pr.cutoff_end
                                FROM payroll_notifications pn
                                LEFT JOIN payroll_records pr ON pn.employee_id = pr.employee_id
                                    AND pn.cutoff_start = pr.cutoff_start AND pn.cutoff_end = pr.cutoff_end
                                WHERE pn.employee_id = ? AND pn.is_read = 0
                                ORDER BY pn.created_at DESC LIMIT 1");
                            $lp->execute([$staffEmpId]);
                            $myLatestPayrollNotif = $lp->fetch();
                        } catch (Exception $e) {}
                    }
                    if ($myLatestPayrollNotif):
                        $pNet = (float)($myLatestPayrollNotif['net_pay'] ?? 0);
                        $pStart = date('M j, Y', strtotime($myLatestPayrollNotif['cutoff_start']));
                        $pEnd = date('M j, Y', strtotime($myLatestPayrollNotif['cutoff_end']));
                        $pWhen = $myLatestPayrollNotif['processed_at'] ? date('M j g:i A', strtotime($myLatestPayrollNotif['processed_at'])) : '';
                    ?>
                    <div class="alert alert-success alert-dismissible fade show shadow" role="alert">
                        <div class="d-flex align-items-center">
                            <div class="mr-3"><i class="fas fa-money-bill-wave fa-2x text-success"></i></div>
                            <div class="flex-grow-1">
                                <h5 class="alert-heading mb-1 font-weight-bold"><i class="fas fa-bell mr-1"></i>Your Payroll is Ready!</h5>
                                <p class="mb-1">Cutoff: <strong><?php echo $pStart; ?> – <?php echo $pEnd; ?></strong> • <?php if ($pWhen): ?>Processed: <small><?php echo $pWhen; ?></small><?php endif; ?></p>
                                <p class="mb-0"><strong class="text-success" style="font-size:1.2rem;">₱ <?php echo number_format(max($pNet, 0), 2); ?></strong> net pay</p>
                            </div>
                            <div class="ml-2">
                                <a href="payroll.php?mark_read=1" class="btn btn-sm btn-success"><i class="fas fa-receipt mr-1"></i>View Details →</a>
                            </div>
                        </div>
                        <button type="button" class="close" data-dismiss-notif aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1"><i class="fas fa-clock mr-1"></i>Hours This Month</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($myTotalHoursThisMonth, 2); ?> <small class="text-xs text-muted">hrs</small></div>
                                            <div class="mt-1 text-xs text-muted">Days present: <strong><?php echo $myDaysPresentThisMonth; ?></strong></div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-calendar-check fa-2x text-success opacity-50"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1"><i class="fas fa-money-bill-wave mr-1"></i>Current Pay (<?php echo htmlspecialchars($myCutoffLabel); ?>)</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">₱ <?php echo number_format($myPayThisPeriod, 2); ?></div>
                                            <div class="mt-1 text-xs text-muted">Rate: <strong>₱ <?php echo number_format((float)($myEmpData['hourly_rate'] ?? 0), 2); ?></strong>/hr</div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-money-check-alt fa-2x text-primary opacity-50"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6 mb-4">
                            <?php
                            $todayLabel = 'Default 9:00 AM - 6:00 PM';
                            $todayIcon  = 'fa-clock';
                            $todayBadge = '';
                            if ($myTodayShift) {
                                $lbl = $myTodayShift['shift_label'] ?? 'Custom';
                                $sin = $myTodayShift['shift_in'] ?? null;
                                $sout = $myTodayShift['shift_out'] ?? null;
                                if ($lbl === 'Off') {
                                    $todayLabel = "🌴 $lbl (Rest Day)";
                                } else {
                                    $inStr  = $sin  ? date('g:i A', strtotime($sin))  : 'TBA';
                                    $outStr = $sout ? date('g:i A', strtotime($sout)) : 'TBA';
                                    $todayLabel = "$lbl: $inStr - $outStr";
                                }
                                $todayBadge = '<span class="badge badge-info badge-pill ml-1" style="font-size:0.65rem;"><i class="fas fa-check-circle"></i> Assigned</span>';
                            } elseif ($myHasShiftThisWeek) {
                                $todayBadge = '<span class="badge badge-secondary ml-1" style="font-size:0.65rem;">Published this week</span>';
                            } else {
                                $todayBadge = '<span class="badge badge-light text-muted border ml-1" style="font-size:0.65rem;">Pending Admin publish</span>';
                            }
                            ?>
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1 d-inline-block"><i class="fas fa-store mr-1"></i>My Branch &amp; Shift Today</div><?php echo $todayBadge; ?>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($myEmpData['branch'] ?? 'Not assigned'); ?></div>
                                            <div class="mt-1 text-xs text-muted">
                                                <i class="fas fa-clock"></i>
                                                <?php if (!empty($myEmpData['position'])): ?>As <?php echo htmlspecialchars($myEmpData['position']); ?> • <?php endif; ?>
                                                <span class="font-weight-bold text-gray-700"><?php echo $todayLabel; ?></span>
                                                <?php if (!empty($myTodayShift['notes'])): ?>
                                                    <div class="mt-1 text-warning font-weight-normal"><i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($myTodayShift['notes']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-auto"><i class="fas fa-map-marker-alt fa-2x text-info opacity-50"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $dayList = [];
                    for ($di = 0; $di < 7; $di++) {
                        $dd = date('Y-m-d', strtotime("+$di days", strtotime($weekStart)));
                        $ddow = (int)date('N', strtotime($dd));
                        $dayList[] = [
                            'dow' => $ddow,
                            'label' => date('D', strtotime($dd)),
                            'date' => date('M j', strtotime($dd)),
                            'full' => date('D, M j', strtotime($dd)),
                            'is_today' => $ddow === $todayDow,
                        ];
                    }
                    ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-sm-flex flex-wrap align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-calendar-week mr-1"></i>
                                This Week's Schedule — <b><?php echo date('M j', strtotime($weekStart)); ?> – <?php echo date('M j, Y', strtotime($weekEnd)); ?></b>
                            </h6>
                            <div class="mt-1 mt-sm-0">
                                <?php if ($myWeekPublished): ?>
                                <span class="badge badge-success badge-pill px-3 py-1" style="font-size:.75rem;">
                                    <i class="fas fa-paper-plane mr-1"></i>Sent by Admin · <?php echo date('M j, g:i A', strtotime($myWeekPublishedAt)); ?>
                                </span>
                                <?php else: ?>
                                <span class="badge badge-light text-muted border px-3 py-1" style="font-size:.75rem;">
                                    <i class="fas fa-hourglass-half mr-1"></i>Pending — Admin hasn't published yet
                                </span>
                                <?php endif; ?>
                                <a href="schedule.php" class="btn btn-sm btn-primary ml-2"><i class="fas fa-eye mr-1"></i>Full View</a>
                            </div>
                        </div>
                        <div class="card-body py-3 px-3">
                            <div class="row" style="margin-left:-5px;margin-right:-5px;">
                                <?php foreach ($dayList as $dl):
                                    $dow = $dl['dow'];
                                    $s = $myWeekPreview[$dow] ?? null;
                                    $is_off = false;
                                    $lbl = ''; $sin = ''; $sout = ''; $notes = '';
                                    if ($s) {
                                        $lbl = $s['shift_label'] ?? '';
                                        $sin = $s['shift_in'] ?? '';
                                        $sout = $s['shift_out'] ?? '';
                                        $notes = $s['notes'] ?? '';
                                        $sr = $s['shift_row'] ?? '';
                                        if (strcasecmp($lbl, 'Off') === 0 || strcasecmp($sr, 'Off') === 0) { $is_off = true; }
                                    }
                                    $show = $myWeekPublished || $isAdmin;
                                    $color = '#4e73df';
                                    if ($s) {
                                        $rowkey = $s['shift_row'] ?? '';
                                        if (!empty($shift_colors_cache)) {
                                            $color = $shift_colors_cache[$rowkey] ?? '#4e73df';
                                        }
                                        if ($is_off) $color = '#e74a3b';
                                    }
                                ?>
                                <div class="col-xl-1 col-lg-2 col-md-3 col-6 mb-3 px-2">
                                    <div class="card h-100 <?php echo $dl['is_today'] ? 'border-primary shadow-sm' : ''; ?>"
                                         style="border-left:<?php echo $show && $s ? '4px solid '.htmlspecialchars($color) : '4px solid #e3e6f0'; ?>;">
                                        <div class="card-body py-2 px-2">
                                            <div class="text-xs font-weight-bold <?php echo $dl['is_today'] ? 'text-primary' : 'text-gray-700'; ?>">
                                                <?php echo $dl['label']; ?>
                                            </div>
                                            <div class="text-xs text-gray-500 mb-2"><?php echo $dl['date']; ?></div>
                                            <?php if (!$show): ?>
                                            <div class="text-xs text-muted">
                                                <i class="fas fa-lock opacity-60 mr-1"></i>
                                                Pending
                                            </div>
                                            <?php elseif (!$s): ?>
                                            <div class="text-xs text-gray-400">
                                                <i class="far fa-calendar-times opacity-60 mr-1"></i>
                                                No shift
                                            </div>
                                            <?php else: ?>
                                            <div class="mb-1">
                                                <span class="badge text-white px-2 py-1"
                                                      style="background:<?php echo htmlspecialchars($color); ?>;font-size:.65rem;">
                                                    <?php if ($is_off): ?><i class="fas fa-umbrella-beach mr-1"></i><?php endif; ?>
                                                    <?php echo htmlspecialchars($lbl); ?>
                                                </span>
                                            </div>
                                            <?php if ($sin && !$is_off): ?>
                                            <div class="text-xs font-weight-bold">
                                                <?php echo date('g:i A', strtotime($sin)); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                to <?php echo $sout ? date('g:i A', strtotime($sout)) : 'TBA'; ?>
                                            </div>
                                            <?php elseif ($is_off): ?>
                                            <div class="text-xs text-danger font-weight-bold">Rest Day</div>
                                            <?php endif; ?>
                                            <?php if ($notes): ?>
                                            <div class="text-xs text-warning mt-1" title="<?php echo htmlspecialchars($notes); ?>">
                                                <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars(mb_strimwidth($notes, 0, 20, '…')); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-8 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-alt mr-1"></i>Recent Attendance</h6>
                                    <a href="attendance.php" class="btn btn-sm btn-outline-primary">View All →</a>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-bordered text-nowrap mb-0" style="min-width:680px;">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Date</th><th>Time In</th><th>Time Out</th>
                                                    <th>Hours</th><th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($myRecentAttendance as $a):
                                                    $h = $a['hours_worked'] ?? null;
                                                ?>
                                                <tr>
                                                    <td class="text-nowrap"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); ?></td>
                                                    <td class="text-nowrap"><?php echo $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '-'; ?></td>
                                                    <td class="text-nowrap"><?php echo $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '-'; ?></td>
                                                    <td class="text-nowrap"><strong><?php echo $h !== null ? number_format((float)$h, 2) . 'h' : '-'; ?></strong></td>
                                                    <td class="text-nowrap"><span class="badge badge-<?php echo strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success'); ?>"><?php echo ucfirst($a['status'] ?? 'present'); ?></span></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if (!$myRecentAttendance): ?>
                                                <tr><td colspan="5" class="text-center text-gray-500 py-4">No attendance records yet this month.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-tie mr-1"></i>My Information</h6></div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                            <span class="text-muted small">Employee ID</span>
                                            <strong><?php echo htmlspecialchars($myEmpData['employee_id'] ?? '-'); ?></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                            <span class="text-muted small">Full Name</span>
                                            <strong><?php echo htmlspecialchars(($myEmpData['first_name'] ?? '') . ' ' . ($myEmpData['last_name'] ?? '')); ?></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                            <span class="text-muted small">Position</span>
                                            <strong><?php echo htmlspecialchars($myEmpData['position'] ?? '-'); ?></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                            <span class="text-muted small">Contact</span>
                                            <strong><?php echo htmlspecialchars($myEmpData['contact_number'] ?? '-'); ?></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                            <span class="text-muted small">Status</span>
                                            <span class="badge badge-<?php echo ($myEmpData['status'] ?? 'active') === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($myEmpData['status'] ?? 'active'); ?></span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="card shadow border-left-warning">
                                <div class="card-body">
                                    <p class="mb-1 small text-gray-600"><i class="fas fa-info-circle text-warning"></i> Need to view full payroll details or schedule?</p>
                                    <div class="d-flex gap-2">
                                        <a href="payroll.php" class="btn btn-sm btn-outline-warning mr-1"><i class="fas fa-money-bill-wave"></i> My Payroll</a>
                                        <a href="schedule.php" class="btn btn-sm btn-outline-info"><i class="fas fa-clock"></i> My Schedule</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>
                </div>
<script>
(function() {
    var btns = document.querySelectorAll('[data-dismiss-notif]');
    btns.forEach(function(b) {
        b.addEventListener('click', function() {
            var el = this.closest('.alert');
            if (el) { el.style.display = 'none'; }
        });
    });
})();
</script>

<?php if ($isAdmin): ?>
<script src="assets/vendor/chart.js/Chart.bundle.min.js"></script>
<script>
window.DASHBOARD_DATA = {
    attendanceTrend: {
        dates: <?php echo json_encode($chartAttDates); ?>,
        hours: <?php echo json_encode($chartAttHours); ?>,
        punches: <?php echo json_encode($chartAttPunches); ?>
    },
    attendanceDow: {
        labels: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
        hours: <?php echo json_encode($chartDowHours); ?>,
        punches: <?php echo json_encode($chartDowPunches); ?>
    },
    payrollCutoff: {
        labels: <?php echo json_encode($chartPayrollLabels); ?>,
        gross: <?php echo json_encode($chartPayrollGross); ?>,
        hours: <?php echo json_encode($chartPayrollHours); ?>
    },
    staffLabor: {
        names: <?php echo json_encode($chartEmpNames); ?>,
        hours: <?php echo json_encode($chartEmpHours); ?>,
        pay: <?php echo json_encode($chartEmpPay); ?>
    },
    feedbackRating: {
        labels: ["5 Stars (Excellent)", "4 Stars (Very Good)", "3 Stars (Good)", "2 Stars (Fair)", "1 Star (Poor)"],
        counts: <?php echo json_encode(array_values($chartRatingCounts)); ?>,
        avg: <?php echo json_encode($avgRating); ?>,
        total: <?php echo json_encode($totalFeedbackCount); ?>
    },
    branchFeedback: {
        labels: <?php echo json_encode($chartBranchLabels); ?>,
        counts: <?php echo json_encode($chartBranchCounts); ?>,
        ratings: <?php echo json_encode($chartBranchAvgRating); ?>
    }
};

(function() {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.global.defaultFontFamily = 'Nunito', '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.global.defaultFontColor = '#858796';

    function fmtMoney(v) {
        return '₱ ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // 1. Attendance & Work Hours Trend
    var ctxAtt = document.getElementById("chartAttendanceTrend");
    if (ctxAtt) {
        new Chart(ctxAtt, {
            type: 'line',
            data: {
                labels: window.DASHBOARD_DATA.attendanceTrend.dates,
                datasets: [
                    {
                        label: "Work Hours",
                        lineTension: 0.3,
                        backgroundColor: "rgba(78, 115, 223, 0.12)",
                        borderColor: "rgba(78, 115, 223, 1)",
                        pointRadius: 4,
                        pointBackgroundColor: "rgba(78, 115, 223, 1)",
                        pointBorderColor: "#fff",
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                        pointHoverBorderColor: "#fff",
                        data: window.DASHBOARD_DATA.attendanceTrend.hours,
                        yAxisID: 'y-hours'
                    },
                    {
                        label: "Staff Clock-ins",
                        lineTension: 0.3,
                        backgroundColor: "rgba(28, 200, 138, 0.05)",
                        borderColor: "rgba(28, 200, 138, 1)",
                        borderDash: [5, 5],
                        pointRadius: 3,
                        pointBackgroundColor: "rgba(28, 200, 138, 1)",
                        pointBorderColor: "#fff",
                        data: window.DASHBOARD_DATA.attendanceTrend.punches,
                        yAxisID: 'y-punches'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                tooltips: {
                    backgroundColor: "#fff",
                    bodyFontColor: "#858796",
                    titleFontColor: '#4e73df',
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    xPadding: 12,
                    yPadding: 12,
                    callbacks: {
                        label: function(item, data) {
                            var ds = data.datasets[item.datasetIndex];
                            if (ds.yAxisID === 'y-hours') {
                                return ds.label + ': ' + Number(item.yLabel).toFixed(2) + ' hrs';
                            }
                            return ds.label + ': ' + item.yLabel + ' clock-in(s)';
                        }
                    }
                },
                scales: {
                    xAxes: [{
                        gridLines: { display: false }
                    }],
                    yAxes: [
                        {
                            id: 'y-hours',
                            position: 'left',
                            ticks: {
                                beginAtZero: true,
                                callback: function(val) { return val + ' h'; }
                            },
                            scaleLabel: {
                                display: true,
                                labelString: 'Hours'
                            }
                        },
                        {
                            id: 'y-punches',
                            position: 'right',
                            gridLines: { drawOnChartArea: false },
                            ticks: {
                                beginAtZero: true,
                                precision: 0
                            },
                            scaleLabel: {
                                display: true,
                                labelString: 'Clock-ins'
                            }
                        }
                    ]
                }
            }
        });
    }

    // 2. Attendance by Day of Week
    var ctxDow = document.getElementById("chartAttendanceDow");
    if (ctxDow) {
        new Chart(ctxDow, {
            type: 'bar',
            data: {
                labels: window.DASHBOARD_DATA.attendanceDow.labels,
                datasets: [{
                    label: "Hours Worked",
                    backgroundColor: "rgba(28, 200, 138, 0.85)",
                    hoverBackgroundColor: "rgba(28, 200, 138, 1)",
                    data: window.DASHBOARD_DATA.attendanceDow.hours
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                tooltips: {
                    backgroundColor: "#fff",
                    bodyFontColor: "#858796",
                    titleFontColor: '#1cc88a',
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(item) {
                            var p = window.DASHBOARD_DATA.attendanceDow.punches[item.index] || 0;
                            return Number(item.yLabel).toFixed(1) + ' hrs (' + p + ' punches)';
                        }
                    }
                },
                scales: {
                    xAxes: [{ gridLines: { display: false } }],
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            callback: function(v) { return v + ' h'; }
                        }
                    }]
                }
            }
        });
    }

    // 3. Payroll Expense by Cutoff
    var ctxPay = document.getElementById("chartPayrollCutoff");
    if (ctxPay) {
        new Chart(ctxPay, {
            type: 'bar',
            data: {
                labels: window.DASHBOARD_DATA.payrollCutoff.labels.length > 0 ? window.DASHBOARD_DATA.payrollCutoff.labels : ['Current Cutoff'],
                datasets: [
                    {
                        label: "Gross Labor Cost",
                        backgroundColor: "#f6c23e",
                        hoverBackgroundColor: "#dfa71b",
                        data: window.DASHBOARD_DATA.payrollCutoff.gross.length > 0 ? window.DASHBOARD_DATA.payrollCutoff.gross : [0],
                        yAxisID: 'y-pay'
                    },
                    {
                        label: "Hours Worked",
                        backgroundColor: "#4e73df",
                        hoverBackgroundColor: "#2e59d9",
                        data: window.DASHBOARD_DATA.payrollCutoff.hours.length > 0 ? window.DASHBOARD_DATA.payrollCutoff.hours : [0],
                        yAxisID: 'y-hours'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                tooltips: {
                    backgroundColor: "#fff",
                    bodyFontColor: "#858796",
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(item, data) {
                            var ds = data.datasets[item.datasetIndex];
                            if (ds.yAxisID === 'y-pay') {
                                return ds.label + ': ' + fmtMoney(item.yLabel);
                            }
                            return ds.label + ': ' + Number(item.yLabel).toFixed(1) + ' hrs';
                        }
                    }
                },
                scales: {
                    xAxes: [{ gridLines: { display: false } }],
                    yAxes: [
                        {
                            id: 'y-pay',
                            position: 'left',
                            ticks: {
                                beginAtZero: true,
                                callback: function(v) { return '₱' + Number(v).toLocaleString(); }
                            }
                        },
                        {
                            id: 'y-hours',
                            position: 'right',
                            gridLines: { drawOnChartArea: false },
                            ticks: {
                                beginAtZero: true,
                                callback: function(v) { return v + ' h'; }
                            }
                        }
                    ]
                }
            }
        });
    }

    // 4. Staff Labor Hours & Compensation
    var ctxStaff = document.getElementById("chartStaffLabor");
    if (ctxStaff) {
        new Chart(ctxStaff, {
            type: 'horizontalBar',
            data: {
                labels: window.DASHBOARD_DATA.staffLabor.names,
                datasets: [{
                    label: "Hours Logged",
                    backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#6f42c1'],
                    data: window.DASHBOARD_DATA.staffLabor.hours
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                tooltips: {
                    backgroundColor: "#fff",
                    bodyFontColor: "#858796",
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(item) {
                            var pay = window.DASHBOARD_DATA.staffLabor.pay[item.index] || 0;
                            return 'Hours: ' + item.xLabel + ' hrs | Est: ' + fmtMoney(pay);
                        }
                    }
                },
                scales: {
                    xAxes: [{
                        ticks: {
                            beginAtZero: true,
                            callback: function(v) { return v + ' h'; }
                        }
                    }],
                    yAxes: [{ gridLines: { display: false } }]
                }
            }
        });
    }

    // 5. Customer Feedback Ratings (Doughnut)
    var ctxRating = document.getElementById("chartFeedbackRating");
    if (ctxRating) {
        new Chart(ctxRating, {
            type: 'doughnut',
            data: {
                labels: window.DASHBOARD_DATA.feedbackRating.labels,
                datasets: [{
                    data: window.DASHBOARD_DATA.feedbackRating.counts,
                    backgroundColor: ['#1cc88a', '#36b9cc', '#f6c23e', '#fd7e14', '#e74a3b'],
                    hoverBackgroundColor: ['#17a673', '#2c9faf', '#dda20a', '#e86c07', '#be2617'],
                    hoverBorderColor: "rgba(234, 236, 244, 1)"
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                cutoutPercentage: 72,
                tooltips: {
                    backgroundColor: "#fff",
                    bodyFontColor: "#858796",
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(item, data) {
                            var val = data.datasets[0].data[item.index];
                            var lbl = data.labels[item.index];
                            return lbl + ': ' + val + ' review(s)';
                        }
                    }
                }
            }
        });
    }

    // 6. Branch Feedback & Satisfaction
    var ctxBranch = document.getElementById("chartBranchFeedback");
    if (ctxBranch) {
        var bLabels = window.DASHBOARD_DATA.branchFeedback.labels.length > 0 ? window.DASHBOARD_DATA.branchFeedback.labels : ['Main Store'];
        var bCounts = window.DASHBOARD_DATA.branchFeedback.counts.length > 0 ? window.DASHBOARD_DATA.branchFeedback.counts : [0];
        var bRatings = window.DASHBOARD_DATA.branchFeedback.ratings.length > 0 ? window.DASHBOARD_DATA.branchFeedback.ratings : [5];

        new Chart(ctxBranch, {
            type: 'bar',
            data: {
                labels: bLabels,
                datasets: [
                    {
                        label: "Total Reviews",
                        backgroundColor: "#4e73df",
                        data: bCounts,
                        yAxisID: 'y-count'
                    },
                    {
                        label: "Avg Rating (★)",
                        backgroundColor: "#f6c23e",
                        data: bRatings,
                        yAxisID: 'y-rate'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                tooltips: {
                    backgroundColor: "#fff",
                    bodyFontColor: "#858796",
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(item, data) {
                            var ds = data.datasets[item.datasetIndex];
                            if (ds.yAxisID === 'y-rate') {
                                return ds.label + ': ' + Number(item.yLabel).toFixed(1) + ' ★';
                            }
                            return ds.label + ': ' + item.yLabel + ' review(s)';
                        }
                    }
                },
                scales: {
                    xAxes: [{ gridLines: { display: false } }],
                    yAxes: [
                        {
                            id: 'y-count',
                            position: 'left',
                            ticks: { beginAtZero: true, precision: 0 },
                            scaleLabel: { display: true, labelString: 'Reviews' }
                        },
                        {
                            id: 'y-rate',
                            position: 'right',
                            gridLines: { drawOnChartArea: false },
                            ticks: { beginAtZero: true, max: 5, stepSize: 1 },
                            scaleLabel: { display: true, labelString: 'Stars' }
                        }
                    ]
                }
            }
        });
    }
})();
</script>
<?php endif; ?>
<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
