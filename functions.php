<?php
// Shared utilities for authentication and command execution

// show any PHP errors so login problems are visible immediately
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

// hold a human-readable error from the last authentication attempt
$lastAuthError = '';

/**
 * Authenticate a system user using /etc/shadow via getent.
 * Returns true on success, false otherwise.  On failure a message
 * is stored in global $lastAuthError which callers can inspect.
 */
function authenticate(string $user, string $password): bool
{
    global $lastAuthError;

    // getent shadow requires root privileges. we run via sudo so the
    // webserver user can be granted just this command.  Always use absolute
    // paths (sudo may have a restricted PATH) and log the command output for
    // troubleshooting.
    $escaped = escapeshellarg($user);
    $cmd = "/usr/bin/sudo /usr/bin/getent shadow $escaped";

    // execute and capture output
    $output = [];
    $status = null;
    exec($cmd . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $msg = date('[Y-m-d H:i:s] ') . "cmd=$cmd status=$status output=" .
               implode("|", $output) . "\n";
        // attempt to write to debug file, fall back to error_log if not writable
        if (@file_put_contents('/tmp/lvm_nfs_debug.log', $msg, FILE_APPEND) === false) {
            error_log("[lvm_nfs] failed to write debug log: $msg");
        }
        $lastAuthError = "getent failed (status $status) - check permissions or sudoers entry";
        // include the raw output for visibility
        if (!empty($output)) {
            $lastAuthError .= ' (output: ' . htmlspecialchars(implode(' | ', $output)) . ')';
        }
        return false;
    }
    if (count($output) === 0) {
        $lastAuthError = "user not found in shadow";
        return false;
    }

    // line looks like: username:hash:...
    $parts = explode(':', $output[0]);
    if (count($parts) < 2 || empty($parts[1])) {
        $lastAuthError = "no hash available in shadow entry";
        return false;
    }
    $hash = $parts[1];

    // verify using PHP's crypt()
    $computed = @crypt($password, $hash);
    if ($computed === $hash) {
        $_SESSION['user'] = $user;
        return true;
    }

    // if PHP failed (or produced a trivial value), try external helpers
    $helpers = [];

    // first try python3 if available
    $helpers[] = "/usr/bin/sudo /usr/bin/python3 -c \"import crypt,sys;print(crypt.crypt(sys.argv[1],sys.argv[2]))\"";
    // then try perl which has built-in crypt
    $helpers[] = "/usr/bin/sudo /usr/bin/perl -e \"print crypt(\$ARGV[0],\$ARGV[1])\"";

    $helperResults = [];
    foreach ($helpers as $helper) {
        $cmd = $helper . ' ' . escapeshellarg($password) . ' ' . escapeshellarg($hash);
        $out = [];
        $st = null;
        exec($cmd . ' 2>&1', $out, $st);
        $helperResults[] = [
            'cmd' => $cmd,
            'status' => $st,
            'output' => $out,
        ];
        if ($st === 0 && count($out) > 0 && trim($out[0]) === $hash) {
            $_SESSION['user'] = $user;
            return true;
        }
    }

    // if previous helpers didn't match, try pamtester if installed
    if (file_exists('/usr/bin/pamtester')) {
        // feed password on stdin
        $cmd = "/bin/sh -c " . escapeshellarg("printf '%s\\n' " . escapeshellarg($password) . " | sudo pamtester login " . escapeshellarg($user) . " authenticate");
        $out = [];
        $st = null;
        exec($cmd . ' 2>&1', $out, $st);
        $helperResults[] = [
            'cmd' => $cmd,
            'status' => $st,
            'output' => $out,
        ];
        // pamtester returns 0 on success
        if ($st === 0) {
            $_SESSION['user'] = $user;
            return true;
        }
    }

    // build failure message with details
    $lastAuthError = "password did not match";
    $lastAuthError .= ' (stored='.htmlspecialchars(substr($hash,0,20)).'..., computed='.htmlspecialchars(substr($computed,0,20)).'...)';
    // append helper diagnostic info
    foreach ($helperResults as $hr) {
        $lastAuthError .= ' [' . htmlspecialchars($hr['cmd']) . ' => status=' . $hr['status'] . ' output="' . htmlspecialchars(implode(' | ', $hr['output'])) . '"]';
    }
    return false;
}

function require_login()
{
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Run a shell command safely and return output lines. In production
 * you may want to log these and validate parameters more carefully.
 */
function run_cmd(string $cmd): array
{
    // if we're invoking sudo, add -n to avoid password prompts in a noninteractive
    // environment; if sudo would ask for a password it will instead fail quickly.
    if (strpos($cmd, 'sudo ') === 0) {
        $cmd = str_replace('sudo ', 'sudo -n ', $cmd);
    }

    $output = [];
    $status = null;
    exec($cmd . ' 2>&1', $output, $status);
    if ($status !== 0) {
        // include status line for debugging
        $output[] = "(exit $status)";
    }
    return $output;
}

?>