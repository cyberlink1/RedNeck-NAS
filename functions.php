<?php
// Shared utilities for authentication and command execution

// show any PHP errors so login problems are visible immediately
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

// hold a human-readable error from the last authentication attempt
$lastAuthError = '';

// record a failed authentication attempt; message is logged to PHP/syslog
function log_auth_failure(string $user, string $reason): void
{
    error_log("[lvm_nfs] login failure user=$user reason=$reason");
}

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
        log_auth_failure($user, $lastAuthError);
        // include the raw output for visibility
        if (!empty($output)) {
            $lastAuthError .= ' (output: ' . htmlspecialchars(implode(' | ', $output)) . ')';
        }
        return false;
    }
    if (count($output) === 0) {
        $lastAuthError = "user not found in shadow";
        log_auth_failure($user, $lastAuthError);
        return false;
    }

    // line looks like: username:hash:...
    $parts = explode(':', $output[0]);
    if (count($parts) < 2 || empty($parts[1])) {
        $lastAuthError = "no hash available in shadow entry";
        log_auth_failure($user, $lastAuthError);
        return false;
    }
    $hash = $parts[1];

    // verify using PHP's crypt()
    $computed = @crypt($password, $hash);
    if ($computed === $hash) {
        // successful password; enforce nfs group membership
        if (!user_in_group($user, 'nfs')) {
            $lastAuthError = "user $user is not authorized to use this interface";
            log_auth_failure($user, $lastAuthError);
            return false;
        }
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
            if (!user_in_group($user, 'nfs')) {
                $lastAuthError = "user $user is not authorized to use this interface";
                log_auth_failure($user, $lastAuthError);
                return false;
            }
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
            if (!user_in_group($user, 'nfs')) {
                $lastAuthError = "user $user is not authorized to use this interface";
                log_auth_failure($user, $lastAuthError);
                return false;
            }
            $_SESSION['user'] = $user;
            return true;
        }
    }

    // build failure message with details
    $lastAuthError = "password did not match";
    log_auth_failure($user, $lastAuthError);
    $lastAuthError .= ' (stored='.htmlspecialchars(substr($hash,0,20)).'..., computed='.htmlspecialchars(substr($computed,0,20)).'...)';
    // append helper diagnostic info
    foreach ($helperResults as $hr) {
        $lastAuthError .= ' [' . htmlspecialchars($hr['cmd']) . ' => status=' . $hr['status'] . ' output="' . htmlspecialchars(implode(' | ', $hr['output'])) . '"]';
    }
    return false;
}

function require_login()
{
    // decide whether this is an AJAX/JSON request; many of our
    // client-side calls include an "ajax=1" parameter but we also
    // accept XMLHttpRequest headers for future compatibility.
    $isAjax = !empty($_REQUEST['ajax'])
           || !empty($_REQUEST['json'])
           || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if (empty($_SESSION['user'])) {
        if ($isAjax) {
            // caller will handle redirect client-side
            header('HTTP/1.1 401 Unauthorized');
        } else {
            header('Location: login.php');
        }
        exit;
    }
    // if membership was revoked while session active, treat as logged out
    if (!user_in_group($_SESSION['user'], 'nfs')) {
        session_destroy();
        if ($isAjax) {
            header('HTTP/1.1 401 Unauthorized');
        } else {
            header('Location: login.php');
        }
        exit;
    }
}

/**
 * Return true if the specified user belongs to the given group.
 * This uses the `id -nG` command which reads /etc/group and does not
 * require special privileges.
 */
function user_in_group(string $user, string $group): bool
{
    // `id -nG` prints space-separated group names for the account.
    $cmd = 'id -nG ' . escapeshellarg($user);
    $out = [];
    $status = null;
    exec($cmd . ' 2>&1', $out, $status);
    if ($status !== 0 || count($out) === 0) {
        return false;
    }
    $groups = preg_split('/\s+/', trim($out[0]));
    return in_array($group, $groups, true);
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