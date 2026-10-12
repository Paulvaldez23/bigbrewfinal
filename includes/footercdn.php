    <script src="assets/vendor/jquery/jquery.min.js"></script>
    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <?php if (!empty($extra_script)): ?>
    <script>
    <?php echo $extra_script; ?>

    </script>
    <?php endif; ?>
    <?php if (!empty($extra_js)): foreach ((array)$extra_js as $js): ?>
    <script src="<?php echo htmlspecialchars($js); ?>"></script>
    <?php endforeach; endif; ?>
</body>
</html>
