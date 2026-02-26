<?php
require_once 'functions.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if (authenticate($user, $pass)) {
        header('Location: dashboard.php');
        exit;
    } else {
        global $lastAuthError;
        // always display a simple failure message; log specifics separately
        $err = 'Login failed';
        if (!empty($lastAuthError)) {
            error_log("[lvm_nfs] login error detail user=$user reason=$lastAuthError");
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script defer src="assets/js/functions.js"></script>
    <style>
    /* ensure switch is visible in dark mode on login page */
    .dark-mode #darkModeToggle {
        border-color: #000;
    }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card mt-5">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="card-title mb-0">System Login</h3>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="darkModeToggle">
                                <label class="form-check-label" for="darkModeToggle"></label>
                            </div>
                        </div>
                        <?php if ($err): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label for="user" class="form-label">Username</label>
                                <input type="text" class="form-control" id="user" name="user" required>
                            </div>
                            <div class="mb-3">
                                <label for="pass" class="form-label">Password</label>
                                <input type="password" class="form-control" id="pass" name="pass" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Log in</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>