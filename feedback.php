<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

$page_title = 'Big Brew - Feedback Records';
$active_nav = 'feedback';
$extra_css = ['assets/vendor/datatables/dataTables.bootstrap4.min.css'];
$extra_js = [
    'assets/vendor/datatables/jquery.dataTables.min.js',
    'assets/vendor/datatables/dataTables.bootstrap4.min.js'
];
$extra_script = "$('#dataTable').DataTable({order:[[0,'desc']],scrollX:true,autoWidth:false,columnDefs:[
    {width:'170px',targets:0},
    {width:'170px',targets:1},
    {width:'180px',targets:2},
    {width:'150px',targets:3},
    {width:'110px',targets:4},
    {width:'130px',targets:5},
    {width:'360px',targets:6},
    {width:'140px',targets:7}
]});";

$records = [];
if ($conn) {
    try {
        $records = $conn->query("SELECT
                f.id, f.rating, f.comment, f.branch, f.qr_code_scanned, f.submitted_at, f.submitted_at_local,
                f.on_shift_employee_id, f.on_shift_row, f.on_shift_week_start,
                e.employee_id as emp_code, e.first_name, e.last_name, e.position,
                onDuty.first_name  AS od_first_name,
                onDuty.last_name   AS od_last_name,
                onDuty.employee_id AS od_emp_code,
                onDuty.position    AS od_position
            FROM feedback f
            LEFT JOIN employees e      ON f.employee_id = e.id
            LEFT JOIN employees onDuty ON f.on_shift_employee_id = onDuty.id
            ORDER BY f.submitted_at DESC")->fetchAll();
    } catch (Exception $e) {}
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Feedback Records</h1>
        <a href="feedback_qr.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm"><i class="fas fa-qrcode fa-sm text-white-50"></i> Generate QR</a>
    </div>
    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">All Submitted Feedback</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:1280px;">
                    <thead><tr>
                        <th>Submitted</th>
                        <th>On-Duty Staff</th>
                        <th>Shift</th>
                        <th>Serving Branch</th>
                        <th>Rating</th>
                        <th>Employee (Legacy)</th>
                        <th>Comment</th>
                        <th>QR Scanned</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($records as $f):
                            $useLocalDate = !empty($f['submitted_at_local']) ? $f['submitted_at_local'] : $f['submitted_at'];
                            $odName = '';
                            if (!empty($f['on_shift_employee_id'])) {
                                $odFull = trim(($f['od_first_name'] ?? '') . ' ' . ($f['od_last_name'] ?? ''));
                                if ($odFull) {
                                    $odName = ($f['od_emp_code'] ? ($f['od_emp_code'] . ' · ') : '') . $odFull;
                                } else {
                                    $odName = 'Emp #' . (int)$f['on_shift_employee_id'];
                                }
                            }
                            $legacyEmp = '';
                            if (!empty($f['emp_code']) || !empty($f['first_name'] ?? null)) {
                                $legacyName = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
                                $legacyEmp  = ($f['emp_code'] ? ($f['emp_code'] . ' - ') : '') . $legacyName;
                            }
                            $servingBranch = $f['branch'] ?? '';
                            $shiftLabel    = $f['on_shift_row'] ?? '';
                        ?>
                        <tr>
                            <td class="text-nowrap">
                                <?php if (!empty($f['submitted_at_local'])): ?>
                                    <div><?php echo date('M d, Y h:i A', strtotime($f['submitted_at_local'])); ?></div>
                                    <div class="text-xs text-gray-500 font-italic">Local (Manila)</div>
                                <?php else: ?>
                                    <div><?php echo date('M d, Y h:i A', strtotime($f['submitted_at'])); ?></div>
                                    <div class="text-xs text-gray-500 font-italic">UTC / Default</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($odName): ?>
                                    <span class="font-weight-bold text-gray-800"><?php echo htmlspecialchars($odName); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 small">— no staff linked —</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($shiftLabel): ?>
                                    <span class="badge badge-pill" style="background:#ca7d2a;color:#fff;"><?php echo htmlspecialchars($shiftLabel); ?></span>
                                    <?php if (!empty($f['on_shift_week_start'])): ?><div class="text-xs text-gray-500 mt-1">Week: <?php echo date('M j', strtotime($f['on_shift_week_start'])); ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-400 small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($servingBranch): ?>
                                    <span class="badge badge-pill px-2 py-1" style="background:<?php echo $servingBranch === 'Branch 2' ? '#6f42c1' : ($servingBranch === 'Branch 3' ? '#2b8a8c' : ($servingBranch === 'Head Office' ? '#343a40' : '#ca7d2a')); ?>;color:#fff;">
                                        <i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($servingBranch); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?php if ($f['rating']): for($i=1;$i<=5;$i++): ?><i class="fas fa-star <?php echo $i<=$f['rating']?'text-warning':'text-gray-300'; ?>"></i><?php endfor; else: ?>-<?php endif; ?></td>
                            <td class="small"><?php echo htmlspecialchars($legacyEmp) ?: '-'; ?></td>
                            <td class="small text-break"><?php echo htmlspecialchars($f['comment'] ?? '-'); ?></td>
                            <td class="text-nowrap small"><?php echo htmlspecialchars($f['qr_code_scanned'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$records): ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-4">No feedback yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
