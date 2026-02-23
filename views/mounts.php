<?php
// Mounts management view. dashboard.php already handled authentication.

$message = '';

// helper to run a command in the host mount namespace if nsenter is installed
$nsenterAvailable = file_exists('/usr/bin/nsenter');
function nsCmd($cmd) {
    global $nsenterAvailable;
    if ($nsenterAvailable) {
        return "sudo /usr/bin/nsenter -t 1 -m $cmd";
    }
    return $cmd;
}

// handle mount/unmount
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // handle mount/unmount
    if (isset($_POST['mount_lv'])) {
        $dev = escapeshellarg($_POST['device_select_mount']);
        $mp = '/export/' . trim($_POST['mount_point']);
        $mpEsc = escapeshellarg($mp);

        // collect options if any
        $opts = [];
        if (!empty($_POST['mount_opts']) && is_array($_POST['mount_opts'])) {
            foreach ($_POST['mount_opts'] as $o) {
                $o = trim($o);
                if ($o !== '') {
                    $opts[] = $o;
                }
            }
        }
        $optString = '';
        if (!empty($opts)) {
            $optString = implode(',', $opts);
        }

        // ensure mountpoint directory exists; capture any errors so we can report them
        $out = run_cmd("sudo -n /bin/mkdir -p $mpEsc");
        // if the directory creation failed the mount is pointless; show the
        // mkdir output and don't attempt to execute mount, which may otherwise
        // produce misleading "succeeded but not listed" messages.
        if (!is_dir($mp)) {
            $message = 'Failed to create mount point:<br>' . htmlspecialchars(implode('<br>', $out));
        } else {
            // mount using nsenter if available (see comment above)
            if ($nsenterAvailable) {
                $cmd = "sudo -n /usr/bin/nsenter -t 1 -m /bin/mount -v";
            } else {
                $cmd = "sudo -n /bin/mount -v";
            }
            if ($optString !== '') {
                $cmd .= " -o " . escapeshellarg($optString);
            }
            $cmd .= " $dev $mpEsc";
            $mountOut = run_cmd($cmd);
            // check mount table separately to see if the point actually shows up
            $grepOut = run_cmd(nsCmd("mount | grep " . escapeshellarg($mp)));
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
                    // rename variable for clarity
                    $lv = $dev; // keep old references in remaining code
                    // (optionally check visibility; no need to mention it in the message)
                    $dfout = run_cmd("df " . escapeshellarg($mp));
                    if (!preg_grep('/' . preg_quote(trim($lv, "'\""), '/') . '/', $dfout)) {
                        // save diagnostics in case you want to inspect them later, but keep
                        // a clean success message.
                        $out = array_merge($out, $mountOut, $grepOut, $dfout);
                    }
                }
            }
        }
        // prefix status messages with context so the user can see what was
        // being mounted/mounted to; this makes debugging easier when the
        // confirmation dialog is ignored and the page reloads without obvious
        // visual change.
        if (isset($statusMsg)) {
            $statusMsg = "[device $dev -> $mp] $statusMsg";
        }
        if (isset($statusMsg) && strpos($statusMsg,'Mount succeeded') === 0) {
            // optionally add to fstab if user requested
            if (!empty($_POST['mount_boot'])) {
                // attempt to detect filesystem type
                $fstype = '';
                $blkout = run_cmd("sudo blkid -o value -s TYPE " . escapeshellarg(trim($dev, "'\"")));
                if (!empty($blkout)) {
                    $fstype = trim($blkout[0]);
                }
                if ($fstype === '') {
                    $fstype = 'auto';
                }
                $entryOpts = $optString !== '' ? $optString : 'defaults';
                // remove any surrounding quotes from the device path before writing to fstab
                $devPath = trim($dev, "'\"");
                $entry = "$devPath $mp $fstype $entryOpts 0 0";
                // append via sudo so webserver user can write
                $eEsc = escapeshellarg($entry);
                // use tee (already allowed in sudoers) to append the line without needing
                // a shell redirection. we redirect tee output to /dev/null to keep run_cmd
                // output clean.
                // add -n so sudo fails rather than hanging on a password prompt
                $teeOut = run_cmd("echo $eEsc | sudo -n tee -a /etc/fstab >/dev/null");
                if (preg_grep('/\(exit\s+[1-9]/', $teeOut)) {
                    $statusMsg .= ' (fstab update failed: ' . htmlspecialchars(implode(' | ', $teeOut)) . ')';
                } else {
                    $statusMsg .= ' (added to /etc/fstab)';
                }
            }
            // on success we display only the status message
            $message = $statusMsg;
        } elseif ($message === '') {
            // if we haven't already set an error message (e.g. mkdir failure), show
            // whatever output was accumulated from the mount attempt.
            $message = implode("<br>", $out);
        }
        if ($message !== '') {
            // message set, handled below
        }
    } elseif (isset($_POST['umount_lv'])) {
        $mp = escapeshellarg($_POST['umount_select']);
        if ($nsenterAvailable) {
            $cmd = "sudo -n /usr/bin/nsenter -t 1 -m /bin/umount $mp";
        } else {
            $cmd = "sudo -n /bin/umount $mp";
        }
        $out = run_cmd($cmd);
        // check mount table afterward to see if it really disappeared; strip
        // out the sole "(exit N)" line that grep prints when there’s no match.
        $after = run_cmd(nsCmd("mount | grep " . escapeshellarg($mp)));
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
        // include mount point in message for clarity
        if (isset($statusMsg)) {
            $statusMsg = "[pt $mp] " . $statusMsg;
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
    elseif (isset($_POST['edit_mount'])) {
        // modify existing mount options and adjust /etc/fstab
        $dev = escapeshellarg($_POST['device']);
        $mp  = '/export/' . trim($_POST['mount_point']);
        $mpEsc = escapeshellarg($mp);

        // collect requested options
        $opts = [];
        if (!empty($_POST['mount_opts']) && is_array($_POST['mount_opts'])) {
            foreach ($_POST['mount_opts'] as $o) {
                $o = trim($o);
                if ($o !== '') {
                    $opts[] = $o;
                }
            }
        }
        $optString = $opts ? implode(',', $opts) : '';
        // build a remount options string that explicitly includes the
        // opposite of any boolean choices so remount can add *and* remove
        // flags.  omitting an option on remount leaves it unchanged.
        $remOpts = [];
        if (in_array('rw', $opts, true)) {
            $remOpts[] = 'rw';
        } elseif (in_array('ro', $opts, true)) {
            $remOpts[] = 'ro';
        }
        if (in_array('noexec', $opts, true)) {
            $remOpts[] = 'noexec';
        } else {
            $remOpts[] = 'exec';
        }
        if (in_array('nosuid', $opts, true)) {
            $remOpts[] = 'nosuid';
        } else {
            $remOpts[] = 'suid';
        }
        if (in_array('nodev', $opts, true)) {
            $remOpts[] = 'nodev';
        } else {
            $remOpts[] = 'dev';
        }
        $remOptString = implode(',', $remOpts);

        // instead of unmounting then mounting we can simply remount with
        // new options.  remount operates on the mount point rather than the
        // device, so we don't need the $dev variable here except for diagnostics.
        // ensure the mountpoint directory exists just in case the caller
        // changed the name (unlikely but harmless).
        $out = run_cmd("sudo /bin/mkdir -p $mpEsc");
        $remountOpts = 'remount,' . $remOptString;
        // build command using nsenter if available
        if ($nsenterAvailable) {
            $cmd = "sudo /usr/bin/nsenter -t 1 -m /bin/mount -v -o " . escapeshellarg($remountOpts) . " " . $mpEsc;
        } else {
            $cmd = "sudo /bin/mount -v -o " . escapeshellarg($remountOpts) . " " . $mpEsc;
        }
        $mountOut = run_cmd($cmd);

        $grepOut = run_cmd(nsCmd("mount | grep " . escapeshellarg($mp)));
        $grepOut = array_values(array_filter($grepOut, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));
        if (preg_grep('/\(exit\s+[1-9]/', $mountOut)) {
            $statusMsg = 'Mount failed';
            $out = array_merge($out, $mountOut, $grepOut);
            $out[] = "command: $cmd";
        } elseif (count($grepOut) === 0) {
            $statusMsg = 'Mount command succeeded but mountpoint not listed';
            $out = array_merge($out, $mountOut, $grepOut);
        } else {
            $statusMsg = 'Mount succeeded';
            $lv = $dev;
            $dfout = run_cmd("df " . escapeshellarg($mp));
            if (!preg_grep('/' . preg_quote(trim($lv, "'\""), '/') . '/', $dfout)) {
                $out = array_merge($out, $mountOut, $grepOut, $dfout);
            }
        }

        // remove any existing fstab entry for this point
        run_cmd("sudo -n sh -c 'grep -v -E \"^[[:space:]]*\\S+\\s+" . preg_quote($mp, '/') . "\\s\" /etc/fstab > /tmp/fstab.$$ && mv /tmp/fstab.$$ /etc/fstab'");
        if (!empty($_POST['mount_boot'])) {
            $fstype = '';
            $blkout = run_cmd("sudo blkid -o value -s TYPE " . escapeshellarg(trim($dev, "'\"")));
            if (!empty($blkout)) {
                $fstype = trim($blkout[0]);
            }
            if ($fstype === '') {
                $fstype = 'auto';
            }
            $entryOpts = $optString !== '' ? $optString : 'defaults';
            $devPath = trim($dev, "'\"");
            $entry = "$devPath $mp $fstype $entryOpts 0 0";
            $eEsc = escapeshellarg($entry);
            $teeOut = run_cmd("echo $eEsc | sudo -n tee -a /etc/fstab >/dev/null");
            if (preg_grep('/\(exit\s+[1-9]/', $teeOut)) {
                $statusMsg .= ' (fstab update failed: ' . htmlspecialchars(implode(' | ', $teeOut)) . ')';
            } else {
                $statusMsg .= ' (fstab updated)';
            }
        }

        if (strpos($statusMsg,'Mount succeeded') === 0) {
            $message = $statusMsg;
        } else {
            $out[] = $statusMsg;
            $message = implode("<br>", $out);
        }
    }
}

// gather logical volumes (still used for other views)
$lvs = run_cmd('sudo lvs --noheadings -o lv_path');
// gather current /export mounts for unmount dropdown

// when querying mounts we prefer the host namespace if possible so that
// the table accurately reflects what the system sees rather than whatever
// namespace PHP happens to be in.
$mnts = run_cmd(nsCmd("mount | grep ' on /export/'"));
// strip the lone "(exit N)" line grep prints when there are no matches
$mnts = array_values(array_filter($mnts, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));

// determine candidate devices for the mount modal: any device with a filesystem
// (as reported by blkid) that is not already mounted, not the OS root device,
// not already used as an LVM physical volume, and not one of the underlying
// members of an MD array. the previous implementation accidentally stripped
// digits from the root device name unconditionally, which meant that when
// root itself was on /dev/md0 the computed $osRoot became '/dev/md' and thus
// all /dev/md* candidates were excluded. it also didn’t filter out array
// member disks, so you could attempt to mount /dev/sdb1 even though the real
// filesystem lived on /dev/md0.
$fsDevices = run_cmd('sudo blkid -o device');
$mounted = run_cmd(nsCmd("mount | awk '{print $1}'"));
$mountSet = array_map('trim', $mounted);

// compute root device and its parent disk name
$osRoot = '';
$rootSrc = run_cmd("findmnt -n -o SOURCE /");
if (!empty($rootSrc)) {
    $osRoot = trim($rootSrc[0]);
    if (strpos($osRoot, '/dev/md') === 0) {
        // root lives on an md device; use it verbatim so we only exclude that
        // specific array rather than all /dev/md*.
        // leave $osRoot unchanged.
    } elseif (preg_match('#^/dev/([a-zA-Z0-9]+)#', $osRoot, $m)) {
        // any other block device, strip trailing digits to get the whole disk
        $base = $m[1];
        $base = preg_replace('/\d+$/', '', $base);
        $osRoot = '/dev/' . $base;
    } else {
        // not a block device path
        $osRoot = '';
    }
}

// gather list of pv paths to avoid
$pvs = run_cmd('sudo pvs --noheadings -o pv_name');
$pvSet = array_map('trim', $pvs);

// gather md array member devices so that we don't offer them for mounting
$mdmembers = [];
$mdlines = run_cmd('cat /proc/mdstat');
foreach ($mdlines as $line) {
    if (preg_match_all('/\b(sd[a-z0-9]+)\b/', $line, $m2)) {
        foreach ($m2[1] as $dname) {
            $mdmembers[] = '/dev/' . $dname;
        }
    }
}

$candidates = [];
foreach ($fsDevices as $d) {
    $d = trim($d);
    if ($d === '') continue;
    if (in_array($d, $mountSet, true)) continue; // already mounted
    // exclude OS disk/partition
    if ($osRoot !== '' && (strpos($d, $osRoot) === 0)) continue;
    // exclude any PV (either exact or starts-with for LVM naming)
    $skip = false;
    foreach ($pvSet as $pv) {
        if ($pv === '') continue;
        if ($d === $pv || strpos($d, $pv) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;
    // exclude md array members as they are not mountable (filesystem lives
    // on the md device itself)
    foreach ($mdmembers as $mm) {
        if ($d === $mm || strpos($d, $mm) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;
    $candidates[] = $d;
}

// load fstab lines so we can mark existing entries
$fstabLines = [];
$fstabPath = '/etc/fstab';
if (file_exists($fstabPath)) {
    $fstabLines = file($fstabPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

// build a structured list for the table view (device, mount point, options, fstab flag)
$mounts = [];
foreach ($mnts as $m) {
    // capture device, point, type and options if present
    if (preg_match('/^(\S+) on (\/export\/\S+) type (\S+) \(([^)]+)\)/', $m, $mm)) {
        $pt = $mm[2];
        $inFstab = false;
        foreach ($fstabLines as $line) {
            if (preg_match('/^\s*\S+\s+' . preg_quote($pt, '/') . '\s/', $line)) {
                $inFstab = true;
                break;
            }
        }
        $mounts[] = ['dev' => $mm[1], 'pt' => $pt, 'opts' => $mm[4], 'fstab' => $inFstab];
    } elseif (preg_match('/^(\S+) on (\/export\/\S+)/', $m, $mm)) {
        $pt = $mm[2];
        $inFstab = false;
        foreach ($fstabLines as $line) {
            if (preg_match('/^\s*\S+\s+' . preg_quote($pt, '/') . '\s/', $line)) {
                $inFstab = true;
                break;
            }
        }
        $mounts[] = ['dev' => $mm[1], 'pt' => $pt, 'opts' => '', 'fstab' => $inFstab];
    }
}
// eliminate duplicate entries for the same mount point, keeping the last
// occurrence (which should reflect the most recent state).
if (count($mounts) > 1) {
    $seen = [];
    $filtered = [];
    for ($i = count($mounts) - 1; $i >= 0; $i--) {
        $m = $mounts[$i];
        if (!isset($seen[$m['pt']])) {
            $seen[$m['pt']] = true;
            array_unshift($filtered, $m);
        }
    }
    $mounts = $filtered;
}
// if we ended up with no rows yet mount command returned something, warn
if (count($mounts) === 0 && count($mnts) > 0) {
    $message .= '<br><strong>debug:</strong> mount list parsed but produced no rows:<br>'
              . htmlspecialchars(implode("<br>", $mnts));
}
?>

<?php if ($message): ?>
    <div id="initialMessage" class="d-none"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row mb-3 align-items-center">
    <div class="col">
        <h5>Existing /export mounts</h5>
    </div>
    <div class="col text-end">
        <button id="btnShowMountModal" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createMountModal">Create mount</button>
    </div>
</div>

<?php if (count($mounts) > 0): ?>
    <table class="table table-sm table-hover" id="mountTable">
        <thead>
            <tr>
                <th>Device</th>
                <th>Mount point</th>
                <th>Options</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mounts as $m): ?>
            <tr data-dev="<?php echo htmlspecialchars($m['dev']); ?>" data-pt="<?php echo htmlspecialchars($m['pt']); ?>" data-opts="<?php echo htmlspecialchars($m['opts'] ?? ''); ?>" data-infstab="<?php echo $m['fstab'] ? '1' : '0'; ?>">
                <td><?php echo htmlspecialchars($m['dev']); ?></td>
                <td><?php echo htmlspecialchars($m['pt']); ?></td>
                <td><?php echo htmlspecialchars($m['opts'] ?? ''); ?></td>
                <td>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="umount_select" value="<?php echo htmlspecialchars($m['pt']); ?>">
                        <button name="umount_lv" class="btn btn-sm btn-secondary btn-umount-row" type="submit">Unmount</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No mounts defined.</p>
<?php endif; ?>

<!-- mount creation modal -->
<div class="modal fade" id="createMountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Mount Device</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="mountForm" method="post">
            <div class="mb-3">
                <label class="form-label">Device</label>
                <select name="device_select_mount" class="form-select" required>
                    <option value="">-- choose --</option>
                    <?php foreach ($candidates as $dev):
                        if (trim($dev) === '') continue;
                    ?>
                    <option value="<?php echo htmlspecialchars($dev); ?>"><?php echo htmlspecialchars($dev); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Mount point (subdir under /export)</label>
                <input name="mount_point" class="form-control" placeholder="myshare" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Mount options</label>
                <div class="d-flex flex-column">
                <?php
                $optChoices = ['rw' => 'Read/write', 'ro' => 'Read-only', 'noexec' => 'No exec', 'nosuid' => 'No suid', 'nodev' => 'No dev'];
                foreach ($optChoices as $opt => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="mount_opts[]" value="<?php echo htmlspecialchars($opt); ?>" id="opt_<?php echo htmlspecialchars($opt); ?>"<?php if (in_array($opt, ['rw','noexec','nodev'], true)) echo ' checked'; ?>>
                        <label class="form-check-label" for="opt_<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($label); ?></label>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" name="mount_boot" id="mountBoot">
                <label class="form-check-label" for="mountBoot">Mount at boot (add to /etc/fstab)</label>
            </div>
            <div class="text-end">
                <button id="btnMount" name="mount_lv" class="btn btn-primary" type="submit">Mount</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- edit mount modal (triggered by clicking a table row) -->
<div class="modal fade" id="editMountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editMountModalTitle">Edit mount</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="editMountForm" method="post">
            <input type="hidden" name="device">
            <div class="mb-3">
                <label class="form-label">Mount point</label>
                <input name="mount_point" id="editMountPoint" class="form-control" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">Mount options</label>
                <div class="d-flex flex-column">
                <?php
                foreach ($optChoices as $opt => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="mount_opts[]" value="<?php echo htmlspecialchars($opt); ?>" id="edit_opt_<?php echo htmlspecialchars($opt); ?>">
                        <label class="form-check-label" for="edit_opt_<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($label); ?></label>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" name="mount_boot" id="editMountBoot">
                <label class="form-check-label" for="editMountBoot">Mount at boot (add to /etc/fstab)</label>
            </div>
            <div class="text-end">
                <button id="btnEditMount" name="edit_mount" class="btn btn-primary" type="submit">Save</button>
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
