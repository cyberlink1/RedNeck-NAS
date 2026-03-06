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
        $mp = rtrim(mount_root(), '/') . '/' . trim($_POST['mount_point']);
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
        // remember whether it existed before creation so we only adjust ownership
        // on newly-created paths (the export requirement only applies before a
        // filesystem is mounted).  ownership/perm changes performed here will be
        // overwritten once the filesystem is mounted; we apply the user-specified
        // values again after a successful mount below.
        $existed = is_dir($mp);
        $out = run_cmd("sudo -n /bin/mkdir -p $mpEsc");
        // capture requested ownership/permissions for later
        $owner = trim($_POST['mount_owner'] ?? '');
        $group = trim($_POST['mount_group'] ?? '');
        // build perms from checkbox matrix
        $flag = 0;
        if (!empty($_POST['perm_own_r'])) $flag |= 0400;
        if (!empty($_POST['perm_own_w'])) $flag |= 0200;
        if (!empty($_POST['perm_own_x'])) $flag |= 0100;
        if (!empty($_POST['perm_grp_r'])) $flag |= 0040;
        if (!empty($_POST['perm_grp_w'])) $flag |= 0020;
        if (!empty($_POST['perm_grp_x'])) $flag |= 0010;
        if (!empty($_POST['perm_oth_r'])) $flag |= 0004;
        if (!empty($_POST['perm_oth_w'])) $flag |= 0002;
        if (!empty($_POST['perm_oth_x'])) $flag |= 0001;
        $perms = $flag ? sprintf('%04o', $flag) : '';
        $setuid = !empty($_POST['mount_setuid']);
        $setgid = !empty($_POST['mount_setgid']);
        // preliminary ownership/permissions only affect the empty mountpoint
        if (is_dir($mp)) {
            if ($owner !== '' || $group !== '') {
                $spec = ($owner !== '' ? $owner : '') . ':' . ($group !== '' ? $group : '');
                $chownOut = run_cmd("sudo -n /bin/chown $spec $mpEsc");
                if (preg_grep('/\(exit\s+[1-9]/', $chownOut)) {
                    $out = array_merge($out, ['chown failed: ' . implode(' | ', $chownOut)]);
                }
            }
            if ($perms !== '') {
                $chmodOut = run_cmd("sudo -n /bin/chmod " . escapeshellarg($perms) . " $mpEsc");
                if (preg_grep('/\(exit\s+[1-9]/', $chmodOut)) {
                    $out = array_merge($out, ['chmod failed: ' . implode(' | ', $chmodOut)]);
                }
            }
            if ($setuid) {
                run_cmd("sudo -n /bin/chmod u+s $mpEsc");
            }
            if ($setgid) {
                run_cmd("sudo -n /bin/chmod g+s $mpEsc");
            }
        }
        // if we just created the directory and not specified explicit owner,
        // default to nobody:nogroup so NFS can export it even when a filesystem
        // is mounted there.
        if (!$existed && is_dir($mp) && $owner === '' && $group === '') {
            $chownOut = run_cmd("sudo -n /bin/chown nobody:nogroup $mpEsc");
            if (preg_grep('/\(exit\s+[1-9]/', $chownOut)) {
                $out = array_merge($out, ['chown failed: ' . implode(' | ', $chownOut)]);
            }
        }
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
                    // apply ownership/permission changes inside the mounted filesystem
                    if (($owner !== '' || $group !== '') || $perms !== '' || $setuid || $setgid) {
                        if ($owner !== '' || $group !== '') {
                            $spec = ($owner !== '' ? $owner : '') . ':' . ($group !== '' ? $group : '');
                            run_cmd("sudo -n /bin/chown $spec $mpEsc");
                        }
                        if ($perms !== '') {
                            run_cmd("sudo -n /bin/chmod " . escapeshellarg($perms) . " $mpEsc");
                        }
                        if ($setuid) {
                            run_cmd("sudo -n /bin/chmod u+s $mpEsc");
                        }
                        if ($setgid) {
                            run_cmd("sudo -n /bin/chmod g+s $mpEsc");
                        }
                    }
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
        // the prefix above means the message no longer *starts* with
        // "Mount succeeded"; use strpos!==false so we still catch a success.
        if (isset($statusMsg) && strpos($statusMsg,'Mount succeeded') !== false) {
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
                    // reload systemd so the new mount unit is registered
                    $reload = run_cmd("sudo -n /bin/systemctl daemon-reload");
                    if (preg_grep('/\(exit\s+[1-9]/', $reload)) {
                        $statusMsg .= ' (systemctl daemon-reload failed: ' . htmlspecialchars(implode(' | ', $reload)) . ')';
                    } else {
                        $statusMsg .= ' (systemd reloaded)';
                    }
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
        $rawMp = trim($_POST['umount_select']);
        $mpEsc = escapeshellarg($rawMp);
        if ($nsenterAvailable) {
            $cmd = "sudo -n /usr/bin/nsenter -t 1 -m /bin/umount $mpEsc";
        } else {
            $cmd = "sudo -n /bin/umount $mpEsc";
        }
        $out = run_cmd($cmd);
        // check whether an fstab entry exists by grepping the second field
        $haveEntry = false;
        $grepOut = run_cmd("grep -F ' " . escapeshellarg($rawMp) . " ' /etc/fstab");
        foreach ($grepOut as $line) {
            if (strpos($line, '(exit') === false) {
                $haveEntry = true;
                break;
            }
        }
        // perform removal using awk which tests the second column exactly;
        // this will always rewrite the file but leave it unchanged if no match
        // exists. using awk avoids quoting headaches with grep regex.
        // perform safe removal purely in PHP to avoid shell quoting headaches
        // read the current fstab and filter out any line whose second field matches
        $lines = @file('/etc/fstab', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $filtered = [];
        foreach ($lines as $l) {
            // skip comment lines entirely (allow leading whitespace before '#')
            if (preg_match('/^\s*#/', $l)) {
                $filtered[] = $l;
                continue;
            }
            // if the second field equals the mountpoint, drop the line; we use a
            // regex to avoid problems with leading spaces producing an empty
            // first element when preg_split is used.
            if (preg_match('/^\s*\S+\s+' . preg_quote($rawMp, '/') . '(\s|$)/', $l)) {
                continue;
            }
            $filtered[] = $l;
        }
        // write filtered result to temp file under /etc using sudo tee,
        // then move into place. mv is now allowed in sudoers so this should
        // succeed reliably and we avoid needing /bin/sh.
        $tmpfh = popen('sudo -n tee /etc/fstab.tmp 2>&1', 'w');
        if ($tmpfh === false) {
            $out[] = "ERROR: failed to popen tee";
        } else {
            foreach ($filtered as $l) {
                fwrite($tmpfh, $l . "\n");
            }
            $status = pclose($tmpfh);
            $out[] = "tee exit status: $status";
        }
        $mvout = run_cmd('sudo -n mv /etc/fstab.tmp /etc/fstab');
        foreach ($mvout as $l) { $out[] = "mv> $l"; }
        // if we actually removed an entry make systemd reload the unit files
        if ($haveEntry) {
            $reload = run_cmd("sudo -n /bin/systemctl daemon-reload");
            if (preg_grep('/\(exit\\s+[1-9]/', $reload)) {
                $out[] = "systemctl daemon-reload failed: " . implode(' | ', $reload);
            } else {
                $statusMsg .= ' (systemd reloaded)';
            }
        }
        // examine unmount result for status
        $failed = preg_grep('/\(exit\s+[1-9]/', $out);
        if (!$failed) {
            $statusMsg = 'Unmount succeeded';
            if ($haveEntry) {
                $statusMsg .= ' (fstab entry removed)';
            }
        } else {
            // even on failure include mount-table snippet for diagnostics
            $after = run_cmd(nsCmd("mount | grep " . escapeshellarg($rawMp)));
            $after = array_values(array_filter($after, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));
            if ($failed) {
                $out[] = "command: $cmd";
                $statusMsg = 'Unmount failed';
                $out = array_merge($out, $after);
            } else {
                $statusMsg = 'Unmount claimed success but entry still present';
                $out = array_merge($out, $after);
            }
        }
        // include mount point in message for clarity; use unescaped path
        if (isset($statusMsg)) {
            $statusMsg = "[pt $rawMp] " . $statusMsg;
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
        $mp  = rtrim(mount_root(), '/') . '/' . trim($_POST['mount_point']);
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
        // ensure the (possibly renamed) mountpoint exists; if it was absent
        // previously make sure to give it the correct owner so NFS can export it
        // prior to a mount being placed on top of it.
        $existed = is_dir($mp);
        $out = run_cmd("sudo /bin/mkdir -p $mpEsc");
        // apply requested ownership/permissions updates
        $owner = trim($_POST['mount_owner'] ?? '');
        $group = trim($_POST['mount_group'] ?? '');
        $flag = 0;
        if (!empty($_POST['perm_own_r'])) $flag |= 0400;
        if (!empty($_POST['perm_own_w'])) $flag |= 0200;
        if (!empty($_POST['perm_own_x'])) $flag |= 0100;
        if (!empty($_POST['perm_grp_r'])) $flag |= 0040;
        if (!empty($_POST['perm_grp_w'])) $flag |= 0020;
        if (!empty($_POST['perm_grp_x'])) $flag |= 0010;
        if (!empty($_POST['perm_oth_r'])) $flag |= 0004;
        if (!empty($_POST['perm_oth_w'])) $flag |= 0002;
        if (!empty($_POST['perm_oth_x'])) $flag |= 0001;
        $perms = $flag ? sprintf('%04o', $flag) : '';
        $setuid = !empty($_POST['mount_setuid']);
        $setgid = !empty($_POST['mount_setgid']);
        if (is_dir($mp)) {
            if ($owner !== '' || $group !== '') {
                $spec = ($owner !== '' ? $owner : '') . ':' . ($group !== '' ? $group : '');
                $chownOut = run_cmd("sudo -n /bin/chown $spec $mpEsc");
                if (preg_grep('/\(exit\s+[1-9]/', $chownOut)) {
                    $out = array_merge($out, ['chown failed: ' . implode(' | ', $chownOut)]);
                }
            }
            if ($perms !== '') {
                $chmodOut = run_cmd("sudo -n /bin/chmod " . escapeshellarg($perms) . " $mpEsc");
                if (preg_grep('/\(exit\s+[1-9]/', $chmodOut)) {
                    $out = array_merge($out, ['chmod failed: ' . implode(' | ', $chmodOut)]);
                }
            }
            if ($setuid) {
                run_cmd("sudo -n /bin/chmod u+s $mpEsc");
            }
            if ($setgid) {
                run_cmd("sudo -n /bin/chmod g+s $mpEsc");
            }
        }
        if (!$existed && is_dir($mp) && $owner === '' && $group === '') {
            $chownOut = run_cmd("sudo -n /bin/chown nobody:nogroup $mpEsc");
            if (preg_grep('/\(exit\s+[1-9]/', $chownOut)) {
                $out = array_merge($out, ['chown failed: ' . implode(' | ', $chownOut)]);
            }
        }
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

        // ensure systemd reloads after any fstab edits (entry removed or added)
        $reload = run_cmd("sudo -n /bin/systemctl daemon-reload");
        if (preg_grep('/\(exit\s+[1-9]/', $reload)) {
            $statusMsg .= ' (systemctl daemon-reload failed: ' . htmlspecialchars(implode(' | ', $reload)) . ')';
        } else {
            $statusMsg .= ' (systemd reloaded)';
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
// gather current mounts under the configured mount base for unmount dropdown

// when querying mounts we prefer the host namespace if possible so that
// the table accurately reflects what the system sees rather than whatever
// namespace PHP happens to be in.

$root = mount_root();
// determine where the OS root device actually comes from; fall back to
// empty if findmnt fails.  dashboard.php performs the same computation but
// mounts.php previously relied on $rootSrc being set by the caller, which
// isn't guaranteed (and didn't work on the RedHat system). compute it here
// so the mount filtering logic is self-contained.
$rootSrc = run_cmd("findmnt -n -o SOURCE /");

// match lines containing " on <root>" (singular or plural); the helper
// already handles the optional trailing 's'.
$onRegex = '# on ' . preg_quote($root, '#') . 's?(?:/|$)#';
$all = run_cmd(nsCmd("mount"));
$mnts = array_values(array_filter($all, fn($l)=> preg_match($onRegex, $l)));

// determine candidate devices for the mount modal: any device with a filesystem
// (as reported by blkid) that is not already mounted, not the OS root device,
// not already used as an LVM physical volume, and not one of the underlying
// members of an MD array. the previous implementation accidentally stripped
// digits from the root device name unconditionally, which meant that when
// root itself was on /dev/md0 the computed $osRoot became '/dev/md' and thus
// all /dev/md* candidates were excluded. it also didn’t filter out array
// member disks, so you could attempt to mount /dev/sdb1 even though the real
// filesystem lived on /dev/md0.

// devices which appear to have a filesystem according to blkid. some
// distributions (or versions of blkid) may not support "-o device" or
// may refuse to run under sudo without a password, so capture the raw
// output for debugging if the list comes back empty.
$fsDevices = run_cmd('sudo blkid -o device');
if (count($fsDevices) === 0) {
    $message .= '<br><strong>debug:</strong> blkid returned no devices; ' .
                'check sudo privileges or blkid version.';
}
$mounted = run_cmd(nsCmd("mount | awk '{print $1}'"));
// if `mount` produced no output (sudo restrictions, missing nsenter, etc.)
// fall back to parsing /proc/1/mounts directly so we still know what’s
// mounted on the host.
if (count(array_filter($mounted, fn($l)=>trim($l) !== '')) === 0) {
    $mounted = [];
    if (file_exists('/proc/1/mounts')) {
        $lines = file('/proc/1/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            $f = preg_split('/\s+/', trim($l));
            if (isset($f[0])) {
                $mounted[] = $f[0];
            }
        }
    }
    $message .= '<br><strong>debug:</strong> used /proc/1/mounts fallback for mounted device list.';
}
$mountSet = array_map('trim', $mounted);
// for debugging, keep a copy of what we think is mounted
$debugMounted = $mountSet;
// also build canonical forms of mounted devices to compare against
$canonicalMounted = [];
foreach ($mountSet as $m) {
    if (strpos($m,'/dev/') !== 0) {
        // ignore non-block entries such as "proc"/"sysfs"; readlink on
        // these yields paths under the PHP working directory, polluting the
        // canonical list and causing unrelated candidates to be dropped.
        continue;
    }
    $canon = run_cmd("readlink -f " . escapeshellarg($m));
    $canonicalMounted[] = trim($canon[0] ?? $m);
}
// retain canonical list for diagnostic output as well
$debugCanonical = $canonicalMounted;
// we'll also keep a canonical version of the OS root device for filtering
$canonicalOsRoot = '';
$osRoot = '';
if (!empty($rootSrc)) {
    // start with the raw source reported by findmnt and canonicalize it
    $osRoot = trim($rootSrc[0]);
    $canonLines = run_cmd("readlink -f " . escapeshellarg($osRoot));
    $osRoot = trim($canonLines[0] ?? $osRoot);

    if (strpos($osRoot, '/dev/md') === 0) {
        // md device – exclude exactly this array.
    } else {
        // strip trailing digits only for simple disk partitions (/dev/sda1->/dev/sda).
        // do *not* touch dm-* or mapper paths; they contain hyphens and are already
        // node-specific.  also be defensive: if the value ends in a hyphen with no
        // digit (seen on some RHEL versions), treat it as invalid.
        if (preg_match('#^/dev/[a-z]+[0-9]+$#', $osRoot)) {
            $osRoot = preg_replace('/\d+$/', '', $osRoot);
        }
    }
    // remember canonical form for later comparisons
    $corootLines = run_cmd("readlink -f " . escapeshellarg($osRoot));
    $canonicalOsRoot = trim($corootLines[0] ?? $osRoot);
    if (preg_match('#/dev/dm-$#', $canonicalOsRoot)) {
        // bogus result (no trailing number); ignore entirely
        $canonicalOsRoot = '';
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
// maintain a map from canonical path to the display string we will
// present; this allows us to deduplicate variants such as
// "/dev/mapper/vg-lv" vs "/dev/vg/lv" even though they are different
// strings.  canonicalPaths will hold the keys we’ve already seen.
$canonicalSeen = [];
foreach ($fsDevices as $d) {
    $d = trim($d);
    if ($d === '') continue;
    // canonicalize candidate and compare against mounted devices to avoid
    // mismatches like /dev/root vs /dev/dm-0
    $dcanonLines = run_cmd("readlink -f " . escapeshellarg($d));
    $dcanon = trim($dcanonLines[0] ?? $d);
    if (in_array($dcanon, $canonicalMounted, true)) continue; // already mounted
    if (in_array($d, $mountSet, true)) continue; // also check raw form
    // also exclude any candidate that matches a canonical mounted path of a LV
    // since mountSet may list the mapper name while blkid returned the /dev/vg/lv
    foreach ($canonicalMounted as $mc) {
        if ($mc === $dcanon) { continue 2; }
    }
    // exclude OS disk/partition (use canonical paths too)
    if ($osRoot !== '') {
        $oscanonLines = run_cmd("readlink -f " . escapeshellarg($osRoot));
        $oscanon = trim($oscanonLines[0] ?? $osRoot);
        if (strpos($dcanon, $oscanon) === 0) continue;
    }
    // skip swap or devices lacking a filesystem type; blkid may return an empty
    // string for unformatted volumes or raid chunks which we don't want.
    $typeLines = run_cmd("sudo blkid -s TYPE -o value " . escapeshellarg($dcanon));
    $type = trim($typeLines[0] ?? '');
    if ($type === '' || $type === 'swap') {
        continue;
    }
    // also skip anything that lsblk reports as already mounted, this catches
    // cases where the mount device is reported under an alias such as
    // /dev/root or /dev/dm-0 but the candidate is the underlying physical
    // partition.
    $mpLines = run_cmd("lsblk -n -o MOUNTPOINT " . escapeshellarg($dcanon));
    if (trim($mpLines[0] ?? '') !== '') {
        continue;
    }
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
    // determine display name as before
    $display = $d;
    if (strpos($dcanon, '/dev/mapper/') === 0) {
        $display = $dcanon;
    } elseif (preg_match('#^/dev/dm-(\d+)$#', $d, $m)) {
        $sysname = "/sys/block/dm-$m[1]/dm/name";
        if (file_exists($sysname)) {
            $name = trim(@file_get_contents($sysname));
            if ($name !== '') {
                $display = "/dev/mapper/$name";
            }
        }
    }
    // deduplicate by canonical path
    if (in_array($dcanon, $canonicalSeen, true)) {
        continue;
    }
    $canonicalSeen[] = $dcanon;
    $candidates[] = $display;
}
// debug banner removed once filtering logic stable

// legacy conditional retained for backward-compatibility (should always true now)
foreach ($candidates as $cand) {
    if (preg_match('#(/dev/|swap)#', $cand)) {
        break;
    }
}
// ensure lvPaths is defined in case debug flag is used early (will be
// overwritten shortly when we actually query lvs below)
$lvPaths = [];

// additionally include any logical volumes returned by `lvs` that were
// missed by blkid (e.g. non‑standard filesystem types or blkid failures).  We
// apply the same filtering rules so we don't offer already‑mounted or
// special devices.  On some systems sudoers may not allow `lvs` without a
// path or the output may be empty; warn in that case.
$lvPaths = run_cmd('sudo /usr/sbin/lvs --noheadings -o lv_path');
if (count($lvPaths) === 0) {
    $message .= '<br><strong>debug:</strong> `lvs` produced no output; check sudoers entry for lvs or binary path.';
}
// now that we have lvPaths, optionally dump debug data
if (!empty($_GET['debug'])) {
    $message .= '<br><strong>debug arrays:</strong>' .
                '<br>rootSrc=' . htmlspecialchars(json_encode($rootSrc)) .
                '<br>fsDevices=' . htmlspecialchars(json_encode($fsDevices)) .
                '<br>mountSet=' . htmlspecialchars(json_encode($mountSet)) .
                '<br>canonicalMounted=' . htmlspecialchars(json_encode($canonicalMounted)) .
                '<br>osRoot=' . htmlspecialchars($osRoot) .
                '<br>canonicalOsRoot=' . htmlspecialchars($canonicalOsRoot) .
                '<br>candidates=' . htmlspecialchars(json_encode($candidates)) .
                '<br>lvPaths=' . htmlspecialchars(json_encode($lvPaths));
}
foreach ($lvPaths as $lv) {
    $lv = trim($lv);
    if ($lv === '') continue;
    // canonicalize for comparisons
    $canonLvLines = run_cmd("readlink -f " . escapeshellarg($lv));
    $lvcanon = trim($canonLvLines[0] ?? $lv);
    // skip duplicates of already-added candidates (canonical comparison)
    if (in_array($lvcanon, $canonicalSeen, true)) continue;
    // skip ones already mounted (check both raw and canonical forms)
    if (in_array($lv, $mountSet, true) || in_array($lvcanon, $canonicalMounted, true)) continue;
    // apply same root filtering using canonical paths
    if ($canonicalOsRoot !== '' && strpos($lvcanon, $canonicalOsRoot) === 0) continue;
    // ignore swap/empty filesystems even if blkid failed earlier
    $typeLines = run_cmd("sudo blkid -s TYPE -o value " . escapeshellarg($lvcanon));
    $type = trim($typeLines[0] ?? '');
    if ($type === '' || $type === 'swap') {
        continue;
    }
    $skip = false;
    foreach ($pvSet as $pv) {
        if ($pv === '') continue;
        if ($lv === $pv || strpos($lv, $pv) === 0 || $lvcanon === $pv || strpos($lvcanon, $pv) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;
    foreach ($mdmembers as $mm) {
        if ($lv === $mm || strpos($lv, $mm) === 0 || $lvcanon === $mm || strpos($lvcanon, $mm) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;
    // determine display name same way as above
    $display = $lv;
    if (strpos($lvcanon, '/dev/mapper/') === 0) {
        $display = $lvcanon;
    } elseif (preg_match('#^/dev/dm-(\d+)$#', $lv, $m)) {
        $sysname = "/sys/block/dm-$m[1]/dm/name";
        if (file_exists($sysname)) {
            $name = trim(@file_get_contents($sysname));
            if ($name !== '') {
                $display = "/dev/mapper/$name";
            }
        }
    }
    $canonicalSeen[] = $lvcanon;
    $candidates[] = $display;
}

// deduplicate canonical candidates list (LV fallback may add duplicates)
$candidates = array_values(array_unique($candidates));

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
    if (preg_match('/^(\S+) on (' . preg_quote(mount_root(), '/') . '\S+) type (\S+) \(([^)]+)\)/', $m, $mm)) {
        $pt = $mm[2];
        $inFstab = false;
        foreach ($fstabLines as $line) {
            if (preg_match('/^\s*\S+\s+' . preg_quote($pt, '/') . '\s/', $line)) {
                $inFstab = true;
                break;
            }
        }
        // collect ownership and permissions
        $ownerName = '';
        $groupName = '';
        $perms = '';
        $setuid = 0;
        $setgid = 0;
        if (is_dir($pt) || is_file($pt)) {
            $stat = @stat($pt);
            if ($stat) {
                if (function_exists('posix_getpwuid')) {
                    $pw = posix_getpwuid($stat['uid']);
                    $ownerName = $pw['name'] ?? $stat['uid'];
                } else {
                    $ownerName = $stat['uid'];
                }
                if (function_exists('posix_getgrgid')) {
                    $gr = posix_getgrgid($stat['gid']);
                    $groupName = $gr['name'] ?? $stat['gid'];
                } else {
                    $groupName = $stat['gid'];
                }
                // if only numeric IDs remain, try to resolve them with getent
                if (ctype_digit((string)$ownerName)) {
                    $lookup = run_cmd("getent passwd " . intval($ownerName) . " | cut -d: -f1");
                    if (!empty($lookup) && trim($lookup[0]) !== '') {
                        $ownerName = trim($lookup[0]);
                    }
                }
                if (ctype_digit((string)$groupName)) {
                    $lookup = run_cmd("getent group " . intval($groupName) . " | cut -d: -f1");
                    if (!empty($lookup) && trim($lookup[0]) !== '') {
                        $groupName = trim($lookup[0]);
                    }
                }
                $perms = substr(sprintf('%o', $stat['mode']), -4);
                $setuid = ($stat['mode'] & 04000) ? 1 : 0;
                $setgid = ($stat['mode'] & 02000) ? 1 : 0;
            }
        }
        $mounts[] = ['dev' => $mm[1], 'pt' => $pt, 'opts' => $mm[4], 'fstab' => $inFstab,
                     'owner' => $ownerName, 'group' => $groupName,
                     'perms' => $perms, 'setuid' => $setuid, 'setgid' => $setgid];
    } elseif (preg_match('/^(\S+) on (' . preg_quote(mount_root(), '/') . '\S+)/', $m, $mm)) {
        $pt = $mm[2];
        $inFstab = false;
        foreach ($fstabLines as $line) {
            if (preg_match('/^\s*\S+\s+' . preg_quote($pt, '/') . '\s/', $line)) {
                $inFstab = true;
                break;
            }
        }
        $ownerName = '';
        $groupName = '';
        $perms = '';
        $setuid = 0;
        $setgid = 0;
        if (is_dir($pt) || is_file($pt)) {
            $stat = @stat($pt);
            if ($stat) {
                if (function_exists('posix_getpwuid')) {
                    $pw = posix_getpwuid($stat['uid']);
                    $ownerName = $pw['name'] ?? $stat['uid'];
                } else {
                    $ownerName = $stat['uid'];
                }
                if (function_exists('posix_getgrgid')) {
                    $gr = posix_getgrgid($stat['gid']);
                    $groupName = $gr['name'] ?? $stat['gid'];
                } else {
                    $groupName = $stat['gid'];
                }
                if (ctype_digit((string)$ownerName)) {
                    $lookup = run_cmd("getent passwd " . intval($ownerName) . " | cut -d: -f1");
                    if (!empty($lookup) && trim($lookup[0]) !== '') {
                        $ownerName = trim($lookup[0]);
                    }
                }
                if (ctype_digit((string)$groupName)) {
                    $lookup = run_cmd("getent group " . intval($groupName) . " | cut -d: -f1");
                    if (!empty($lookup) && trim($lookup[0]) !== '') {
                        $groupName = trim($lookup[0]);
                    }
                }
                $perms = substr(sprintf('%o', $stat['mode']), -4);
                $setuid = ($stat['mode'] & 04000) ? 1 : 0;
                $setgid = ($stat['mode'] & 02000) ? 1 : 0;
            }
        }
        $mounts[] = ['dev' => $mm[1], 'pt' => $pt, 'opts' => '', 'fstab' => $inFstab,
                     'owner' => $ownerName, 'group' => $groupName,
                     'perms' => $perms, 'setuid' => $setuid, 'setgid' => $setgid];
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
        <?php
            // mount_root() normalizes slashes and guarantees a leading slash;
            // show both the effective value and the raw configured string so
            // the administrator can verify that the right configuration file
            // was loaded (this is the most common reason the UI still displays
            // "/export").
            $cfgBase = cfg('mount_base', '/export');
            $displayRoot = htmlentities(mount_root(), ENT_QUOTES);
        ?>
        <h5>Existing <?= $displayRoot ?> mounts
            <small class="text-muted">(configured <?= htmlentities($cfgBase, ENT_QUOTES) ?>)</small>
        </h5>
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
                <th>Owner</th>
                <th>Perms</th>
                <th>Options</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mounts as $m): ?>
            <tr data-dev="<?php echo htmlspecialchars($m['dev']); ?>" data-pt="<?php echo htmlspecialchars($m['pt']); ?>" data-owner="<?php echo htmlspecialchars($m['owner'] ?? ''); ?>" data-group="<?php echo htmlspecialchars($m['group'] ?? ''); ?>" data-perms="<?php echo htmlspecialchars($m['perms'] ?? ''); ?>" data-setuid="<?php echo $m['setuid'] ? '1' : '0'; ?>" data-setgid="<?php echo $m['setgid'] ? '1' : '0'; ?>" data-opts="<?php echo htmlspecialchars($m['opts'] ?? ''); ?>" data-infstab="<?php echo $m['fstab'] ? '1' : '0'; ?>">
                <td><?php echo htmlspecialchars($m['dev']); ?></td>
                <td><?php echo htmlspecialchars($m['pt']); ?></td>
                <td><?php echo htmlspecialchars(($m['owner'] ?? '') . ':' . ($m['group'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($m['perms'] ?? ''); ?><?php if(!empty($m['setuid'])) echo ' (u+s)'; ?><?php if(!empty($m['setgid'])) echo ' (g+s)'; ?></td>
                <td><?php echo htmlspecialchars($m['opts'] ?? ''); ?></td>
                <td>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="view" value="mounts">
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
            <input type="hidden" name="view" value="mounts">
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
                <label class="form-label">Mount point (subdir under <?= htmlentities(mount_root(), ENT_QUOTES) ?>)</label>
                <input name="mount_point" class="form-control" placeholder="myshare" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Owner</label>
                <input name="mount_owner" class="form-control" placeholder="user">
            </div>
            <div class="mb-3">
                <label class="form-label">Group</label>
                <input name="mount_group" class="form-control" placeholder="group">
            </div>
            <div class="mb-3">
                <label class="form-label">Permissions</label>
                <table class="table table-sm">
                  <thead><tr><th></th><th>Read</th><th>Write</th><th>Exec</th></tr></thead>
                  <tbody>
                    <tr><td>Owner</td>
                        <td><input type="checkbox" name="perm_own_r"></td>
                        <td><input type="checkbox" name="perm_own_w"></td>
                        <td><input type="checkbox" name="perm_own_x"></td>
                    </tr>
                    <tr><td>Group</td>
                        <td><input type="checkbox" name="perm_grp_r"></td>
                        <td><input type="checkbox" name="perm_grp_w"></td>
                        <td><input type="checkbox" name="perm_grp_x"></td>
                    </tr>
                    <tr><td>Other</td>
                        <td><input type="checkbox" name="perm_oth_r"></td>
                        <td><input type="checkbox" name="perm_oth_w"></td>
                        <td><input type="checkbox" name="perm_oth_x"></td>
                    </tr>
                  </tbody>
                </table>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="mount_setuid" id="mountSetuid">
                    <label class="form-check-label" for="mountSetuid">setuid</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="mount_setgid" id="mountSetgid">
                    <label class="form-check-label" for="mountSetgid">setgid</label>
                </div>
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
            <input type="hidden" name="view" value="mounts">
            <input type="hidden" name="device">
            <div class="mb-3">
                <label class="form-label">Mount point</label>
                <input name="mount_point" id="editMountPoint" class="form-control" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">Owner</label>
                <input name="mount_owner" id="editMountOwner" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">Group</label>
                <input name="mount_group" id="editMountGroup" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">Permissions</label>
                <table class="table table-sm">
                  <thead><tr><th></th><th>Read</th><th>Write</th><th>Exec</th></tr></thead>
                  <tbody>
                    <tr><td>Owner</td>
                        <td><input type="checkbox" name="perm_own_r" id="editPermOwnR"></td>
                        <td><input type="checkbox" name="perm_own_w" id="editPermOwnW"></td>
                        <td><input type="checkbox" name="perm_own_x" id="editPermOwnX"></td>
                    </tr>
                    <tr><td>Group</td>
                        <td><input type="checkbox" name="perm_grp_r" id="editPermGrpR"></td>
                        <td><input type="checkbox" name="perm_grp_w" id="editPermGrpW"></td>
                        <td><input type="checkbox" name="perm_grp_x" id="editPermGrpX"></td>
                    </tr>
                    <tr><td>Other</td>
                        <td><input type="checkbox" name="perm_oth_r" id="editPermOthR"></td>
                        <td><input type="checkbox" name="perm_oth_w" id="editPermOthW"></td>
                        <td><input type="checkbox" name="perm_oth_x" id="editPermOthX"></td>
                    </tr>
                  </tbody>
                </table>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="mount_setuid" id="editMountSetuid">
                    <label class="form-check-label" for="editMountSetuid">setuid</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="mount_setgid" id="editMountSetgid">
                    <label class="form-check-label" for="editMountSetgid">setgid</label>
                </div>
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
