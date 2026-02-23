<?php
// NFS exports management view. Authentication already handled.
$message = '';
$exportsPath = '/etc/exports';

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
            run_cmd('exportfs -ra');
            $message = 'Export added.';
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
                if ($ltrim === '' || strpos(ltrim($ltrim), '#') === 0) {
                    // preserve comments/blank lines
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
                // remove matching spec
                $remaining = [];
                foreach ($parts as $p) {
                    if ($p === $spec) {
                        continue;
                    }
                    $remaining[] = $p;
                }
                if (count($remaining) > 0) {
                    $new[] = $thisdir . ' ' . implode(' ', $remaining);
                }
                // if no remaining clients, drop whole line
            }
            $content = implode("\n", $new) . (count($new) ? "\n" : '');
            // rewrite using sudo tee (overwrite)
            $cEsc = escapeshellarg($content);
            run_cmd("echo $cEsc | sudo -n tee $exportsPath >/dev/null");
            run_cmd('exportfs -ra');
            $message = 'Export removed.';
        } else {
            // fallback to old behaviour (remove entire line)
            $toRemove = trim($_POST['remove_export']);
            $lines = read_exports();
            $new = array_filter($lines, function($l) use ($toRemove) {
                return trim($l) !== $toRemove;
            });
            file_put_contents($exportsPath, implode("\n", $new) . "\n");
            run_cmd('exportfs -ra');
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
// gather list of candidate directories under /export that are actual mount points
$exportDirs = [];
$mnts = run_cmd(nsCmd("mount | grep ' on /export/'"));
// strip any solitary "(exit N)" lines emitted by grep
$mnts = array_values(array_filter($mnts, fn($l)=>!preg_match('/^\(exit \d+\)$/',$l)));
foreach ($mnts as $m) {
    if (preg_match('/ on (\/export\/\S+)/', $m, $mm)) {
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
$deviceMap = [];
foreach ($entries as $e) {
    $d = $e['dir'];
    if (!isset($deviceMap[$d])) {
        $out = run_cmd("findmnt -n -o SOURCE --target " . escapeshellarg($d));
        $deviceMap[$d] = !empty($out) ? trim($out[0]) : '';
    }
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

<?php if (count($entries) > 0): ?>
<table class="table table-sm table-hover" id="exportsTable">
    <thead>
        <tr>
            <th>Directory</th>
            <th>Device</th>
            <th>Client</th>
            <th>Options</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td><?php echo htmlspecialchars($e['dir']); ?></td>
            <td><?php echo htmlspecialchars($deviceMap[$e['dir']]); ?></td>
            <td><?php echo htmlspecialchars($e['client']); ?></td>
            <td><?php echo htmlspecialchars($e['opts']); ?></td>
            <td>
                <form method="post" class="m-0">
                    <input type="hidden" name="remove_dir" value="<?php echo htmlspecialchars($e['dir']); ?>">
                    <input type="hidden" name="remove_client" value="<?php echo htmlspecialchars($e['client']); ?>">
                    <input type="hidden" name="remove_opts" value="<?php echo htmlspecialchars($e['opts']); ?>">
                    <button name="remove_export" class="btn btn-sm btn-danger btn-remove-export" type="submit">Remove</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
    <p>No exports defined.</p>
<?php endif; ?>

<!-- create export modal -->
<div class="modal fade" id="createExportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
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
            <button name="add_export" type="submit" class="btn btn-primary">Create</button>
      </form>
    </div>
   </div>
  </div>
</div>

<!-- client entry modal -->
<div class="modal fade" id="clientEntryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
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
          <label class="form-label" for="clientOpts">Options</label>
          <input type="text" id="clientOpts" class="form-control" placeholder="e.g. rw,sync">
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
       <button type="button" class="btn btn-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button>
       <button type="button" class="btn btn-primary btn-ok">OK</button>
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
