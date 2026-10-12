            </div>
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>&copy; <?php echo date('Y'); ?> Big Brew HR Suite &middot; Coffee &middot; Tea &middot; Milktea</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                    <a class="btn btn-primary" href="includes/logout.php">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" role="dialog" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" id="changePasswordModalLabel">
                        <i class="fas fa-key mr-2"></i>Change Password
                    </h5>
                    <button class="close text-white" type="button" data-dismiss="modal" aria-label="Close" style="opacity:.85;">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <form id="changePasswordForm" autocomplete="off">
                    <div class="modal-body p-4">
                        <div id="changePasswordAlert" style="display:none;" class="alert py-2 mb-3 small"></div>

                        <div class="form-group mb-3">
                            <label class="small font-weight-bold text-gray-700 mb-1">Current Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="cp_current" class="form-control" required placeholder="Enter current password">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary toggle-pw-btn" type="button" data-target="#cp_current" tabindex="-1">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label class="small font-weight-bold text-gray-700 mb-1">New Password <span class="text-danger">*</span> <small class="text-muted">(min. 6 characters)</small></label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="cp_new" class="form-control" required minlength="6" placeholder="Enter new password">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary toggle-pw-btn" type="button" data-target="#cp_new" tabindex="-1">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-1">
                            <label class="small font-weight-bold text-gray-700 mb-1">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="cp_confirm" class="form-control" required minlength="6" placeholder="Re-enter new password">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary toggle-pw-btn" type="button" data-target="#cp_confirm" tabindex="-1">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-2">
                        <button class="btn btn-secondary btn-sm" type="button" data-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary btn-sm px-3 font-weight-bold" type="submit" id="btnSubmitChangePw">
                            <i class="fas fa-save mr-1"></i>Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    window.addEventListener('load', function() {
        if (typeof jQuery === 'undefined') return;
        jQuery(function($) {
            $(document).on('click', '.toggle-pw-btn', function(e) {
                e.preventDefault();
                var targetId = $(this).data('target');
                var $input = $(targetId);
                var $icon = $(this).find('i');
                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    $input.attr('type', 'password');
                    $icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });

            $('#changePasswordForm').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $('#btnSubmitChangePw');
                var $alert = $('#changePasswordAlert');
                var cur = $('#cp_current').val();
                var nw = $('#cp_new').val();
                var conf = $('#cp_confirm').val();

                if (nw.length < 6) {
                    $alert.removeClass('alert-success').addClass('alert-danger')
                          .html('<i class="fas fa-exclamation-circle mr-1"></i>New password must be at least 6 characters.').slideDown();
                    return;
                }
                if (nw !== conf) {
                    $alert.removeClass('alert-success').addClass('alert-danger')
                          .html('<i class="fas fa-exclamation-circle mr-1"></i>New password and confirmation do not match.').slideDown();
                    return;
                }

                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Saving...');
                $alert.slideUp();

                $.ajax({
                    url: 'change_password.php',
                    method: 'POST',
                    data: $form.serialize(),
                    dataType: 'json',
                    success: function(res) {
                        $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>Update Password');
                        if (res && res.success) {
                            $alert.removeClass('alert-danger').addClass('alert-success')
                                  .html('<i class="fas fa-check-circle mr-1"></i>' + res.message).slideDown();
                            $form[0].reset();
                            setTimeout(function() {
                                $('#changePasswordModal').modal('hide');
                                $alert.hide();
                            }, 1800);
                        } else {
                            var errMsg = (res && res.message) ? res.message : 'Failed to update password.';
                            $alert.removeClass('alert-success').addClass('alert-danger')
                                  .html('<i class="fas fa-exclamation-circle mr-1"></i>' + errMsg).slideDown();
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>Update Password');
                        $alert.removeClass('alert-success').addClass('alert-danger')
                              .html('<i class="fas fa-exclamation-triangle mr-1"></i>Unable to connect to server. Please try again.').slideDown();
                    }
                });
            });

            $('#changePasswordModal').on('hidden.bs.modal', function() {
                $('#changePasswordForm')[0].reset();
                $('#changePasswordAlert').hide();
                $('.toggle-pw-btn i').removeClass('fa-eye-slash').addClass('fa-eye');
                $('#changePasswordForm input').attr('type', 'password');
            });
        });
    });
    </script>
