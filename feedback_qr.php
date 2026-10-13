<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_login();

$page_title = 'Big Brew - QR Generator';
$active_nav = 'qr';

$branches = ['Branch 1', 'Branch 2', 'Branch 3', 'Head Office'];
$selectedBranch = $_GET['branch'] ?? 'Branch 1';

if (!empty($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false) {
    // Live hosting (e.g. bigbrew.infy.click)
    $feedbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/feedback_form.php';
} else {
    // Local development
    $feedbackUrl = 'http://localhost/bigbrewfinal/feedback_form.php';
}

$qrData = $feedbackUrl . '?branch=' . urlencode($selectedBranch);

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800 font-weight-bold">QR Code Generator</h1>
            <p class="text-muted small mb-0">I-print o i-display ang QR code sa inyong branch para makapag-feedback ang customer.</p>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-5">
            <div class="card shadow mb-4" style="border-radius:16px;border:none;">
                <div class="card-header py-3" style="background:#fff;border-bottom:1px solid #f0e6dc;border-top-left-radius:16px;border-top-right-radius:16px;">
                    <h6 class="m-0 font-weight-bold" style="color:#a8632a;"><i class="fas fa-store mr-2"></i>Select Branch</h6>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="form-group">
                            <label class="font-weight-bold small text-gray-700">Piliin ang Branch para sa QR:</label>
                            <select name="branch" class="form-control" required style="border-radius:10px;">
                                <?php foreach ($branches as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $selectedBranch === $b ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted mt-2">
                                <i class="fas fa-info-circle text-primary mr-1"></i> Tiyakin na may naka-assign na staff sa <b>Shift Schedule</b> para sa branch na ito upang kusa silang ma-link sa feedback.
                            </small>
                        </div>
                        <button class="btn btn-primary btn-block font-weight-bold py-2" style="border-radius:10px;">
                            <i class="fas fa-qrcode mr-2"></i>Generate QR Code
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow mb-4" style="border-radius:16px;border:none;">
                <div class="card-header py-3" style="background:#fff;border-bottom:1px solid #f0e6dc;border-top-left-radius:16px;border-top-right-radius:16px;">
                    <h6 class="m-0 font-weight-bold" style="color:#a8632a;"><i class="fas fa-qrcode mr-2"></i>Generated QR Code</h6>
                </div>
                <div class="card-body text-center py-4">
                    <div class="mb-3">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=<?php echo urlencode($qrData); ?>" alt="QR Code" class="p-3 bg-white shadow-sm" style="border-radius:16px;border:1px solid #fae8d4;max-width:100%;height:auto;">
                    </div>
                    <div class="mb-2">
                        <span class="badge badge-pill px-3 py-2 text-white font-weight-bold" style="background:#ca7d2a;font-size:0.9rem;">
                            <i class="fas fa-store mr-1"></i> <?php echo htmlspecialchars($selectedBranch); ?>
                        </span>
                    </div>
                    <p class="text-muted small mb-3">I-scan gamit ang cellphone camera para direktang mabuksan ang customer feedback form.</p>
                    <a class="btn btn-sm btn-outline-primary font-weight-bold px-3 py-2 mr-2" style="border-radius:10px;" href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?php echo urlencode($qrData); ?>" target="_blank">
                        <i class="fas fa-download mr-1"></i>Download High Res (Print)
                    </a>
                    <a class="btn btn-sm btn-light border font-weight-bold px-3 py-2" style="border-radius:10px;" href="<?php echo htmlspecialchars($qrData); ?>" target="_blank">
                        <i class="fas fa-external-link-alt mr-1"></i>Buksan ang Link
                    </a>
                    <div class="mt-4 p-2 rounded bg-light border small text-break text-muted">
                        <b>URL:</b> <?php echo htmlspecialchars($qrData); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
