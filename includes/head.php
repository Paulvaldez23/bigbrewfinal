<?php require_once __DIR__ . '/brand.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Big Brew — Coffee & Tea HR, Attendance & Shift Management System">
    <meta name="theme-color" content="#c17633">
    <meta name="author" content="">
    <title><?php echo $page_title ?? 'Big Brew HR System'; ?></title>
    <link rel="icon" type="image/jpeg" sizes="32x32" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link rel="icon" type="image/jpeg" sizes="64x64" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars(BIGBREW_LOGO_JPG); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars(BIGBREW_FAVICON); ?>">
    <link href="assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <?php bigbrew_brand_css(); ?>
    <?php if (!empty($extra_css)): foreach ((array)$extra_css as $css): ?>
    <link href="<?php echo htmlspecialchars($css); ?>" rel="stylesheet">
    <?php endforeach; endif; ?>
</head>
<body id="page-top">
