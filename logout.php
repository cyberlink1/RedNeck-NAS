<?php
require_once 'functions.php';

// destroy session and redirect to login. the redirect helper uses the
// configured base_url so this works when the UI is served from a
// subdirectory or rewritten path.
session_unset();
session_destroy();
redirect('login.php');
?>