<?php
// LVM/RAID view (included by dashboard.php). The dashboard already
// performs authentication and loads functions.php, so we do not
// re‑require or re‑login here.

// helper wrappers to call lvm/raid commands
function list_pvs() {
    // show only physical volume name, its VG and size with explicit separator
    $out = run_cmd("sudo pvs --noheadings -o pv_name,vg_name,pv_size --separator '|'");
    // remove duplicate lines if any
    return array_values(array_unique($out));
}
function list_vgs() {
    return run_cmd('sudo vgs --noheadings -o vg_name,vg_size,vg_free');
}
function list_lvs() {
    // include full logical volume path to make formatting/removal reliable
    return run_cmd('sudo lvs --noheadings -o lv_path,vg_name,lv_size');
}
// list raw disks not containing partitions
function list_disks() {
    // list only disk-type devices, running under sudo to ensure visibility
    $out = run_cmd("sudo lsblk -dn -o NAME,SIZE,TYPE");
    // also fetch existing PV names so we can filter them out
    $pvs = run_cmd('sudo pvs --noheadings -o pv_name');
    // determine member devices of existing MD arrays
    $mdmembers = [];
    $mdlines = run_cmd('cat /proc/mdstat');
    foreach ($mdlines as $line) {
        // look for devices like sdb1
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
        // skip if already used as a PV (pvcreate may have just run)
        if (in_array($dev, array_map('trim', $pvs), true)) {
            continue;
        }
        // obtain size via lsblk
        $sizeLine = run_cmd("lsblk -dn -o SIZE " . escapeshellarg($dev));
        $size = trim($sizeLine[0] ?? '');
        $result[] = $dev . "," . $size;
    }
    return array_values(array_unique($result));
}

// POST handlers
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
        // use -y to auto‑answer prompts and -Z y to zero the start of the new LV
        // this prevents creation from aborting if old signatures exist
        $out = run_cmd("sudo lvcreate -n $name -L $size -y -Z y $vg");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['create_raid'])) {
        // simple mdadm raid create
        $level = intval($_POST['raid_level']);
        $nameRaw = trim($_POST['raid_name']);
        // strip leading /dev/ if provided
        $nameClean = preg_replace('#^/dev/#','',$nameRaw);
        $name = escapeshellarg($nameClean);
        $devList = $_POST['devices'] ?? [];
        // normalize each device path, strip duplicated /dev/
        $devs = [];
        foreach ($devList as $d) {
            $d = trim($d);
            $devs[] = preg_replace('#^/dev/#','/dev/',$d);
        }
        $norm = array_map('escapeshellarg', $devs);
        $devices = implode(' ', $norm);
        $out = [];
        // wipe any lingering superblock metadata before creation so mdadm won't prompt
        foreach ($devs as $d) {
            $out = array_merge($out, run_cmd("sudo mdadm --zero-superblock " . escapeshellarg($d)));
        }
        $out = array_merge($out, run_cmd("sudo mdadm --create /dev/$name --level=$level --raid-devices=" . count($devList) . " $devices"));
        // suppress known benign warnings that don't affect the result
        $out = array_filter($out, function($line) {
            return !preg_match('#(Unrecognised md component device|appears to be part of a raid array|Defaulting to version)#', $line);
        });
        $message = implode("<br>", $out);
    } elseif (isset($_POST['remove_lv'])) {
        $lv = escapeshellarg(trim($_POST['lv_select'] ?? ''));
        $out = run_cmd("sudo lvremove -fy $lv");
        $message = implode("<br>", $out);
    } elseif (isset($_POST['remove_vg'])) {
        $vgName = $_POST['vg_select'] ?? '';
        $vg = escapeshellarg($vgName);
        // collect pv names belonging to this vg so we can clear them afterwards
        $pvLines = run_cmd("sudo pvs --noheadings -o pv_name --select vg_name=" . $vg);
        $pvNames = array_map('trim', $pvLines);
        $out = run_cmd("sudo vgremove -fy $vg");
        // wipe PV metadata so they appear unused
        foreach ($pvNames as $pvdev) {
            if ($pvdev !== '') {
                $out = array_merge($out, run_cmd("sudo pvremove -ff " . escapeshellarg($pvdev)));
            }
        }
        $message = implode("<br>", $out);
    } elseif (isset($_POST['format_lv'])) {
        $lv = escapeshellarg($_POST['lv_select2']);
        $fs = escapeshellarg($_POST['fs_type']);
        $out = run_cmd("sudo mkfs -t $fs $lv");
        // determine if mkfs failed by seeing a nonzero exit marker
        $failed = false;
        foreach ($out as $line) {
            if (preg_match('/\(exit\s+[1-9]/', $line)) {
                $failed = true;
                break;
            }
        }
        if ($failed) {
            $message = 'Failed to format logical volume.';
        } else {
            // try to extract UUID from mkfs output itself
            $uuid = '';
            foreach ($out as $line) {
                if (preg_match('/Filesystem UUID:\s*(\S+)/i', $line, $m)) {
                    $uuid = $m[1];
                    break;
                }
                if (preg_match('/UUID="?([0-9A-Za-z\-]+)"?/', $line, $m)) {
                    $uuid = $m[1];
                    break;
                }
            }
            // as a last resort we can still fall back to blkid if available
            if (!$uuid) {
                $uuidLines = run_cmd("sudo /usr/sbin/blkid -s UUID -o value " . escapeshellarg($lv));
                foreach ($uuidLines as $line) {
                    $line = trim($line);
                    if ($line === ''
                        || stripos($line, 'password is required') !== false
                        || stripos($line, 'sudo:') === 0
                        || preg_match('/^\(exit\s+\d+\)/', $line)
                    ) {
                        continue;
                    }
                    $uuid = $line;
                    break;
                }
                if (!$uuid) {
                    $alt = run_cmd("sudo /usr/sbin/blkid " . escapeshellarg($lv));
                    foreach ($alt as $line) {
                        if (preg_match('/UUID="([^"]+)"/', $line, $m)) {
                            $uuid = $m[1];
                            break;
                        }
                    }
                }
            }
            $message = 'Logical volume formatted successfully.';
            if ($uuid) {
                $message .= '<br>Filesystem UUID: ' . htmlspecialchars($uuid);
            } else {
                $message .= ' (UUID lookup failed; ensure /usr/sbin/blkid is available to sudo)';
            }
        }
    } elseif (isset($_POST['remove_raid'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        if ($raid === '') {
            $message = 'No RAID device selected.';
        } else {
            $msgs = [];
            // strip prefix
            $raidName = preg_replace('#^/dev/#','',$raid);
            $raidPath = "/dev/" . $raidName;
            // check if the device is used as a physical volume or in a VG
            $pvcheck = run_cmd("sudo pvs --noheadings -o pv_name --select pv_name=" . escapeshellarg($raidPath));
            // keep only valid device names; pvs may emit errors otherwise
            $pvcheck = array_filter(array_map('trim', $pvcheck), function($v){ return strpos($v, '/dev/') === 0; });
            if (count($pvcheck) > 0) {
                // still in use by LVM – ask user to clean up first
                $msgs[] = 'RAID device ' . htmlspecialchars($raidPath) . ' is still part of an LVM PV. ' .
                         'Remove any logical volumes and volume groups using it, then try again.';
                $message = implode("<br>", $msgs);
            } else {
                // gather member devices to clear metadata later
                $members = [];
                $detail = run_cmd("sudo mdadm --detail " . escapeshellarg($raidPath));
                foreach ($detail as $line) {
                    if (preg_match('#\s+(/dev/\S+)#', $line, $m)) {
                        $members[] = $m[1];
                    }
                }
                // stop/remove the array
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

// prepare data for rendering
$pvs = list_pvs();
$vgs = list_vgs();
$lvs = list_lvs();
$disks = list_disks();
?>

<?php if ($message): ?>
    <div id="initialMessage" style="display:none"><?php echo $message; ?></div>
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
                        <?php
                    $shown=0;
                    foreach ($pvs as $line):
                            $parts = explode('|', trim($line));
                            $pv = trim($parts[0] ?? '');
                            $vg = trim($parts[1] ?? '');
                            if ($vg === '-') { $vg = ''; }
                            if ($vg !== '') {
                                continue; // skip PV already in VG
                            }
                            if (!$pv) continue;
                            $shown++;
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
                    <?php
            // determine five unused /dev/mdX names
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
            // raid level labels for dropdown
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
                            // don't list existing md devices when building a new array
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
                            // list /dev/md* entries
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
                                    $lvpath = $parts[0] ?? '';
                                    if (!$lvpath) continue;
                                    $display = basename($lvpath);
                                ?>
                                <option value="<?php echo htmlspecialchars($lvpath); ?>"><?php echo htmlspecialchars($display); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button id="btnRemoveLv" name="remove_lv" class="btn btn-danger" type="submit">Remove LV</button>
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
                        <button id="btnRemoveVg" name="remove_vg" class="btn btn-danger" type="submit">Remove VG</button>
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
                                    $lvpath = $parts[0] ?? '';
                                    if (!$lvpath) continue;
                                    $display = basename($lvpath);
                                ?>
                                <option value="<?php echo htmlspecialchars($lvpath); ?>"><?php echo htmlspecialchars($display); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Filesystem type</label>
                            <input name="fs_type" class="form-control" value="ext4" required>
                        </div>
                        <button id="btnFormatLv" name="format_lv" class="btn btn-warning" type="submit">Format LV</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

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

