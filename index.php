<?php
// redirect to login.  the `redirect()` helper uses url() which in turn
// consults the configured base_url, so this works even when the UI is
// mounted on a subpath or served through a reverse proxy with a rewritten
// prefix.
require_once 'functions.php';
redirect('login.php');
?>
