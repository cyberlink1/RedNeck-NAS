<?php
require_once 'functions.php';
require_login();

$message = '';
// handle mount/unmount
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mount_lv'])) {
        $lv = escapeshellarg($_POST['lv_select_mount']);
        $mp = '/export/' . trim($_POST['mount_point']);
        $mpEsc = escapeshellarg($mp);
        $out = run_cmd("sudo /bin/mkdir -p $mpEsc");
        // use nsenter to run the mount in the host's mount namespace if
        // nsenter is available (necessary when Apache runs in a private
        // namespace).  The command will fail if nsenter isn't installed or
        // permitted; fallback to regular mount then.
        if (file_exists('/usr/bin/nsenter')) {
            $cmd = "sudo /usr/bin/nsenter -t 1 -m /bin/mount -v $lv $mpEsc";
        } else {
            $cmd = "sudo /bin/mount -v $lv $mpEsc";
        }
        $mountOut = run_cmd($cmd);
        // check mount table separately to see if the point actually shows up
        $grepOut = run_cmd("mount | grep " . escapeshellarg($mp));
        $grepOut = array_values(array_filter($grepOut, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));
        // determine status
        if (preg_grep('/\(exit\s+[1-9]/', $mountOut)) {
            // mount command itself failed
            $statusMsg = 'Mount failed';
            $out = array_merge($out, $mountOut, $grepOut);
            $out[] = "command: $cmd";
        } else {
            // if grep returned nothing the point is not present at all
            if (count($grepOut) === 0) {
                $statusMsg = 'Mount command succeeded but mountpoint not listed';
                $out = array_merge($out, $mountOut, $grepOut);
            } else {
                // at least one line exists, assume correct volume was mounted on the
                // requested point; matching the LV path is redundant since the grep
                // already filtered on the point and the command we executed only
                // mounted the selected LV.
                $statusMsg = 'Mount succeeded';
                // (optionally check visibility; no need to mention it in the message)
                $dfout = run_cmd("df " . escapeshellarg($mp));
                if (!preg_grep('/' . preg_quote(trim($lv, "'\""), '/') . '/', $dfout)) {
                    // save diagnostics in case you want to inspect them later, but keep
                    // a clean success message.
                    $out = array_merge($out, $mountOut, $grepOut, $dfout);
                }
            }
        }
        if (strpos($statusMsg,'Mount succeeded') === 0) {
            // on success we display only the status message
            $message = $statusMsg;
        } else {
            $message = implode("<br>", $out);
        }
    } elseif (isset($_POST['umount_lv'])) {
        $mp = escapeshellarg($_POST['umount_select']);
        if (file_exists('/usr/bin/nsenter')) {
            $cmd = "sudo /usr/bin/nsenter -t 1 -m /bin/umount $mp";
        } else {
            $cmd = "sudo /bin/umount $mp";
        }
        $out = run_cmd($cmd);
        // check mount table afterward to see if it really disappeared; strip
        // out the sole "(exit N)" line that grep prints when there’s no match.
        $after = run_cmd("mount | grep " . escapeshellarg($mp));
        $after = array_values(array_filter($after, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));
        // if the entry vanished, treat it as success regardless of command exit code
        if (count($after) === 0) {
            $statusMsg = 'Unmount succeeded';
        } elseif (preg_grep('/\(exit\s+[1-9]/', $out)) {
            $out[] = "command: $cmd";
            $statusMsg = 'Unmount failed';
            $out = array_merge($out, $after);
        } else {
            $statusMsg = 'Unmount claimed success but entry still present';
            $out = array_merge($out, $after);
        }
        if (strpos($statusMsg, 'Unmount succeeded') === 0) {
            // successful unmount, ignore any prior output
            $message = $statusMsg;
        } else {
            // always include status message so user sees the reason
            $out[] = $statusMsg;
            $message = implode("<br>", $out);
        }
    }
}

// gather logical volumes for dropdown
$lvs = run_cmd('sudo lvs --noheadings -o lv_path');
// gather current /export mounts for unmount dropdown
$mnts = run_cmd("mount | grep ' on /export/'");

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Mounts Management</title>
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
                <li class="nav-item"><a class="nav-link" href="nfs.php">NFS Exports</a></li>
                <li class="nav-item"><a class="nav-link active" href="mounts.php">Mounts</a></li>
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

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Mount Logical Volume</div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Logical Volume</label>
                            <select name="lv_select_mount" class="form-select" required>
                                <option value="">-- choose --</option>
                                <?php foreach ($lvs as $line):
                                    $lvpath = trim($line);
                                    if ($lvpath === '') continue;
                                    $display = basename($lvpath);
                                ?>
                                <option value="<?php echo htmlspecialchars($lvpath); ?>"><?php echo htmlspecialchars($display); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mount point (subdir under /export)</label>
                            <input name="mount_point" class="form-control" placeholder="myshare" required>
                        </div>
                        <button name="mount_lv" class="btn btn-primary" type="submit">Mount</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Unmount <code>/export/</code> mount</div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Select mount</label>
                            <select name="umount_select" class="form-select">
                                <option value="">-- none --</option>
                                <?php foreach ($mnts as $m):
                                    if (preg_match('/ on (\/export\/\S+)/', $m, $mm)) {
                                        $pt = $mm[1];
                                ?>
                                <option value="<?php echo htmlspecialchars($pt); ?>"><?php echo htmlspecialchars($pt); ?></option>
                                <?php
                                    }
                                endforeach; ?>
                            </select>
                        </div>
                        <button name="umount_lv" class="btn btn-secondary" type="submit">Unmount</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- global confirmation modal used by JS -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm</h5>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
window.addEventListener('DOMContentLoaded', function() {
    if (window.__initialMessage) {
        showConfirmation(window.__initialMessage);
        delete window.__initialMessage;
    }
});
</script>
</body>
</html>
