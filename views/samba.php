<?php
// Samba shares management view. Authentication already handled in dashboard.php
$message = '';
$smbConf = '/etc/samba/smb.conf';

function read_smb_conf(): array {
    global $smbConf;
    if (!file_exists($smbConf)) {
        return [];
    }
    return file($smbConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

/**
 * Parse smb.conf lines and return an associative array of shares.  The
 * returned structure maps share name -> option array (path, read only, etc).
 * The global section is ignored.  Comments and blank lines are skipped.
 */
function parse_shares(array $lines): array {
    $shares = [];
    $current = '';
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '#') === 0 || strpos($trim, ';') === 0) {
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]/', $trim, $m)) {
            $sec = $m[1];
            if (strcasecmp($sec, 'global') === 0) {
                $current = '';
            } else {
                $current = $sec;
                $shares[$current] = [];
            }
            continue;
        }
        if ($current) {
            if (preg_match('/^([^=]+)=(.*)$/', $trim, $m2)) {
                $shares[$current][trim($m2[1])] = trim($m2[2]);
            }
        }
    }
    return $shares;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_share'])) {
        $name = trim($_POST['share_name'] ?? '');
        $path = trim($_POST['share_path'] ?? '');
        $comment = trim($_POST['share_comment'] ?? '');
        if ($name !== '' && $path !== '') {
            $lines = read_smb_conf();
            $shares = parse_shares($lines);
            if (isset($shares[$name])) {
                $message = 'Share already exists.';
            } else {
                $section = "\n";
                if ($comment !== '') {
                    $section .= '# ' . $comment . "\n";
                }
                $section .= "[{$name}]\n\tpath = {$path}\n\tread only = no\n";
                // include any options provided by the user
                if (!empty($_POST['opt_name']) && is_array($_POST['opt_name'])) {
                    foreach ($_POST['opt_name'] as $idx => $optname) {
                        $optname = trim($optname);
                        if ($optname === '') continue;
                        $optval = trim($_POST['opt_val'][$idx] ?? '');
                        if ($optval !== '') {
                            $section .= "\t{$optname} = {$optval}\n";
                        } else {
                            $section .= "\t{$optname}\n";
                        }
                    }
                }
                $esc = escapeshellarg($section);
                run_cmd("echo $esc | sudo -n tee -a $smbConf >/dev/null");
                run_cmd('sudo -n systemctl restart smbd');
                $message = 'Share added.';
            }
        }
    } elseif (isset($_POST['edit_share'])) {
        $old = trim($_POST['old_share'] ?? '');
        $newName = trim($_POST['share_name'] ?? '');
        $newPath = trim($_POST['share_path'] ?? '');
        $newComment = trim($_POST['share_comment'] ?? '');
        if ($old !== '' && $newName !== '' && $newPath !== '') {
            $lines = read_smb_conf();
            $shares = parse_shares($lines);
            if ($newName !== $old && isset($shares[$newName])) {
                $message = 'Another share already uses that name.';
            } else {
                $new = [];
                $skip = false;
                foreach ($lines as $line) {
                $trim = trim($line);
                if ($skip) {
                    if (preg_match('/^\s*\[[^\]]+\]\s*$/', $trim)) {
                        // section ended
                        $skip = false;
                    }
                    continue;
                }
                if (preg_match('/^\s*\[' . preg_quote($old, '/') . '\]\s*$/', $trim)) {
                    // drop a single comment line immediately above the header (if present)
                    if (!empty($new)) {
                        $lastLine = $new[count($new)-1];
                        if (preg_match('/^\s*[#;]/', trim($lastLine))) {
                            array_pop($new);
                        }
                    }
                    $skip = true;
                    continue;
                }
                $new[] = $line;
            }
            // append updated section
            if ($newComment !== '') {
                $new[] = '# ' . $newComment;
            }
            $new[] = '[' . $newName . ']';
            $new[] = "\tpath = " . $newPath;
            // include any options submitted
            $optsArr = [];
            if (!empty($_POST['opt_name']) && is_array($_POST['opt_name'])) {
                foreach ($_POST['opt_name'] as $idx => $optname) {
                    $optname = trim($optname);
                    if ($optname === '') continue;
                    $optval = trim($_POST['opt_val'][$idx] ?? '');
                    if ($optval !== '') {
                        $optsArr[] = $optname . ' = ' . $optval;
                    } else {
                        $optsArr[] = $optname;
                    }
                }
            }
            if (!empty($optsArr)) {
                foreach ($optsArr as $o) {
                    $new[] = "\t" . $o;
                }
            }
            // default to rw if not supplied
            $hasRo = false;
            foreach ($optsArr as $o) {
                if (preg_match('/^read\s+only\s*=/', $o)) {
                    $hasRo = true;
                    break;
                }
            }
            if (!$hasRo) {
                $new[] = "\tread only = no";
            }
            $content = implode("\n", $new) . "\n";
            $cEsc = escapeshellarg($content);
            run_cmd("echo $cEsc | sudo -n tee $smbConf >/dev/null");
            run_cmd('sudo -n systemctl restart smbd');
            $message = 'Share updated.';
            }
        }
    } elseif (isset($_POST['remove_share'])) {
        $name = trim($_POST['remove_share_name'] ?? '');
        if ($name !== '') {
            $lines = read_smb_conf();
            $new = [];
            $skip = false;
            foreach ($lines as $line) {
                if (preg_match('/^\s*\[' . preg_quote($name, '/') . '\]\s*$/', trim($line))) {
                    // remove a single comment line immediately above this header
                    if (!empty($new)) {
                        $last = array_pop($new);
                        if (!preg_match('/^\s*[#;]/', trim($last))) {
                            // not a comment, put it back
                            $new[] = $last;
                        }
                    }
                    $skip = true;
                    continue;
                }
                if ($skip && preg_match('/^\s*\[[^\]]+\]\s*$/', $line)) {
                    // section ended
                    $skip = false;
                }
                if (!$skip) {
                    $new[] = $line;
                }
            }
            $content = implode("\n", $new) . "\n";
            $cEsc = escapeshellarg($content);
            run_cmd("echo $cEsc | sudo -n tee $smbConf >/dev/null");
            run_cmd('sudo -n systemctl restart smbd');
            $message = 'Share removed.';
        }
    }
}

// list shares for display
$raw = read_smb_conf();
$shares = parse_shares($raw);

// capture comments preceding a share section (same strategy as NFS view)
$comments = [];
$lastComment = '';
foreach ($raw as $line) {
    $trim = trim($line);
    if ($trim === '') {
        $lastComment = '';
        continue;
    }
    if (strpos($trim, '#') === 0 || strpos($trim, ';') === 0) {
        // strip leading comment character
        $lastComment = trim(substr($trim, 1));
        continue;
    }
    if (preg_match('/^\[([^\]]+)\]/', $trim, $m)) {
        $name = $m[1];
        if ($lastComment !== '') {
            $comments[$name] = $lastComment;
        }
    }
    $lastComment = '';
}

// gather list of candidate directories under /export that are actual mount points
// so we can offer them in the create/edit dialogs; reuse helpers from nfs view
$shareDirs = [];
$nsenterAvailable = file_exists('/usr/bin/nsenter');
function nsCmdSamba($cmd) {
    global $nsenterAvailable;
    if ($nsenterAvailable) {
        return "sudo /usr/bin/nsenter -t 1 -m $cmd";
    }
    return $cmd;
}
$mnts = run_cmd(nsCmdSamba("mount | grep ' on /export/'"));
$mnts = array_values(array_filter($mnts, fn($l)=>!preg_match('/^\(exit \d+\)$/',$l)));
foreach ($mnts as $m) {
    if (preg_match('/ on (\/export\/\S+)/', $m, $mm)) {
        $shareDirs[] = $mm[1];
    }
}
// remove directories already shared
$already = array_keys($shares);
$shareDirs = array_unique($shareDirs);
$shareDirs = array_filter($shareDirs, function($d) use ($shares) {
    foreach ($shares as $opts) {
        if (isset($opts['path']) && $opts['path'] === $d) {
            return false;
        }
    }
    return true;
});
sort($shareDirs);
?>

<?php if ($message): ?>
    <div id="initialMessage" class="d-none"><?php echo $message; ?></div>
<?php endif; ?>

<?php
// list of common Samba share options for the pulldown; clients can still type
// custom values thanks to the datalist element.  note that path is
// managed by the main form and therefore is excluded from this list.
$allowedSmbOpts = [
    'read only','guest ok','browseable','valid users','writeable',
    'force user','force group','create mask','directory mask',
    'vfs objects','comment'
];
?>
<datalist id="shareOptionNames">
<?php foreach ($allowedSmbOpts as $opt): ?>
    <option value="<?php echo htmlspecialchars($opt); ?>">
<?php endforeach; ?>
</datalist>

<div class="row mb-3 align-items-center">
    <div class="col">
        <h5>Configured Shares</h5>
    </div>
    <div class="col text-end">
        <button id="btnShowShareModal" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createShareModal">Create share</button>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header">Configured Shares</div>
    <div class="card-body">
        <?php if (empty($shares)): ?>
            <p>No Samba shares defined.</p>
        <?php else: ?>
            <table class="table table-sm" id="sambaTable">
                <thead><tr><th>Name</th><th>Path</th><th>Comment</th></tr></thead>
                <tbody>
                <?php foreach ($shares as $name => $opts): ?>
                    <tr data-share="<?php echo htmlspecialchars($name); ?>">
                        <td><?php echo htmlspecialchars($name); ?></td>
                        <td><?php echo htmlspecialchars($opts['path'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($comments[$name] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- create share modal (structure similar to NFS create) -->
<div class="modal fade" id="createShareModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Add new share</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <form id="createShareForm" method="post">
            <div class="mb-3">
                <label class="form-label">Comment (optional)</label>
                <input name="share_comment" class="form-control" placeholder="Add a comment line">
            </div>
            <div class="mb-3">
                <label class="form-label">Share name</label>
                <input type="text" name="share_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Path</label>
                <select name="share_path" id="share_path" class="form-control" required>
                    <?php foreach ($shareDirs as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Options</label>
                <table class="table table-sm shareOptionTable">
                    <thead><tr><th>Option</th><th>Value</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="addOptionBtn btn btn-sm btn-secondary">+ Add option</button>
            </div>
            <div class="text-end">
                <button type="submit" class="btn btn-primary" name="add_share">Create</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
            </div>
      </form>
    </div>
   </div>
  </div>
</div>

<script>
// expose samba data for client-side editing
var sambaShares = <?php
    $jsShares = [];
    foreach ($shares as $name => $opts) {
        $jsShares[$name] = $opts;
        $jsShares[$name]['comment'] = $comments[$name] ?? '';
    }
    echo json_encode($jsShares, JSON_HEX_TAG|JSON_HEX_AMP);
?>;
var sambaDirs = <?php echo json_encode($shareDirs, JSON_HEX_TAG|JSON_HEX_AMP); ?>;
</script>

<!-- edit share modal -->
<div class="modal fade" id="editShareModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Edit share <span id="editShareName"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <form id="editShareForm" method="post">
            <input type="hidden" name="old_share" id="old_share">
            <div class="mb-3">
                <label class="form-label">Comment (optional)</label>
                <input name="share_comment" id="editShareComment" class="form-control" placeholder="Add a comment line">
            </div>
            <div class="mb-3">
                <label class="form-label">Share name</label>
                <input type="text" name="share_name" id="editShareNameInput" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Path</label>
                <select name="share_path" id="editSharePath" class="form-control" required>
                    <?php foreach ($shareDirs as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Options</label>
                <table class="table table-sm shareOptionTable">
                    <thead><tr><th>Option</th><th>Value</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="addOptionBtn btn btn-sm btn-secondary">+ Add option</button>
            </div>
            <div class="text-end">
                <button name="edit_share" type="submit" class="btn btn-primary">Save</button>
                <button type="button" id="deleteShareBtn" class="btn btn-danger ms-2">Delete</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
            </div>
      </form>
    </div>
   </div>
  </div>
</div>

<?php
// retain existing remove-share form/button handling still above
?>

<!-- generic result modal used by JS -->
<div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="1">
  <div class="modal-dialog">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Result</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body"></div>
    <div class="modal-footer">
       <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
    </div>
   </div>
  </div>
</div>

<!-- existing confirmation modal (used by JS) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Notice</h5>
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
