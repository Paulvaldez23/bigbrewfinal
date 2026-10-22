<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$page_title = 'Big Brew - Shift Schedule';
$active_nav = 'shifts';
$extra_css = [];
$extra_js = [];

if ($conn) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS employee_shifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            week_start DATE NOT NULL,
            day_of_week TINYINT NOT NULL COMMENT '1=Mon 7=Sun',
            shift_row VARCHAR(50) NOT NULL COMMENT 'matches shifts.shift_name key',
            shift_label VARCHAR(100),
            shift_in TIME,
            shift_out TIME,
            notes VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_wk_d (employee_id, week_start, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $col = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_shifts' AND COLUMN_NAME = 'shift_row'")->fetchColumn();
        if (!$col) {
            $conn->exec("ALTER TABLE employee_shifts ADD COLUMN shift_row VARCHAR(50) NOT NULL DEFAULT 'Mid' COMMENT 'matches shifts.shift_name key' AFTER day_of_week");
        }
    } catch (Exception $e) {}
    try {
        $col = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_shifts' AND COLUMN_NAME = 'branch'")->fetchColumn();
        if (!$col) {
            $conn->exec("ALTER TABLE employee_shifts ADD COLUMN branch VARCHAR(50) NOT NULL DEFAULT 'Branch 1' COMMENT 'Branch 1 or Branch 2' AFTER shift_row");
        }
    } catch (Exception $e) {}
    // =========================================================
    // UNIQUE INDEX UPGRADE (for DOUBLE SHIFT support):
    //   Legacy tables have UNIQUE `uniq_emp_week_day` (employee_id, week_start, day_of_week)
    //   This BLOCKS same employee on Morning + Afternoon + Night of SAME DAY!
    //   Fix: replace it with 4-column compound unique that includes `shift_row`.
    // =========================================================
    try {
        $schema = $conn->query("SELECT DATABASE()")->fetchColumn();
        $oldIdxExists = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = " . $conn->quote($schema) . "
              AND TABLE_NAME   = 'employee_shifts'
              AND INDEX_NAME   = 'uniq_emp_week_day'")->fetchColumn();
        if ($oldIdxExists) {
            try { $conn->exec("ALTER TABLE employee_shifts DROP INDEX uniq_emp_week_day"); } catch (Exception $_e) {}
        }
        $branchIdxExists = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = " . $conn->quote($schema) . "
              AND TABLE_NAME   = 'employee_shifts'
              AND INDEX_NAME   = 'uniq_emp_week_day_row_branch'")->fetchColumn();
        if (!$branchIdxExists) {
            try {
                $old4Exists = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = " . $conn->quote($schema) . "
                      AND TABLE_NAME   = 'employee_shifts'
                      AND INDEX_NAME   = 'uniq_emp_week_day_row'")->fetchColumn();
                if ($old4Exists) {
                    try { $conn->exec("ALTER TABLE employee_shifts DROP INDEX uniq_emp_week_day_row"); } catch (Exception $_e) {}
                }
                $conn->exec("ALTER TABLE employee_shifts
                    ADD UNIQUE KEY uniq_emp_week_day_row_branch
                    (employee_id, week_start, day_of_week, shift_row, branch)");
            } catch (Exception $_e) {}
        }
    } catch (Exception $e) {}
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS shift_weeks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_start DATE NOT NULL UNIQUE,
            published_at DATETIME NULL,
            published_by INT NULL,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS shift_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            employee_id INT NOT NULL,
            week_start DATE NOT NULL,
            is_read TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_emp_read (employee_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS shifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shift_name VARCHAR(100) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            color VARCHAR(20) DEFAULT '#4e73df',
            description VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $chk = $conn->query("SELECT COUNT(*) FROM shifts")->fetchColumn();
        if (!$chk) {
            $conn->exec("INSERT INTO shifts (shift_name, start_time, end_time, color, description) VALUES
                ('Morning Shift','10:00:00','19:00:00','#1cc88a','10:00 AM - 7:00 PM'),
                ('Afternoon Shift','12:00:00','21:00:00','#36b9cc','12:00 PM - 9:00 PM'),
                ('Night Shift','14:00:00','23:00:00','#858796','2:00 PM - 11:00 PM')");
        }
    } catch (Exception $e) {}
}

$shiftRows = [];
if ($conn) {
    try { $shiftRows = $conn->query("SELECT * FROM shifts ORDER BY start_time")->fetchAll(); } catch (Exception $e) {}
}
$seen = [];
$deduped = [];
foreach ($shiftRows as $s) {
    $k = strtolower(trim($s['shift_name'] ?? ''));
    if ($k === '' || isset($seen[$k])) { continue; }
    $seen[$k] = true;
    if (empty($s['color'])) { $s['color'] = '#4e73df'; }
    $deduped[] = $s;
}
$shiftRows = $deduped;
if (!$shiftRows) {
    $shiftRows = [
        ['id'=>0,'shift_name'=>'Morning Shift','start_time'=>'10:00:00','end_time'=>'19:00:00','color'=>'#1cc88a'],
        ['id'=>0,'shift_name'=>'Afternoon Shift','start_time'=>'12:00:00','end_time'=>'21:00:00','color'=>'#36b9cc'],
        ['id'=>0,'shift_name'=>'Night Shift','start_time'=>'14:00:00','end_time'=>'23:00:00','color'=>'#858796'],
    ];
}

$weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
}
$dt = DateTime::createFromFormat('Y-m-d', $weekStart);
if (!$dt || (int)$dt->format('N') !== 1) {
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($weekStart ?: 'now')));
    $dt = DateTime::createFromFormat('Y-m-d', $weekStart);
}
$prevWeek = date('Y-m-d', strtotime('-1 week', strtotime($weekStart)));
$nextWeek = date('Y-m-d', strtotime('+1 week', strtotime($weekStart)));
$thisWeek = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('+6 days', strtotime($weekStart)));
$dayHeaders = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i days", strtotime($weekStart)));
    $dow = (int)date('N', strtotime($d));
    $dayHeaders[] = [
        'date' => $d,
        'dow' => $dow,
        'label' => date('D', strtotime($d)),
        'sub' => date('M j', strtotime($d)),
        'isToday' => $d === date('Y-m-d'),
    ];
}

$employees = [];
if ($conn) {
    try {
        // Clean up any historical shifts erroneously created for admin/owner accounts
        $conn->exec("DELETE FROM employee_shifts WHERE employee_id IN (
            SELECT e.id FROM employees e 
            LEFT JOIN admin_users au ON au.employee_id = e.id 
            WHERE au.role = 'Admin' OR LOWER(COALESCE(e.position, '')) IN ('owner', 'admin')
        )");

        $employees = $conn->query("SELECT e.id, e.employee_id, e.first_name, e.last_name, e.position, e.status
            FROM employees e
            LEFT JOIN admin_users au ON au.employee_id = e.id
            WHERE (au.role IS NULL OR au.role != 'Admin')
              AND LOWER(COALESCE(e.position, '')) NOT IN ('owner', 'admin')
            ORDER BY e.first_name")->fetchAll();
    } catch (Exception $e) {}
}

$assignments = [];
if ($conn) {
    try {
        $stmt = $conn->prepare("SELECT es.* FROM employee_shifts es
            JOIN employees e ON es.employee_id = e.id
            LEFT JOIN admin_users au ON au.employee_id = e.id
            WHERE es.week_start = ?
              AND (au.role IS NULL OR au.role != 'Admin')
              AND LOWER(COALESCE(e.position, '')) NOT IN ('owner', 'admin')");
        $stmt->execute([$weekStart]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $rowKey = $r['shift_row'] ?? $r['shift_label'] ?? 'Mid';
            if (empty($rowKey)) { $rowKey = 'Mid'; }
            $assignments[(int)$r['day_of_week']][$rowKey][] = $r;
        }
    } catch (Exception $e) {}
}

$weekStatus = ['published' => false, 'published_at' => null, 'published_by_name' => null, 'count_assignments' => 0];
if ($conn) {
    try {
        $ws = $conn->prepare("SELECT sw.*, au.name AS publisher_name FROM shift_weeks sw
            LEFT JOIN admin_users au ON sw.published_by = au.id
            WHERE sw.week_start = ? LIMIT 1");
        $ws->execute([$weekStart]);
        $wrow = $ws->fetch();
        if ($wrow) {
            $weekStatus['published'] = !empty($wrow['published_at']);
            $weekStatus['published_at'] = $wrow['published_at'];
            $weekStatus['published_by_name'] = $wrow['publisher_name'] ?? null;
        }
        $cnt = $conn->prepare("SELECT COUNT(DISTINCT es.employee_id) FROM employee_shifts es
            JOIN employees e ON es.employee_id = e.id
            LEFT JOIN admin_users au ON au.employee_id = e.id
            WHERE es.week_start = ?
              AND (au.role IS NULL OR au.role != 'Admin')
              AND LOWER(COALESCE(e.position, '')) NOT IN ('owner', 'admin')");
        $cnt->execute([$weekStart]);
        $weekStatus['count_assignments'] = (int)$cnt->fetchColumn();
    } catch (Exception $e) {}
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (($action === 'save' || $action === 'publish') && $conn) {
        header('Content-Type: application/json');
        $payload = json_decode($_POST['data'] ?? '[]', true) ?: [];
        $isPublish = ($action === 'publish');
        try {
            $conn->beginTransaction();
            $del = $conn->prepare("DELETE FROM employee_shifts WHERE week_start = ?");
            $del->execute([$weekStart]);
            $ins = $conn->prepare("INSERT INTO employee_shifts
                (employee_id, week_start, day_of_week, shift_row, branch, shift_label, shift_in, shift_out, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $affectedEmployeeIds = [];
            $b1Count = 0;
            $b2Count = 0;
            foreach ($payload as $row) {
                $eid = (int)($row['employee_id'] ?? 0);
                if ($eid > 0) { $affectedEmployeeIds[$eid] = true; }
                $branch = in_array(($row['branch'] ?? 'Branch 1'), ['Branch 1', 'Branch 2'], true) ? ($row['branch'] ?? 'Branch 1') : 'Branch 1';
                if ($branch === 'Branch 2') { $b2Count++; } else { $b1Count++; }
                $ins->execute([
                    $eid,
                    $weekStart,
                    (int)($row['day_of_week'] ?? 0),
                    (string)($row['shift_row'] ?? ''),
                    $branch,
                    (string)($row['shift_label'] ?? ''),
                    !empty($row['shift_in']) ? $row['shift_in'] : null,
                    !empty($row['shift_out']) ? $row['shift_out'] : null,
                    !empty($row['notes']) ? $row['notes'] : null,
                ]);
            }
            $upsert = $conn->prepare("INSERT INTO shift_weeks (week_start, published_at, published_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    published_at = VALUES(published_at),
                    published_by = VALUES(published_by)");
            if ($isPublish) {
                $nowDt = date('Y-m-d H:i:s');
                $publisher = (int)($_SESSION['user_id'] ?? 0);
                $upsert->execute([$weekStart, $nowDt, ($publisher ?: null)]);
                if ($affectedEmployeeIds) {
                    $notif = $conn->prepare("INSERT IGNORE INTO shift_notifications
                        (admin_user_id, employee_id, week_start, is_read) VALUES (?, ?, ?, 0)");
                    foreach ($affectedEmployeeIds as $empId => $_t) {
                        $notif->execute([($publisher ?: 0), (int)$empId, $weekStart]);
                    }
                }
            } else {
                $upsert->execute([$weekStart, null, null]);
            }
            $conn->commit();
            $empCount = count($affectedEmployeeIds);
            $totalShifts = $b1Count + $b2Count;
            if ($isPublish) {
                $msg = "📢 Published & Sent to $empCount staff ($totalShifts shifts: Branch 1: $b1Count, Branch 2: $b2Count) for week " . date('M j', strtotime($weekStart)) . '-' . date('M j, Y', strtotime($weekEnd)) . '. Staff can now view in My Schedule.';
            } else {
                $msg = "✅ Schedule draft saved ($totalShifts shifts: Branch 1: $b1Count, Branch 2: $b2Count across $empCount employees). <b>Not yet visible to staff — click <i>Publish & Notify</i> to send.</b>";
            }
            echo json_encode(['ok' => true, 'published' => $isPublish, 'msg' => $msg, 'count' => $empCount, 'b1_count' => $b1Count, 'b2_count' => $b2Count]);
            exit;
        } catch (Exception $e) {
            if ($conn && $conn->inTransaction()) { try { $conn->rollBack(); } catch (Exception $_r) {} }
            $msg = $e->getMessage();
            // Translate common DB errors to human-friendly Taglish
            if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'uniq_emp') !== false) {
                $msg = '⚠️ Nakaproblema ang database index. I-refresh mo ang page ng 1 beses (may nag-aayos na automatic migration), tapos i-save ulit. Salamat!';
            } elseif (stripos($msg, 'Integrity constraint') !== false) {
                $msg = '⚠️ May problema sa database habang nagsasave — pakirefresh ang page at subukan ulit. (auto-fix migration tumatakbo na sa page load)';
            }
            echo json_encode(['ok' => false, 'msg' => $msg]);
            exit;
        }
    }
    if ($action === 'copy_prev' && $conn) {
        try {
            $prev = date('Y-m-d', strtotime('-1 week', strtotime($weekStart)));
            $stmt = $conn->prepare("SELECT * FROM employee_shifts WHERE week_start = ?");
            $stmt->execute([$prev]);
            $rows = $stmt->fetchAll();
            if ($rows) {
                $conn->prepare("DELETE FROM employee_shifts WHERE week_start = ?")->execute([$weekStart]);
                $ins = $conn->prepare("INSERT INTO employee_shifts
                    (employee_id, week_start, day_of_week, shift_row, branch, shift_label, shift_in, shift_out, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($rows as $r) {
                    $branch = in_array(($r['branch'] ?? 'Branch 1'), ['Branch 1', 'Branch 2'], true) ? ($r['branch'] ?? 'Branch 1') : 'Branch 1';
                    $ins->execute([
                        (int)$r['employee_id'], $weekStart, (int)$r['day_of_week'],
                        $r['shift_row'], $branch, $r['shift_label'], $r['shift_in'], $r['shift_out'], $r['notes']
                    ]);
                }
                $message = 'Schedule copied from previous week.';
                $messageType = 'success';
            } else {
                $message = 'No schedule from the previous week to copy.';
                $messageType = 'info';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
        header('Location: shifts.php?week=' . urlencode($weekStart) . '&m=' . urlencode($message));
        exit;
    }
    if ($action === 'clear' && $conn) {
        try {
            $conn->prepare("DELETE FROM employee_shifts WHERE week_start = ?")->execute([$weekStart]);
            $message = 'Schedule cleared for the week.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
    if ($action === 'respond_request' && $conn) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'resolved');
        $adminReply = trim($_POST['admin_reply'] ?? '');
        $adminUserId = (int)($_SESSION['user_id'] ?? 0);
        if (!in_array($newStatus, ['pending', 'approved', 'declined', 'resolved'], true)) {
            $newStatus = 'resolved';
        }
        try {
            $upd = $conn->prepare("UPDATE schedule_requests SET
                status = ?, admin_reply = ?, reviewed_by = ?, reviewed_at = NOW(), is_staff_read = 0
                WHERE id = ?");
            $upd->execute([$newStatus, $adminReply, ($adminUserId ?: null), $reqId]);
            $message = 'Staff schedule request updated successfully (' . ucfirst($newStatus) . ').';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error updating request: ' . $e->getMessage();
            $messageType = 'danger';
        }
        header('Location: shifts.php?week=' . urlencode($weekStart) . '&m=' . urlencode($message));
        exit;
    }
}

$weekRequests = [];
$pendingWeekReqCount = 0;
if ($conn) {
    try {
        $wrq = $conn->prepare("SELECT sr.*, e.first_name, e.last_name, e.position, au.name AS reviewer_name
            FROM schedule_requests sr
            JOIN employees e ON sr.employee_id = e.id
            LEFT JOIN admin_users au ON sr.reviewed_by = au.id
            WHERE sr.week_start = ?
            ORDER BY sr.created_at DESC");
        $wrq->execute([$weekStart]);
        $weekRequests = $wrq->fetchAll();
        foreach ($weekRequests as $wr) {
            if ($wr['status'] === 'pending') {
                $pendingWeekReqCount++;
            }
        }
    } catch (Exception $e) {}
}

if (isset($_GET['m']) && $message === '') {
    $message = $_GET['m'];
    $messageType = 'info';
}

$shiftsById = [];
foreach ($shiftRows as $s) { $shiftsById[$s['shift_name']] = $s; }

$assignments_json = [];
foreach ($assignments as $dow => $byRow) {
    foreach ($byRow as $rowKey => $list) {
        foreach ($list as $a) {
            $sr = $a['shift_row'] ?? $a['shift_label'] ?? 'Mid';
            if (empty($sr)) { $sr = 'Mid'; }
            $assignments_json[] = [
                'employee_id' => (int)$a['employee_id'],
                'day_of_week' => (int)$a['day_of_week'],
                'shift_row' => $sr,
                'branch'      => in_array(($a['branch'] ?? 'Branch 1'), ['Branch 1', 'Branch 2'], true) ? ($a['branch'] ?? 'Branch 1') : 'Branch 1',
                'shift_label' => $a['shift_label'] ?? $sr,
                'shift_in' => $a['shift_in'] ?? null,
                'shift_out' => $a['shift_out'] ?? null,
                'notes' => $a['notes'] ?? null,
            ];
        }
    }
}
$employees_json = [];
foreach ($employees as $e) {
    $employees_json[] = [
        'id' => (int)$e['id'],
        'employee_id' => $e['employee_id'],
        'first_name' => $e['first_name'],
        'last_name' => $e['last_name'],
        'name' => $e['first_name'] . ' ' . $e['last_name'],
        'position' => $e['position'],
    ];
}
$shiftRows_json = [];
foreach ($shiftRows as $s) {
    $shiftRows_json[] = [
        'key' => $s['shift_name'],
        'shift_name' => $s['shift_name'],
        'start_time' => $s['start_time'],
        'end_time' => $s['end_time'],
        'color' => $s['color'] ?? '#4e73df',
    ];
}


$shift_app_data = [
    'week_start'          => $weekStart,
    'employees'           => $employees_json,
    'shift_rows'          => $shiftRows_json,
    'day_headers'         => $dayHeaders,
    'initial_assignments' => $assignments_json,
    'shifts_by_key'       => $shiftsById,
    'save_url'            => 'shifts.php',
];

$extra_js = ['js/shifts_scheduler.js'];
$extra_script = "window.SHIFT_APP_DATA = " . json_encode($shift_app_data) . ";";

$inline_css = "
.bb-emp-card{transition:all .15s ease;border-left:4px solid #4e73df;cursor:pointer;user-select:none;}
.bb-emp-card:hover{border-left-color:#1cc88a;transform:translateX(2px);box-shadow:0 2px 8px rgba(0,0,0,.12);}
.bb-emp-selected{background:rgba(28,200,138,.08);}
.bb-chip{transition:transform .1s ease;}
.bb-chip:hover{transform:translateY(-1px);}
.bb-cell{min-height:80px;vertical-align:top;transition:background .15s ease;cursor:pointer;}
.bb-cell:hover{background:rgba(78,115,223,.05);}
.bb-drag-over{background:rgba(78,115,223,.08) !important;outline:2px dashed #4e73df;outline-offset:-4px;}
.bb-preset{cursor:pointer;user-select:none;opacity:.85;transition:all .15s ease;}
.bb-preset:hover{opacity:1;}
.bb-empty-msg{pointer-events:none;}
";

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<?php if (!empty($inline_css)): ?>
<style><?php echo $inline_css; ?></style>
<?php endif; ?>

<div class="container-fluid">
    <?php
    // Banner keys for localStorage dismissal (keyed per week so switch between weeks still shows banner for new week)
    $wkKey = $weekStart;
    $bannerKeyPublished = 'bb-shift-banner-'.$wkKey.'-published';
    $bannerKeyDraft     = 'bb-shift-banner-'.$wkKey.'-draft';
    $bannerKeyTip       = 'bb-shift-banner-tip';
    ?>
    <div data-bb-banner-key="<?php echo $bannerKeyPublished; ?>" data-bb-banner-default="show"
         class="bb-dismissible-banner alert alert-success alert-dismissible fade show py-2 mb-2 <?php echo empty($weekStatus['published']) ? 'd-none' : ''; ?>" role="alert">
        <i class="fas fa-paper-plane mr-2"></i>
        <b>SENT TO STAFF:</b> Schedule for <b><?php echo date('M j', strtotime($weekStart)); ?> - <?php echo date('M j, Y', strtotime($weekEnd)); ?></b>
        was published on <?php echo date('M j, Y g:i A', strtotime($weekStatus['published_at'])); ?>
        <?php if (!empty($weekStatus['published_by_name'])): ?>by <b><?php echo htmlspecialchars($weekStatus['published_by_name']); ?></b><?php endif; ?>.
        <button type="button" class="close" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <div data-bb-banner-key="<?php echo $bannerKeyDraft; ?>" data-bb-banner-default="show"
         class="bb-dismissible-banner alert alert-warning alert-dismissible fade show py-2 mb-2 <?php echo (empty($weekStatus['published']) && !empty($weekStatus['count_assignments'])) ? '' : 'd-none'; ?>" role="alert">
        <i class="fas fa-file-alt mr-2"></i>
        <b>DRAFT:</b> You have <b><?php echo (int)$weekStatus['count_assignments']; ?> employees</b> assigned but
        this week <u>has NOT been published yet</u>. Staff CANNOT see this schedule.
        <button type="button" class="close" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <div data-bb-banner-key="<?php echo $bannerKeyTip; ?>" data-bb-banner-default="show"
         class="bb-dismissible-banner alert alert-info alert-dismissible fade show py-2 mb-2 <?php echo (empty($weekStatus['published']) && empty($weekStatus['count_assignments'])) ? '' : 'd-none'; ?>" role="alert">
        <i class="fas fa-lightbulb mr-2"></i>
        <b>Tip:</b> <u>CLICK an employee card</u> to select (turns green), then <u>CLICK any cell</u> to assign. Or just drag &amp; drop cards. When done, click <b>Publish &amp; Notify Staff</b> to send.
        <button type="button" class="close" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <script>
        // Dismiss banner + persist to localStorage (stays hidden on reload).
        // Fallback native JS — works even if Bootstrap's alert.js is not loaded.
        (function() {
            try {
                var banners = document.querySelectorAll('.bb-dismissible-banner');
                banners.forEach(function(b) {
                    var key = b.getAttribute('data-bb-banner-key');
                    if (key && window.localStorage.getItem(key) === 'dismissed') {
                        b.classList.add('d-none');
                        return;
                    }
                    var btn = b.querySelector('.close');
                    if (btn) {
                        btn.addEventListener('click', function() {
                            try { b.classList.add('d-none'); } catch(e) {}
                            try { window.localStorage.setItem(key, 'dismissed'); } catch(e) {}
                        });
                    }
                });
            } catch(e) {}
        })();
    </script>

    <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType ?: 'info'; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="d-sm-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0 text-gray-800 d-flex align-items-center">
                <i class="fas fa-calendar-week text-primary mr-2"></i>Weekly Shift Schedule
                <span class="ml-2 badge <?php echo !empty($weekStatus['published']) ? 'badge-success' : 'badge-warning'; ?>" style="font-size:.55em;vertical-align:middle;">
                    <?php if (!empty($weekStatus['published'])): ?>
                    <i class="fas fa-check-circle mr-1"></i>Published &amp; Sent
                    <?php else: ?>
                    <i class="fas fa-pen mr-1"></i>Draft
                    <?php endif; ?>
                </span>
            </h1>
            <p class="mb-0 text-muted small mt-1">
                <i class="far fa-calendar mr-1"></i>
                Week: <b><?php echo date('l, M j', strtotime($weekStart)); ?></b> to
                <b><?php echo date('l, M j, Y', strtotime($weekEnd)); ?></b>
                &nbsp;•&nbsp;
                <i class="fas fa-users mr-1"></i><span id="bb-count-assigned"><?php echo (int)$weekStatus['count_assignments']; ?></span> assigned / <?php echo count($employees); ?> total
            </p>
        </div>
        <div class="d-flex flex-wrap align-items-center mt-2">
            <div class="btn-group btn-group-sm mr-2">
                <a class="btn btn-outline-secondary" href="shifts.php?week=<?php echo urlencode($prevWeek); ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                <a class="btn btn-outline-primary <?php echo $weekStart === $thisWeek ? 'active' : ''; ?>" href="shifts.php?week=<?php echo urlencode($thisWeek); ?>"><i class="fas fa-calendar-day mr-1"></i>This Week</a>
                <a class="btn btn-outline-secondary" href="shifts.php?week=<?php echo urlencode($nextWeek); ?>">Next <i class="fas fa-chevron-right"></i></a>
            </div>
            <div class="input-group input-group-sm mr-2" style="width:185px;">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-calendar-alt"></i></span></div>
                <input type="week" class="form-control"
                    value="<?php echo date('o-\\WW', strtotime($weekStart)); ?>"
                    onchange="if(this.value){var p=this.value.split('-W');var y=p[0];var w=p[1];var d=new Date(Date.UTC(+y,0,1+(+w-1)*7));var d2=d.getUTCDay()||7;d.setUTCDate(d.getUTCDate()+1-d2);window.location='shifts.php?week='+d.toISOString().slice(0,10);}">
            </div>
            <button type="button" class="btn btn-sm btn-info shadow-sm mr-1" data-toggle="modal" data-target="#staffRequestsModal">
                <i class="fas fa-comments mr-1"></i>Staff Requests
                <?php if ($pendingWeekReqCount > 0): ?>
                <span class="badge badge-warning ml-1"><?php echo $pendingWeekReqCount; ?> pending</span>
                <?php elseif (!empty($weekRequests)): ?>
                <span class="badge badge-light text-dark ml-1"><?php echo count($weekRequests); ?></span>
                <?php endif; ?>
            </button>
            <button class="btn btn-sm btn-outline-secondary shadow-sm mr-1" id="bb-save-draft"><i class="fas fa-save mr-1"></i>Save Draft</button>
            <button class="btn btn-sm btn-success shadow-sm" id="bb-publish"><i class="fas fa-paper-plane mr-1"></i>Publish &amp; Notify Staff</button>
        </div>
    </div>

    <div class="d-sm-flex flex-wrap align-items-center mb-3 small">
        <div class="mr-auto-sm">
            <form method="POST" class="d-inline mr-2">
                <input type="hidden" name="action" value="copy_prev">
                <button type="submit" class="btn btn-sm btn-outline-info"><i class="fas fa-copy mr-1"></i>Copy Previous Week</button>
            </form>
            <form method="POST" class="d-inline mr-2" onsubmit="return confirm('Clear this week\'s schedule?');">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-eraser mr-1"></i>Clear Week</button>
            </form>
        </div>
        <div class="d-inline-flex flex-wrap align-items-center mt-1 mt-sm-0">
            <span class="text-muted mr-2 mt-1"><i class="fas fa-store mr-1"></i>Branch View &amp; Assign:</span>
            <span role="button" tabindex="0" class="bb-branch-preset badge badge-pill mr-1 mb-1" data-branch="Branch 1"
                  style="background:#ca7d2a;color:#fff;padding:.5rem .8rem;cursor:pointer;user-select:none;outline:none;-webkit-tap-highlight-color:transparent;box-shadow:0 0 0 3px rgba(0,0,0,.22);opacity:1;">
                <i class="fas fa-store mr-1"></i>Branch 1
            </span>
            <span role="button" tabindex="0" class="bb-branch-preset badge badge-pill mr-1 mb-1" data-branch="Branch 2"
                  style="background:#6f42c1;color:#fff;padding:.5rem .8rem;cursor:pointer;user-select:none;outline:none;-webkit-tap-highlight-color:transparent;opacity:.85;">
                <i class="fas fa-store mr-1"></i>Branch 2
            </span>
            <span role="button" tabindex="0" class="bb-branch-preset badge badge-pill mr-3 mb-1" data-branch="all"
                  style="background:#4e73df;color:#fff;padding:.5rem .8rem;cursor:pointer;user-select:none;outline:none;-webkit-tap-highlight-color:transparent;opacity:.85;">
                <i class="fas fa-layer-group mr-1"></i>Both Branches (All)
            </span>
            <span class="text-muted mr-2 mt-1">Shift presets:</span>
            <?php foreach ($shiftRows as $s): ?>
            <span class="bb-preset badge badge-pill mr-1 mb-1" data-key="<?php echo htmlspecialchars($s['shift_name']); ?>"
                  style="background:<?php echo htmlspecialchars($s['color'] ?? '#4e73df'); ?>;color:#fff;padding:.45rem .75rem;">
                <?php echo htmlspecialchars($s['shift_name']); ?>
            </span>
            <?php endforeach; ?>
            <span class="bb-preset badge badge-pill mr-1 mb-1" data-key="Off"
                  style="background:#e74a3b;color:#fff;padding:.45rem .75rem;">
                <i class="fas fa-umbrella-beach mr-1"></i>Rest Day
            </span>
        </div>
    </div>

    <div class="alert alert-light border py-2 px-3 mb-3 small" id="bb-assign-hint" role="alert">
        <i class="fas fa-info-circle text-primary mr-1"></i>
        <b>HOW TO USE:</b> Click any employee on the left → turns green. Then click any slot below to assign. Or HOLD + DRAG employees into cells. Click assigned chip to edit times / remove.
    </div>

    <div class="row">
        <div class="col-lg-3 col-md-4 mb-4">
            <?php if (empty($employees)): ?>
            <div class="alert alert-danger py-3 mb-2" role="alert">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                <b>Walang rehistradong empleyado.</b><br>
                Pumunta muna sa <a href="employees.php" class="alert-link"><u>Employees</u></a> para mag-add ng records bago mag-assign ng shift.
            </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 bg-light d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary small"><i class="fas fa-users mr-1"></i>Employees</h6>
                    <span class="badge badge-pill badge-primary"><?php echo count($employees); ?></span>
                </div>
                <div class="card-body p-2" style="max-height:68vh;overflow-y:auto;">
                    <?php if (empty($employees)): ?>
                    <div class="text-xs text-muted text-center py-4">
                        <i class="fas fa-user-slash text-gray-300 fa-2x mb-2 d-block"></i>
                        No employees yet.
                    </div>
                    <?php else: ?>
                        <?php foreach ($employees as $e):
                            $eid = (int)$e['id'];
                            $assigned_anywhere = false;
                            foreach ($assignments as $d => $byRow) {
                                foreach ($byRow as $rk => $l) {
                                    foreach ($l as $a) {
                                        if ((int)($a['employee_id'] ?? 0) === $eid) { $assigned_anywhere = true; break 3; }
                                    }
                                }
                            }
                            $card_opacity = $assigned_anywhere ? '0.55' : '1';
                            $pos = !empty($e['position']) ? htmlspecialchars($e['position']) : 'Staff';
                            $br = !empty($e['branch']) ? ' • ' . htmlspecialchars($e['branch']) : '';
                        ?>
                        <div class="card bb-emp-card mb-2 shadow-sm"
                             draggable="true"
                             data-emp="<?php echo (int)$e['id']; ?>"
                             style="opacity:<?php echo $card_opacity; ?>;">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex align-items-center">
                                    <div class="mr-2"><i class="fas fa-user-circle text-gray-500 fa-lg"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="font-weight-bold text-sm"><?php echo htmlspecialchars(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')); ?></div>
                                        <div class="text-xs text-gray-600"><?php echo $pos; ?></div>
                                    </div>
                                    <i class="fas fa-grip-vertical text-gray-300 ml-2"></i>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header py-2 bg-light"><h6 class="m-0 font-weight-bold text-primary small"><i class="fas fa-lightbulb text-warning mr-1"></i>Quick Guide</h6></div>
                <div class="card-body p-3 small text-gray-600">
                    <ul class="mb-0 pl-3">
                        <li><b>Click &amp; Click (easy):</b> select employee → click cell</li>
                        <li><b>Drag &amp; Drop:</b> HOLD a card → drop into cell</li>
                        <li><b>Swap/emergency:</b> drag chip to another cell</li>
                        <li><b>Edit:</b> click colored chip to edit times/notes</li>
                        <li><b>Rest Day:</b> click red "Rest Day" preset → assign</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-9 col-md-8">
            <div class="card shadow">
                <div class="card-body p-0 overflow-auto">
                    <table class="table table-bordered text-center align-middle mb-0" style="min-width:820px;">
                        <thead class="bg-light">
                            <tr>
                                <th style="width:170px;" class="text-left">Shift</th>
                                <?php foreach ($dayHeaders as $d): ?>
                                <th class="small">
                                    <div class="font-weight-bold"><?php echo htmlspecialchars($d['label']); ?></div>
                                    <div class="font-weight-normal <?php echo !empty($d['isToday']) ? 'text-primary font-weight-bold' : 'text-muted'; ?>">
                                        <?php echo htmlspecialchars($d['sub']); ?>
                                        <?php if (!empty($d['isToday'])): ?>
                                        <span class="badge badge-primary badge-pill ml-1 px-2" style="font-size:.55rem;">TODAY</span>
                                        <?php endif; ?>
                                    </div>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody id="bb-grid-body">
                            <?php foreach ($shiftRows as $sh):
                                $color = !empty($sh['color']) ? $sh['color'] : '#4e73df';
                                $s_in  = $sh['start_time'] ?? '';
                                $s_out = $sh['end_time']   ?? '';
                                $rowKey = $sh['shift_name'];
                            ?>
                            <tr>
                                <th class="text-left align-top bg-light">
                                    <div class="d-flex align-items-center">
                                        <span class="mr-2" style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?php echo htmlspecialchars($color); ?>;"></span>
                                        <div>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($rowKey); ?></div>
                                            <div class="text-xs text-gray-600 font-weight-normal">
                                                <?php echo $s_in ? date('g:i A', strtotime($s_in)) : ''; ?> - <?php echo $s_out ? date('g:i A', strtotime($s_out)) : ''; ?>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                <?php foreach ($dayHeaders as $d):
                                    $dow = (int)$d['dow'];
                                    $list = $assignments[$dow][$rowKey] ?? [];
                                    $chips = '';
                                    foreach ($list as $a) {
                                        $empE = null;
                                        foreach ($employees as $__e) {
                                            if ((int)$__e['id'] === (int)($a['employee_id'] ?? 0)) { $empE = $__e; break; }
                                        }
                                        if (!$empE) continue;
                                        $chipColor = $color;
                                        $chipLbl   = $a['shift_label'] ?? $rowKey;
                                        if (strcasecmp($chipLbl, 'Off') === 0) $chipColor = '#e74a3b';
                                        $chipName  = ($empE['first_name'] ?? '') . ' ' . mb_substr($empE['last_name'] ?? '', 0, 1) . '.';
                                        $chipBr    = in_array(($a['branch'] ?? 'Branch 1'), ['Branch 1','Branch 2'], true) ? ($a['branch'] ?? 'Branch 1') : 'Branch 1';
                                        $chipBrColor = $chipBr === 'Branch 2' ? '#6f42c1' : '#ca7d2a';
                                        $chipTip   = $chipLbl . ' • ' . $chipBr . ' • ' . ($s_in ? date('g:i A', strtotime($a['shift_in'] ?? $s_in)) : '') . ' - ' . ($s_out ? date('g:i A', strtotime($a['shift_out'] ?? $s_out)) : '');
                                        if (!empty($a['notes'])) $chipTip .= ' • ' . $a['notes'];
                                        $chips .= '<span class="bb-chip d-inline-block m-1 px-2 py-1 rounded text-white text-xs font-weight-bold" style="background:'.htmlspecialchars($chipColor).';border:1px solid rgba(0,0,0,.08);cursor:grab;" draggable="true" data-emp="'.(int)$empE['id'].'" data-dow="'.$dow.'" data-row="'.htmlspecialchars($rowKey).'" data-branch="'.htmlspecialchars($chipBr).'" title="'.htmlspecialchars($chipTip).'"><i class="fas fa-grip-horizontal opacity-60 mr-1"></i>'.htmlspecialchars($chipName).' <small class="ml-1 px-1 rounded" style="background:'.htmlspecialchars($chipBrColor).';">'.htmlspecialchars($chipBr === 'Branch 2' ? 'B2' : 'B1').'</small></span>';
                                    }
                                ?>
                                <td class="bb-cell align-top p-1" style="min-height:80px;"
                                    data-dow="<?php echo $dow; ?>" data-row="<?php echo htmlspecialchars($rowKey); ?>">
                                    <?php if ($chips): ?>
                                    <div class="text-left"><?php echo $chips; ?></div>
                                    <?php else: ?>
                                    <div class="bb-empty-msg text-xs text-gray-300 pt-2 px-2"><i class="fas fa-plus mr-1"></i>Click here (or drop)</div>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <noscript>
                <div class="alert alert-danger mt-2 small">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <b>Need JavaScript.</b> Enable JavaScript para sa click/drag assignment.
                </div>
            </noscript>
        </div>
    </div>
</div>

<div class="modal fade" id="bb-edit-modal" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title" id="bb-edit-title">Edit Assignment</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="bb-edit-empid">
        <input type="hidden" id="bb-edit-dow">
        <input type="hidden" id="bb-edit-row">
        <input type="hidden" id="bb-edit-orig-branch">
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Shift Label</label>
                <input id="bb-edit-label" class="form-control" placeholder="e.g. Mid / Opening">
            </div>
            <div class="form-group col-md-4">
                <label>Branch Assignment</label>
                <select id="bb-edit-branch" class="form-control">
                    <option value="Branch 1">🏬 Branch 1</option>
                    <option value="Branch 2">🏬 Branch 2</option>
                </select>
                <small class="form-text text-muted">Sent to staff so they know which branch to report to.</small>
            </div>
            <div class="form-group col-md-2">
                <label>Time In</label>
                <input type="time" id="bb-edit-in" class="form-control">
            </div>
            <div class="form-group col-md-2">
                <label>Time Out</label>
                <input type="time" id="bb-edit-out" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <input id="bb-edit-notes" class="form-control" placeholder="Optional: Trainee, swap note, etc.">
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger mr-auto" id="bb-edit-delete"><i class="fas fa-trash mr-1"></i>Remove Assignment</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="bb-edit-save"><i class="fas fa-check mr-1"></i>Save Changes</button>
    </div>
</div></div>
</div>

<!-- MODAL: STAFF REQUESTS & CONCERNS FOR THE WEEK -->
<div class="modal fade" id="staffRequestsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-comments mr-2"></i>Staff Schedule Concerns &amp; Requests
                    <span class="small font-weight-normal opacity-80 d-block mt-1">
                        Week: <?php echo date('M j', strtotime($weekStart)); ?> – <?php echo date('M j, Y', strtotime($weekEnd)); ?>
                    </span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-3 bg-light">
                <?php if (empty($weekRequests)): ?>
                <div class="text-center py-5 text-muted bg-white rounded border">
                    <i class="far fa-comments fa-3x mb-3 text-gray-300"></i>
                    <h6 class="font-weight-bold text-gray-700">Walang staff requests o concerns para sa linggong ito.</h6>
                    <p class="small text-gray-500 mb-0">Kapag nag-submit ang mga staff ng shift swap o leave request mula sa kanilang My Schedule page, lilitaw ang mga ito rito.</p>
                </div>
                <?php else: ?>
                <div class="mb-3 d-flex align-items-center justify-content-between px-1">
                    <span class="small font-weight-bold text-gray-700">
                        Total Requests: <?php echo count($weekRequests); ?>
                        <?php if ($pendingWeekReqCount > 0): ?>
                        <span class="badge badge-warning ml-2"><?php echo $pendingWeekReqCount; ?> Pending</span>
                        <?php endif; ?>
                    </span>
                    <span class="text-xs text-muted">Maaari kang sumagot at mag-update ng status dito.</span>
                </div>

                <?php foreach ($weekRequests as $wreq):
                    $st = $wreq['status'] ?? 'pending';
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
                ?>
                <div class="card shadow-sm mb-3 border-left-<?php echo $st === 'approved' ? 'success' : ($st === 'declined' ? 'danger' : ($st === 'resolved' ? 'info' : 'warning')); ?>">
                    <div class="card-body py-3 px-3 bg-white">
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
                            <div>
                                <span class="font-weight-bold text-gray-800" style="font-size:1.05rem;">
                                    <i class="fas fa-user-circle text-primary mr-1"></i>
                                    <?php echo htmlspecialchars($wreq['first_name'] . ' ' . $wreq['last_name']); ?>
                                </span>
                                <?php if (!empty($wreq['position'])): ?>
                                <span class="text-muted small ml-1">• <?php echo htmlspecialchars($wreq['position']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="badge <?php echo $badgeClass; ?> px-2 py-1 font-weight-bold mr-2">
                                    <i class="fas <?php echo $badgeIcon; ?> mr-1"></i><?php echo $badgeText; ?>
                                </span>
                                <span class="text-xs text-muted"><?php echo date('M j, g:i A', strtotime($wreq['created_at'])); ?></span>
                            </div>
                        </div>

                        <div class="mb-2">
                            <span class="badge badge-light border text-primary font-weight-bold mr-2">
                                <?php echo htmlspecialchars($wreq['request_type']); ?>
                            </span>
                            <?php if (!empty($wreq['target_date'])): ?>
                            <span class="text-xs text-muted">
                                <i class="far fa-calendar-alt mr-1"></i>Target Date: <b><?php echo date('D, M j, Y', strtotime($wreq['target_date'])); ?></b>
                            </span>
                            <?php else: ?>
                            <span class="text-xs text-muted"><i class="far fa-calendar-week mr-1"></i>Whole Week</span>
                            <?php endif; ?>
                        </div>

                        <div class="p-2 bg-light rounded border text-gray-800 small mb-3">
                            <i class="fas fa-quote-left text-gray-400 mr-1"></i>
                            <?php echo nl2br(htmlspecialchars($wreq['message'])); ?>
                        </div>

                        <?php if (!empty($wreq['admin_reply'])): ?>
                        <div class="alert alert-light border small py-2 px-3 mb-3">
                            <i class="fas fa-comment-dots text-primary mr-1"></i>
                            <b>Admin Reply:</b> <?php echo nl2br(htmlspecialchars($wreq['admin_reply'])); ?>
                            <span class="text-muted ml-2">(<?php echo !empty($wreq['reviewed_at']) ? date('M j, g:i A', strtotime($wreq['reviewed_at'])) : ''; ?>)</span>
                        </div>
                        <?php endif; ?>

                        <!-- Admin Response Form -->
                        <form method="POST" class="border-top pt-2">
                            <input type="hidden" name="action" value="respond_request">
                            <input type="hidden" name="request_id" value="<?php echo $wreq['id']; ?>">
                            <div class="form-row align-items-center">
                                <div class="col-md-3 mb-2 mb-md-0">
                                    <select name="status" class="form-control form-control-sm" required>
                                        <option value="approved" <?php echo $st === 'approved' ? 'selected' : ''; ?>>✅ Approve</option>
                                        <option value="declined" <?php echo $st === 'declined' ? 'selected' : ''; ?>>❌ Decline</option>
                                        <option value="resolved" <?php echo $st === 'resolved' ? 'selected' : ''; ?>>ℹ️ Resolve</option>
                                        <option value="pending" <?php echo $st === 'pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-2 mb-md-0">
                                    <input type="text" name="admin_reply" class="form-control form-control-sm"
                                           placeholder="Admin response/note (e.g. Approved. Swapped with Juan.)"
                                           value="<?php echo htmlspecialchars($wreq['admin_reply'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 text-right">
                                    <button type="submit" class="btn btn-sm btn-primary btn-block">
                                        <i class="fas fa-save mr-1"></i> Update
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="modal-footer bg-white py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
