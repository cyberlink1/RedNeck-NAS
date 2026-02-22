<?php
// Disk management view (included by dashboard.php) or invoked directly for
// AJAX lookups. When called directly we must load functions and enforce login
// ourselves.

if (!defined('IN_DASHBOARD')) {
    require_once __DIR__ . '/../functions.php';
    require_login();
}

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
        // some systems present CD/DVD drives as regular disks named "sr0",
        // etc.; ignore those too so they never appear in the list.
        if (preg_match('/^sr\d+$/', $name)) continue;
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
// allow selection via POST (normal page) or GET (AJAX)
$selected = $_REQUEST['disk'] ?? '';

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
            $cmd = 'sudo parted -s ' . escapeshellarg($dev) .
                   ' mkpart ' . escapeshellarg($type) .
                   ' ' . escapeshellarg($start) .
                   ' ' . escapeshellarg($end);
            $out = run_cmd($cmd);
            // drop initial unrecognised-label error if we will create a label
            $labelError = false;
            foreach ($out as $i => $line) {
                if (stripos($line, 'unrecognised disk label') !== false) {
                    $labelError = true;
                    unset($out[$i]);
                }
            }
            if ($labelError) {
                $out[] = '(initialising GPT label)';
                $out = array_merge($out,
                       run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' mklabel gpt'));
                $out = array_merge($out, run_cmd($cmd));
            }
            $message = implode("<br>", $out);
        }
        $selected = $dev;
    } elseif (isset($_POST['create_part_size'])) {
        $dev = $_POST['disk'] ?? '';
        $size = trim($_POST['size'] ?? '');
        if ($dev === '' || $size === '') {
            $message = 'Select a disk and specify a size for the new partition.';
        } else {
            // enforce the four‑partition limit
            $current = partition_count($dev);
            if ($current >= 4) {
                $message = 'Disk already has maximum (4) partitions.';
            } else {
                // treat the supplied size as the end point; compute a sensible
            // start.  On an empty disk this is 1MiB; for subsequent partitions
            // use the end of the last partition + 1MiB.  unit mib makes parted
            // output predictable for parsing.
            $start = '1MiB';
            if ($current > 0) {
                $lastEnd = 1; // in MiB
                $print = run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' unit mib print');
                foreach ($print as $line) {
                    if (preg_match('/^\s*\d+\s+([0-9]+\.?[0-9]*)MiB\s+([0-9]+\.?[0-9]*)MiB/', $line, $m)) {
                        $endVal = floatval($m[2]);
                        if ($endVal > $lastEnd) {
                            $lastEnd = $endVal;
                        }
                    }
                }
                // start one MiB after last end
                $start = ($lastEnd + 1) . 'MiB';
            }
            // validate that requested size is larger than start
            $startMiB = 0;
            if (preg_match('/([0-9]+\.?[0-9]*)MiB/', $start, $sm)) {
                $startMiB = floatval($sm[1]);
            }
            // compute approximate available space using disk size from lsblk
            $diskBytes = intval(trim(run_cmd('sudo lsblk -nb -o SIZE ' . escapeshellarg($dev))[0] ?? '0'));
            $diskMiB = $diskBytes / (1024 * 1024);
            $availMiB = max(0, $diskMiB - $startMiB);

            $sizeMiB = convert_to_mib($size);
            if ($sizeMiB !== null && $sizeMiB <= 0) {
                $message = 'Specified size must be greater than zero.';
            } elseif ($sizeMiB !== null && $sizeMiB > $availMiB) {
                $message = 'Requested size ('.$size.') exceeds available space (approx '.round($availMiB,1).' MiB).';
            } else {
                // if we understood the size, compute an explicit end value to
                // avoid rounding/interpretation discrepancies with parted
                if ($sizeMiB !== null) {
                    $endMiB = $startMiB + $sizeMiB;
                    // round to three decimals for safety
                    $end = round($endMiB, 3) . 'MiB';
                } else {
                    $end = $size;
                }
                $cmd = 'sudo parted -s ' . escapeshellarg($dev) .
                       ' mkpart primary ' . escapeshellarg($start) . ' ' . escapeshellarg($end);
                $out = run_cmd($cmd);
                // same label initialization logic as above
                $labelError = false;
                foreach ($out as $i => $line) {
                    if (stripos($line, 'unrecognised disk label') !== false) {
                        $labelError = true;
                        unset($out[$i]);
                    }
                }
                if ($labelError) {
                    $out[] = '(initialising GPT label)';
                    $out = array_merge($out,
                           run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' mklabel gpt'));
                    $out = array_merge($out, run_cmd($cmd));
                }
                $message = implode("<br>", $out);
            }
            }
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

// determine disks that are part of existing md arrays and map members -> array names
$mdmembers = [];
$mdmap = []; // device => [md0,md1,...]
$mdlines = run_cmd('cat /proc/mdstat');
foreach ($mdlines as $line) {
    // look for array header (mdN :)
    if (preg_match('/^(md\d+)\s*:/', $line, $m)) {
        $array = $m[1];
        // find all member devices like sdb1[0] or nvme0n1p1[1]
        if (preg_match_all('/\b([a-zA-Z0-9]+?)\[\d+\]/', $line, $mm)) {
            foreach ($mm[1] as $d) {
                $dev = '/dev/'.$d;
                $mdmembers[] = $dev;
                if (!isset($mdmap[$dev])) {
                    $mdmap[$dev] = [];
                }
                $mdmap[$dev][] = $array;
            }
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

// helper to detect if a disk currently has any partitions
function has_partitions($dev) {
    $lines = run_cmd('sudo lsblk -n -o TYPE ' . escapeshellarg($dev));
    foreach ($lines as $line) {
        if (trim($line) === 'part') {
            return true;
        }
    }
    return false;
}

// helper that returns the number of partitions on a disk
function partition_count($dev) {
    $count = 0;
    $lines = run_cmd('sudo lsblk -n -o TYPE ' . escapeshellarg($dev));
    foreach ($lines as $line) {
        if (trim($line) === 'part') {
            $count++;
        }
    }
    return $count;
}

// convert human‑readable size string to MiB (approx); returns null on failure
// Recognises suffixes like "1G", "500MB", "2TiB".  We first compute the
// value in bytes using either decimal (1000) or binary (1024) multipliers,
// then divide by 2^20 to obtain MiB.  This matches `parted`’s parsing rules –
// bare "MB" is decimal while "MiB" or "MB" without an explicit suffix may
// be treated differently, but the approximation is close enough for our
// validation purposes.
function convert_to_mib($sz) {
    if (!is_string($sz) || $sz === '') return null;
    if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*([kmgtKMGT]?)(i?B)?\s*$/', $sz, $m)) {
        $val = floatval($m[1]);
        $unit = strtolower($m[2]);
        $suffix = isset($m[3]) ? strtolower($m[3]) : '';
        $binary = true;
        if ($suffix === 'b' && $unit !== '') {
            // plain MB/GB/etc. -> decimal
            $binary = false;
        }
        // compute bytes
        switch ($unit) {
            case 't':
                $val *= ($binary ? 1024 ** 4 : 1000 ** 4);
                break;
            case 'g':
                $val *= ($binary ? 1024 ** 3 : 1000 ** 3);
                break;
            case 'm':
                $val *= ($binary ? 1024 ** 2 : 1000 ** 2);
                break;
            case 'k':
                $val *= ($binary ? 1024 : 1000);
                break;
            default:
                // bytes input, nothing to do
                break;
        }
        // convert bytes to MiB
        return $val / (1024 * 1024);
    }
    return null;
}

// helper to detect if a device appears to be a CD/DVD drive
function is_cdrom($dev) {
    $lines = run_cmd('sudo lsblk -n -o TYPE ' . escapeshellarg($dev));
    foreach ($lines as $line) {
        if (trim($line) === 'rom') {
            return true;
        }
    }
    return false;
}

// generate card HTML for a selected disk (used in page and ajax)
$cards_html = '';
if ($selected) {
    $disabled = disk_in_use($selected, $mdmembers, $pvNames);
    $hasParts = has_partitions($selected);
    $partCount = partition_count($selected);
    $isCdrom = is_cdrom($selected);
    if ($isCdrom) {
        $disabled = true; // prevent any partition/wipe actions
    }
    ob_start();
    // show any message produced by a POST action at the top of the modal
    if ($message) {
        echo '<div class="alert alert-info">' . $message . '</div>';
    }
    ?>
    <div class="card mb-3">
        <div class="card-header">Partition Table for <?php echo htmlspecialchars($selected); ?></div>
        <div class="card-body"><pre><?php echo htmlspecialchars(implode("\n", part_print($selected))); ?></pre></div>
    </div>
    <?php if ($disabled): ?>
    <div class="alert alert-warning">
        <?php if ($isCdrom): ?>This device appears to be a CD/DVD; modifications are disabled.<?php else: ?>
        This device is in use by RAID or LVM; partitioning and wiping actions are disabled.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="action-buttons mt-3">
        <?php if (!$disabled): ?>
            <button id="btnOpenCreate" class="btn btn-primary" data-disk="<?php echo htmlspecialchars($selected); ?>" <?php echo $partCount >= 4 ? 'disabled' : ''; ?>>Create Partition</button>
            <?php if ($hasParts): ?>
            <button id="btnOpenDelete" class="btn btn-danger ms-2" data-disk="<?php echo htmlspecialchars($selected); ?>">Delete Partition</button>
            <?php endif; ?>
            <form method="post" style="display:inline" class="ms-2">
                <input type="hidden" name="disk" value="<?php echo htmlspecialchars($selected); ?>">
                <button id="btnWipe" name="wipe_disk" class="btn btn-warning">Wipe disk</button>
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
    <?php
    $cards_html = ob_get_clean();
}

// AJAX response support (GET or POST)
// whenever the caller asked for `ajax=1` we only return the card HTML for
// the selected disk; this keeps modal submissions from sending the full page
// back and prevents the disks list from polluting the popup.
if (!empty($_REQUEST['ajax']) && $selected) {
    echo $cards_html;
    exit;
}
// support standalone partition listing for delete-dropdown
if (!empty($_GET['list_parts']) && !empty($_GET['disk'])) {
    $dev = $_GET['disk'];
    $parts = [];
    // use parted to list partition table; capture line text for label
    $out = run_cmd('sudo parted -s ' . escapeshellarg($dev) . ' print');
    foreach ($out as $line) {
        if (preg_match('/^\s*(\d+)\b/', $line, $m)) {
            $parts[] = [
                'num' => intval($m[1]),
                'label' => trim($line)
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($parts);
    exit;
}

?>

<?php if ($message): ?>
    <div id="initialMessage" style="display:none"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-header">Disks</div>
            <div class="card-body">
                <?php if (count($disks) === 0): ?>
                    <em>No disks found.</em>
                <?php else: ?>
                    <?php
                        // determine the OS disk by following root mount parent chain
                        $osDisk = '';
                        $rootLines = run_cmd('lsblk -nr -o NAME,MOUNTPOINT');
                        foreach ($rootLines as $l) {
                            if (preg_match('/^(\S+)\s+\/\s*$/', trim($l), $m)) {
                                $rootName = $m[1];
                                $cur = $rootName;
                                while (true) {
                                    $parentLines = run_cmd('lsblk -nr -o PKNAME ' . escapeshellarg('/dev/'.$cur));
                                    $parent = trim($parentLines[0] ?? '');
                                    if ($parent === '' || $parent === $cur) break;
                                    $cur = $parent;
                                }
                                $osDisk = '/dev/' . $cur;
                                break;
                            }
                        }
                    ?>
                    <form method="post" id="diskSelectForm">
                        <input type="hidden" name="disk" id="selectedDisk" value="<?php echo htmlspecialchars($selected); ?>">
                        <table class="table table-sm table-hover" id="diskTable">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Name/Model</th>
                                    <th>Size</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($disks as $line):
                                $parts = preg_split('/\s+/', trim($line), 5);
                                $name   = $parts[0] ?? '';
                                $size   = $parts[1] ?? '';
                                $model  = $parts[2] ?? '';
                                $serial = $parts[3] ?? '';
                                $type   = $parts[4] ?? '';
                                $dev = '/dev/' . $name;
                                $status = [];
                                if ($dev === $osDisk) {
                                    $status[] = 'OS disk';
                                }
                                if (strpos($dev, '/dev/md') === 0) {
                                    $status[] = 'RAID device';
                                } elseif (!empty($mdmap[$dev])) {
                                    $status[] = 'member of '.implode(',', $mdmap[$dev]);
                                }
                                if (in_array($dev, $pvNames, true)) {
                                    $status[] = 'LVM PV';
                                }
                                $statusStr = $status ? implode('; ', $status) : '';
                                $rowClass = ($dev === $selected) ? 'table-active' : '';
                            ?>
                                <tr class="<?php echo $rowClass; ?>" data-dev="<?php echo htmlspecialchars($dev); ?>" data-name="<?php echo htmlspecialchars(trim($model . ' ' . $serial)); ?>" data-size="<?php echo htmlspecialchars($size); ?>" data-status="<?php echo htmlspecialchars($statusStr); ?>">
                                    <td><?php echo htmlspecialchars($dev); ?></td>
                                    <td><?php echo htmlspecialchars(trim($model . ' ' . $serial)); ?></td>
                                    <td><?php echo htmlspecialchars($size); ?></td>
                                    <td><?php echo htmlspecialchars($statusStr); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($selected): ?>
            <?php echo $cards_html; ?>
        <?php endif; ?>
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

<!-- info/preview modal used for disk row clicks -->
<div class="modal fade" id="infoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Disk Details</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body"></div>
    <div class="modal-footer">
       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    </div>
   </div>
  </div>
</div>

<!-- create/delete partition secondary modals -->
<div class="modal fade" id="createPartModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Partition</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form>
          <input type="hidden" name="disk" value="">
          <div class="mb-3">
            <label class="form-label">Size (e.g. 1G, 500M, 2T)</label>
            <input name="size" class="form-control" required placeholder="e.g. 10G">
          </div>
          <button type="submit" name="create_part_size" class="btn btn-primary">Create</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="deletePartModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete Partition</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form>
          <input type="hidden" name="disk" value="">
          <div class="mb-3">
            <label class="form-label">Partition number</label>
            <select name="part_num" class="form-select" required>
              <option value="">(loading…)</option>
            </select>
          </div>
          <button type="submit" name="delete_part" class="btn btn-danger">Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>
