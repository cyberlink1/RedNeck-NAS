<?php
// RAID management view (included by dashboard.php) or invoked directly via
// AJAX. When called directly we must load helper functions and enforce login.

if (!defined('IN_DASHBOARD')) {
    require_once __DIR__ . '/../functions.php';
    require_login();
}

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

// helper to enumerate current RAID arrays and extract a few fields for a table
function list_raids() {
    // pre‑scan /proc/mdstat to build a map of array -> member devices
    $mdstat = run_cmd('cat /proc/mdstat');
    $memberMap = [];
    foreach ($mdstat as $line) {
        if (preg_match('/^(md\d+)\s*:/', $line, $mm)) {
            $array = '/dev/' . $mm[1];
            if (preg_match_all('/([a-z0-9]+)\[\d+\]/', $line, $mm2)) {
                foreach ($mm2[1] as $devname) {
                    $memberMap[$array][] = '/dev/' . $devname;
                }
            }
        }
    }

    $mds = run_cmd('ls /dev/md* 2>/dev/null');
    $raids = [];
    foreach ($mds as $m) {
        $m = trim($m);
        // ignore non-md entries and any with colon suffix (e.g. md0:1)
        if ($m === '' || !preg_match('#^/dev/md\d+$#', $m)) {
            continue;
        }
        $info = ['name' => $m, 'level' => '', 'size' => '', 'members' => $memberMap[$m] ?? []];
        $detail = run_cmd('sudo mdadm --detail ' . escapeshellarg($m));
        foreach ($detail as $line) {
            if (preg_match('/Raid Level *: *(.+)/i', $line, $mm)) {
                $info['level'] = trim($mm[1]);
            }
            if (preg_match('/Array Size *: *(.+)/i', $line, $mm)) {
                $info['size'] = trim($mm[1]);
            }
            // continue scanning detail, but member list already filled from mdstat
        }
        $raids[] = $info;
    }
    return $raids;
}

// helper for writing debug output; uses system temp dir so webserver
// always has permission (hardcoded /tmp was causing missing logs).
function raid_log($msg) {
    $path = rtrim(sys_get_temp_dir(), '/') . '/raid_debug.log';
    @file_put_contents($path, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// POST handlers for RAID-specific operations only
$message = '';
// filesystem types we can format with; the RAID view is essentially a
// superset of the disk view so we replicate the same mkfs scanning logic
// that disks.php uses.  This ensures the "format partition" modal in the
// RAID page has a populated dropdown.
$fsTypes = [];
$paths = array_merge(glob('/sbin/mkfs.*') ?: [], glob('/usr/sbin/mkfs.*') ?: []);
foreach ($paths as $path) {
    $name = basename($path);
    if (strpos($name, 'mkfs.') === 0) {
        $type = substr($name, 5);
        if ($type === '' || $type === 'mkfs') continue;
        if (!in_array($type, $fsTypes, true)) {
            $fsTypes[] = $type;
        }
    }
}
sort($fsTypes);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // dump debug info so we can see what arrives when clicking actions
    @file_put_contents('/tmp/raid_debug.log', date('[Y-m-d H:i:s] ') .
        "POST= " . print_r($_POST,true) . "\n", FILE_APPEND);
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
        // pipe a 'y' to answer any interactive questions such as bitmap
        // enable or continue prompt; some mdadm builds don't support a
        // non‑interactive switch at all.
        // use default metadata 1.2 which is modern and placed at end of device
        $out = array_merge($out, run_cmd("yes y | sudo mdadm --create --metadata=1.2 /dev/$name --level=$level --raid-devices=" . count($devList) . " $devices"));
        $out = array_filter($out, function($line) {
            return !preg_match('#(Unrecognised md component device|appears to be part of a raid array|Defaulting to version)#', $line);
        });
        $message = implode("<br>", $out);
    } elseif (isset($_POST['format_raid'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        if ($raid === '') {
            $message = 'No RAID device selected.';
        } else {
            // run a filesystem creation; default to ext4 for now
            $message = implode("<br>", run_cmd('sudo mkfs.ext4 -F ' . escapeshellarg($raid)));
        }
    } elseif (isset($_POST['rebuild_raid'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        if ($raid === '') {
            $message = 'No RAID device selected.';
        } else {
            // attempt to automatically add the first unused *physical* disk
            // found by the same helper we use when creating arrays.  The helper
            // also returns existing /dev/md* devices, so we explicitly drop
            // those (and the array we’re rebuilding) before picking a candidate.
            $cand = list_disks();
            // filter out md devices and the current raid path itself
            $cand = array_filter($cand, function($entry) use ($raid) {
                $dev = explode(',', $entry)[0];
                if ($dev === $raid) return false;
                if (preg_match('#^/dev/md#', $dev)) return false;
                return true;
            });
            if (empty($cand)) {
                $message = 'No unused disks available to add; attach a spare or use mdadm manually.';
            } else {
                // candidate entries are "dev,size" strings
                $parts = explode(',', current($cand));
                $dev = $parts[0];
                $out = run_cmd("sudo mdadm --add " . escapeshellarg($raid) . " " . escapeshellarg($dev));
                $message = 'Initiated rebuild by adding ' . htmlspecialchars($dev) . ' to ' . htmlspecialchars($raid) . '.<br>' .
                           implode('<br>', $out);
            }
        }
    } elseif (isset($_POST['add_member']) || isset($_POST['add_spare'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        $new = trim($_POST['new_disk'] ?? '');
        $isSpare = isset($_POST['add_spare']);
        if ($raid === '' || $new === '') {
            $message = 'Select a RAID device and disk to add.';
        } elseif (!file_exists($new) || filetype($new) !== 'block') {
            $message = 'Selected disk ' . htmlspecialchars($new) . ' is not a valid block device.';
        } else {
            // determine raid level and current member count
            $level = '';
            $memberCount = 0;
            foreach (run_cmd('sudo mdadm --detail ' . escapeshellarg($raid)) as $line) {
                if (preg_match('/Raid Level *: *(.*)/i', $line, $m)) {
                    $level = strtolower(trim($m[1]));
                }
                if (preg_match('#\s+(/dev/\S+)#', $line)) {
                    $memberCount++;
                }
            }
            // if this is RAID0 and we're not just adding a spare, we cannot
            // perform the grow operation here (mdadm requires --grow instead).
            if (strpos($level,'raid0') !== false && !$isSpare) {
                // for RAID0 we perform a combined grow/add in a single command
                $out = run_cmd("sudo mdadm --zero-superblock " . escapeshellarg($new));
                $growAdd = "sudo mdadm --grow " . escapeshellarg($raid) .
                           " --raid-devices=" . ($memberCount + 1) .
                           " --add " . escapeshellarg($new);
                $out = array_merge($out, run_cmd($growAdd));
                // retry with force if spare warning appears
                foreach ($out as $line) {
                    if (strpos($line, 'Need') !== false && strpos($line, 'spare') !== false) {
                        $out = array_merge($out, run_cmd($growAdd . ' --force'));
                        break;
                    }
                }
                $message = implode('<br>', $out);
            } else {
                // zero signature and add device
                $out = run_cmd("sudo mdadm --zero-superblock " . escapeshellarg($new));
                // if zero-superblock complained about unrecognised component, try
                // wiping any lingering metadata and rerun
                foreach ($out as $line) {
                    if (stripos($line, 'Unrecognised md component') !== false) {
                        raid_log("zero_super failed, wiping metadata on $new");
                        $out = array_merge($out, run_cmd("sudo wipefs -a " . escapeshellarg($new)));
                        $out = array_merge($out, run_cmd("sudo sgdisk --zap-all " . escapeshellarg($new)));
                        // retry zero
                        $out = array_merge($out, run_cmd("sudo mdadm --zero-superblock " . escapeshellarg($new)));
                        break;
                    }
                }
                // attempt add; if it still fails with unrecognised component try a
                // brute-force sector zeroing before retrying once more
                $addOut = run_cmd("sudo mdadm --add " . escapeshellarg($raid) . " " . escapeshellarg($new));
                // if add failed with invalid argument, retry with --force
                $retryForce = false;
                foreach ($addOut as $line) {
                    if (strpos($line, 'Invalid argument') !== false) {
                        $retryForce = true;
                        break;
                    }
                }
                if ($retryForce) {
                    raid_log("add returned invalid argument, retrying with --force");
                    $addOut = array_merge($addOut, run_cmd("sudo mdadm --add --force " . escapeshellarg($raid) . " " . escapeshellarg($new)));
                }
                $needRetry = false;
                foreach ($addOut as $line) {
                    if (stripos($line, 'Unrecognised md component') !== false) {
                        $needRetry = true;
                        break;
                    }
                }
                if ($needRetry) {
                    raid_log("add failed, zeroing first megabyte of $new and retrying");
                    $out = array_merge($out, run_cmd("sudo dd if=/dev/zero of=" . escapeshellarg($new) . " bs=1M count=10"));
                    $addOut = run_cmd("sudo mdadm --add " . escapeshellarg($raid) . " " . escapeshellarg($new));
                }
                $out = array_merge($out, $addOut);
                // if raid1/5/6 grow now
                if (preg_match('/raid1|raid5|raid6/', $level) && !$isSpare) {
                    $growCmd = "sudo mdadm --grow " . escapeshellarg($raid) . " --raid-devices=" . ($memberCount + 1);
                    $growOut = run_cmd($growCmd);
                    foreach ($growOut as $line) {
                        if (strpos($line, 'Need') !== false && strpos($line, 'spare') !== false) {
                            $growOut = array_merge($growOut, run_cmd($growCmd . ' --force'));
                            break;
                        }
                    }
                    // detect if mdadm changed the level as part of the grow
                    foreach ($growOut as $line) {
                        if (preg_match('/level of (\S+) changed to (raid\d+)/i', $line, $m)) {
                            $out[] = 'NOTICE: array ' . $m[1] . ' level changed to ' . $m[2] . ' during grow';
                            raid_log("level change detected: {$m[1]} -> {$m[2]}");
                        }
                    }
                    $out = array_merge($out, $growOut);
                }
                // strip out the usual mdadm/GPT warnings so the user sees a clean
                // success/failure result rather than a wall of noise.  this mirrors
                // the filtering applied during array creation.
                $out = array_filter($out, function($line) {
                    return !preg_match('#(Unrecognised md component device|Creating new GPT entries|GPT data structures destroyed|appears to be part of a raid array|Defaulting to version)#i', $line);
                });
                $message = implode('<br>', $out);
            }
        }
    } elseif (isset($_POST['fail_member'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        $member = trim($_POST['member'] ?? '');
        @file_put_contents('/tmp/raid_debug.log', date('[Y-m-d H:i:s] ') . "fail_member raid=$raid member=$member\n", FILE_APPEND);
        if ($raid === '' || $member === '') {
            $message = 'Select a RAID device and member to fail/remove.';
        } else {
            $out = run_cmd("sudo mdadm --fail " . escapeshellarg($raid) . " " . escapeshellarg($member));
            $out = array_merge($out, run_cmd("sudo mdadm --remove " . escapeshellarg($raid) . " " . escapeshellarg($member)));
            $message = 'Marked ' . htmlspecialchars($member) . ' as failed and removed from ' . htmlspecialchars($raid) . '.<br>' .
                       implode('<br>', $out);
            @file_put_contents('/tmp/raid_debug.log', date('[Y-m-d H:i:s] ') . "fail_member output=" . implode(';',$out) . "\n", FILE_APPEND);
        }
    } elseif (isset($_POST['remove_raid'])) {
        $raid = trim($_POST['raid_select'] ?? '');
        @file_put_contents('/tmp/raid_debug.log', date('[Y-m-d H:i:s] ') . "remove_raid POST received raid=" . var_export($raid, true) . "\n", FILE_APPEND);
        if ($raid === '') {
            $message = 'No RAID device selected.';
        } else {
            $reloadAfter = false; // flag that we should refresh the page when the message is dismissed
            $msgs = [];
            $raidName = preg_replace('#^/dev/#','',$raid);
            $raidPath = "/dev/" . $raidName;

            // ask pvs for vg_name as well so we can distinguish a stray PV with
            // no associated volume group (which is safe to clear) from one that's
            // actually in use.
            $pvinfo = run_cmd("sudo pvs --noheadings -o pv_name,vg_name --separator='|' --select pv_name=" . escapeshellarg($raidPath));
            $pvinfo = array_map('trim', $pvinfo);
            @file_put_contents('/tmp/raid_debug.log', date('[Y-m-d H:i:s] ') . "pvinfo for $raidPath: " . implode(';', $pvinfo) . "\n", FILE_APPEND);
            // filter blank lines
            $pvinfo = array_filter($pvinfo, function($v){ return $v !== ''; });

            if (!empty($pvinfo)) {
                // parse each line, check for vg_name
                $inUse = false;
                $linesMsg = [];
                foreach ($pvinfo as $line) {
                    list($pv, $vg) = explode('|', $line . '|');
                    $pv = trim($pv);
                    $vg = trim($vg);
                    // show pv and vg name for diagnostics
                    $linesMsg[] = htmlspecialchars($pv . ' -> vg=' . $vg);
                    if ($vg !== '') {
                        $inUse = true;
                    }
                }
                if ($inUse) {
                    $msgs[] = 'RAID device ' . htmlspecialchars($raidPath) . ' is still part of an LVM physical volume belonging to a volume group:';
                    $msgs = array_merge($msgs, $linesMsg);
                    $msgs[] = 'Remove the associated logical volumes/volume group before deleting the array.';
                    $message = implode("<br>", $msgs);
                } else {
                    // PV exists but not assigned to any VG; remove it automatically
                    $msgs[] = 'Found leftover LVM PV metadata on ' . htmlspecialchars($raidPath) . '. Clearing it now.';
                    $outPv = run_cmd("sudo pvremove -ffy " . escapeshellarg($raidPath));
                    $msgs = array_merge($msgs, array_map('htmlspecialchars',$outPv));
                    $message = implode("<br>", $msgs);
                    // continue with remove; don't set $message yet, we'll build it later
                    $pvinfo = [];
                }
            }
            if (!empty($pvinfo)) {
                // still something left, abort removal
                // (message already set above)
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
                // verify that the array really went away
                $still = file_exists($raidPath) ||
                         preg_match('/' . preg_quote(basename($raidPath), '/') . '/', implode("\n", $out));
                if ($still) {
                    $msgs[] = 'ERROR: RAID array ' . htmlspecialchars($raidPath) . ' still appears to exist; removal may have failed.';
                } else {
                    $msgs[] = 'RAID array ' . htmlspecialchars($raidPath) . ' removed successfully.';
                    $reloadAfter = true;
                }
                $message = implode("<br>", $msgs);
                @file_put_contents('/tmp/raid_debug.log', date('[Y-m-d H:i:s] ') . "actual removal output:\n" . implode("\n", $out) . "\n", FILE_APPEND);
            }
            if (!empty($reloadAfter)) {
                // trick the frontend into refreshing after the confirmation dialog
                $message = '<span data-reload="1"></span>' . $message;
            }
        }
    }
}

$disks = list_disks();
$raids = list_raids();

// lightweight JSON endpoints used by the front-end
if (isset($_GET['json_unused'])) {
    header('Content-Type: application/json');
    $list = list_disks();
    // drop any /dev/md entries
    $names = [];
    foreach ($list as $e) {
        $dev = explode(',', $e)[0];
        if (preg_match('#^/dev/md#', $dev)) continue;
        $names[] = $dev;
    }
    echo json_encode($names);
    exit;
}
if (isset($_GET['json_members']) && isset($_GET['raid'])) {
    $raidPath = $_GET['raid'];
    $raidPath = preg_replace('#^/dev/#','/dev/',$raidPath);
    $detail = run_cmd('sudo mdadm --detail ' . escapeshellarg($raidPath));
    $members = [];
    foreach ($detail as $line) {
        if (preg_match('#\s+(/dev/\S+)#', $line, $m)) {
            $members[] = $m[1];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($members);
    exit;
}

// AJAX endpoint for fetching array details similar to disk view
if (isset($_GET['ajax']) && isset($_GET['raid'])) {
    $raidPath = $_GET['raid'];
    // normalize name and ensure it begins with /dev/
    $raidPath = preg_replace('#^/dev/#','/dev/',$raidPath);
    $detail = run_cmd("sudo mdadm --detail " . escapeshellarg($raidPath));
    // build HTML containing the detail output plus action buttons
    echo '<div><pre>' . htmlspecialchars(implode("\n", $detail)) . '</pre></div>';
    // list member devices as clickable links
    if (!empty($memberMap[$raidPath])) {
        echo '<div class="mt-2"><strong>Members:</strong><ul class="list-unstyled">';
        foreach ($memberMap[$raidPath] as $m) {
            echo '<li><a href="#" class="raid-member" data-dev="' . htmlspecialchars($m) . '">' . htmlspecialchars($m) . '</a></li>';
        }
        echo '</ul></div>';
    }
    echo '<div class="action-buttons mt-2">';
    // provide a partition button that transitions into the disk view (with raid=1 so partitioning remains enabled)
    echo '<button id="btnPartitionRaid" class="btn btn-sm btn-primary me-1" data-raid="' . htmlspecialchars($raidPath) . '">Partition</button>';
    echo '<button id="btnFormatRaid" class="btn btn-sm btn-secondary me-1" data-raid="' . htmlspecialchars($raidPath) . '">Format</button>';
    // determine raid level so we can disable unsupported actions
    $raidLevel = '';
    foreach ($detail as $line) {
        if (preg_match('/Raid Level *: *(.*)/i', $line, $m)) {
            $raidLevel = strtolower(trim($m[1]));
            break;
        }
    }
    echo '<button id="btnAddRaid" class="btn btn-sm btn-secondary me-1" data-raid="' . htmlspecialchars($raidPath) . '">Add disk</button>';
    if (strpos($raidLevel,'raid0') === false && strpos($raidLevel,'raid1') === false) {
        echo '<button id="btnAddSpareRaid" class="btn btn-sm btn-secondary me-1" data-raid="' . htmlspecialchars($raidPath) . '">Add spare</button>';
    }
    echo '<button id="btnFailRaid" class="btn btn-sm btn-secondary me-1" data-raid="' . htmlspecialchars($raidPath) . '">Fail/Remove</button>';
    echo '<button id="btnRebuildRaid" class="btn btn-sm btn-secondary me-1" data-raid="' . htmlspecialchars($raidPath) . '">Rebuild</button>';
    echo '<button id="btnRemoveRaid" class="btn btn-sm btn-danger" data-raid="' . htmlspecialchars($raidPath) . '">Remove</button>';
    echo '</div>';
    exit;
}
?>

<?php if ($message): ?>
    <div id="initialMessage" class="d-none"<?php if (strpos($message,'data-reload="1"') !== false) echo ' data-reload="1"'; ?>><?php echo $message; ?></div>
<?php endif; ?>


<div class="row mb-3 align-items-center">
    <div class="col">
        <h5>Existing RAID Arrays</h5>
    </div>
    <div class="col text-end">
        <button id="btnShowCreateRaid" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRaidModal">Create RAID</button>
    </div>
</div>

<?php if (count($raids) > 0): ?>
    <table class="table table-sm table-hover" id="raidTable">
        <thead>
            <tr>
                <th>Array</th>
                <th>Level</th>
                <th>Size</th>
                <th>Members</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($raids as $r): ?>
            <tr data-dev="<?php echo htmlspecialchars($r['name']); ?>">
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo htmlspecialchars($r['level']); ?></td>
                <td><?php echo htmlspecialchars($r['size']); ?></td>
                <td><?php echo htmlspecialchars(implode(',', $r['members'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No RAID arrays defined.</p>
<?php endif; ?>

<!-- modal containing create form -->
<div class="modal fade" id="createRaidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create RAID Array</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
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
            <div class="text-end">
            <button name="create_raid" type="submit" class="btn btn-primary">Create RAID</button>
            <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
        </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- modal for adding a spare disk to an existing array -->
<div class="modal fade" id="addRaidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Disk to RAID</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="addRaidForm" method="post">
          <input type="hidden" name="raid_select" value="">
          <div class="mb-3">
            <label class="form-label">Disk to add</label>
            <select name="new_disk" class="form-select" required>
              <option value="">(loading…)</option>
            </select>
          </div>
          <div class="text-end">
            <button type="submit" name="add_member" class="btn btn-primary">Add</button>
            <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
        </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- modal for failing/removing a member from an array -->
<div class="modal fade" id="failRaidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Fail/Remove Member</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="failRaidForm" method="post">
          <input type="hidden" name="raid_select" value="">
          <div class="mb-3">
            <label class="form-label">Member to fail/remove</label>
            <select name="member" class="form-select" required>
              <option value="">(loading…)</option>
            </select>
          </div>
          <div class="text-end">
            <button type="submit" name="fail_member" class="btn btn-danger">Fail/Remove</button>
            <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
        </div>
        </form>
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
                <button type="button" class="btn btn-primary btn-ok">OK</button>
                <button type="button" class="btn btn-secondary btn-cancel ms-2" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- info/preview modal reused by both disk and raid views -->
<div class="modal fade" id="infoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Details</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body"></div>
    <div class="modal-footer">
       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    </div>
   </div>
  </div>
</div>

<!-- create/delete partition secondary modals (copied from disks.php so they exist in RAID view) -->
<div class="modal fade" id="createPartModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Partition</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form>
          <input type="hidden" name="disk" value="">
          <input type="hidden" name="raid" value="1">
          <div class="mb-3">
            <label class="form-label">Size (e.g. 1G, 500M, 2T)</label>
            <input name="size" class="form-control" required placeholder="e.g. 10G">
          </div>
          <button type="submit" name="create_part_size" class="btn btn-primary">Create</button>          <button type="button" class="btn btn-outline-secondary ms-2" data-bs-dismiss="modal">Cancel</button>        </form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="deletePartModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete Partition</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form>
          <input type="hidden" name="disk" value="">
          <input type="hidden" name="raid" value="1">
          <div class="mb-3">
            <label class="form-label">Partition number</label>
            <select name="part_num" class="form-select" required>
              <option value="">(loading…)</option>
            </select>
          </div>
          <div class="text-end">
            <button type="submit" name="delete_part" class="btn btn-danger">Delete</button>
            <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
        </div>
        </form>
      </div>
    </div>
  </div>
</div>
  <div class="modal fade" id="formatPartModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Format Partition</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form>
            <input type="hidden" name="disk" value="">
            <input type="hidden" name="raid" value="1">
            <div class="mb-3">
              <label class="form-label">Partition number</label>
              <select name="part_num" class="form-select" required>
                <option value="">(loading…)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Filesystem type</label>
              <select name="fstype" class="form-select" required>
                <?php foreach ($fsTypes as $t) echo '<option>'.htmlspecialchars($t).'</option>'; ?>
              </select>
            </div>
            <button type="submit" name="format_part" class="btn btn-secondary">Format</button>
            <button type="button" class="btn btn-outline-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
          </form>
        </div>
      </div>
    </div>
  </div>

<!-- simple result modal for notifications (does *not* hide infoModal) -->
<div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="1">
  <div class="modal-dialog modal-xl">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Result</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body"></div>
    <div class="modal-footer">
       <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
       <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
    </div>
   </div>
  </div>
</div>
