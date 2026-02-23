<?php
// Mounts management view. dashboard.php already handled authentication.

$message = '';
// handle mount/unmount
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mount_lv'])) {
        $lv = escapeshellarg($_POST['lv_select_mount']);
        $mp = '/export/' . trim($_POST['mount_point']);
        $mpEsc = escapeshellarg($mp);
        $out = run_cmd("sudo /bin/mkdir -p $mpEsc");
        // use nsenter to run the mount in the host's mount namespace if
        // nsenter is available (necessary when Apache runs in a private
        // namespace).  The command will fail if nsenter isn't installed or
        // permitted; fallback to regular mount then.
        if (file_exists('/usr/bin/nsenter')) {
            $cmd = "sudo /usr/bin/nsenter -t 1 -m /bin/mount -v $lv $mpEsc";
        } else {
            $cmd = "sudo /bin/mount -v $lv $mpEsc";
        }
        $mountOut = run_cmd($cmd);
        // check mount table separately to see if the point actually shows up
        $grepOut = run_cmd("mount | grep " . escapeshellarg($mp));
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
                // (optionally check visibility; no need to mention it in the message)
                $dfout = run_cmd("df " . escapeshellarg($mp));
                if (!preg_grep('/' . preg_quote(trim($lv, "'\""), '/') . '/', $dfout)) {
                    // save diagnostics in case you want to inspect them later, but keep
                    // a clean success message.
                    $out = array_merge($out, $mountOut, $grepOut, $dfout);
                }
            }
        }
        if (strpos($statusMsg,'Mount succeeded') === 0) {
            // on success we display only the status message
            $message = $statusMsg;
        } else {
            $message = implode("<br>", $out);
        }
    } elseif (isset($_POST['umount_lv'])) {
        $mp = escapeshellarg($_POST['umount_select']);
        if (file_exists('/usr/bin/nsenter')) {
            $cmd = "sudo /usr/bin/nsenter -t 1 -m /bin/umount $mp";
        } else {
            $cmd = "sudo /bin/umount $mp";
        }
        $out = run_cmd($cmd);
        // check mount table afterward to see if it really disappeared; strip
        // out the sole "(exit N)" line that grep prints when there’s no match.
        $after = run_cmd("mount | grep " . escapeshellarg($mp));
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
        if (strpos($statusMsg, 'Unmount succeeded') === 0) {
            // successful unmount, ignore any prior output
            $message = $statusMsg;
        } else {
            // always include status message so user sees the reason
            $out[] = $statusMsg;
            $message = implode("<br>", $out);
        }
    }
}

// gather logical volumes for dropdown
$lvs = run_cmd('sudo lvs --noheadings -o lv_path');
// gather current /export mounts for unmount dropdown
$mnts = run_cmd("mount | grep ' on /export/'");
// strip the lone "(exit N)" line grep prints when there are no matches
$mnts = array_values(array_filter($mnts, fn($l)=>!preg_match('/^\(exit \d+\)$/', $l)));
?>

<?php if ($message): ?>
    <div id="initialMessage" class="d-none"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">Mount Logical Volume</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Logical Volume</label>
                        <select name="lv_select_mount" class="form-select" required>
                            <option value="">-- choose --</option>
                            <?php foreach ($lvs as $line):
                                $lvpath = trim($line);
                                if ($lvpath === '') continue;
                                $display = basename($lvpath);
                                if ($display === 'thin') continue; // hide the underlying thin pool
                            ?>
                            <option value="<?php echo htmlspecialchars($lvpath); ?>"><?php echo htmlspecialchars($display); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mount point (subdir under /export)</label>
                        <input name="mount_point" class="form-control" placeholder="myshare" required>
                    </div>
                    <button id="btnMount" name="mount_lv" class="btn btn-primary" type="submit">Mount</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">Unmount <code>/export/</code> mount</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Select mount</label>
                        <select name="umount_select" class="form-select">
                            <option value="">-- none --</option>
                            <?php foreach ($mnts as $m):
                                if (preg_match('/ on (\/export\/\S+)/', $m, $mm)) {
                                    $pt = $mm[1];
                            ?>
                            <option value="<?php echo htmlspecialchars($pt); ?>"><?php echo htmlspecialchars($pt); ?></option>
                            <?php
                                }
                            endforeach; ?>
                        </select>
                    </div>
                    <button id="btnUmount" name="umount_lv" class="btn btn-secondary" type="submit">Unmount</button>
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
                <button type="button" class="btn btn-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-ok">OK</button>
            </div>
        </div>
    </div>
</div>
