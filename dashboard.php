<?php
require_once 'functions.php';
require_login();
$user = $_SESSION['user'];

// available view names map to files under views/
$validViews = ['raid','lvm','mounts','nfs','disks'];
$view = $_GET['view'] ?? '';
if (!in_array($view, $validViews, true)) {
    $view = '';
}
// prepare data structures; default to empty so count() never errors
$lvs = [];
$mnts = [];
// collect summary info when rendering the root dashboard
if ($view === '') {
    $lvs = run_cmd('sudo lvs --noheadings -o lv_path,vg_name,lv_size');
    $mnts = run_cmd("mount | grep ' on /export/'");
    // strip the lone "(exit N)" record that grep emits when nothing matched
    $mnts = array_values(array_filter($mnts, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>RNN (Red Neck NAS) Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">RNN (Red Neck NAS)</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link <?php if ($view==='raid') echo 'active'; ?>" href="dashboard.php?view=raid">RAID</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='lvm') echo 'active'; ?>" href="dashboard.php?view=lvm">LVM</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='mounts') echo 'active'; ?>" href="dashboard.php?view=mounts">Mounts</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='nfs') echo 'active'; ?>" href="dashboard.php?view=nfs">NFS Exports</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='disks') echo 'active'; ?>" href="dashboard.php?view=disks">Disks</a></li>
                </ul>
                <span class="navbar-text me-2">Logged in as <?php echo htmlspecialchars($user); ?></span>
                <a class="btn btn-outline-light" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <?php if ($view === ''): ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header">Logical Volumes</div>
                        <div class="card-body">
                            <?php if (count($lvs) === 0): ?>
                                <em>No logical volumes found.</em>
                            <?php else: ?>
                                <pre><?php echo htmlspecialchars(implode("\n", $lvs)); ?></pre>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto">
                    <div class="card mb-3">
                        <div class="card-header">Mounts</div>
                        <div class="card-body">
                            <?php if (count($mnts) === 0): ?>
                                <em>No /export mounts found.</em>
                            <?php else: ?>
                                <pre><?php echo htmlspecialchars(implode("\n", $mnts)); ?></pre>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php include __DIR__ . "/views/{$view}.php"; ?>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>