<?php
// Disk management view (included by dashboard.php). Authentication and
// functions.php have already been loaded by the caller.

// helpers
function list_disks_full() {
    // name,size,model,serial,type
    $out = run_cmd('sudo lsblk -dn -o NAME,SIZE,MODEL,SERIAL,TYPE');
    $filtered = [];
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line), 5);
        if (count($parts) < 5) continue;
        list($name,$size,$model,$serial,$type) = $parts;
        // skip cdrom/rom, loopback, and partition entries
        if (in_array($type, ['rom','loop','part'], true)) continue;
        // otherwise include everything (disks, md devices, crypt, etc.)
        $filtered[] = $line;
    }
    return $filtered;
}

function part_print($dev) {
    // use parted for a human-readable table; -s to suppress menus
    return run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' print');
}

$message = '';
$selected = $_POST['disk'] ?? '';

// detect whether selected device is part of md or lvm so we can
// disable partitioning/wiping actions later
$inMd = false;
$inLvm = false;
$isMdDevice = false;
if ($selected) {
    if (strpos($selected, '/dev/md') === 0) {
        $isMdDevice = true;
        $inMd = true;
    }
    // check /proc/mdstat for this disk appearing as a member
    $mdlines = run_cmd('cat /proc/mdstat');
    foreach ($mdlines as $line) {
        if (strpos($line, $selected) !== false) {
            $inMd = true;
            break;
        }
    }
    // check if this disk is already an LVM PV
    $pvLines = run_cmd('sudo pvs --noheadings -o pv_name');
    foreach ($pvLines as $pl) {
        if (trim($pl) === $selected) {
            $inLvm = true;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_part'])) {
        $dev = $_POST['disk'] ?? '';
        $start = trim($_POST['start'] ?? '');
        $end = trim($_POST['end'] ?? '');
        $type = trim($_POST['ptype'] ?? 'primary');
        if ($dev === '' || $start === '' || $end === '') {
            $message = 'Please select a disk and specify start/end for new partition.';
        } else {
            $out = run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' mkpart ' . escapeshellarg($type) . ' ' . escapeshellarg($start) . ' ' . escapeshellarg($end));
            $message = implode("<br>", $out);
        }
        $selected = $dev;
    } elseif (isset($_POST['delete_part'])) {
        $dev = $_POST['disk'] ?? '';
        $num = intval($_POST['part_num'] ?? 0);
        if ($dev === '' || $num <= 0) {
            $message = 'Select a disk and valid partition number to delete.';
        } else {
            $out = run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' rm ' . escapeshellarg($num));
            $message = implode("<br>", $out);
        }
        $selected = $dev;
    } elseif (isset($_POST['wipe_disk'])) {
        $dev = $_POST['disk'] ?? '';
        if ($dev === '') {
            $message = 'No disk selected.';
        } else {
            $out = run_cmd('sudo sgdisk --zap-all ' . escapeshellarg($dev));
            $out = array_merge($out, run_cmd('sudo wipefs -a ' . escapeshellarg($dev)));
            $message = implode("<br>", $out);
        }
        $selected = $dev;
    } elseif (isset($_POST['smart_status'])) {
        $dev = $_POST['disk'] ?? '';
        if ($dev === '') {
            $message = 'No disk selected.';
        } else {
            $out = run_cmd('sudo smartctl -H ' . escapeshellarg($dev));
            // append temperature line if present in attributes
            $attr = run_cmd('sudo smartctl -A ' . escapeshellarg($dev));
            foreach ($attr as $line) {
                if (preg_match('/Temperature/i', $line)) {
                    $out[] = $line;
                }
            }
            $message = implode("<br>", $out);
        }
        $selected = $dev;
    } elseif (isset($_POST['identify'])) {
        $dev = $_POST['disk'] ?? '';
        if ($dev === '') {
            $message = 'No disk selected.';
        } else {
            $name = basename($dev);
            $path = "/sys/block/" . $name . "/device/locate";
            if (file_exists($path)) {
                // attempt to trigger locate LED for a short time
                run_cmd("echo 1 | sudo tee " . escapeshellarg($path));
                $message = "Identify signal sent to $dev (LED should blink if supported).";
            } else {
                $message = "Identify not supported on $dev (no sysfs locate file).";
            }
        }
        $selected = $dev;
    }
}


$disks = list_disks_full();

// determine disks that are part of existing md arrays
$mdmembers = [];
$mdlines = run_cmd('cat /proc/mdstat');
foreach ($mdlines as $line) {
    // split on whitespace and look for tokens that look like /dev/...
    $parts = preg_split('/\s+/', trim($line));
    foreach ($parts as $p) {
        if (strpos($p, '/dev/') === 0 && $p !== '/dev/md' && !preg_match('#^/dev/md\d#', $p)) {
            // add only actual member devices (not the md device itself)
            $mdmembers[] = $p;
        }
    }
}

// collect LVM PVs
$pvLines = run_cmd('sudo pvs --noheadings -o pv_name');
$pvNames = array_map('trim', $pvLines);

// helper to check if disk should have partition/wipe actions
function disk_in_use($dev, $mdmembers, $pvNames) {
    // treat md devices themselves as in-use
    if (strpos($dev, '/dev/md') === 0) {
        return true;
    }
    // md members (not including md device itself) or pv match
    if (in_array($dev, $mdmembers, true)) {
        return true;
    }
    if (in_array($dev, $pvNames, true)) {
        return true;
    }
    return false;
}
?>

<?php if ($message): ?>
    <div id="initialMessage" style="display:none"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">Disks</div>
            <div class="card-body">
                <?php if (count($disks) === 0): ?>
                    <em>No disks found.</em>
                <?php else: ?>
                    <form method="post" id="diskSelectForm">
                        <div class="mb-3">
                            <label class="form-label">Select disk</label>
                            <select name="disk" class="form-select" onchange="this.form.submit()">
                                <option value="">-- choose --</option>
                                <?php foreach ($disks as $line):
                                    $parts = preg_split('/\s+/', trim($line), 5);
                                    $name   = $parts[0] ?? '';
                                    $size   = $parts[1] ?? '';
                                    $model  = $parts[2] ?? '';
                                    $serial = $parts[3] ?? '';
                                    // parts[4] contains TYPE which we don't show
                                    $dev = '/dev/' . $name;
                                ?>
                                    <option value="<?php echo htmlspecialchars($dev); ?>" <?php if ($dev === $selected) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars("$dev ($size) $model $serial"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <?php if ($selected): ?>
            <?php $disabled = disk_in_use($selected, $mdmembers, $pvNames); ?>
            <div class="card mb-3">
                <div class="card-header">Partition Table for <?php echo htmlspecialchars($selected); ?></div>
                <div class="card-body"><pre><?php echo htmlspecialchars(implode("\n", part_print($selected))); ?></pre></div>
            </div>
            <?php if ($disabled): ?>
            <div class="alert alert-warning">This device is in use by RAID or LVM; partitioning and wiping actions are disabled.</div>
            <?php else: ?>
            <div class="card mb-3">
                <div class="card-header">Create Partition</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="disk" value="<?php echo htmlspecialchars($selected); ?>">
                        <div class="mb-3">
                            <label class="form-label">Partition type</label>
                            <select name="ptype" class="form-select">
                                <option value="primary">primary</option>
                                <option value="logical">logical</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start (e.g. 0%, 1MiB)</label>
                            <input name="start" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End (e.g. 100%, 10GiB)</label>
                            <input name="end" class="form-control" required>
                        </div>
                        <button name="create_part" type="submit" class="btn btn-primary">Create</button>
                    </form>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-header">Delete Partition</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="disk" value="<?php echo htmlspecialchars($selected); ?>">
                        <div class="mb-3">
                            <label class="form-label">Partition number</label>
                            <input name="part_num" type="number" min="1" class="form-control" required>
                        </div>
                        <button id="btnDeletePart" name="delete_part" type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <div class="card mb-3">
                <div class="card-header">Other Actions</div>
                <div class="card-body">
                    <?php if (!$disabled): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="disk" value="<?php echo htmlspecialchars($selected); ?>">
                        <button id="btnWipe" name="wipe_disk" class="btn btn-warning">Wipe disk (GPT+superblocks)</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline" class="ms-2">
                        <input type="hidden" name="disk" value="<?php echo htmlspecialchars($selected); ?>">
                        <button name="smart_status" class="btn btn-secondary">SMART status</button>
                    </form>
                    <form method="post" style="display:inline" class="ms-2">
                        <input type="hidden" name="disk" value="<?php echo htmlspecialchars($selected); ?>">
                        <button name="identify" class="btn btn-info">Identify (LED)</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
