    <script src="assets/vendor/jquery/jquery.min.js"></script>
    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script>
    // iOS & Android Mobile Drawer Handler
    (function($) {
        $(function() {
            var $backdrop = $('<div class="sidebar-mobile-backdrop"></div>');
            $('body').append($backdrop);

            function toggleDrawer(open) {
                if ($(window).width() < 768) {
                    var shouldOpen = typeof open === 'boolean' ? open : !$('body').hasClass('mobile-sidebar-open');
                    if (shouldOpen) {
                        $('body').addClass('mobile-sidebar-open');
                        $('#accordionSidebar').addClass('mobile-show');
                    } else {
                        $('body').removeClass('mobile-sidebar-open');
                        $('#accordionSidebar').removeClass('mobile-show');
                    }
                }
            }

            // Click hamburger
            $(document).on('click', '#sidebarToggleTop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleDrawer();
            });

            // Click backdrop to close
            $(document).on('click', '.sidebar-mobile-backdrop', function() {
                toggleDrawer(false);
            });

            // Auto-close on link tap on mobile
            $(document).on('click', '#accordionSidebar .nav-link:not([data-toggle="collapse"])', function() {
                if ($(window).width() < 768) {
                    toggleDrawer(false);
                }
            });

            // Auto-close on resize back to desktop
            $(window).on('resize orientationchange', function() {
                if ($(window).width() >= 768) {
                    toggleDrawer(false);
                }
            });
        });
    })(jQuery);
    </script>
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
