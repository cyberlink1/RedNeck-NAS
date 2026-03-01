<?php
require_once 'functions.php';
require_login();
$user = $_SESSION['user'];

// available view names map to files under views/
// the samba view is only exposed if the system actually has Samba installed
$sambaAvailable = file_exists('/usr/sbin/smbd') || file_exists('/usr/bin/smbd');
$validViews = ['raid','lvm','mounts','nfs','disks'];
if ($sambaAvailable) {
    $validViews[] = 'samba';
}
// accept view name from either GET or POST so form submissions stay on the
// same page rather than falling back to the default dashboard content.
$view = $_REQUEST['view'] ?? '';
if (!in_array($view, $validViews, true)) {
    $view = '';
}
// early AJAX endpoints must return *only* the data, no surrounding HTML
if ($view === 'lvm' && isset($_GET['ajax']) && $_GET['ajax'] === 'list_snaps' && !empty($_GET['lv'])) {
    $target = $_GET['lv'];
    $basename = basename($target);
    $snaps = run_cmd("sudo lvs --noheadings --units b -o lv_path,lv_size,lv_time,origin --separator '|' 2>/dev/null");
    foreach ($snaps as $line) {
        $parts = explode('|', trim($line));
        if (count($parts) < 4) continue;
        list($path, $size, $time, $origin) = $parts;
        // ignore malformed/missing path entries
        if ($path === '') continue;
        // some LVM versions return only the LV name in origin, others the full
        // path.  match either one.
        if ($origin === $target || $origin === $basename) {
            echo implode('|', [$path, $size, $time]) . "\n";
        }
    }
    exit;
}
// prepare data structures; default to empty so count() never errors
$lvs = [];
$mnts = [];
// helper for nsenter use (matching mounts view logic)
$nsenterAvailable = file_exists('/usr/bin/nsenter');
function nsCmdDash($cmd) {
    global $nsenterAvailable;
    if ($nsenterAvailable) {
        return "sudo /usr/bin/nsenter -t 1 -m $cmd";
    }
    return $cmd;
}
// additional summary counts (only for root dashboard)
$totalDrives = 0;
$raidCount = 0;
$exportCount = 0;
$sambaCount = 0;
$exports = [];
if ($view === '') {
    // existing LV and mount listing
    $lvs = run_cmd('sudo lvs --noheadings -o lv_path,vg_name,lv_size');
    // lvs outputs a summary line ("Total") on some systems; only count
    // actual logical volumes which have a /dev path.
    $filteredLvs = [];
    foreach ($lvs as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\s+/', $line);
        if (isset($parts[0]) && strpos($parts[0], '/dev/') === 0) {
            $filteredLvs[] = $line;
        }
    }
    $lvs = $filteredLvs;
    // determine mounts under the configured mount base (singular or
    // plural).  use the helper so behaviour stays in sync with other code.
    $mnts = [];
    $root = mount_root();
    $regex = mount_root_regex();
    // prefer reading /proc/1/mounts to avoid extra shell calls
    if (file_exists('/proc/1/mounts')) {
        $lines = file('/proc/1/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            $fields = preg_split('/\s+/', trim($l));
            if (isset($fields[1]) && preg_match($regex, $fields[1])) {
                $mnts[] = $l;
            }
        }
    } else {
        // fallback via nsenter and mount output without grep; filter in PHP
        $all = run_cmd(nsCmdDash("mount"));
        $mnts = array_values(array_filter($all, fn($l) => preg_match($regex, $l)));
    }

    // count physical drives (lsblk shows TYPE column). we want to omit the
    // disk containing the root filesystem. determine its parent disk via
    // findmnt; fallback to no exclusion if the command fails.
    $osDev = '';
    $rootSrc = run_cmd("findmnt -n -o SOURCE /");
    if (!empty($rootSrc)) {
        // source might be /dev/sda1 or UUID=...; only handle /dev/*
        if (preg_match('#^/dev/([a-zA-Z0-9]+)#', trim($rootSrc[0]), $m)) {
            $osDev = $m[1];
            // strip trailing digits to get whole-disk name if necessary
            $osDev = preg_replace('/\d+$/', '', $osDev);
        }
    }
    $disklines = run_cmd('sudo lsblk -dn -o NAME,TYPE');
    foreach ($disklines as $line) {
        if (preg_match('/\sdisk$/', trim($line))) {
            $fields = preg_split('/\s+/', trim($line));
            $name = $fields[0];
            if ($osDev !== '' && $name === $osDev) {
                continue;
            }
            $totalDrives++;
        }
    }

    // count RAID arrays by listing /dev/md*; ignore any partition nodes
    $mds = run_cmd('ls -1 /dev/md* 2>/dev/null');
    foreach ($mds as $line) {
        $dev = trim($line);
        // only count top‑level /dev/mdN entries (no trailing "p" or digits)
        if (preg_match('#^/dev/md\d+$#', $dev)) {
            $raidCount++;
        }
    }

    // count exports ignoring comments/blank lines
    $expFile = cfg('exports_file', '/etc/exports');
    if (file_exists($expFile)) {
        $lines = file($expFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            $t = trim($l);
            if ($t === '' || strpos($t, '#') === 0) continue;
            $exports[] = $t;
        }
    }
    $exportCount = count($exports);

    // count samba shares if samba is available and config exists
    if ($sambaAvailable) {
        $conf = '/etc/samba/smb.conf';
        if (file_exists($conf)) {
            $lines = file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) {
                    $sec = $m[1];
                    // ignore the global section
                    if (strcasecmp($sec, 'global') !== 0) {
                        $sambaCount++;
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>RNN (RedNeck NAS) Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php print_js_config(); ?>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">RNN (RedNeck NAS)</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link <?php if ($view==='disks') echo 'active'; ?>" href="dashboard.php?view=disks">Disks</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='raid') echo 'active'; ?>" href="dashboard.php?view=raid">RAID</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='lvm') echo 'active'; ?>" href="dashboard.php?view=lvm">LVM</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='mounts') echo 'active'; ?>" href="dashboard.php?view=mounts">Mounts</a></li>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='nfs') echo 'active'; ?>" href="dashboard.php?view=nfs">NFS Exports</a></li>
<?php if ($sambaAvailable): ?>
                    <li class="nav-item"><a class="nav-link <?php if ($view==='samba') echo 'active'; ?>" href="dashboard.php?view=samba">Samba Shares</a></li>
<?php endif; ?>
                </ul>
                <span class="navbar-text me-2">Logged in as <?php echo htmlspecialchars($user); ?></span>
                <div class="form-check form-switch ms-2 mb-0">
                    <input class="form-check-input" type="checkbox" id="darkModeToggle">
                    <label class="form-check-label" for="darkModeToggle"></label>
                </div>
                <a class="btn btn-outline-light ms-2" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <?php if ($view === ''): ?>
            <div class="row text-center">
                <div class="col-sm-6 col-md-4 col-lg-2 mb-3">
                    <div class="card stat-card">
                        <div class="card-header">Physical Drives</div>
                        <div class="card-body">
                            <div class="stat-number"><?php echo $totalDrives; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4 col-lg-2 mb-3">
                    <div class="card stat-card">
                        <div class="card-header">RAID Arrays</div>
                        <div class="card-body">
                            <div class="stat-number"><?php echo $raidCount; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4 col-lg-2 mb-3">
                    <div class="card stat-card">
                        <div class="card-header">Logical Volumes</div>
                        <div class="card-body">
                            <div class="stat-number"><?php echo count($lvs); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4 col-lg-2 mb-3">
                    <div class="card stat-card">
                        <div class="card-header">Mounts</div>
                        <div class="card-body">
                            <div class="stat-number"><?php echo count($mnts); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4 col-lg-2 mb-3">
                    <div class="card stat-card">
                        <div class="card-header">Exports</div>
                        <div class="card-body">
                            <div class="stat-number"><?php echo $exportCount; ?></div>
                        </div>
                    </div>
                </div>
<?php if ($sambaAvailable): ?>
                <div class="col-sm-6 col-md-4 col-lg-2 mb-3">
                    <div class="card stat-card">
                        <div class="card-header">Samba Shares</div>
                        <div class="card-body">
                            <div class="stat-number"><?php echo $sambaCount; ?></div>
                        </div>
                    </div>
                </div>
<?php endif; ?>
            </div>
        <?php else: ?>
            <?php include __DIR__ . "/views/{$view}.php"; ?>
        <?php endif; ?>
    </div>
    <div id="spinnerOverlay">
        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
    </div>
    <div class="carrier-msg">
        +++<br>
        NO CARRIER
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/functions.js"></script>
<?php if (!empty($view)): ?>
    <?php if ($view === 'disks'): ?>
        <script src="assets/js/disks.js"></script>
    <?php elseif ($view === 'raid'): ?>
        <script src="assets/js/raid.js"></script>
    <?php elseif ($view === 'lvm'): ?>
        <script src="assets/js/lvm.js"></script>
    <?php elseif ($view === 'mounts'): ?>
        <script src="assets/js/mounts.js"></script>
    <?php elseif ($view === 'nfs'): ?>
        <script src="assets/js/nfs.js"></script>
    <?php elseif ($view === 'samba'): ?>
        <script src="assets/js/samba.js"></script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
