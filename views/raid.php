<?php
// RAID management view (included by dashboard.php).  Authentication and
// functions.php have already been loaded by the caller.

// helper wrapper to list raw disks; we reuse the same implementation used by
// the old combined view so the RAID page offers the same device filtering
// (skips disks already in use as LVM PVs or members of another md array,
// excludes devices with partitions, and includes /dev/md* devices unless they
// have already been pvcreated).
function list_disks() {
    // list only disk-type devices, running under sudo to ensure visibility
    $out = run_cmd("sudo lsblk -dn -o NAME,SIZE,TYPE");
    // also fetch existing PV names so we can filter them out
    $pvs = run_cmd('sudo pvs --noheadings -o pv_name');
    // determine member devices of existing MD arrays
    $mdmembers = [];
    $mdlines = run_cmd('cat /proc/mdstat');
    foreach ($mdlines as $line) {
        if (preg_match_all('/\b(sd[a-z0-9]+)\b/', $line, $m)) {
            foreach ($m[1] as $d) {
                $mdmembers[] = '/dev/'.$d;
            }
        }
    }
    $result = [];
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 3) continue;
        list($name,$size,$type) = $parts;
        if ($type !== 'disk') continue;
        $dev = "/dev/" . $name;
        // skip disks already used as a PV
        if (in_array($dev, array_map('trim', $pvs), true)) {
            continue;
        }
        // skip disks that are part of md array
        if (in_array($dev, $mdmembers, true)) {
            continue;
        }
        // skip disks with any partitions
        $children = run_cmd("sudo lsblk -n -o TYPE " . escapeshellarg($dev));
        $hasPart = false;
        foreach ($children as $c) {
            if (trim($c) === 'part') { $hasPart = true; break; }
        }
        if ($hasPart) continue;
        $result[] = $dev . "," . $size;
    }
    // include md devices themselves so they can be pvcreated (unless already a PV)
    $mds = run_cmd("/bin/ls /dev/md* 2>/dev/null");
    foreach ($mds as $line) {
        $dev = trim($line);
        if ($dev === '' || !preg_match('#^/dev/md#', $dev)) continue;
        if (in_array($dev, array_map('trim', $pvs), true)) {
            continue;
        }
        $sizeLine = run_cmd("lsblk -dn -o SIZE " . escapeshellarg($dev));
        $size = trim($sizeLine[0] ?? '');
        $result[] = $dev . "," . $size;
    }
    return array_values(array_unique($result));
}

// POST handlers for RAID-specific operations only
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_raid'])) {
        $level = intval($_POST['raid_level']);
        $nameRaw = trim($_POST['raid_name']);
        $nameClean = preg_replace('#^/dev/#','',$nameRaw);
        $name = escapeshellarg($nameClean);
        $devList = $_POST['devices'] ?? [];
        $devs = [];
        foreach ($devList as $d) {
            $d = trim($d);
            $devs[] = preg_replace('#^/dev/#','/dev/',$d);
        }
        $norm = array_map('escapeshellarg', $devs);
        $devices = implode(' ', $norm);
        $out = [];
        foreach ($devs as $d) {
            $out = array_merge($out, run_cmd("sudo mdadm --zero-superblock " . escapeshellarg($d)));
        }
        $out = array_merge($out, run_cmd("sudo mdadm --create /dev/$name --level=$level --raid-devices=" . count($devList) . " $devices"));
        $out = array_filter($out, function($line) {
            return !preg_match('#(Unrecognised md component device|appears to be part of a raid array|Defaulting to version)#', $line);
        });
        $message = implode("<br>", $out);
    } elseif (isset($_POST['remove_raid'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        if ($raid === '') {
            $message = 'No RAID device selected.';
        } else {
            $msgs = [];
            $raidName = preg_replace('#^/dev/#','',$raid);
            $raidPath = "/dev/" . $raidName;
            $pvcheck = run_cmd("sudo pvs --noheadings -o pv_name --select pv_name=" . escapeshellarg($raidPath));
            $pvcheck = array_filter(array_map('trim', $pvcheck), function($v){ return strpos($v, '/dev/') === 0; });
            if (count($pvcheck) > 0) {
                $msgs[] = 'RAID device ' . htmlspecialchars($raidPath) . ' is still part of an LVM PV. ' .
                         'Remove any logical volumes and volume groups using it, then try again.';
                $message = implode("<br>", $msgs);
            } else {
                $members = [];
                $detail = run_cmd("sudo mdadm --detail " . escapeshellarg($raidPath));
                foreach ($detail as $line) {
                    if (preg_match('#\s+(/dev/\S+)#', $line, $m)) {
                        $members[] = $m[1];
                    }
                }
                $out = run_cmd("sudo mdadm --stop " . escapeshellarg($raidPath));
                if (file_exists($raidPath)) {
                    $out = array_merge($out, run_cmd("sudo mdadm --remove " . escapeshellarg($raidPath)));
                }
                foreach ($members as $m) {
                    $out = array_merge($out, run_cmd("sudo mdadm --zero-superblock " . escapeshellarg($m)));
                }
                $out = array_filter($out, function($line){
                    return strpos($line, 'No such file or directory') === false;
                });
                $msgs[] = implode("<br>", $out);
                $msgs[] = 'RAID array ' . htmlspecialchars($raidPath) . ' removed successfully.';
                $message = implode("<br>", $msgs);
            }
        }
    }
}

$disks = list_disks();
?>

<?php if ($message): ?>
    <div id="initialMessage" style="display:none"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="card mb-3">
            <div class="card-header">Create RAID Array</div>
            <div class="card-body">
                <form id="raidForm" method="post">
                    <?php
            $used = [];
            $mds = run_cmd('ls /dev/md* 2>/dev/null');
            foreach ($mds as $m) {
                $m = trim($m);
                if ($m === '') continue;
                if (preg_match('/md(\d+)$/', $m, $mm)) {
                    $used[intval($mm[1])] = true;
                }
            }
            $freeNames = [];
            for ($i = 0; count($freeNames) < 5 && $i < 100; $i++) {
                if (!isset($used[$i])) {
                    $freeNames[] = "/dev/md$i";
                }
            }
            $raidLevels = [
                0 => 'Striped',
                1 => 'Mirrored',
                5 => 'RAID5',
                6 => 'RAID6',
            ];
            ?>
            <div class="mb-3">
                <label class="form-label">Raid name</label>
                <select name="raid_name" class="form-select" required>
                    <option value="">-- choose --</option>
                    <?php foreach ($freeNames as $n): ?>
                        <option value="<?php echo htmlspecialchars($n); ?>"><?php echo htmlspecialchars($n); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Level</label>
                <select name="raid_level" class="form-select" required>
                    <option value="">-- choose --</option>
                    <?php foreach ($raidLevels as $num => $label): ?>
                        <option value="<?php echo $num; ?>"><?php echo htmlspecialchars("$label ($num)"); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
                    <div class="mb-3">
                        <label class="form-label">Select Disks</label>
                        <?php foreach ($disks as $line) {
                            list($dev,$size)=explode(',',trim($line));
                            if (strpos($dev, '/dev/md') === 0) continue;
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

<!-- existing RAID arrays -->
<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">Existing RAID Arrays</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Select RAID device</label>
                        <select name="raid_select" class="form-select">
                            <option value="">-- none --</option>
                            <?php
                            $mds = run_cmd('ls /dev/md* 2>/dev/null');
                            foreach ($mds as $m) {
                                $dev = trim($m);
                                if ($dev === '') continue;
                                echo '<option value="' . htmlspecialchars($dev) . '">' . htmlspecialchars($dev) . '</option>';
                            }
                            ?>
                            </select>
                        </div>
                        <button name="remove_raid" class="btn btn-danger" type="submit" onclick="return false;" id="btnRemoveRaid">Remove RAID</button>
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
