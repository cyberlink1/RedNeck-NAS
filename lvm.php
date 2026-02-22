<?php
require_once 'functions.php';
require_login();

// helper wrappers to call lvm/raid commands
function list_pvs() {
    return run_cmd('sudo pvs --noheadings -o pv_name,vg_name,lv_name,size');
}
function list_vgs() {
    return run_cmd('sudo vgs --noheadings -o vg_name,vg_size,vg_free');
}
function list_lvs() {
    return run_cmd('sudo lvs --noheadings -o lv_name,vg_name,lv_size');
}
// list raw disks not containing partitions
function list_disks() {
    // list only disk-type devices, running under sudo to ensure visibility
    $out = run_cmd("sudo lsblk -dn -o NAME,SIZE,TYPE");
    $result = [];
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 3) continue;
        list($name,$size,$type) = $parts;
        if ($type !== 'disk') continue;
        $dev = "/dev/" . $name;
        // skip disks with any partitions
        $children = run_cmd("sudo lsblk -n -o TYPE " . escapeshellarg($dev));
        $hasPart = false;
        foreach ($children as $c) {
            if (trim($c) === 'part') { $hasPart = true; break; }
        }
        if ($hasPart) continue;
        $result[] = $dev . "," . $size;
    }
    return $result;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['init_pvs'])) {
        $sel = $_POST['disks'] ?? [];
        $outs = [];
        foreach ($sel as $d) {
            $outs = array_merge($outs, run_cmd("sudo pvcreate " . escapeshellarg($d)));
        }
        $message = implode("<br>", $outs);
    } elseif (isset($_POST['create_vg'])) {
        $name = escapeshellarg($_POST['vg_name']);
        $sel = $_POST['pvs'] ?? [];
        $pvs = implode(' ', array_map('escapeshellarg', $sel));
        $out = run_cmd("sudo vgcreate $name $pvs");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['create_lv'])) {
        $vg = escapeshellarg($_POST['lv_vg']);
        $name = escapeshellarg($_POST['lv_name']);
        $size = escapeshellarg($_POST['lv_size']);
        $out = run_cmd("sudo lvcreate -n $name -L $size $vg");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['create_raid'])) {
        // simple mdadm raid create
        $level = intval($_POST['raid_level']);
        $name = escapeshellarg($_POST['raid_name']);
        $devices = implode(' ', array_map('escapeshellarg', ($_POST['devices'] ?? [])));
        $out = run_cmd("sudo mdadm --create /dev/$name --level=$level --raid-devices=".count($_POST['devices'])." $devices");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['remove_lv'])) {
        $lv = escapeshellarg($_POST['lv_select']);
        $out = run_cmd("sudo lvremove -fy $lv");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['remove_vg'])) {
        $vg = escapeshellarg($_POST['vg_select']);
        $out = run_cmd("sudo vgremove -fy $vg");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['format_lv'])) {
        $lv = escapeshellarg($_POST['lv_select2']);
        $fs = escapeshellarg($_POST['fs_type']);
        $out = run_cmd("sudo mkfs -t $fs $lv");
        $message = implode("<br>", $out);
    }
}

$pvs = list_pvs();
$vgs = list_vgs();
$lvs = list_lvs();
$disks = list_disks();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>LVM & RAID Management</title>
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
                <li class="nav-item"><a class="nav-link active" href="lvm.php">LVM/RAID</a></li>
                <li class="nav-item"><a class="nav-link" href="nfs.php">NFS Exports</a></li>
            </ul>
            <a class="btn btn-outline-light" href="logout.php">Logout</a>
        </div>
    </div>
</nav>
<div class="container mt-4">
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-header">Available Disks</div>
                <div class="card-body">
                    <?php if (count($disks) === 0): ?>
                        <em>No raw disks detected.</em>
                    <?php else: ?>
                        <form method="post" id="initForm">
                        <?php foreach ($disks as $line):
                            $parts = explode(',', trim($line));
                            $dev = $parts[0] ?? '';
                            $size = $parts[1] ?? 'unknown';
                            // skip if no device name
                            if ($dev === '') continue;
                        ?>
                            <div class="form-check">
                                <input class="form-check-input" name="disks[]" type="checkbox" value="<?php echo htmlspecialchars($dev); ?>" id="disk<?php echo htmlspecialchars(basename($dev)); ?>">
                                <label class="form-check-label" for="disk<?php echo htmlspecialchars(basename($dev)); ?>"><?php echo htmlspecialchars($dev.' ('.$size.')'); ?></label>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" name="init_pvs" class="btn btn-sm btn-secondary mt-2">Initialize as PV</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- existing PV/VG/LV cards -->
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-header">Physical Volumes</div>
                <div class="card-body">
                    <?php if (count($pvs) === 0): ?>
                        <em>No physical volumes found (unpartitioned disks will not appear).</em>
                    <?php else: ?>
                        <pre><?php echo htmlspecialchars(implode("\n", $pvs)); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-header">Volume Groups</div>
                <div class="card-body">
                    <?php if (count($vgs) === 0): ?>
                        <em>No volume groups defined.</em>
                    <?php else: ?>
                        <pre><?php echo htmlspecialchars(implode("\n", $vgs)); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
                <div class="card-header">Logical Volumes</div>
                <div class="card-body">
                    <?php if (count($lvs) === 0): ?>
                        <em>No logical volumes present.</em>
                    <?php else: ?>
                        <pre><?php echo htmlspecialchars(implode("\n", $lvs)); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Create Volume Group</div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">VG Name</label>
                            <input name="vg_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select PVs</label>
                            <?php foreach ($pvs as $line):
                                $parts = preg_split('/\s+/', trim($line));
                                $pv = $parts[0] ?? '';
                                if (!$pv) continue;
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" name="pvs[]" type="checkbox" value="<?php echo htmlspecialchars($pv); ?>" id="pv<?php echo htmlspecialchars(basename($pv)); ?>">
                                <label class="form-check-label" for="pv<?php echo htmlspecialchars(basename($pv)); ?>"><?php echo htmlspecialchars($pv); ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button name="create_vg" type="submit" class="btn btn-primary">Create VG</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Create Logical Volume</div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">VG</label>
                            <select name="lv_vg" class="form-select" required>
                                <option value="">-- choose --</option>
                                <?php foreach ($vgs as $line) {
                                    $parts = preg_split('/\s+/', trim($line));
                                    $vgname = $parts[0] ?? '';
                                    if (!$vgname) continue;
                                ?>
                                <option value="<?php echo htmlspecialchars($vgname); ?>"><?php echo htmlspecialchars($vgname); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">LV Name</label>
                            <input name="lv_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Size (e.g. 10G)</label>
                            <input name="lv_size" class="form-control" required>
                        </div>
                        <button name="create_lv" type="submit" class="btn btn-primary">Create LV</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-12">
            <div class="card mb-3">
                <div class="card-header">Create RAID Array</div>
                <div class="card-body">
                    <form id="raidForm" method="post">
                        <div class="mb-3">
                            <label class="form-label">Raid name (/dev/mdX)</label>
                            <input name="raid_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Level</label>
                            <input name="raid_level" type="number" min="0" max="6" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select Disks</label>
                            <?php foreach ($disks as $line) {
                                list($dev,$size)=explode(',',trim($line));
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" name="devices[]" type="checkbox" value="<?php echo htmlspecialchars($dev); ?>" id="raid<?php echo htmlspecialchars(basename($dev)); ?>">
                                <label class="form-check-label" for="raid<?php echo htmlspecialchars(basename($dev)); ?>"><?php echo htmlspecialchars($dev.' ('.$size.')'); ?></label>
                            </div>
                            <?php } ?>
                        </div>
                        <div id="deviceFields"></div>
                        <button name="create_raid" type="submit" class="btn btn-primary">Create RAID</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- removal/format section -->
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Remove Volume / Group</div>
                <div class="card-body">
                    <form method="post" class="mb-3">
                        <div class="mb-3">
                            <label class="form-label">Select Logical Volume</label>
                            <select name="lv_select" class="form-select">
                                <option value="">-- none --</option>
                                <?php foreach ($lvs as $line) {
                                    $parts = preg_split('/\s+/', trim($line));
                                    $lvname = $parts[0] ?? '';
                                    if (!$lvname) continue;
                                ?>
                                <option value="<?php echo htmlspecialchars($lvname); ?>"><?php echo htmlspecialchars($lvname); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button name="remove_lv" class="btn btn-danger" type="submit" onclick="return confirm('Remove selected LV? This will destroy its data.');">Remove LV</button>
                    </form>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Select Volume Group</label>
                            <select name="vg_select" class="form-select">
                                <option value="">-- none --</option>
                                <?php foreach ($vgs as $line) {
                                    $parts = preg_split('/\s+/', trim($line));
                                    $vgname = $parts[0] ?? '';
                                    if (!$vgname) continue;
                                ?>
                                <option value="<?php echo htmlspecialchars($vgname); ?>"><?php echo htmlspecialchars($vgname); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button name="remove_vg" class="btn btn-danger" type="submit" onclick="return confirm('Remove selected VG? All contained LVs will be lost.');">Remove VG</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Format Logical Volume</div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Select Logical Volume</label>
                            <select name="lv_select2" class="form-select" required>
                                <option value="">-- choose --</option>
                                <?php foreach ($lvs as $line) {
                                    $parts = preg_split('/\s+/', trim($line));
                                    $lvname = $parts[0] ?? '';
                                    if (!$lvname) continue;
                                ?>
                                <option value="<?php echo htmlspecialchars($lvname); ?>"><?php echo htmlspecialchars($lvname); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Filesystem type</label>
                            <input name="fs_type" class="form-control" value="ext4" required>
                        </div>
                        <button name="format_lv" class="btn btn-warning" type="submit" onclick="return confirm('Format selected LV? All data will be erased.');">Format LV</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/app.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>