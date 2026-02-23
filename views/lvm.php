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
// obtain simple name/model map for devices shown in tables
function get_device_models() {
    $map = [];
    // we only need name and model, omit empty models
    $out = run_cmd("sudo lsblk -dn -o NAME,MODEL");
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) === 2) {
            $map['/dev/' . $parts[0]] = trim($parts[1]);
        }
    }
    return $map;
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
$showVgAfter = false;
$showLvAfter = false;
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
        $showVgAfter = true;
    } elseif (isset($_POST['create_lv'])) {
        $vg = escapeshellarg($_POST['lv_vg']);
        $name = escapeshellarg($_POST['lv_name']);
        $size = escapeshellarg($_POST['lv_size']);
        // use -y to auto‑answer prompts and -Z y to zero the start of the new LV
        // this prevents creation from aborting if old signatures exist
        $out = run_cmd("sudo lvcreate -n $name -L $size -y -Z y $vg");
        $message = implode("<br>", $out);
        $showLvAfter = true;
    } elseif (isset($_POST['remove_lv'])) {
        $lv = escapeshellarg(trim($_POST['lv_select'] ?? ''));
        $out = run_cmd("sudo lvremove -fy $lv");
        $message = implode("<br>", $out);
        $showLvAfter = true;
    } elseif (isset($_POST['format_lv'])) {
        $sel = $_POST['lvs'] ?? [];
        if (!is_array($sel)) { $sel = [$sel]; }
        $fs = escapeshellarg($_POST['fs_type']);
        $outs = [];
        if (count($sel) === 0) {
            $message = 'No logical volumes selected to format.';
        } else {
            // build a human-readable list of the commands we intend to run so the
            // user can see them in the feedback modal; this also aids debugging
            // when the formatting appears to do nothing.
            $cmds = [];
            foreach ($sel as $lvpath) {
                if (!$lvpath) continue;
                // check if the volume is mounted; formatting a mounted device is
                // dangerous and often prevented by mkfs, so warn instead.  the
                // previous grep-based test could trigger false positives because
                // mount output may mention the device even when not actually
                // mounted (e.g. "devtmpfs on /dev" etc).  use lsblk which reports
                // the mountpoint for the specific device.
                $mpLines = run_cmd('lsblk -n -o MOUNTPOINT ' . escapeshellarg($lvpath));
                $mp = trim($mpLines[0] ?? '');
                if ($mp !== '') {
                    $outs[] = "Skipping $lvpath: mounted at $mp (unmount before formatting).";
                    continue;
                }
                $lv = escapeshellarg($lvpath);
                $cmds[] = "sudo mkfs -F -t $fs $lv";
                // include -F to force mkfs to proceed without any interactive
                // confirmation (mirrors RAID formatting logic).
                $out = run_cmd("sudo mkfs -F -t $fs $lv");
                // collect output per lv
                $outs[] = "Formatting $lvpath:";
                $outs = array_merge($outs, $out);
            }
            if (count($cmds)) {
                $message = 'Commands executed:<br>' . implode('<br>', array_map('htmlspecialchars', $cmds)) . '<br><br>';
            } else {
                $message = '';
            }
            // simple success message if no output
            if (count($outs) === 0) {
                $message .= 'Logical volumes formatted (no output).';
            } else {
                $message .= implode("<br>", $outs);
            }
        }
        $showLvAfter = true;
    } elseif (isset($_POST['remove_vg'])) {
        $vgNames = $_POST['vg_select'] ?? [];
        if (!is_array($vgNames)) {
            $vgNames = [$vgNames];
        }
        $outs = [];
        foreach ($vgNames as $vgName) {
            $vg = escapeshellarg($vgName);
            // collect pv names belonging to this vg so we can report or reuse them later
            $pvLines = run_cmd("sudo pvs --noheadings -o pv_name --select vg_name=" . $vg);
            $pvNames = array_map('trim', $pvLines);
            $outs = array_merge($outs, run_cmd("sudo vgremove -fy $vg"));
            // note: we no longer wipe PV metadata here.  keeping the PV initialized
            // allows it to be reassigned to a different group if desired.
        }
        $message = implode("<br>", $outs);
        $showVgAfter = true;
    } elseif (isset($_POST['extend_vg'])) {
        $vgName = $_POST['vg_name'] ?? '';
        $vg = escapeshellarg($vgName);
        $sel = $_POST['pvs'] ?? [];
        $pvs = implode(' ', array_map('escapeshellarg', $sel));
        $out = run_cmd("sudo vgextend $vg $pvs");
        if (count($out) === 0) {
            $message = 'Volume group extended (no output).';
        } else {
            $message = implode("<br>", $out);
        }
        $showVgAfter = true;
    } elseif (isset($_POST['extend_vg_multi'])) {
        $vgNames = $_POST['vg_name'] ?? [];
        if (!is_array($vgNames)) {
            $vgNames = [$vgNames];
        }
        $outs = [];
        // expect pvs array keyed by vg name
        $pvsPost = $_POST['pvs'] ?? [];
        foreach ($vgNames as $vgName) {
            $vg = escapeshellarg($vgName);
            $sel = [];
            if (isset($pvsPost[$vgName]) && is_array($pvsPost[$vgName])) {
                $sel = $pvsPost[$vgName];
            }
            if (count($sel) === 0) continue;
            $pvs = implode(' ', array_map('escapeshellarg', $sel));
            $outs = array_merge($outs, run_cmd("sudo vgextend $vg $pvs"));
        }
        if (count($outs) === 0) {
            $message = 'Volume groups extended (no output).';
        } else {
            $message = implode("<br>", $outs);
        }
        $showVgAfter = true;
    }
    // end POST handler for $_SERVER
}

// prepare data for rendering
$pvs = list_pvs();
$vgs = list_vgs();
$lvs = list_lvs();
$disks = list_disks();
// compute list of unused physical volumes once for use in various modals
$unassigned = [];
foreach ($pvs as $line) {
    $parts = explode('|', trim($line));
    $pv = trim($parts[0] ?? '');
    $vg = trim($parts[1] ?? '');
    if ($vg === '-') { $vg = ''; }
    if ($pv !== '' && $vg === '') {
        $unassigned[] = $pv;
    }
}

// build a unified table of devices and PVs.  raw disks are marked
// "available" so we can render checkboxes for initialization.
$models = get_device_models();
$rows = [];
foreach ($disks as $line) {
    $parts = explode(',', trim($line));
    $dev = $parts[0] ?? '';
    $size = $parts[1] ?? '';
    if ($dev === '') continue;
    // mark RAID devices specially
    $model = '';
    if (strpos($dev, '/dev/md') === 0) {
        $model = 'Raid Device';
    } else {
        $model = $models[$dev] ?? '';
    }
    $rows[] = [
        'device'    => $dev,
        'model'     => $model,
        'vg'        => '',
        'size'      => $size,
        'available' => true,
    ];
}
foreach ($pvs as $line) {
    $parts = explode('|', trim($line));
    $dev = trim($parts[0] ?? '');
    $vg  = trim($parts[1] ?? '');
    $size= trim($parts[2] ?? '');
    if ($dev === '') continue;
    if ($vg === '-' ) { $vg = ''; }
    // also mark RAID devices when they appear as PVs
    $model = '';
    if (strpos($dev, '/dev/md') === 0) {
        $model = 'Raid Device';
    } else {
        $model = $models[$dev] ?? '';
    }
    $rows[] = [
        'device'    => $dev,
        'model'     => $model,
        'vg'        => $vg,
        'size'      => $size,
        'available' => false,
    ];
}
$anyAvailable = false;
foreach ($rows as $r) {
    if ($r['available']) { $anyAvailable = true; break; }
}
// gather available mkfs filesystem types
$fsTypes = [];
foreach (glob('/sbin/mkfs.*') as $path) {
    $base = basename($path);
    if (preg_match('/^mkfs\.(.+)$/', $base, $m)) {
        $fsTypes[] = $m[1];
    }
}
sort($fsTypes);
?>

<?php if ($message): ?>
    <div id="initialMessage" class="d-none"<?php if ($showVgAfter) echo ' data-reopen-vg="1"'; ?><?php if ($showLvAfter) echo ' data-reopen-lv="1"'; ?>><?php echo $message; ?></div>
<?php endif; ?>
<!-- global spinner overlay -->
<div id="spinnerOverlay">
    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
</div>

<!-- button to launch VG management modal -->
<button id="btnShowVgModal" class="btn btn-primary mb-3 me-2">Volume groups</button>
<button id="btnShowLvModal" class="btn btn-primary mb-3">Logical volumes</button>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">Disks &amp; Physical Volumes</div>
            <div class="card-body">
                <?php if (count($rows) === 0): ?>
                    <em>No disks or physical volumes detected.</em>
                <?php else: ?>
                    <form method="post" id="initForm">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Device</th>
                                <th>Name/Model</th>
                                <th>Volume group</th>
                                <th>Size</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <?php if ($r['available']): ?>
                                        <input type="checkbox" name="disks[]" value="<?php echo htmlspecialchars($r['device']); ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['device']); ?></td>
                                <td><?php echo htmlspecialchars($r['model']); ?></td>
                                <td><?php echo htmlspecialchars($r['vg']); ?></td>
                                <td><?php echo htmlspecialchars($r['size']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($anyAvailable): ?>
                        <button type="submit" name="init_pvs" class="btn btn-sm btn-secondary mt-2">Initialize as PV</button>
                    <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

<!-- LV management button and modals will handle logical volumes -->
</div>

<!-- LV management modal -->
<div class="modal fade" id="lvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Logical Volumes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if (count($lvs) === 0): ?>
            <em>No logical volumes present.</em>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th></th><th>Device</th><th>Volume Group</th><th>Filesystem</th><th>Size</th></tr>
                </thead>
                <tbody>
                <?php foreach ($lvs as $line):
                    $parts = preg_split('/\s+/', trim($line));
                    $lvpath = $parts[0] ?? '';
                    $vgname = $parts[1] ?? '';
                    $lvsize = $parts[2] ?? '';
                    if (!$lvpath) continue;
                    $display = basename($lvpath);
                    // determine filesystem type if available
                    $fstype = '';
                    $blk = run_cmd('sudo blkid -s TYPE -o value ' . escapeshellarg($lvpath));
                    if (count($blk)) {
                        $fstype = trim($blk[0]);
                        // blkid returns a status line like "(exit 2)" when it can't
                        // identify the filesystem; treat that as no filesystem.
                        if ($fstype === '' || preg_match('/^\(exit\s+\d+\)/', $fstype)) {
                            $fstype = '';
                        }
                    }
                    if ($fstype === '') {
                        $fstype = 'None';
                    }
                ?>
                    <tr>
                        <td><input type="checkbox" class="lv-checkbox" value="<?php echo htmlspecialchars($lvpath); ?>"></td>
                        <td><?php echo htmlspecialchars($display); ?></td>
                        <td><?php echo htmlspecialchars($vgname); ?></td>
                        <td><?php echo htmlspecialchars($fstype); ?></td>
                        <td><?php echo htmlspecialchars($lvsize); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
      </div>
      <div class="modal-footer d-flex justify-content-end flex-nowrap">
        <button id="btnOpenCreateLv" class="btn btn-primary btn-sm me-1">Create</button>
        <button id="btnOpenFormatLv" class="btn btn-warning btn-sm me-1">Format</button>
        <button id="btnOpenRemoveLv" class="btn btn-danger btn-sm me-1">Remove</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- create LV modal -->
<div class="modal fade" id="createLvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Logical Volume</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="createLvForm">
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
</div>

<!-- format LV modal -->
<div class="modal fade" id="formatLvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Format Logical Volume</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="formatLvForm">
            <div id="formatList" class="mb-3">
                <!-- list of selected volumes inserted by JS -->
            </div>
            <div class="mb-3">
                <label class="form-label">Filesystem type</label>
                <select name="fs_type" class="form-select" required>
                    <?php foreach ($fsTypes as $t) {
                        $sel = ($t === 'ext4') ? ' selected' : '';
                        echo '<option'.$sel.'>'.htmlspecialchars($t).'</option>';
                    } ?>
                </select>
            </div>
            <button name="format_lv" class="btn btn-warning">Format LV(s)</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- remove LV modal -->
<div class="modal fade" id="removeLvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Remove Logical Volume</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="removeLvForm">
            <div class="mb-3">
                <label class="form-label">Select Logical Volume</label>
                <select name="lv_select" class="form-select" required>
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
            <button name="remove_lv" class="btn btn-danger">Remove LV</button>
        </form>
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

<!-- Volume Group management modal -->
<div class="modal fade" id="vgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Manage Volume Groups</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if (count($vgs) === 0): ?>
            <em>No volume groups defined.</em>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th><input type="checkbox" id="selectAllVgs"></th><th>Name</th><th>Size</th><th>Free</th></tr>
                </thead>
                <tbody>
                <?php foreach ($vgs as $line):
                    $parts = preg_split('/\s+/', trim($line));
                    $name = $parts[0] ?? '';
                    $size = $parts[1] ?? '';
                    $free = $parts[2] ?? '';
                    if (!$name) continue;
                ?>
                    <tr>
                        <td><input type="checkbox" class="vg-checkbox" value="<?php echo htmlspecialchars($name); ?>"></td>
                        <td><?php echo htmlspecialchars($name); ?></td>
                        <td><?php echo htmlspecialchars($size); ?></td>
                        <td><?php echo htmlspecialchars($free); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="mt-3 text-end">
            <button id="btnOpenCreateVg" class="btn btn-sm btn-primary">Create Volume Group</button>
            <button id="btnExtendSelectedVgs" type="button" class="btn btn-sm btn-secondary ms-2">Extend</button>
            <button id="btnOpenRemoveVg" class="btn btn-sm btn-danger ms-2">Remove Group</button>
            <button type="button" class="btn btn-sm btn-secondary ms-2" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- modal containing create VG form -->
<div class="modal fade" id="createVgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Volume Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="createVgForm">
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
            <div class="text-end">
                <button name="create_vg" type="submit" class="btn btn-primary">Create VG</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Close</button>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- modal containing remove Volume Group form -->
<div class="modal fade" id="removeVgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Remove Volume Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
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
            <div class="text-end">
                    <button id="btnRemoveVg" name="remove_vg" class="btn btn-danger ms-2" type="submit">Remove VG</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Close</button>
            </div>
        </form>
      </div>
    </div>
    </div>
  </div>
</div>

<!-- modal containing extend VG form -->
<div class="modal fade" id="extendVgModal" tabindex="-1" aria-hidden="true" data-unassigned-pvs='<?php echo htmlspecialchars(json_encode($unassigned), ENT_QUOTES); ?>'>
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Extend Volume Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="extendVgForm">
            <input type="hidden" name="vg_name" value="">
            <div class="mb-3">
                <label class="form-label">Select Physical Volumes to add</label>
                <?php
                $unassigned = [];
                foreach ($pvs as $line) {
                    $parts = explode('|', trim($line));
                    $pv = trim($parts[0] ?? '');
                    $vg = trim($parts[1] ?? '');
                    if ($vg === '-') { $vg = ''; }
                    if ($pv !== '' && $vg === '') {
                        $unassigned[] = $pv;
                    }
                }
                if (count($unassigned) === 0): ?>
                    <div><em>No unused physical volumes available.</em></div>
                <?php else:
                    foreach ($unassigned as $pv): ?>
                        <div class="form-check">
                            <input class="form-check-input" name="pvs[]" type="checkbox" value="<?php echo htmlspecialchars($pv); ?>" id="extpv<?php echo htmlspecialchars(basename($pv)); ?>">
                            <label class="form-check-label" for="extpv<?php echo htmlspecialchars(basename($pv)); ?>"><?php echo htmlspecialchars($pv); ?></label>
                        </div>
                    <?php endforeach;
                endif;
                ?>
            </div>
            <button name="extend_vg" type="submit" class="btn btn-primary">Add to VG</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- modal for extending multiple selected volume groups -->
<div class="modal fade" id="extendSelectedVgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Extend Selected Volume Groups</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="extendSelectedForm">
            <!-- content populated by JS -->
        </form>
      </div>
      <div class="modal-footer">
        <button name="extend_vg_multi" type="submit" class="btn btn-primary">Add to VGs</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

