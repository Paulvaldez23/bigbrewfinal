<?php
session_start();
require_once dirname(__DIR__) . '/db_con.php';
require_once __DIR__ . '/brand.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$conn) {
        $error = 'Database connection failed. Please setup the database first.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['name'] = $user['name'];
                if (!empty($user['role'])) {
                    $_SESSION['role'] = $user['role'];
                } else {
                    $_SESSION['role'] = empty($user['employee_id']) ? 'Admin' : 'Staff';
                }
                header('Location: ../index.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="theme-color" content="#c17633">
    <title>Big Brew HR — Login</title>
    <link rel="icon" type="image/jpeg" sizes="32x32" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link rel="icon" type="image/jpeg" sizes="64x64" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link href="../assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,300,400,600,700,800" rel="stylesheet">
    <link href="../css/sb-admin-2.min.css" rel="stylesheet">
    <?php bigbrew_brand_css(); ?>
    <style>
        body.bg-gradient-primary {
            background:
                radial-gradient(1200px 600px at 10% 10%, rgba(255,243,227,0.12) 0%, transparent 60%),
                radial-gradient(1000px 500px at 90% 90%, rgba(168,99,42,0.35) 0%, transparent 55%),
                linear-gradient(135deg, #1f1b16 0%, #2b241c 55%, #3a2e20 100%) !important;
            min-height: 100vh;
        }
        .bb-login-split {
            background:
                radial-gradient(600px 300px at 50% 20%, rgba(255,243,227,0.22) 0%, transparent 60%),
                linear-gradient(160deg, #a8632a 0%, #c17633 45%, #d48a48 100%);
            position: relative;
            overflow: hidden;
        }
        .bb-login-split::before, .bb-login-split::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(255,243,227,0.08);
            pointer-events: none;
        }
        .bb-login-split::before { width: 420px; height: 420px; top: -120px; right: -160px; }
        .bb-login-split::after  { width: 280px; height: 280px; bottom: -90px; left: -110px; }
        .bb-login-card { border-radius: 22px; overflow: hidden; }
        .bb-subtitle { letter-spacing: 0.18em; font-weight: 700; opacity: 0.88; }
        .brand-logo-wrap { filter: drop-shadow(0 14px 30px rgba(0,0,0,0.35)); }
        .btn-user { border-radius: 10rem; font-size: .9rem; padding: .85rem 1rem; font-weight: 700; letter-spacing: .03em; }
    </style>
</head>
<body class="bg-gradient-primary">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-12 col-md-9">
                <div class="card o-hidden border-0 shadow-lg my-5 bb-login-card">
                    <div class="card-body p-0">
                        <div class="row">
                            <div class="col-lg-6 bb-login-split d-none d-lg-flex align-items-center justify-content-center">
                                <div class="text-center text-white p-5 position-relative brand-logo-wrap">
                                    <div class="mb-4">
                                        <?php bigbrew_logo_img(200, true); ?>
                                    </div>
                                    <h1 class="h1 mb-2 font-weight-800" style="color:#fff3e3; text-shadow:0 2px 18px rgba(0,0,0,0.25);">Big Brew</h1>
                                    <div class="bb-subtitle small text-uppercase mb-4" style="color:#ffe7c7;">Coffee &middot; Tea &middot; Milktea</div>
                                    <p class="mb-0 font-weight-light" style="color:#ffe7c7; opacity:0.9;">
                                        HR &amp; Attendance Management System
                                    </p>
                                    <div class="mt-4 small" style="color:#ffe7c7; opacity:0.6;">
                                        Admin &middot; Staff accounts &middot; Weekly Shifts &middot; Payroll
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6" style="background:#fffaf2;">
                                <div class="p-5">
                                    <div class="text-center mb-4 d-lg-none">
                                        <div class="mb-2" style="display:inline-block;"><?php bigbrew_logo_img(76, false); ?></div>
                                        <h1 class="h5 font-weight-bold text-gray-800 mb-0">Big Brew HR</h1>
                                    </div>
                                    <div class="text-center mb-4 mt-1">
                                        <h1 class="h4 font-weight-bold mb-1" style="color:#a8632a;">Welcome Back!</h1>
                                        <p class="small text-gray-500 mb-0">Sign in to continue to your workspace.</p>
                                    </div>
                                    <?php if ($error): ?>
                                        <div class="alert alert-danger border-0 rounded-lg shadow-sm" role="alert"><i class="fas fa-exclamation-triangle mr-2 text-danger"></i><?php echo htmlspecialchars($error); ?></div>
                                    <?php endif; ?>
                                    <?php if ($success): ?>
                                        <div class="alert alert-success border-0 rounded-lg shadow-sm" role="alert"><i class="fas fa-check-circle mr-2 text-success"></i><?php echo htmlspecialchars($success); ?></div>
                                    <?php endif; ?>
                                    <form class="user" method="POST" action="">
                                        <div class="form-group">
                                            <div class="input-group">
                                                <div class="input-group-prepend"><span class="input-group-text bg-white border-right-0" style="border-top-left-radius:10rem;border-bottom-left-radius:10rem;"><i class="fas fa-user" style="color:#c17633;"></i></span></div>
                                                <input type="text" name="username" class="form-control form-control-user border-left-0" placeholder="Username" required style="border-top-right-radius:10rem;border-bottom-right-radius:10rem;">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <div class="input-group">
                                                <div class="input-group-prepend"><span class="input-group-text bg-white border-right-0" style="border-top-left-radius:10rem;border-bottom-left-radius:10rem;"><i class="fas fa-lock" style="color:#c17633;"></i></span></div>
                                                <input type="password" name="password" class="form-control form-control-user border-left-0" placeholder="Password" required style="border-top-right-radius:10rem;border-bottom-right-radius:10rem;">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-user btn-block" style="border-radius:10rem;">
                                            <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                                        </button>
                                    </form>
                                    <hr class="my-4">
                                    <div class="text-center small text-gray-500">
                                        &copy; <?php echo date('Y'); ?> Big Brew HR System
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="../assets/vendor/jquery/jquery.min.js"></script>
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
