<?php
// Prevent 404 if accessed via /includes/index.php
// Redirect directly to the main root index.php
header('Location: ../index.php');
exit;
