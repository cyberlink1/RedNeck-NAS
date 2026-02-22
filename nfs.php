<?php
require_once 'functions.php';
require_login();

$message = '';
$exportsPath = '/etc/exports';

function read_exports(): array {
    global $exportsPath;
    if (!file_exists($exportsPath)) {
        return [];
    }
    return file($exportsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_export'])) {
        $line = trim($_POST['export_line']);
        if ($line !== '') {
            file_put_contents($exportsPath, $line . "\n", FILE_APPEND | LOCK_EX);
            run_cmd('exportfs -ra');
            $message = 'Export added.';
        }
    } elseif (isset($_POST['remove_export'])) {
        $toRemove = trim($_POST['remove_export']);
        $lines = read_exports();
        $new = array_filter($lines, function($l) use ($toRemove) {
            return trim($l) !== $toRemove;
        });
        file_put_contents($exportsPath, implode("\n", $new) . "\n");
        run_cmd('exportfs -ra');
        $message = 'Export removed.';
    }
}

$exports = read_exports();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>NFS Exports</title>
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
                <li class="nav-item"><a class="nav-link" href="lvm.php">LVM/RAID</a></li>
                <li class="nav-item"><a class="nav-link active" href="nfs.php">NFS Exports</a></li>
            </ul>
            <a class="btn btn-outline-light" href="logout.php">Logout</a>
        </div>
    </div>
</nav>
<div class="container mt-4">
    <?php if ($message): ?>
        <script>
            window.__initialMessage = <?php echo json_encode($message); ?>;
        </script>
    <?php endif; ?>
    <div class="card mb-3">
        <div class="card-header">Current exports</div>
        <div class="card-body">
            <ul class="list-group">
                <?php foreach ($exports as $line): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?php echo htmlspecialchars($line); ?>
                        <form method="post" class="m-0">
                            <button name="remove_export" value="<?php echo htmlspecialchars($line); ?>" class="btn btn-sm btn-danger">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Add new export</div>
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <input name="export_line" class="form-control" placeholder="/path client(options)" required>
                </div>
                <button name="add_export" type="submit" class="btn btn-primary">Add</button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- confirmation modal used by JS -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Notice</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body"></div>
    <div class="modal-footer">
       <button type="button" class="btn btn-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button>
       <button type="button" class="btn btn-primary btn-ok">OK</button>
    </div>
   </div>
  </div>
</div>

</body>
</html>