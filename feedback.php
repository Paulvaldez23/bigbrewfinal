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
    {width:'160px',targets:0},
    {width:'190px',targets:1},
    {width:'150px',targets:2},
    {width:'130px',targets:3},
    {width:'160px',targets:4},
    {width:'380px',targets:5},
    {width:'130px',targets:6}
]});";

// Auto-migrate schema in case feedback.php is loaded first
if ($conn) {
    try {
        $db = $conn->query("SELECT DATABASE()")->fetchColumn();
        $cols = [
            'on_shift_staff_list' => "ADD COLUMN on_shift_staff_list VARCHAR(255) NULL AFTER on_shift_employee_id",
            'q1_rating'           => "ADD COLUMN q1_rating TINYINT NULL AFTER rating",
            'q2_rating'           => "ADD COLUMN q2_rating TINYINT NULL AFTER q1_rating",
            'q3_rating'           => "ADD COLUMN q3_rating TINYINT NULL AFTER q2_rating",
            'submitted_at_local'  => "ADD COLUMN submitted_at_local DATETIME NULL AFTER submitted_at",
        ];
        foreach ($cols as $colName => $alterSql) {
            $exists = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = " . $conn->quote($db) . " AND TABLE_NAME = 'feedback' AND COLUMN_NAME = " . $conn->quote($colName))->fetchColumn();
            if (!$exists) {
                try { $conn->exec("ALTER TABLE feedback $alterSql"); } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) {}
}

$records = [];
if ($conn) {
    try {
        $records = $conn->query("SELECT
                f.id, f.rating, f.q1_rating, f.q2_rating, f.q3_rating, f.comment, f.branch, f.qr_code_scanned,
                f.submitted_at, f.submitted_at_local,
                f.on_shift_employee_id, f.on_shift_staff_list, f.on_shift_row, f.on_shift_week_start,
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

// Auto-relink past unlinked records & compute ratings if missing
if ($conn && !empty($records)) {
    foreach ($records as &$f) {
        // 1. Auto-relink duty staff if not linked
        if (empty($f['on_shift_employee_id']) && empty($f['on_shift_staff_list'])) {
            $subDate = !empty($f['submitted_at_local']) ? $f['submitted_at_local'] : $f['submitted_at'];
            if ($subDate) {
                $ts = strtotime($subDate);
                $dow = (int)date('N', $ts);
                $wkStart = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
                $hm = (int)date('Hi', $ts);

                $candidates = [
                    ['wk' => $wkStart, 'dow' => $dow, 'is_prev' => false]
                ];
                if ($hm < 800) {
                    $pDow = $dow - 1;
                    $pWk = $wkStart;
                    if ($pDow < 1) {
                        $pDow = 7;
                        $pWk = date('Y-m-d', strtotime('-7 days', strtotime($wkStart)));
                    }
                    $candidates[] = ['wk' => $pWk, 'dow' => $pDow, 'is_prev' => true];
                }

                $candRows = [];
                try {
                    $chkStmt = $conn->prepare("SELECT es.*, e.first_name, e.last_name, e.employee_id AS emp_code
                        FROM employee_shifts es
                        JOIN employees e ON es.employee_id = e.id
                        WHERE es.week_start = ? AND es.day_of_week = ? AND es.shift_row <> 'Off'
                        ORDER BY (es.branch = ?) DESC");

                    foreach ($candidates as $cand) {
                        $chkStmt->execute([$cand['wk'], $cand['dow'], $f['branch'] ?? 'Branch 1']);
                        $fetched = $chkStmt->fetchAll();
                        foreach ($fetched as $cr) {
                            $cr['_is_prev'] = $cand['is_prev'];
                            $candRows[] = $cr;
                        }
                    }

                    $matched = [];
                    $mRow = '';
                    $mWk = $wkStart;
                    foreach ($candRows as $cr) {
                        $si = !empty($cr['shift_in']) ? (int)str_replace(':', '', substr($cr['shift_in'], 0, 5)) : null;
                        $so = !empty($cr['shift_out']) ? (int)str_replace(':', '', substr($cr['shift_out'], 0, 5)) : null;
                        $sr = strtolower(trim($cr['shift_row']));
                        if ($si === null) {
                            if (strpos($sr, 'morn') !== false || strpos($sr, 'open') !== false) { $si = 600; $so = 1400; }
                            elseif (strpos($sr, 'after') !== false || strpos($sr, 'mid') !== false) { $si = 1400; $so = 2200; }
                            elseif (strpos($sr, 'night') !== false || strpos($sr, 'clos') !== false) { $si = 2200; $so = 600; }
                        }
                        $on = false;
                        $isOvernight = ($so !== null && $si !== null && $so <= $si);

                        if (!empty($cr['_is_prev'])) {
                            if ($isOvernight && $hm < $so) {
                                $on = true;
                            }
                        } else {
                            if ($isOvernight) {
                                $on = ($hm >= $si) || ($hm < $so);
                            } elseif ($si !== null && $so !== null) {
                                $on = ($hm >= $si) && ($hm < $so);
                            }
                        }

                        if ($on) {
                            $matched[] = $cr;
                            if (!$mRow) {
                                $mRow = $cr['shift_row'];
                                $mWk = $cr['week_start'];
                            }
                        }
                    }
                    if (empty($matched) && !empty($candRows)) {
                        $matched = [$candRows[0]];
                        $mRow = $candRows[0]['shift_row'];
                        $mWk = $candRows[0]['week_start'];
                    }
                    if (!empty($matched)) {
                        $mNames = [];
                        foreach ($matched as $mr) {
                            $fn = trim(($mr['first_name'] ?? '') . ' ' . ($mr['last_name'] ?? ''));
                            if ($fn && !in_array($fn, $mNames, true)) $mNames[] = $fn;
                        }
                        $f['on_shift_employee_id'] = (int)$matched[0]['employee_id'];
                        $f['on_shift_staff_list']  = implode(', ', $mNames);
                        $f['on_shift_row']         = $mRow;
                        $f['on_shift_week_start']  = $mWk;
                        $f['_auto_linked']         = true;

                        // Persist to database
                        try {
                            $conn->prepare("UPDATE feedback SET
                                on_shift_employee_id = ?, on_shift_staff_list = ?, on_shift_row = ?, on_shift_week_start = ?
                                WHERE id = ?")
                                ->execute([(int)$matched[0]['employee_id'], implode(', ', $mNames), $mRow, $mWk, $f['id']]);
                        } catch (Exception $_pe) {}
                    }
                } catch (Exception $_e) {}
            }
        }

        // 2. Auto-repair missing ratings from feedback_answers if rating is empty
        if (empty($f['rating'])) {
            try {
                $aStmt = $conn->prepare("SELECT answer_text FROM feedback_answers WHERE feedback_id = ?");
                $aStmt->execute([$f['id']]);
                $answers = $aStmt->fetchAll(PDO::FETCH_COLUMN);
                $numericVals = [];
                foreach ($answers as $av) {
                    $iv = (int)$av;
                    if ($iv >= 1 && $iv <= 5) $numericVals[] = $iv;
                }
                if (!empty($numericVals)) {
                    $calcAvg = (int)round(array_sum($numericVals) / count($numericVals));
                    $f['rating'] = $calcAvg;
                    try {
                        $conn->prepare("UPDATE feedback SET rating = ? WHERE id = ?")->execute([$calcAvg, $f['id']]);
                    } catch (Exception $_p2) {}
                }
            } catch (Exception $_e2) {}
        }
    }
    unset($f);
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800 font-weight-bold">Feedback Records</h1>
            <p class="text-muted small mb-0">Subaybayan ang mga rating, komento, at kung sino ang staff na naka-duty.</p>
        </div>
        <a href="feedback_qr.php" class="btn btn-sm btn-primary shadow-sm px-3 py-2 font-weight-bold" style="border-radius:10px;">
            <i class="fas fa-qrcode mr-2"></i> Generate QR Code
        </a>
    </div>

    <div class="card shadow mb-4" style="border-radius:16px;border:none;">
        <div class="card-header py-3 d-flex align-items-center justify-content-between" style="background:#fff;border-bottom:1px solid #f0e6dc;border-top-left-radius:16px;border-top-right-radius:16px;">
            <h6 class="m-0 font-weight-bold" style="color:#a8632a;">
                <i class="fas fa-comments mr-2"></i>All Submitted Customer Feedback
            </h6>
            <span class="badge badge-pill badge-light border text-muted px-3 py-1 font-weight-bold">
                Total: <?php echo count($records); ?> responses
            </span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:1150px;">
                    <thead style="background:#faf5f0;color:#6b4d32;">
                        <tr>
                            <th>Submitted</th>
                            <th>On-Duty Staff</th>
                            <th>Shift</th>
                            <th>Serving Branch</th>
                            <th>Rating</th>
                            <th>Comment &amp; Breakdown</th>
                            <th>QR Scanned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $f):
                            $useLocalDate = !empty($f['submitted_at_local']) ? $f['submitted_at_local'] : $f['submitted_at'];
                            
                            // On-Duty Staff Name
                            $odName = '';
                            if (!empty($f['on_shift_staff_list'])) {
                                $odName = $f['on_shift_staff_list'];
                            } elseif (!empty($f['on_shift_employee_id'])) {
                                $odFull = trim(($f['od_first_name'] ?? '') . ' ' . ($f['od_last_name'] ?? ''));
                                if ($odFull) {
                                    $odName = ($f['od_emp_code'] ? ($f['od_emp_code'] . ' · ') : '') . $odFull;
                                } else {
                                    $odName = 'Emp #' . (int)$f['on_shift_employee_id'];
                                }
                            }

                            $servingBranch = $f['branch'] ?? '';
                            $shiftLabel    = $f['on_shift_row'] ?? '';
                            $ratingVal     = (int)($f['rating'] ?? 0);
                        ?>
                        <tr>
                            <!-- Submitted Timestamp -->
                            <td class="text-nowrap align-middle">
                                <div class="font-weight-bold text-gray-800"><?php echo date('M d, Y h:i A', strtotime($useLocalDate)); ?></div>
                                <div class="text-xs text-muted"><i class="fas fa-clock mr-1 text-primary"></i>Manila Time</div>
                            </td>

                            <!-- On-Duty Staff -->
                            <td class="align-middle">
                                <?php if ($odName): ?>
                                    <div class="font-weight-bold text-gray-900" style="font-size:0.95rem;">
                                        <i class="fas fa-user-check mr-1 text-success"></i> <?php echo htmlspecialchars($odName); ?>
                                    </div>
                                    <?php if (!empty($f['_auto_linked'])): ?>
                                        <span class="badge badge-light border text-primary" style="font-size:0.7rem;">Auto-linked</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small font-italic">— no staff linked —</span>
                                <?php endif; ?>
                            </td>

                            <!-- Shift -->
                            <td class="align-middle">
                                <?php if ($shiftLabel): ?>
                                    <span class="badge badge-pill px-2 py-1" style="background:#ca7d2a;color:#fff;font-size:0.82rem;">
                                        <?php echo htmlspecialchars($shiftLabel); ?>
                                    </span>
                                    <?php if (!empty($f['on_shift_week_start'])): ?>
                                        <div class="text-xs text-muted mt-1">Wk: <?php echo date('M j', strtotime($f['on_shift_week_start'])); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Serving Branch -->
                            <td class="align-middle">
                                <?php if ($servingBranch): ?>
                                    <span class="badge badge-pill px-2 py-1" style="background:<?php echo $servingBranch === 'Branch 2' ? '#6f42c1' : ($servingBranch === 'Branch 3' ? '#2b8a8c' : ($servingBranch === 'Head Office' ? '#343a40' : '#ca7d2a')); ?>;color:#fff;font-size:0.82rem;">
                                        <i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($servingBranch); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Rating -->
                            <td class="text-nowrap align-middle">
                                <?php if ($ratingVal > 0): ?>
                                    <div>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $ratingVal ? 'text-warning' : 'text-gray-300'; ?>"></i>
                                        <?php endfor; ?>
                                        <span class="font-weight-bold ml-1 text-gray-800"><?php echo $ratingVal; ?>.0</span>
                                    </div>
                                    <?php if (!empty($f['q1_rating']) || !empty($f['q2_rating']) || !empty($f['q3_rating'])): ?>
                                        <div class="text-xs text-muted mt-1" title="Inumin / Staff / Kalinisan">
                                            <span>🍵 <?php echo (int)$f['q1_rating']; ?>★</span>
                                            <span class="mx-1">&middot;</span>
                                            <span>👤 <?php echo (int)$f['q2_rating']; ?>★</span>
                                            <span class="mx-1">&middot;</span>
                                            <span>✨ <?php echo (int)$f['q3_rating']; ?>★</span>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Comment & Breakdown -->
                            <td class="align-middle text-break" style="white-space:normal;max-width:380px;">
                                <?php if (!empty($f['comment'])): ?>
                                    <span class="font-weight-bold text-gray-800">"<?php echo htmlspecialchars($f['comment']); ?>"</span>
                                <?php else: ?>
                                    <span class="text-muted small font-italic">No comment written</span>
                                <?php endif; ?>
                            </td>

                            <!-- QR Scanned -->
                            <td class="text-nowrap align-middle small text-muted">
                                <?php echo htmlspecialchars($f['qr_code_scanned'] ?? $servingBranch ?? '-'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$records): ?>
                        <tr><td colspan="7" class="text-center text-gray-500 py-4">No feedback records found.</td></tr>
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
