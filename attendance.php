<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

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

// ---------- Weekly archive / filter logic ----------
$todayObj = new DateTime('today');
$todayDow = (int)$todayObj->format('N'); // 1=Mon ... 7=Sun
$thisWeekStart = (clone $todayObj)->sub(new DateInterval('P' . ($todayDow - 1) . 'D'));
$thisWeekStartStr = $thisWeekStart->format('Y-m-d');

$weekStartStr = $_GET['week_start'] ?? $thisWeekStartStr;
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $weekStartStr)) $weekStartStr = $thisWeekStartStr;
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
    ? "columnDefs:[{width:'115px',targets:0},{width:'180px',targets:1},{width:'110px',targets:2},{width:'110px',targets:3},{width:'115px',targets:4},{width:'100px',targets:5},{width:'105px',targets:6},{width:'260px',targets:7}]"
    : "columnDefs:[{width:'130px',targets:0},{width:'110px',targets:1},{width:'110px',targets:2},{width:'100px',targets:3},{width:'100px',targets:4},{width:'90px',targets:5},{width:'250px',targets:6}]";
$orderCol = $isAdmin ? 0 : 0;
$extra_script = "$(function(){ if (typeof $.fn.DataTable === 'function') { try { $('#dataTable').DataTable({order:[[$orderCol,'asc']],scrollX:true,autoWidth:false,pageLength:25,$colDefs}); } catch(e) {} } });";

$attendance = [];
$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'total_hours' => 0, 'days_with_records' => 0, 'total_days_in_week' => 7, 'total_emp_days' => 0, 'records' => 0];
$weekBadge = '';
if ($isThisWeek) $weekBadge = '<span class="badge badge-success ml-2"><i class="fas fa-star mr-1"></i>Current Week</span>';
elseif ($isPastWeek) $weekBadge = '<span class="badge badge-secondary ml-2"><i class="fas fa-archive mr-1"></i>Archived Week</span>';
elseif ($isFutureWeek) $weekBadge = '<span class="badge badge-warning ml-2"><i class="fas fa-calendar-plus mr-1"></i>Upcoming Week</span>';

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
        } elseif ($staffEmpId) {
            $sql = "SELECT a.*
                FROM attendance a
                WHERE a.employee_id = ? AND a.attendance_date BETWEEN ? AND ?
                ORDER BY a.attendance_date ASC, a.id ASC";
            $stmt = $conn->prepare($sql);
            array_unshift($params, $staffEmpId);
            $stmt->execute($params);
        } else {
            $sql = null;
        }
        if (isset($stmt)) $attendance = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Build summary cards from filtered results
if ($attendance) {
    $seenKeys = [];
    foreach ($attendance as $a) {
        $st = strtolower($a['status'] ?? 'present');
        if (strpos($st, 'late') !== false) $summary['late']++;
        elseif ($st === 'absent') $summary['absent']++;
        else $summary['present']++;
        if (!empty($a['hours_worked'])) $summary['total_hours'] += (float)$a['hours_worked'];
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
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-clipboard-check text-primary mr-2"></i>
            <?php echo $isAdmin ? 'Attendance Records' : 'My Attendance'; ?>
            <?php echo $weekBadge; ?>
        </h1>
        <div>
            <?php if (!$isAdmin && $myEmpData): ?>
            <div class="text-sm text-muted mr-3 d-inline-block"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($myEmpData['first_name'] . ' ' . $myEmpData['last_name']); ?></div>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <a href="upload_attendance.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm mr-2"><i class="fas fa-upload fa-sm text-white-50"></i> Upload CSV</a>
            <?php endif; ?>
        </div>
    </div>

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
                            value="<?php echo $weekStartObj->format('o-\\WW'); ?>"
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
            <div class="alert alert-secondary mt-3 mb-0 py-2 small"><i class="fas fa-info-circle mr-1"></i><strong>Archived week:</strong> This week is already over. Records are kept here permanently for payroll reference and will not appear by default next week.</div>
            <?php elseif ($isThisWeek): ?>
            <div class="alert alert-success mt-3 mb-0 py-2 small"><i class="fas fa-info-circle mr-1"></i><strong>Current week:</strong> New attendance records will appear here automatically. At the end of this week (Sunday), this week gets archived automatically.</div>
            <?php elseif ($isFutureWeek): ?>
            <div class="alert alert-warning mt-3 mb-0 py-2 small"><i class="fas fa-info-circle mr-1"></i><strong>Upcoming week:</strong> This week hasn't started yet — any records already here for this date range are pre-loaded / forecasted.</div>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Hours</div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)$summary['total_hours'], 2); ?><span class="text-sm font-weight-normal text-gray-500 ml-1">hrs</span></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-hourglass-half fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:1080px;">
                    <thead><tr><th>Date</th><th>Employee</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th><th>Source</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <?php foreach ($attendance as $a):
                            $h = $a['hours_worked'] ?? null;
                            $isToday = (new DateTime($a['attendance_date']))->format('Y-m-d') === $todayObj->format('Y-m-d');
                        ?>
                        <tr class="<?php echo $isToday ? 'table-info' : ''; ?>">
                            <td class="text-nowrap"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); if ($isToday) echo ' <span class="badge badge-primary ml-1">Today</span>'; ?></td>
                            <td><?php echo htmlspecialchars(($a['emp_code'] ?? 'ID:'.$a['employee_id']) . ' - ' . ($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')); ?></td>
                            <td class="text-nowrap"><?php echo $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '-'; ?></td>
                            <td class="text-nowrap"><?php echo $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '-'; ?></td>
                            <td class="text-nowrap"><strong><?php echo $h !== null ? number_format((float)$h, 2) . ' hrs' : '-'; ?></strong></td>
                            <td class="text-nowrap"><span class="badge badge-<?php echo strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success'); ?>"><?php echo ucfirst($a['status'] ?? 'present'); ?></span></td>
                            <td class="text-nowrap"><?php echo ucfirst($a['source'] ?? '-'); ?></td>
                            <td class="text-break"><?php echo htmlspecialchars($a['remarks'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$attendance): ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-5"><i class="fas fa-inbox fa-2x mb-2 d-block text-gray-300"></i>No attendance records for this week. Upload a CSV/XLSX file or wait for staff to punch in.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:820px;">
                    <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th><th>Source</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <?php foreach ($attendance as $a):
                            $h = $a['hours_worked'] ?? null;
                            $isToday = (new DateTime($a['attendance_date']))->format('Y-m-d') === $todayObj->format('Y-m-d');
                        ?>
                        <tr class="<?php echo $isToday ? 'table-info' : ''; ?>">
                            <td class="text-nowrap"><?php echo date('M d, Y (D)', strtotime($a['attendance_date'])); if ($isToday) echo ' <span class="badge badge-primary ml-1">Today</span>'; ?></td>
                            <td class="text-nowrap"><?php echo $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '-'; ?></td>
                            <td class="text-nowrap"><?php echo $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '-'; ?></td>
                            <td class="text-nowrap"><strong><?php echo $h !== null ? number_format((float)$h, 2) . ' hrs' : '-'; ?></strong></td>
                            <td class="text-nowrap"><span class="badge badge-<?php echo strpos(strtolower($a['status'] ?? ''),'late')!==false?'warning':($a['status']==='absent'?'danger':'success'); ?>"><?php echo ucfirst($a['status'] ?? 'present'); ?></span></td>
                            <td class="text-nowrap text-muted small"><?php echo ucfirst($a['source'] ?? '-'); ?></td>
                            <td class="text-break"><?php echo htmlspecialchars($a['remarks'] ?? ''); ?></td>
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
<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
