<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$page_title = 'Big Brew - Employees';
$active_nav = 'employees';
$extra_css = ['assets/vendor/datatables/dataTables.bootstrap4.min.css'];
$extra_js = [
    'assets/vendor/datatables/jquery.dataTables.min.js',
    'assets/vendor/datatables/dataTables.bootstrap4.min.js'
];
$extra_script = "$(function(){ if (typeof $.fn.DataTable === 'function') { try { $('#dataTable').DataTable({scrollX:true,autoWidth:false,pageLength:25,columnDefs:[{width:'60px',targets:0},{width:'120px',targets:1},{width:'200px',targets:2},{width:'180px',targets:3},{width:'160px',targets:4},{width:'170px',targets:5}]}); } catch(e) {} } }); $(document).on('click', '.btn-edit-emp', function() { var b=$(this); var id=b.attr('data-id'); $('#edit_id').val(id); $('#edit_employee_id').val(b.attr('data-employee_id')||''); $('#edit_first_name').val(b.attr('data-first_name')||''); $('#edit_last_name').val(b.attr('data-last_name')||''); $('#edit_position').val(b.attr('data-position')||''); $('#edit_contact_number').val(b.attr('data-contact_number')||''); $('#edit_email').val(b.attr('data-email')||''); $('#edit_hourly_rate').val(b.attr('data-hourly_rate')||0); $('#edit_status').val(b.attr('data-status')||'active'); var hasLogin=b.attr('data-has_login')==='1'; $('#edit_has_login').prop('checked', hasLogin); $('#edit_login_section').toggle(hasLogin); $('#edit_username').val(b.attr('data-username')||''); $('#edit_password').val(''); $('#edit_admin_id').val(b.attr('data-admin_id')||''); $('#edit_role').val(b.attr('data-role')||'Staff'); }); $(document).on('change','#edit_has_login',function(){ $('#edit_login_section').toggle($(this).is(':checked')); }); $(document).on('click', '.btn-auto-pw', function() { var chars='ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@'; var s=''; for (var i=0;i<10;i++) s+=chars.charAt(Math.floor(Math.random()*chars.length)); var t=$($(this).attr('data-target')); t.val(s); t.attr('type','text'); setTimeout(function(){ t.attr('type','password'); }, 3500); }); $(document).on('click','.btn-toggle-pw',function(){ var t=$($(this).attr('data-target')); if (t.attr('type')==='password') { t.attr('type','text'); $(this).html('<i class=\"fas fa-eye-slash\"></i>'); } else { t.attr('type','password'); $(this).html('<i class=\"fas fa-eye\"></i>'); } }); $(document).on('keyup','#add_first_name,#add_last_name,#add_employee_id',function(){ var fn=$('#add_first_name').val().toLowerCase().trim(); var ln=$('#add_last_name').val().toLowerCase().trim(); var eid=$('#add_employee_id').val().toLowerCase().trim(); var u=''; if (fn||ln) u=(fn+'.'+ln).replace(/[^a-z0-9]/g,''); else u=eid; if (u) u=u.replace(/\\.+/g,'.').replace(/^\\.|\\.$/g,''); if (u) $('#add_username').val(u); });";

$message = '';
$messageType = '';
$employees = [];

function password_strong($pw) {
    return strlen($pw) >= 6;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && $conn) {
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO employees (employee_id, first_name, last_name, position, contact_number, email, hourly_rate, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                $_POST['employee_id'], $_POST['first_name'], $_POST['last_name'],
                $_POST['position'] ?? null,
                $_POST['contact_number'] ?? null, $_POST['email'] ?? null,
                $_POST['hourly_rate'] ?? 0
            ]);
            $newEmpId = $conn->lastInsertId();
            $fullName = trim(($_POST['first_name'] ?? '') . ' ' . ($_POST['last_name'] ?? ''));
            if (!$fullName) $fullName = 'New Employee #' . $newEmpId;
            $role = in_array(($_POST['role'] ?? 'Staff'), ['Staff', 'Admin'], true) ? ($_POST['role'] ?? 'Staff') : 'Staff';
            $createLogin = !empty($_POST['create_login']) && trim($_POST['username'] ?? '') !== '';
            if ($createLogin) {
                $username = trim($_POST['username']);
                $password = trim($_POST['password'] ?? '');
                if (!password_strong($password)) {
                    $conn->rollBack();
                    $message = 'Error: Password must be at least 6 characters.';
                    $messageType = 'danger';
                } else {
                    $chk = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
                    $chk->execute([$username]);
                    if ($chk->rowCount()) {
                        $conn->rollBack();
                        $message = 'Error: Username "' . htmlspecialchars($username) . '" is already taken.';
                        $messageType = 'danger';
                    } else {
                        $stmtU = $conn->prepare("INSERT INTO admin_users (username, password, role, employee_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $stmtU->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $newEmpId]);
                        $conn->commit();
                        $roleLabel = $role === 'Admin' ? 'Admin' : 'Staff';
                        $message = htmlspecialchars($fullName) . ' added! ' . $roleLabel . ' login created: <strong>' . htmlspecialchars($username) . '</strong> / temporary pw: <em>' . htmlspecialchars($password) . '</em> (write this down!).';
                        $messageType = 'success';
                    }
                }
            } else {
                $conn->commit();
                $message = htmlspecialchars($fullName) . ' added successfully! (Tip: Click Edit later to give them a staff login account.)';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    if ($action === 'edit' && $conn) {
        try {
            $conn->beginTransaction();
            $empId = (int)($_POST['id'] ?? 0);
            if ($empId <= 0) throw new Exception('Invalid employee.');

            $fullName = trim(($_POST['first_name'] ?? '') . ' ' . ($_POST['last_name'] ?? ''));
            if (!$fullName) $fullName = 'Employee #' . $empId;

            $stmt = $conn->prepare("UPDATE employees SET employee_id=?, first_name=?, last_name=?, position=?, contact_number=?, email=?, hourly_rate=?, status=? WHERE id=?");
            $stmt->execute([
                $_POST['employee_id'], $_POST['first_name'], $_POST['last_name'],
                $_POST['position'] ?? null,
                $_POST['contact_number'] ?? null, $_POST['email'] ?? null,
                $_POST['hourly_rate'] ?? 0, $_POST['status'] ?? 'active',
                $empId
            ]);

            $hasLogin = !empty($_POST['edit_has_login']);
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $role = in_array(($_POST['role'] ?? 'Staff'), ['Staff', 'Admin'], true) ? ($_POST['role'] ?? 'Staff') : 'Staff';
            $pwChangedMsg = '';
            $roleLabel = $role === 'Admin' ? 'Admin' : 'Staff';

            $existingAcc = null;
            $row = $conn->prepare("SELECT id, username FROM admin_users WHERE employee_id = ? LIMIT 1");
            $row->execute([$empId]);
            $existingAcc = $row->fetch();

            if (!$hasLogin) {
                if ($existingAcc) {
                    $conn->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$existingAcc['id']]);
                    $message = htmlspecialchars($fullName) . '\'s account updated! Staff login access has been removed.';
                } else {
                    $message = htmlspecialchars($fullName) . '\'s information updated successfully.';
                }
            } else {
                if ($username === '') throw new Exception('Username is required when login account is enabled.');
                if ($existingAcc) {
                    $chk = $conn->prepare("SELECT id FROM admin_users WHERE username = ? AND id != ? LIMIT 1");
                    $chk->execute([$username, $existingAcc['id']]);
                    if ($chk->rowCount()) throw new Exception('Username "' . htmlspecialchars($username) . '" is already taken.');
                    if ($password !== '') {
                        if (!password_strong($password)) throw new Exception('Password must be at least 6 characters.');
                        $conn->prepare("UPDATE admin_users SET username=?, password=?, role=? WHERE id=?")->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $existingAcc['id']]);
                        $pwChangedMsg = ' (new password saved — tell the staff)';
                    } else {
                        $conn->prepare("UPDATE admin_users SET username=?, role=? WHERE id=?")->execute([$username, $role, $existingAcc['id']]);
                    }
                    $message = htmlspecialchars($fullName) . '\'s account updated! Login username: <strong>' . htmlspecialchars($username) . '</strong> (' . $roleLabel . ' account)' . $pwChangedMsg . '.';
                } else {
                    if ($password === '') throw new Exception('Password is required when creating a new login.');
                    if (!password_strong($password)) throw new Exception('Password must be at least 6 characters.');
                    $chk = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
                    $chk->execute([$username]);
                    if ($chk->rowCount()) throw new Exception('Username "' . htmlspecialchars($username) . '" is already taken.');
                    $conn->prepare("INSERT INTO admin_users (username, password, role, employee_id, created_at) VALUES (?, ?, ?, ?, NOW())")->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $empId]);
                    $message = htmlspecialchars($fullName) . '\'s account updated! New ' . $roleLabel . ' login created: <strong>' . htmlspecialchars($username) . '</strong> / temporary pw: <em>' . htmlspecialchars($password) . '</em> (write this down!).';
                }
            }

            $conn->commit();
            $messageType = 'success';
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    if ($action === 'delete' && $conn) {
        try {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM admin_users WHERE employee_id = ?")->execute([$_POST['id']]);
            $conn->prepare("DELETE FROM employees WHERE id = ?")->execute([$_POST['id']]);
            $conn->commit();
            $message = 'Employee and linked login (if any) deleted.';
            $messageType = 'success';
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

if ($conn) {
    try {
        $employees = $conn->query("SELECT e.*, a.id AS admin_id, a.username, a.role FROM employees e LEFT JOIN admin_users a ON a.employee_id = e.id ORDER BY e.created_at DESC")->fetchAll();
    } catch (Exception $e) {}
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Employees</h1>
        <div class="mt-2 mt-sm-0">
            <span class="mr-2 small text-gray-600">
                <?php
                $cnt = count($employees);
                $withAcc = 0; foreach ($employees as $e) if (!empty($e['admin_id'])) $withAcc++;
                echo $cnt . ' total • ' . $withAcc . ' with login • ' . ($cnt - $withAcc) . ' no login';
                ?>
            </span>
            <button class="btn btn-sm btn-primary shadow-sm" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus fa-sm text-white-50"></i> Add Employee</button>
        </div>
    </div>
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div><?php endif; ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Employee List</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:1100px;">
                    <thead><tr><th>#</th><th>Employee Code</th><th>Name</th><th>Position</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php $idx = 0; foreach ($employees as $e): $idx++;
                            $fullName = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
                            $hasLogin = !empty($e['admin_id']);
                            $suggest = preg_replace('/[^a-z0-9]/', '', strtolower(($e['first_name'] ?? '') . '.' . ($e['last_name'] ?? '')));
                            $suggest = trim(preg_replace('/\.+/', '.', $suggest), '.');
                            if (!$suggest) $suggest = strtolower($e['employee_id'] ?? '');
                        ?>
                        <tr>
                            <td class="text-nowrap"><?php echo $idx; ?></td>
                            <td class="text-nowrap"><?php echo htmlspecialchars($e['employee_id']); ?></td>
                            <td><?php echo htmlspecialchars($fullName); ?></td>
                            <td class="text-break"><?php echo htmlspecialchars($e['position'] ?? '-'); ?></td>
                            <td class="text-nowrap">
                                <span class="badge badge-<?php echo $e['status'] === 'active' ? 'success' : 'secondary'; ?> mr-1"><?php echo ucfirst($e['status'] ?? 'active'); ?></span>
                                <?php if ($hasLogin): ?>
                                    <span class="badge badge-info" title="Login account: <?php echo htmlspecialchars($e['username']); ?>"><i class="fas fa-key mr-1"></i><?php echo htmlspecialchars($e['username']); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><i class="fas fa-user-slash mr-1"></i>No login</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <button class="btn btn-sm btn-primary mr-1 btn-edit-emp"
                                    data-id="<?php echo $e['id']; ?>"
                                    data-admin_id="<?php echo (int)($e['admin_id'] ?? 0); ?>"
                                    data-has_login="<?php echo $hasLogin ? '1' : '0'; ?>"
                                    data-username="<?php echo htmlspecialchars($e['username'] ?? ''); ?>"
                                    data-role="<?php echo htmlspecialchars($e['role'] ?? 'Staff'); ?>"
                                    data-employee_id="<?php echo htmlspecialchars($e['employee_id']); ?>"
                                    data-first_name="<?php echo htmlspecialchars($e['first_name']); ?>"
                                    data-last_name="<?php echo htmlspecialchars($e['last_name']); ?>"
                                    data-position="<?php echo htmlspecialchars($e['position'] ?? ''); ?>"
                                    data-contact_number="<?php echo htmlspecialchars($e['contact_number'] ?? ''); ?>"
                                    data-email="<?php echo htmlspecialchars($e['email'] ?? ''); ?>"
                                    data-hourly_rate="<?php echo htmlspecialchars($e['hourly_rate'] ?? 0); ?>"
                                    data-status="<?php echo htmlspecialchars($e['status'] ?? 'active'); ?>"
                                    data-toggle="modal" data-target="#editModal">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete employee and their login account?')" style="display:inline-block;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                                    <button class="btn btn-sm btn-danger" title="Delete employee + login"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$employees): ?>
                        <tr><td colspan="6" class="text-center text-gray-500 py-4">No employees yet. Click <strong>Add Employee</strong> to start.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
<div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add Employee + Staff Login</h5><button class="close" type="button" data-dismiss="modal"><span>×</span></button></div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group"><label>Employee ID</label><input name="employee_id" id="add_employee_id" required class="form-control" placeholder="e.g. BB-001"></div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>First Name</label><input name="first_name" id="add_first_name" required class="form-control"></div>
            <div class="form-group col-md-6"><label>Last Name</label><input name="last_name" id="add_last_name" required class="form-control"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-12"><label>Position</label><input name="position" class="form-control" placeholder="e.g. Barista, Cashier, Owner"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>Contact Number</label><input name="contact_number" class="form-control"></div>
            <div class="form-group col-md-6"><label>Email</label><input type="email" name="email" class="form-control"></div>
        </div>
        <div class="form-group"><label>Hourly Rate (₱)</label><input type="number" step="0.01" name="hourly_rate" value="0" min="0" class="form-control"></div>

        <hr class="my-3">
        <div class="bg-light p-3 rounded mb-2">
            <div class="custom-control custom-checkbox mb-3">
                <input type="checkbox" class="custom-control-input" id="create_login" name="create_login" value="1" checked>
                <label class="custom-control-label font-weight-bold" for="create_login">Create Staff Login Account (recommended)</label>
            </div>
            <div class="form-row">
                <div class="form-group col-md-5">
                    <label>Username</label>
                    <input name="username" id="add_username" class="form-control" placeholder="e.g. nash.rodrigo">
                    <small class="form-text text-muted">Auto-generated from name; editable.</small>
                </div>
                <div class="form-group col-md-3">
                    <label>Account Role</label>
                    <select name="role" id="add_role" class="form-control">
                        <option value="Staff" selected>Staff</option>
                        <option value="Admin">Admin</option>
                    </select>
                    <small class="form-text text-muted">Staff = Employee only. Admin = Full access.</small>
                </div>
                <div class="form-group col-md-4">
                    <label>Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="add_password" class="form-control" placeholder="At least 6 characters">
                        <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary btn-toggle-pw" data-target="#add_password" title="Show/Hide"><i class="fas fa-eye"></i></button>
                            <button type="button" class="btn btn-outline-primary btn-auto-pw" data-target="#add_password" title="Generate strong password"><i class="fas fa-magic mr-1"></i>Auto</button>
                        </div>
                    </div>
                    <small class="form-text text-muted">Click <strong>Auto</strong> to generate a 10-char random password. Shown for 3.5 seconds.</small>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i>Add Employee</button></div>
    </form>
</div></div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
<div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Employee + Staff Login</h5><button class="close" type="button" data-dismiss="modal"><span>×</span></button></div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <input type="hidden" name="admin_id" id="edit_admin_id">

        <div class="form-group"><label>Employee ID</label><input name="employee_id" id="edit_employee_id" required class="form-control"></div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>First Name</label><input name="first_name" id="edit_first_name" required class="form-control"></div>
            <div class="form-group col-md-6"><label>Last Name</label><input name="last_name" id="edit_last_name" required class="form-control"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-12"><label>Position</label><input name="position" id="edit_position" class="form-control"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>Contact Number</label><input name="contact_number" id="edit_contact_number" class="form-control"></div>
            <div class="form-group col-md-6"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>Hourly Rate (₱)</label><input type="number" step="0.01" name="hourly_rate" id="edit_hourly_rate" value="0" min="0" class="form-control"></div>
            <div class="form-group col-md-6">
                <label>Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <hr class="my-3">
        <div class="bg-light p-3 rounded mb-2">
            <div class="custom-control custom-checkbox mb-3">
                <input type="checkbox" class="custom-control-input" id="edit_has_login" name="edit_has_login" value="1">
                <label class="custom-control-label font-weight-bold" for="edit_has_login">Enable Staff Login Account</label>
                <small class="form-text text-muted mt-1">Uncheck this to REMOVE login access for this employee.</small>
            </div>
            <div id="edit_login_section">
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label>Username</label>
                        <input name="username" id="edit_username" class="form-control" placeholder="e.g. nash.rodrigo">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Account Role</label>
                        <select name="role" id="edit_role" class="form-control">
                            <option value="Staff">Staff</option>
                            <option value="Admin">Admin</option>
                        </select>
                        <small class="form-text text-muted">Staff = Employee only. Admin = Full access.</small>
                    </div>
                    <div class="form-group col-md-4">
                        <label>New Password </label>
                        <div class="input-group">
                            <input type="password" name="password" id="edit_password" class="form-control" placeholder="At least 6 characters (if changing)">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary btn-toggle-pw" data-target="#edit_password" title="Show/Hide"><i class="fas fa-eye"></i></button>
                                <button type="button" class="btn btn-outline-primary btn-auto-pw" data-target="#edit_password" title="Generate strong password"><i class="fas fa-magic mr-1"></i>Auto</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Changes</button></div>
    </form>
</div></div>
</div>

<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
