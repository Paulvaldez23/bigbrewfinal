<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

$page_title = 'Big Brew - QR Generator';
$active_nav = 'qr';

$branches = ['Branch 1', 'Branch 2', 'Branch 3', 'Head Office'];
$qrData = null;
$feedbackUrl = 'http://localhost/bigbrewfinal/feedback_form.php';
if (!empty($_GET['branch'])) {
    $qrData = $feedbackUrl . '?branch=' . urlencode($_GET['branch']);
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">QR Code Generator</h1>
    </div>
    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Select Branch</h6></div>
                <div class="card-body">
                    <form method="GET">
                        <div class="form-group">
                            <label>Branch</label>
                            <select name="branch" class="form-control" required>
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-primary btn-block"><i class="fas fa-qrcode mr-2"></i>Generate QR Code</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Generated QR Code</h6></div>
                <div class="card-body text-center py-5">
                    <?php if ($qrData): ?>
                        <div class="mb-3">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode($qrData); ?>" alt="QR Code" class="border p-2 bg-white shadow">
                        </div>
                        <p class="small text-gray-600 mb-0">Branch: <strong><?php echo htmlspecialchars($_GET['branch']); ?></strong></p>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?php echo urlencode($qrData); ?>" target="_blank"><i class="fas fa-download mr-1"></i>Download High Res</a>
                        <p class="mt-3 small">
                            Feedback URL:<br>
                            <a href="<?php echo htmlspecialchars($qrData); ?>" target="_blank" class="text-primary"><?php echo htmlspecialchars($qrData); ?></a>
                        </p>
                    <?php else: ?>
                        <i class="fas fa-qrcode fa-6x text-gray-300 mb-3"></i>
                        <p class="text-gray-500 mb-0">Select a branch to generate a feedback QR code.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
