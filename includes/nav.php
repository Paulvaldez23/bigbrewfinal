<?php
if (!isset($user) && function_exists('current_user')) {
    $user = current_user();
}
$displayName = isset($user) ? ($user['name'] ?: $user['username']) : 'Admin';
$isStaffNav = function_exists('is_admin') ? !is_admin() : false;
$userIdNav = (int)($_SESSION['user_id'] ?? 0);
$staffEmpIdNav = null;
$unreadShiftNotifs = [];
$unreadPayrollNotifs = [];
$unreadAllNotifs = [];
$unreadCount = 0;
if ($isStaffNav && !empty($conn) && $userIdNav) {
    try {
        $s = $conn->prepare("SELECT employee_id FROM admin_users WHERE id = ? LIMIT 1");
        $s->execute([$userIdNav]);
        $srow = $s->fetch();
        if ($srow && !empty($srow['employee_id'])) {
            $staffEmpIdNav = (int)$srow['employee_id'];
            $n = $conn->prepare("SELECT sn.*, sw.published_at
                FROM shift_notifications sn
                LEFT JOIN shift_weeks sw ON sn.week_start = sw.week_start
                WHERE sn.employee_id = ? AND sn.is_read = 0
                ORDER BY sn.created_at DESC");
            $n->execute([$staffEmpIdNav]);
            $rawShift = $n->fetchAll() ?: [];
            foreach ($rawShift as $rs) {
                $unreadShiftNotifs[] = $rs;
                $unreadAllNotifs[] = [
                    'type' => 'shift',
                    'created_at' => $rs['published_at'] ?? $rs['created_at'],
                    'raw' => $rs,
                ];
            }

            $pn = $conn->prepare("SELECT pn.*, pr.processed_at, pr.net_pay, pr.cutoff_start, pr.cutoff_end,
                au.username AS processed_by_name, e.first_name, e.last_name
                FROM payroll_notifications pn
                LEFT JOIN payroll_records pr ON pn.employee_id = pr.employee_id AND pn.cutoff_start = pr.cutoff_start AND pn.cutoff_end = pr.cutoff_end
                LEFT JOIN admin_users au ON pn.processed_by = au.id
                LEFT JOIN employees e ON pn.employee_id = e.id
                WHERE pn.employee_id = ? AND pn.is_read = 0
                ORDER BY pn.created_at DESC");
            $pn->execute([$staffEmpIdNav]);
            $rawPayroll = $pn->fetchAll() ?: [];
            foreach ($rawPayroll as $rp) {
                $unreadPayrollNotifs[] = $rp;
                $unreadAllNotifs[] = [
                    'type' => 'payroll',
                    'created_at' => $rp['processed_at'] ?? $rp['created_at'],
                    'raw' => $rp,
                ];
            }

            try {
                $rq = $conn->prepare("SELECT sr.*, au.name AS reviewer_name
                    FROM schedule_requests sr
                    LEFT JOIN admin_users au ON sr.reviewed_by = au.id
                    WHERE sr.employee_id = ? AND sr.is_staff_read = 0 AND sr.status != 'pending'
                    ORDER BY sr.reviewed_at DESC");
                $rq->execute([$staffEmpIdNav]);
                $rawRequests = $rq->fetchAll() ?: [];
                foreach ($rawRequests as $rr) {
                    $unreadAllNotifs[] = [
                        'type' => 'request_reply',
                        'created_at' => $rr['reviewed_at'] ?? $rr['created_at'],
                        'raw' => $rr,
                    ];
                }
            } catch (Exception $e) {}

            usort($unreadAllNotifs, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            $unreadAllNotifs = array_slice($unreadAllNotifs, 0, 15);
            $unreadCount = count($unreadShiftNotifs) + count($unreadPayrollNotifs) + (isset($rawRequests) ? count($rawRequests) : 0);
        }
    } catch (Exception $e) {}
}

$adminPendingRequests = 0;
if (!$isStaffNav && !empty($conn)) {
    try {
        $adminPendingRequests = (int)$conn->query("SELECT COUNT(*) FROM schedule_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {}
}
?>
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <?php if ($isStaffNav && $staffEmpIdNav): ?>
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-bell fa-fw"></i>
                                <?php if ($unreadCount > 0): ?>
                                <span class="badge badge-danger badge-counter" style="position:absolute;top:6px;right:2px;font-size:.65rem;padding:2px 5px;"><?php echo (int)$unreadCount; ?></span>
                                <?php endif; ?>
                            </a>
                            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="alertsDropdown" style="width:22rem;">
                                <h6 class="dropdown-header">
                                    <i class="fas fa-bell mr-1"></i>Alerts Center
                                    <span class="ml-2 small font-weight-normal opacity-80">(<?php echo count($unreadPayrollNotifs); ?> payroll • <?php echo count($unreadShiftNotifs); ?> schedule)</span>
                                </h6>
                                <?php if (!$unreadAllNotifs): ?>
                                <div class="text-center text-gray-500 py-4 small">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No new notifications yet.
                                </div>
                                <?php else: ?>
                                    <?php foreach ($unreadAllNotifs as $item):
                                        $raw = $item['raw'];
                                        if ($item['type'] === 'shift'):
                                            $wk = $raw['week_start'];
                                            $we = date('M j, Y', strtotime($wk));
                                            $ws = date('M j', strtotime($wk . ' +6 days'));
                                            $label = 'Published ' . date('M j g:i A', strtotime($raw['published_at'] ?? $raw['created_at']));
                                    ?>
                                    <a class="dropdown-item d-flex align-items-center" href="schedule.php?mark_read=<?php echo urlencode($wk); ?>">
                                        <div class="mr-3">
                                            <div class="icon-circle bg-success"><i class="fas fa-calendar-check text-white"></i></div>
                                        </div>
                                        <div>
                                            <div class="small text-gray-500"><?php echo $label; ?></div>
                                            <span class="font-weight-bold">New Schedule:</span>
                                            <span class="text-gray-700"> <?php echo $we; ?> – <?php echo $ws; ?></span>
                                        </div>
                                    </a>
                                    <?php elseif ($item['type'] === 'payroll'):
                                        $cs = $raw['cutoff_start'];
                                        $ce = $raw['cutoff_end'];
                                        $startMonth = date('M j', strtotime($cs));
                                        $endMonth = date('M j, Y', strtotime($ce));
                                        $processedLabel = 'Processed ' . date('M j g:i A', strtotime($raw['processed_at'] ?? $raw['created_at']));
                                        $netAmt = (float)($raw['net_pay'] ?? 0);
                                    ?>
                                    <a class="dropdown-item d-flex align-items-center" href="payroll.php?mark_read=1">
                                        <div class="mr-3">
                                            <div class="icon-circle bg-warning"><i class="fas fa-money-bill-wave text-white"></i></div>
                                        </div>
                                        <div>
                                            <div class="small text-gray-500"><?php echo $processedLabel; ?></div>
                                            <span class="font-weight-bold text-success">Payroll Ready:</span>
                                            <span class="text-gray-700"> <?php echo $startMonth; ?> – <?php echo $endMonth; ?></span>
                                            <div class="small text-success mt-1"><strong>₱ <?php echo number_format(max($netAmt, 0), 2); ?></strong> net pay</div>
                                        </div>
                                    </a>
                                    <?php elseif ($item['type'] === 'request_reply'):
                                        $wk = $raw['week_start'];
                                        $st = $raw['status'];
                                        $iconBg = $st === 'approved' ? 'bg-success' : ($st === 'declined' ? 'bg-danger' : 'bg-info');
                                        $statusText = ucfirst($st);
                                    ?>
                                    <a class="dropdown-item d-flex align-items-center" href="schedule.php?week=<?php echo urlencode($wk); ?>#schedule-requests-section">
                                        <div class="mr-3">
                                            <div class="icon-circle <?php echo $iconBg; ?>"><i class="fas fa-comments text-white"></i></div>
                                        </div>
                                        <div>
                                            <div class="small text-gray-500">Schedule Request <?php echo $statusText; ?> • <?php echo date('M j g:i A', strtotime($raw['reviewed_at'] ?? $raw['created_at'])); ?></div>
                                            <span class="font-weight-bold text-gray-800"><?php echo htmlspecialchars($raw['request_type']); ?>:</span>
                                            <div class="text-muted small"><?php echo htmlspecialchars(mb_strimwidth($raw['admin_reply'] ?: ('Status: ' . $statusText), 0, 42, '...')); ?></div>
                                        </div>
                                    </a>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                <div class="d-flex border-top">
                                    <a class="dropdown-item col text-center small text-gray-500 border-right" href="payroll.php?mark_read=1">
                                        <i class="fas fa-money-bill-wave mr-1"></i>View My Payroll
                                    </a>
                                    <a class="dropdown-item col text-center small text-gray-500" href="schedule.php">
                                        <i class="fas fa-calendar-week mr-1"></i>View My Schedule
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endif; ?>
                        <?php if (!$isStaffNav && $adminPendingRequests > 0): ?>
                        <li class="nav-item mx-1">
                            <a class="nav-link" href="shifts.php" title="Staff Shift Requests (<?php echo $adminPendingRequests; ?> pending)">
                                <i class="fas fa-comments fa-fw"></i>
                                <span class="badge badge-warning badge-counter" style="position:absolute;top:6px;right:2px;font-size:.65rem;padding:2px 5px;"><?php echo (int)$adminPendingRequests; ?></span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo htmlspecialchars($displayName); ?></span>
                                <img class="img-profile rounded-circle" src="assets/img/undraw_profile.svg">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                                <?php if ($isStaffNav): ?>
                                <a class="dropdown-item" href="schedule.php">
                                    <i class="fas fa-calendar-week fa-sm fa-fw mr-2 text-gray-400"></i>
                                    My Schedule
                                </a>
                                <a class="dropdown-item" href="payroll.php">
                                    <i class="fas fa-money-bill-wave fa-sm fa-fw mr-2 text-gray-400"></i>
                                    My Payroll
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php endif; ?>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#changePasswordModal">
                                    <i class="fas fa-key fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Change Password
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Logout
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>
