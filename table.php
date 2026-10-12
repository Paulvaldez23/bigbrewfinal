<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

$page_title = 'Big Brew - Tables';
$active_nav = 'table';
$extra_css = ['assets/vendor/datatables/dataTables.bootstrap4.min.css'];
$extra_js = [
    'assets/vendor/datatables/jquery.dataTables.min.js',
    'assets/vendor/datatables/dataTables.bootstrap4.min.js'
];
$extra_script = "$('#dataTable').DataTable({scrollX:true,autoWidth:false,columnDefs:[{width:'70px',targets:0},{width:'100px',targets:1},{width:'220px',targets:2},{width:'200px',targets:3},{width:'110px',targets:4}]});";

$rows = [];
if ($conn) {
    try {
        $stmt = $conn->query("SELECT e.id, e.employee_id, e.first_name, e.last_name, e.position, e.status
            FROM employees e
            ORDER BY e.id DESC");
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {}
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <h1 class="h3 mb-2 text-gray-800">Employee Table</h1>
    <p class="mb-4">List of all Big Brew employees.</p>
    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Employee Data</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered text-nowrap" id="dataTable" width="100%" cellspacing="0" style="min-width:820px;">
                    <thead>
                        <tr>
                            <th>ID</th><th>Emp Code</th><th>Name</th><th>Position</th><th>Status</th>
                        </tr>
                    </thead>
                    <tfoot>
                        
                    </tfoot>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-nowrap"><?php echo $r['id']; ?></td>
                            <td class="text-nowrap"><?php echo htmlspecialchars($r['employee_id']); ?></td>
                            <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td class="text-break"><?php echo htmlspecialchars($r['position'] ?? '-'); ?></td>
                            <td class="text-nowrap"><span class="badge badge-<?php echo $r['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-center text-gray-500 py-4">No records. Database may not be installed yet.</td></tr>
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
