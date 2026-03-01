<?php
// Shared utilities for authentication and command execution

// show any PHP errors so login problems are visible immediately
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// load site configuration; file may be under version control or a
// local override (see config.php comments).  if the file is missing we
// populate a minimal set of defaults so that callers can safely use
// cfg() without additional guards.
$CONFIG = [];
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
} else {
    // fallback defaults mirror the values documented in the template
    $CONFIG['mount_base'] = '/export';
    $CONFIG['login_group'] = 'nfs';
    $CONFIG['trusted_proxies'] = [];
    $CONFIG['proxy_header_scheme'] = 'X-Forwarded-Proto';
    $CONFIG['proxy_header_host'] = 'X-Forwarded-Host';
    $CONFIG['cookie_secure'] = false;
    $CONFIG['base_url'] = '';
    $CONFIG['exports_file'] = '/etc/exports';
}

// adjust request metadata according to trusted proxy headers before doing
// anything that might depend on the client's address or scheme.
normalize_request();

// start a session with configured cookie parameters
init_session();

// normalize proxy headers if we’re behind a trusted proxy
function normalize_request(): void {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach (cfg('trusted_proxies', []) as $net) {
        if (ip_in_cidr($remote, $net)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $_SERVER['REMOTE_ADDR'] = trim($parts[0]);
            }
            $schemeHeader = cfg('proxy_header_scheme', 'X-Forwarded-Proto');
            $httpName = 'HTTP_' . strtoupper(str_replace('-', '_', $schemeHeader));
            if (!empty($_SERVER[$httpName])) {
                $scheme = strtolower($_SERVER[$httpName]);
                if ($scheme === 'https' || $scheme === 'http') {
                    $_SERVER['REQUEST_SCHEME'] = $scheme;
                    $_SERVER['HTTPS'] = $scheme === 'https' ? 'on' : 'off';
                }
            }
            $hostHeader = cfg('proxy_header_host', 'X-Forwarded-Host');
            $httpHost = 'HTTP_' . strtoupper(str_replace('-', '_', $hostHeader));
            if (!empty($_SERVER[$httpHost])) {
                $_SERVER['HTTP_HOST'] = $_SERVER[$httpHost];
            }
            break;
        }
    }
}

function ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($network, $mask) = explode('/', $cidr, 2);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = ip2long($ip);
        $network = ip2long($network);
        $mask = ~((1 << (32 - intval($mask))) - 1);
        return ($ip & $mask) === ($network & $mask);
    }
    return false; // IPv6 not yet supported
}

function init_session(): void {
    if (!empty($GLOBALS['CONFIG']['cookie_secure'])) {
        $params = session_get_cookie_params();
        $params['secure'] = true;
        if ($GLOBALS['CONFIG']['cookie_secure'] === 'strict') {
            $params['samesite'] = 'Strict';
        }
        session_set_cookie_params($params);
    }
    session_start();
}

function mount_root(): string {
    $base = cfg('mount_base', '/export');
    return '/' . trim($base, '/');
}

function mount_root_regex(): string {
    $root = mount_root();
    if (substr($root, -1) !== 's') {
        return '#^' . preg_quote($root, '#') . 's?(?:/|$)#';
    }
    return '#^' . preg_quote($root, '#') . '(?:/|$)#';
}

// convenience accessor for configuration values
function cfg(string $key, $default = null) {
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

// build a URL optionally prefixed with base_url from configuration
function url(string $path): string {
    $base = cfg('base_url', '');
    if ($base !== '') {
        // ensure exactly one slash between base and path
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
    return $path;
}

// helper to emit a small JavaScript snippet exposing configuration values
// to client‑side code.  This is called from pages such as login.php and
// the dashboard header; the script creates `window.CONFIG` and sets
// `window.BASE_URL` so existing JS helpers continue to work.
function print_js_config(): void {
    $data = [
        'mountBase'   => mount_root(),
        'exportsFile' => cfg('exports_file', '/etc/exports'),
        'baseUrl'     => cfg('base_url', ''),
    ];
    echo "<script>\n";
    echo "window.CONFIG = window.CONFIG || {};\n";
    foreach ($data as $k => $v) {
        echo "window.CONFIG[" . json_encode($k) . "] = " . json_encode($v) . ";\n";
    }
    // also mirror baseUrl as BASE_URL for legacy helpers
    echo "window.BASE_URL = window.CONFIG.baseUrl || '';\n";
    echo "</script>\n";
}

// send a redirect using url(); exits after issuing header
function redirect(string $path): void {
    header('Location: ' . url($path));
    exit;
}

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
        // successful password; enforce configured login group membership
        $group = cfg('login_group', 'nfs');
        if (!user_in_group($user, $group)) {
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
            $group = cfg('login_group', 'nfs');
            if (!user_in_group($user, $group)) {
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
            $group = cfg('login_group', 'nfs');
            if (!user_in_group($user, $group)) {
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
    $group = cfg('login_group', 'nfs');
    if (!user_in_group($_SESSION['user'], $group)) {
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