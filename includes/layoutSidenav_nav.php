<?php
require_once __DIR__ . '/brand.php';
if (!isset($user) && function_exists('current_user')) {
    $user = current_user();
}
$isAdminView = function_exists('is_admin') ? is_admin() : true;
$active_nav = $active_nav ?? 'dashboard';
$nav_active = function($key) use ($active_nav) { return $active_nav === $key ? ' active' : ''; };

$adminPendingAttendanceRequests = 0;
if ($isAdminView && !empty($conn)) {
    try {
        $adminPendingAttendanceRequests = (int)$conn->query("SELECT COUNT(*) FROM attendance_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {}
}
?>
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <div class="d-flex align-items-center justify-content-between px-3 pt-2 pb-1 d-md-none" style="border-bottom:1px solid rgba(255,255,255,0.12);">
                <span class="small font-weight-bold text-uppercase" style="color:#ffe7c7;letter-spacing:.1em;font-size:.7rem;">Menu</span>
                <button type="button" class="btn btn-sm text-white" onclick="$('body').removeClass('mobile-sidebar-open');$('#accordionSidebar').removeClass('mobile-show');" style="background:rgba(0,0,0,0.2);border:0;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;padding:0;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php" style="padding: .9rem .5rem;">
                <div class="sidebar-brand-icon mr-2" style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;">
                    <?php bigbrew_logo_img(48, true); ?>
                </div>
                <div class="sidebar-brand-text mx-2 text-left" style="line-height:1.05;">
                    <div class="font-weight-800" style="font-size:1.08rem;letter-spacing:.01em;">Big Brew</div>
                    <div class="small font-weight-normal text-uppercase" style="letter-spacing:.18em;opacity:.82;color:#ffe7c7;">HR Suite</div>
                </div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item<?php echo $nav_active('dashboard'); ?>">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span><?php echo $isAdminView ? 'Dashboard' : 'My Dashboard'; ?></span></a>
            </li>
            <hr class="sidebar-divider">
            <?php if ($isAdminView): ?>
            <div class="sidebar-heading">
                Management
            </div>
            <li class="nav-item<?php echo $nav_active('employees'); ?>">
                <a class="nav-link" href="employees.php">
                    <i class="fas fa-fw fa-users"></i>
                    <span>Employees</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('attendance'); ?>">
                <a class="nav-link" href="attendance.php">
                    <i class="fas fa-fw fa-clipboard-check"></i>
                    <span>Attendance</span>
                    <?php if (!empty($adminPendingAttendanceRequests) && $adminPendingAttendanceRequests > 0): ?>
                    <span class="badge badge-warning text-dark ml-1 font-weight-bold" style="font-size:.68rem;padding:2px 5px;"><?php echo (int)$adminPendingAttendanceRequests; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item<?php echo $nav_active('shifts'); ?>">
                <a class="nav-link" href="shifts.php">
                    <i class="fas fa-fw fa-calendar-week"></i>
                    <span>Shift Schedule</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('upload'); ?>">
                <a class="nav-link" href="upload_attendance.php">
                    <i class="fas fa-fw fa-file-upload"></i>
                    <span>Upload Biometrics</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('payroll'); ?>">
                <a class="nav-link" href="payroll.php">
                    <i class="fas fa-fw fa-money-bill-wave"></i>
                    <span>Payroll Processing</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('table'); ?>">
                <a class="nav-link" href="table.php">
                    <i class="fas fa-fw fa-table"></i>
                    <span>Tables</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">
                Feedback
            </div>
            <li class="nav-item<?php echo $nav_active('feedback'); ?>">
                <a class="nav-link" href="feedback.php">
                    <i class="fas fa-fw fa-comments"></i>
                    <span>Feedback Records</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('qr'); ?>">
                <a class="nav-link" href="feedback_qr.php">
                    <i class="fas fa-fw fa-qrcode"></i>
                    <span>QR Generator</span></a>
            </li>
            <?php else: ?>
            <div class="sidebar-heading">
                My Workspace
            </div>
            <li class="nav-item<?php echo $nav_active('attendance'); ?>">
                <a class="nav-link" href="attendance.php">
                    <i class="fas fa-fw fa-clipboard-check"></i>
                    <span>My Attendance</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('payroll'); ?>">
                <a class="nav-link" href="payroll.php">
                    <i class="fas fa-fw fa-money-bill-wave"></i>
                    <span>My Payroll</span></a>
            </li>
            <li class="nav-item<?php echo $nav_active('schedule'); ?>">
                <a class="nav-link" href="schedule.php">
                    <i class="fas fa-fw fa-calendar-week"></i>
                    <span>My Schedule</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">
                Account
            </div>
            <li class="nav-item">
                <a class="nav-link" href="#" data-toggle="modal" data-target="#changePasswordModal">
                    <i class="fas fa-fw fa-key"></i>
                    <span>Change Password</span></a>
            </li>
            <?php endif; ?>
            <hr class="sidebar-divider d-none d-md-block">
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>
        </ul>
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
<?php require __DIR__ . '/nav.php'; ?>
