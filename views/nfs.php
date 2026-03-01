<?php
// NFS exports management view. Authentication already handled.
$message = '';
$exportsPath = cfg('exports_file', '/etc/exports');

function read_exports(): array {
    global $exportsPath;
    if (!file_exists($exportsPath)) {
        return [];
    }
    return file($exportsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

/**
 * Turn raw /etc/exports lines into a flat list of entries.
 * Each entry has 'dir', 'client' and 'opts' keys.  Comments and empty
 * lines are skipped.
 */
function parse_export_lines(array $lines): array {
    $entries = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '#') === 0) {
            continue;
        }
        // split on whitespace; first token is the directory
        $parts = preg_split('/\s+/', $trim);
        if (count($parts) < 2) {
            continue;
        }
        $dir = array_shift($parts);
        foreach ($parts as $p) {
            $client = $p;
            $opts = '';
            if (preg_match('/^([^\(\s]+)\(([^)]+)\)$/', $p, $m)) {
                $client = $m[1];
                $opts = $m[2];
            }
            $entries[] = ['dir' => $dir, 'client' => $client, 'opts' => $opts];
        }
    }
    return $entries;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_export'])) {
        // support old-style raw line or structured form
        if (!empty($_POST['export_line'])) {
            $line = trim($_POST['export_line']);
        } elseif (!empty($_POST['export_dir']) && !empty($_POST['export_client'])) {
            $dir = trim($_POST['export_dir']);
            $client = trim($_POST['export_client']);
            $opts = [];
            // rw/ro radio
            if (!empty($_POST['opt_rw_ro'])) {
                $opts[] = $_POST['opt_rw_ro'];
            }
            $bools = ['noaccess','root_squash','no_root_squash','all_squash',
                      'sync','async','subtree_check','no_subtree_check',
                      'crossmnt','nohide','no_wdelay','wdelay'];
            foreach ($bools as $b) {
                if (!empty($_POST['opt_' . $b])) {
                    $opts[] = $b;
                }
            }
            if (!empty($_POST['opt_anonuid'])) {
                $opts[] = 'anonuid=' . intval($_POST['opt_anonuid']);
            }
            if (!empty($_POST['opt_anongid'])) {
                $opts[] = 'anongid=' . intval($_POST['opt_anongid']);
            }
            if (isset($_POST['opt_fsid']) && $_POST['opt_fsid'] !== '') {
                $v = trim($_POST['opt_fsid']);
                if (is_numeric($v)) {
                    $opts[] = 'fsid=' . intval($v);
                }
            }
            $line = $dir . ' ' . $client;
            if (!empty($opts)) {
                $line .= '(' . implode(',', $opts) . ')';
            }
        } else {
            $line = '';
        }

        if ($line !== '') {
            // optionally write a comment first
            if (!empty($_POST['export_comment'])) {
                $comment = trim($_POST['export_comment']);
                if ($comment !== '') {
                    $cEsc = escapeshellarg('# ' . $comment);
                    run_cmd("echo $cEsc | sudo -n tee -a $exportsPath >/dev/null");
                }
            }
            // append via sudo tee so www-data doesn't need write permission
            $esc = escapeshellarg($line);
            run_cmd("echo $esc | sudo -n tee -a $exportsPath >/dev/null");
            run_cmd('sudo -n exportfs -ra');
            $message = 'Export added.';
        }
    } elseif (isset($_POST['edit_export'])) {
        // update entire export line for a directory (remove any existing lines)
        if (!empty($_POST['replace_dir']) && !empty($_POST['new_line'])) {
            $dir = trim($_POST['replace_dir']);
            $newLine = trim($_POST['new_line']);
            $lines = read_exports();
            $new = [];
            foreach ($lines as $line) {
                $ltrim = trim($line);
                if ($ltrim === '' || strpos($ltrim, '#') === 0) {
                    $new[] = $line;
                    continue;
                }
                $parts = preg_split('/\s+/', $ltrim);
                if (count($parts) > 0 && $parts[0] === $dir) {
                    // skip existing export for this directory
                    continue;
                }
                $new[] = $line;
            }
            // append the updated line once at end
            $new[] = $newLine;
            $content = implode("\n", $new) . (count($new) ? "\n" : '');
            $cEsc = escapeshellarg($content);
            run_cmd("echo $cEsc | sudo -n tee $exportsPath >/dev/null");
            run_cmd('sudo -n exportfs -ra');
            $message = 'Export updated.';
        }
    } elseif (isset($_POST['remove_export'])) {
        // new-style removal carries directory + client + opts separately
        if (isset($_POST['remove_dir'], $_POST['remove_client'])) {
            $dir = trim($_POST['remove_dir']);
            $client = trim($_POST['remove_client']);
            $opts = trim($_POST['remove_opts'] ?? '');
            $spec = $client . ($opts !== '' ? "($opts)" : '');

            $lines = read_exports();
            $new = [];
            foreach ($lines as $line) {
                $orig = $line;
                $ltrim = trim($line);
                if ($ltrim === '') {
                    $new[] = $orig;
                    continue;
                }
                if (strpos($ltrim, '#') === 0) {
                    // comment line, just keep it for now
                    $new[] = $orig;
                    continue;
                }
                $parts = preg_split('/\s+/', $ltrim);
                if (count($parts) === 0) {
                    continue;
                }
                $thisdir = array_shift($parts);
                if ($thisdir !== $dir) {
                    $new[] = $orig;
                    continue;
                }
                // same directory: remove the single client spec
                $remaining = [];
                foreach ($parts as $p) {
                    if ($p === $spec) {
                        continue;
                    }
                    $remaining[] = $p;
                }
                if (count($remaining) > 0) {
                    // keep the line with remaining clients
                    $new[] = $thisdir . ' ' . implode(' ', $remaining);
                } else {
                    // dropped the entire export line; also drop preceding comment if any
                    if (!empty($new)) {
                        $last = end($new);
                        if (trim($last) !== '' && strpos(trim($last), '#') === 0) {
                            array_pop($new);
                        }
                    }
                }
            }
            $content = implode("\n", $new) . (count($new) ? "\n" : '');
            // rewrite using sudo tee (overwrite)
            $cEsc = escapeshellarg($content);
            run_cmd("echo $cEsc | sudo -n tee $exportsPath >/dev/null");
            run_cmd('sudo -n exportfs -ra');
            $message = 'Export removed.';
        } else {
            // fallback to old behaviour (remove entire line). use sudo/tee instead of
            // writing directly in case www-data can't open /etc/exports itself.
            $toRemove = trim($_POST['remove_export']);
            $lines = read_exports();
            $new = [];
            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '') {
                    $new[] = $line;
                    continue;
                }
                if (strpos($trim, '#') === 0) {
                    // keep comments for now
                    $new[] = $line;
                    continue;
                }
                if ($trim === $toRemove) {
                    // drop this export line and any comment immediately before it
                    if (!empty($new)) {
                        $last = end($new);
                        if (trim($last) !== '' && strpos(trim($last), '#') === 0) {
                            array_pop($new);
                        }
                    }
                    continue;
                }
                $new[] = $line;
            }
            $content = implode("\n", $new) . "\n";
            $cEsc = escapeshellarg($content);
            run_cmd("echo $cEsc | sudo -n tee $exportsPath >/dev/null");
            run_cmd('sudo -n exportfs -ra');
            $message = 'Export removed.';
        }
    }
}

// helper to run a command in the host mount namespace if nsenter is installed
$nsenterAvailable = file_exists('/usr/bin/nsenter');
function nsCmd($cmd) {
    global $nsenterAvailable;
    if ($nsenterAvailable) {
        return "sudo /usr/bin/nsenter -t 1 -m $cmd";
    }
    return $cmd;
}
// gather list of candidate directories under the configured mount base
// that are actual mount points
$exportDirs = [];

$root = mount_root();
$onRegex = '# on ' . preg_quote($root, '#') . 's?(?:/|$)#';
$all = run_cmd(nsCmd("mount"));
$mnts = array_values(array_filter($all, fn($l) => preg_match($onRegex, $l)));
foreach ($mnts as $m) {
    // capture the mount path under the configured root
    if (preg_match('# on (' . preg_quote(mount_root(), '#') . '(?:s?/\S+)?)#', $m, $mm)) {
        $exportDirs[] = $mm[1];
    }
}
// filter out directories that already appear in /etc/exports
$rawExports = read_exports();
$already = [];
foreach ($rawExports as $line) {
    $trim = trim($line);
    if ($trim === '' || strpos($trim, '#') === 0) continue;
    $parts = preg_split('/\s+/', $trim);
    if (count($parts) > 0) {
        $already[] = $parts[0];
    }
}
$exportDirs = array_unique($exportDirs);
$exportDirs = array_filter($exportDirs, function($d) use ($already) {
    return !in_array($d, $already, true);
});
sort($exportDirs);

$rawExports = read_exports();
$entries = parse_export_lines($rawExports);

// compute mounted device for each exported directory
// note: findmnt can return network sources (nfs server:path) if the
// export directory itself lives on an NFS mount (e.g. rootfs on NFS).  we
// only display a value when the source looks like a local block device; if
// not, leave it blank (the wrong value was showing up on some systems).
$deviceMap = [];
foreach ($entries as $e) {
    $d = $e['dir'];
    if (!isset($deviceMap[$d])) {
        $dev = '';
        $out = run_cmd("findmnt -n -o SOURCE --target " . escapeshellarg($d));
        if (!empty($out)) {
            $cand = trim($out[0]);
            if (strpos($cand, '/dev/') === 0) {
                $dev = $cand;
            } else {
                // fallback to df in case findmnt returned something odd
                $df = run_cmd("df -P " . escapeshellarg($d) . " | tail -1 | awk '{print $1}'");
                if (!empty($df)) {
                    $dfcand = trim($df[0]);
                    if (strpos($dfcand, '/dev/') === 0) {
                        $dev = $dfcand;
                    }
                }
            }
        }
        $deviceMap[$d] = $dev;
    }
}

// capture comments preceding each export line
$comments = [];
$lastComment = '';
foreach ($rawExports as $line) {
    $trim = trim($line);
    if ($trim === '') {
        $lastComment = '';
        continue;
    }
    if (strpos($trim, '#') === 0) {
        // store without leading '#'
        $lastComment = trim(substr($trim, 1));
        continue;
    }
    // export line
    $parts = preg_split('/\s+/', $trim);
    if (count($parts) > 0) {
        $d = $parts[0];
        if ($lastComment !== '') {
            $comments[$d] = $lastComment;
        }
    }
    $lastComment = '';
}

// group entries by directory for simpler display
$grouped = [];
foreach ($entries as $e) {
    $d = $e['dir'];
    if (!isset($grouped[$d])) {
        $grouped[$d] = ['device' => $deviceMap[$d] ?? '', 'clients' => [], 'comment' => $comments[$d] ?? ''];
    }
    $grouped[$d]['clients'][] = ['client' => $e['client'], 'opts' => $e['opts']];
}
?>

<?php if ($message): ?>
    <div id="initialMessage" class="d-none"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row mb-3 align-items-center">
    <div class="col">
        <h5>Current exports</h5>
    </div>
    <div class="col text-end">
        <button id="btnShowExportModal" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createExportModal">Create export</button>
    </div>
</div>

<?php if (count($grouped) > 0): ?>
<table class="table table-sm table-hover" id="exportsTable">
    <thead>
        <tr>
            <th>Directory</th>
            <th>Device</th>
            <th>Comment</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($grouped as $dir => $info): ?>
        <tr data-dir="<?php echo htmlspecialchars($dir); ?>" class="clickable">
            <td><?php echo htmlspecialchars($dir); ?></td>
            <td><?php echo htmlspecialchars($info['device']); ?></td>
            <td><?php echo htmlspecialchars($info['comment']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
// expose export data for client-side editing
var exportClients = <?php echo json_encode($grouped, JSON_HEX_TAG|JSON_HEX_AMP); ?>;
</script>
<?php else: ?>
    <p>No exports defined.</p>
<?php endif; ?>

<!-- edit export modal -->
<div class="modal fade" id="editExportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Edit export <span id="editExportDir"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <form id="editExportForm" method="post">
            <input type="hidden" name="replace_dir" id="replace_dir">
            <input type="hidden" name="new_line" id="new_line">
            <input type="hidden" name="remove_export" id="remove_export">
            <input type="hidden" name="orig_line" id="orig_line">
            <div class="mb-3">
                <label class="form-label">Comment</label>
                <div id="editComment" class="form-control-plaintext"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Clients / options</label>
                <table class="table table-sm" id="editClientTable">
                    <thead>
                        <tr><th>Client</th><th>Options</th><th></th></tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
                <button type="button" id="addEditClientBtn" class="btn btn-sm btn-secondary">+ Add client</button>
            </div>
            <div class="text-end">
                <button name="edit_export" type="submit" class="btn btn-primary">Save</button>
                <button type="button" id="deleteExportBtn" class="btn btn-danger ms-2">Delete</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
            </div>
      </form>
    </div>
   </div>
  </div>
</div>

<!-- create export modal -->
<div class="modal fade" id="createExportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Add new export</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <form id="createExportForm" method="post">
            <div class="mb-3">
                <label class="form-label">Comment (optional)</label>
                <input name="export_comment" class="form-control" placeholder="Add a comment line">
            </div>
            <div class="mb-3">
                <label class="form-label">Directory to export</label>
                <select name="export_dir" class="form-select" required>
                    <option value="">(select)</option>
                    <?php foreach ($exportDirs as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Clients / options</label>
                <table class="table table-sm" id="clientTable">
                    <thead>
                        <tr><th>Client</th><th>Options</th><th></th></tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
                <button type="button" id="addClientBtn" class="btn btn-sm btn-secondary">+ Add client</button>
            </div>
            <input type="hidden" name="export_line" id="export_line">
            <div class="text-end">
                <button name="add_export" type="submit" class="btn btn-primary">Create</button>
                <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
            </div>
      </form>
    </div>
   </div>
  </div>
</div>

<!-- client entry modal -->
<div class="modal fade" id="clientEntryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Client entry</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <form id="clientForm">
        <div class="mb-3">
          <label class="form-label" for="clientAddr">Client (host or subnet)</label>
          <input type="text" id="clientAddr" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Options</label>
          <div class="row">
            <div class="col-6 mb-2">
              <label class="form-label" for="opt_rw_ro">Read/write</label>
              <select id="opt_rw_ro" class="form-select small-select">
                <option value=""></option>
                <option value="rw">rw</option>
                <option value="ro">ro</option>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="opt_squash">Squash</label>
              <select id="opt_squash" class="form-select small-select">
                <option value=""></option>
                <option value="root_squash">root-squash</option>
                <option value="no_root_squash">no-root-squash</option>
                <option value="all_squash">all-squash</option>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="opt_sync">Sync</label>
              <select id="opt_sync" class="form-select small-select">
                <option value=""></option>
                <option value="sync">sync</option>
                <option value="async">async</option>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="opt_subtree">Subtree</label>
              <select id="opt_subtree" class="form-select small-select">
                <option value=""></option>
                <option value="subtree_check">subtree-check</option>
                <option value="no_subtree_check">no-subtree-check</option>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="opt_wdelay">Write delay</label>
              <select id="opt_wdelay" class="form-select small-select">
                <option value=""></option>
                <option value="wdelay">wdelay</option>
                <option value="no_wdelay">no-wdelay</option>
              </select>
            </div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="opt_noaccess" value="noaccess">
            <label class="form-check-label" for="opt_noaccess">noaccess</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="opt_crossmnt" value="crossmnt">
            <label class="form-check-label" for="opt_crossmnt">crossmnt</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="opt_nohide" value="nohide">
            <label class="form-check-label" for="opt_nohide">nohide</label>
          </div>
          <hr />
          <div class="row">
            <div class="col">
              <label class="form-label" for="opt_anonuid">anonuid</label>
              <input type="number" min="0" class="form-control" id="opt_anonuid">
            </div>
            <div class="col">
              <label class="form-label" for="opt_anongid">anongid</label>
              <input type="number" min="0" class="form-control" id="opt_anongid">
            </div>
            <div class="col">
              <label class="form-label" for="opt_fsid">fsid</label>
              <input type="number" min="0" class="form-control" id="opt_fsid">
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">OK</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
      </form>
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

