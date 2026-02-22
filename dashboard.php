<?php
require_once 'functions.php';
require_login();
$user = $_SESSION['user'];

// available view names map to files under views/
$validViews = ['lvm','nfs','mounts'];
$view = $_GET['view'] ?? '';
if (!in_array($view, $validViews, true)) {
    $view = '';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">Storage Admin</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link <?php if ($view==='lvm') echo 'active'; ?>" href="dashboard.php?view=lvm">LVM/RAID</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='nfs') echo 'active'; ?>" href="dashboard.php?view=nfs">NFS Exports</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='mounts') echo 'active'; ?>" href="dashboard.php?view=mounts">Mounts</a></li>
                </ul>
                <span class="navbar-text me-2">Logged in as <?php echo htmlspecialchars($user); ?></span>
                <a class="btn btn-outline-light" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <?php if ($view === ''): ?>
            <div class="jumbotron bg-white p-4 rounded">
                <h1 class="display-6">Welcome, <?php echo htmlspecialchars($user); ?>!</h1>
                <p class="lead">Select an operation from the menu above.</p>
            </div>
        <?php else: ?>
            <?php include __DIR__ . "/views/{$view}.php"; ?>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>