<?php
declare(strict_types=1);

// SLIM administrator authentication.
//
// Administrator credentials are stored in config/auth.php (or the external
// configuration directory) as bcrypt hashes produced by password_hash().
// No plaintext password is ever written to disk or logged.
//
// This file provides the primitives only. The access gate itself lives in
// lib/admin_session.php, which every Admin script already includes, so that
// protection cannot be forgotten when a new Admin page is added.

require_once __DIR__ . '/bootstrap.php';

const SLIM_ADMIN_MIN_PASSWORD      = 12;
const SLIM_ADMIN_MAX_FAILURES      = 8;     // per IP, per window
const SLIM_ADMIN_LOCKOUT_WINDOW    = 900;   // 15 minutes
const SLIM_ADMIN_IDLE_TIMEOUT      = 4 * 60 * 60;
const SLIM_ADMIN_ABSOLUTE_TIMEOUT  = 12 * 60 * 60;

// A bcrypt hash that no password matches. Used to keep verification timing
// roughly constant for unknown usernames so the login form does not reveal
// which accounts exist.
const SLIM_ADMIN_DUMMY_HASH =
    '$2y$12$C6UzMDM.H6dfI/f/IKcEe.LKnIW8dcQ3ib.GNnOzBoU6NlqKhFqaC';

function slim_admin_auth_path(): string {
    return lakestat_config_path('auth.php');
}

function slim_admin_auth_config(): array {
    $path = slim_admin_auth_path();

    if (!is_file($path)) {
        return ['version' => 1, 'users' => []];
    }

    $config = require $path;

    if (!is_array($config) || !isset($config['users']) || !is_array($config['users'])) {
        return ['version' => 1, 'users' => []];
    }

    return $config;
}

function slim_admin_users(): array {
    return slim_admin_auth_config()['users'];
}

function slim_admin_has_accounts(): bool {
    return slim_admin_users() !== [];
}

function slim_admin_valid_username(string $username): string {
    $username = trim($username);

    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
        throw new InvalidArgumentException(
            'Usernames must be 3-32 characters using letters, numbers, periods, hyphens, or underscores.'
        );
    }

    return $username;
}

function slim_admin_check_password_strength(string $password): void {
    if (strlen($password) < SLIM_ADMIN_MIN_PASSWORD) {
        throw new InvalidArgumentException(
            'Passwords must be at least ' . SLIM_ADMIN_MIN_PASSWORD . ' characters long.'
        );
    }

    if (preg_match('/^(.)\1*$/', $password)) {
        throw new InvalidArgumentException('Choose a less predictable password.');
    }
}

function slim_admin_save_users(array $users): void {
    lakestat_ensure_config_dir();

    lakestat_write_php_config(slim_admin_auth_path(), [
        'version' => 1,
        'users'   => $users
    ]);

    @chmod(slim_admin_auth_path(), 0600);
}

function slim_admin_create_user(string $username, string $password): void {
    $username = slim_admin_valid_username($username);
    slim_admin_check_password_strength($password);

    $users = slim_admin_users();

    foreach (array_keys($users) as $existing) {
        if (strcasecmp((string)$existing, $username) === 0) {
            throw new RuntimeException('That username already exists.');
        }
    }

    $users[$username] = [
        'hash'             => password_hash($password, PASSWORD_DEFAULT),
        'created_at'       => gmdate('Y-m-d H:i:s'),
        'password_changed' => gmdate('Y-m-d H:i:s')
    ];

    slim_admin_save_users($users);
}

function slim_admin_set_password(string $username, string $password): void {
    slim_admin_check_password_strength($password);

    $users = slim_admin_users();

    if (!isset($users[$username])) {
        throw new RuntimeException('Unknown administrator account.');
    }

    $users[$username]['hash']             = password_hash($password, PASSWORD_DEFAULT);
    $users[$username]['password_changed'] = gmdate('Y-m-d H:i:s');

    slim_admin_save_users($users);
}

function slim_admin_delete_user(string $username): void {
    $users = slim_admin_users();

    if (!isset($users[$username])) {
        throw new RuntimeException('Unknown administrator account.');
    }

    if (count($users) <= 1) {
        throw new RuntimeException(
            'This is the only administrator account. Create another account before removing this one.'
        );
    }

    unset($users[$username]);
    slim_admin_save_users($users);
}

// ---------------------------------------------------------------------------
// Failed-login throttling
// ---------------------------------------------------------------------------

function slim_admin_throttle_path(): ?string {
    $dir = lakestat_config_path('runtime' . DIRECTORY_SEPARATOR . 'login');

    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }

    $key = hash('sha256', slim_client_ip());

    return $dir . DIRECTORY_SEPARATOR . $key . '.json';
}

function slim_admin_login_locked(): int {
    $path = slim_admin_throttle_path();

    if ($path === null || !is_file($path)) {
        return 0;
    }

    $state = json_decode((string)@file_get_contents($path), true);

    if (!is_array($state)) {
        return 0;
    }

    $first    = (int)($state['first'] ?? 0);
    $failures = (int)($state['failures'] ?? 0);
    $elapsed  = time() - $first;

    if ($elapsed > SLIM_ADMIN_LOCKOUT_WINDOW) {
        return 0;
    }

    if ($failures < SLIM_ADMIN_MAX_FAILURES) {
        return 0;
    }

    return SLIM_ADMIN_LOCKOUT_WINDOW - $elapsed;
}

function slim_admin_record_failure(): void {
    $path = slim_admin_throttle_path();

    if ($path === null) {
        return;
    }

    $state = is_file($path)
        ? json_decode((string)@file_get_contents($path), true)
        : null;

    if (!is_array($state) || (time() - (int)($state['first'] ?? 0)) > SLIM_ADMIN_LOCKOUT_WINDOW) {
        $state = ['first' => time(), 'failures' => 0];
    }

    $state['failures'] = (int)$state['failures'] + 1;

    @file_put_contents($path, json_encode($state), LOCK_EX);
    @chmod($path, 0600);
}

function slim_admin_clear_failures(): void {
    $path = slim_admin_throttle_path();

    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

// ---------------------------------------------------------------------------
// Session state
// ---------------------------------------------------------------------------

function slim_admin_current_user(): ?string {
    $user = $_SESSION['slim_admin_user'] ?? null;

    return is_string($user) && $user !== '' ? $user : null;
}

function slim_admin_account_fingerprint(array $record): string {
    return hash('sha256', (string)($record['hash'] ?? '') . "\0" . (string)($record['password_changed'] ?? ''));
}

function slim_admin_session_authorized(): bool {
    $user=slim_admin_current_user();
    if($user===null) return false;
    $users=slim_admin_users();
    if(!isset($users[$user]) || !is_array($users[$user])) return false;
    $expected=slim_admin_account_fingerprint($users[$user]);
    $actual=$_SESSION['_slim_admin_auth_fingerprint'] ?? null;
    return is_string($actual) && hash_equals($expected,$actual);
}

function slim_admin_attempt_login(string $username, string $password): bool {
    $users = slim_admin_users();

    // Case-insensitive username lookup, but store/compare the canonical form.
    $matched = null;

    foreach (array_keys($users) as $candidate) {
        if (strcasecmp((string)$candidate, trim($username)) === 0) {
            $matched = (string)$candidate;
            break;
        }
    }

    $hash = $matched !== null
        ? (string)($users[$matched]['hash'] ?? '')
        : SLIM_ADMIN_DUMMY_HASH;

    if ($hash === '') {
        $hash = SLIM_ADMIN_DUMMY_HASH;
    }

    if (!password_verify($password, $hash) || $matched === null) {
        slim_admin_record_failure();
        return false;
    }

    // Transparent rehash if PHP's default cost or algorithm has moved on.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        try {
            $users[$matched]['hash'] = password_hash($password, PASSWORD_DEFAULT);
            slim_admin_save_users($users);
        } catch (Throwable $rehashError) {
            error_log('SLIM admin rehash failed: ' . $rehashError->getMessage());
        }
    }

    slim_admin_clear_failures();

    // A new privilege level requires a new session identifier.
    session_regenerate_id(true);

    $_SESSION['slim_admin_user']        = $matched;
    $_SESSION['_slim_admin_auth_fingerprint'] = slim_admin_account_fingerprint($users[$matched]);
    $_SESSION['slim_admin_login_time']  = time();
    $_SESSION['_slim_admin_last_activity'] = time();

    return true;
}

function slim_admin_logout(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();
}

function slim_admin_session_expired(): bool {
    $login = (int)($_SESSION['slim_admin_login_time'] ?? 0);
    $seen  = (int)($_SESSION['_slim_admin_last_activity'] ?? 0);

    if ($login > 0 && (time() - $login) > SLIM_ADMIN_ABSOLUTE_TIMEOUT) {
        return true;
    }

    if ($seen > 0 && (time() - $seen) > SLIM_ADMIN_IDLE_TIMEOUT) {
        return true;
    }

    return false;
}

// ---------------------------------------------------------------------------
// CSRF for the login / account forms
// ---------------------------------------------------------------------------

function slim_admin_auth_token(): string {
    if (empty($_SESSION['slim_admin_auth_csrf'])) {
        $_SESSION['slim_admin_auth_csrf'] = bin2hex(random_bytes(24));
    }

    return (string)$_SESSION['slim_admin_auth_csrf'];
}

function slim_admin_auth_token_valid(): bool {
    $expected=(string)($_SESSION['slim_admin_auth_csrf'] ?? '');
    $provided=(string)($_POST['auth_csrf'] ?? '');
    return $expected !== '' && $provided !== '' && hash_equals($expected,$provided);
}

// ---------------------------------------------------------------------------
// First-run installation ownership verification
// ---------------------------------------------------------------------------
const SLIM_ADMIN_CLAIM_FILE = 'ADMIN_SETUP_CODE';

function slim_admin_claim_path(): string {
    return lakestat_config_path(SLIM_ADMIN_CLAIM_FILE);
}
function slim_admin_claim_verified(): bool {
    return !empty($_SESSION['slim_admin_claim_verified']);
}
function slim_admin_ensure_claim_code(): void {
    if(slim_admin_has_accounts() || slim_admin_claim_verified()) return;
    if(slim_config_inside_webroot()) {
        throw new RuntimeException('Initial administrator setup is locked until SLIM private configuration is stored outside the published web directory.');
    }
    lakestat_ensure_config_dir();
    $path=slim_admin_claim_path();
    if(is_file($path)) return;
    $code=bin2hex(random_bytes(32));
    $handle=@fopen($path,'x');
    if($handle===false) {
        if(is_file($path)) return;
        throw new RuntimeException('Unable to create the one-time administrator setup code file.');
    }
    $ok=fwrite($handle,$code.PHP_EOL);
    fclose($handle);
    @chmod($path,0600);
    if($ok===false) {
        @unlink($path);
        throw new RuntimeException('Unable to write the one-time administrator setup code file.');
    }
}
function slim_admin_verify_claim_code(string $provided): bool {
    $provided=trim($provided);
    if($provided==='') return false;
    $path=slim_admin_claim_path();
    if(!is_file($path)) return false;
    $expected=trim((string)@file_get_contents($path));
    if($expected==='' || !hash_equals($expected,$provided)) return false;
    session_regenerate_id(true);
    $_SESSION['slim_admin_claim_verified']=true;
    $_SESSION['slim_admin_claim_verified_at']=time();
    return true;
}
function slim_admin_remove_claim_code(): void {
    $path=slim_admin_claim_path();
    if(is_file($path) && !@unlink($path)) {
        throw new RuntimeException('Administrator account was created, but SLIM could not remove the one-time setup code. Remove ADMIN_SETUP_CODE from the private configuration directory manually.');
    }
    unset($_SESSION['slim_admin_claim_verified'],$_SESSION['slim_admin_claim_verified_at']);
}

// ---------------------------------------------------------------------------
// Deployment self-checks
// ---------------------------------------------------------------------------

/**
 * True when the configuration directory sits inside the published document
 * root. That placement works only while the web server honours the
 * config/.htaccess deny rule, which nginx and Apache with AllowOverride None
 * silently ignore.
 */
function slim_config_inside_webroot(): bool {
    $configDir = realpath(lakestat_home());
    $appRoot   = realpath(dirname(__DIR__));

    if ($configDir === false || $appRoot === false) {
        return false;
    }

    $configDir = rtrim($configDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $appRoot   = rtrim($appRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return strpos($configDir, $appRoot) === 0;
}

/**
 * Best-effort probe for whether .htaccess directives are being applied.
 * Apache with mod_php exposes apache_get_modules(); elsewhere we report the
 * server signature so the operator can judge for themselves.
 */
function slim_htaccess_likely_ignored(): bool {
    $software = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));

    if ($software === '') {
        return false;
    }

    return strpos($software, 'apache') === false
        && strpos($software, 'litespeed') === false;
}

// ---------------------------------------------------------------------------
// Login / first-run interface
// ---------------------------------------------------------------------------

function slim_admin_gate_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function slim_admin_render_gate(string $mode, string $error = '', string $notice = ''): void {
    if (!headers_sent()) {
        http_response_code(in_array($mode,['claim','first_run'],true) ? 200 : 401);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
    }

    $token     = slim_admin_gate_escape(slim_admin_auth_token());
    $claim     = $mode === 'claim';
    $firstRun  = $mode === 'first_run';
    $title     = $claim ? 'Verify Installation Ownership'
               : ($firstRun ? 'Create SLIM Administrator' : 'SLIM Administration');
    $minLength = SLIM_ADMIN_MIN_PASSWORD;

    $warnings = [];

    if ($firstRun && slim_config_inside_webroot()) {
        $warnings[] = 'Private configuration must be placed outside the published web directory before '
                    . 'the first administrator account can be created. Set config/EXTERNAL_CONFIG_PATH '
                    . 'to the absolute path of that directory and reload this page.';
    }

    if ($firstRun && slim_htaccess_likely_ignored()) {
        $warnings[] = 'This server does not appear to be Apache or LiteSpeed, so SLIM\'s .htaccess files '
                    . 'are probably ignored. Apply equivalent deny rules for /config/ and /assets/ in your server configuration.';
    }

    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= slim_admin_gate_escape($title) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:460px;margin:70px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:28px}
h1{margin-top:0;font-size:1.4rem}
label{display:block;margin:14px 0 5px;font-weight:600}
input[type=text],input[type=password]{width:100%;padding:9px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
button{margin-top:20px;padding:10px 16px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer;font-size:1rem;width:100%}
button:hover{background:#000}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:11px;border-radius:4px;margin-bottom:14px}
.notice{background:#eef4fb;border:1px solid #a9c4e4;padding:11px;border-radius:4px;margin-bottom:14px}
.warn{background:#fff7dd;border:1px solid #e5cf86;padding:11px;border-radius:4px;margin-bottom:14px;font-size:.9rem}
.hint{color:#666;font-size:.9rem;margin-top:6px}
.foot{color:#888;font-size:.8rem;text-align:center;margin-top:18px}
</style>
</head>
<body>
<div class="wrap"><div class="card">
<h1><?= $claim ? 'Verify Installation Ownership' : ($firstRun ? 'Create SLIM Administrator' : 'SLIM Administration') ?></h1>
<?php foreach ($warnings as $warning): ?>
<div class="warn"><?= slim_admin_gate_escape($warning) ?></div>
<?php endforeach; ?>
<?php if ($notice !== ''): ?><div class="notice"><?= slim_admin_gate_escape($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= slim_admin_gate_escape($error) ?></div><?php endif; ?>
<?php if ($claim): ?>
<p class="hint">This SLIM installation has not yet been claimed. A one-time setup code has been created in the private SLIM configuration directory:</p>
<p><code><?= slim_admin_gate_escape(lakestat_home() . DIRECTORY_SEPARATOR . SLIM_ADMIN_CLAIM_FILE) ?></code></p>
<p class="hint">Retrieve that file using your hosting file manager, SSH, or SFTP and enter the code below. The code itself is never displayed through the web interface.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="auth_csrf" value="<?= $token ?>">
<input type="hidden" name="slim_admin_verify_claim" value="1">
<label for="slim_claim">One-time setup code</label>
<input id="slim_claim" type="password" name="setup_code" required autofocus autocomplete="off">
<button type="submit">Verify installation</button>
</form>
<?php elseif ($firstRun): ?>
<p class="hint">Installation ownership has been verified. Create the first SLIM administrator account.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="auth_csrf" value="<?= $token ?>">
<input type="hidden" name="slim_admin_create_first" value="1">
<label for="slim_u">Username</label>
<input id="slim_u" type="text" name="username" required autofocus autocomplete="username"
       pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32">
<label for="slim_p">Password</label>
<input id="slim_p" type="password" name="password" required autocomplete="new-password"
       minlength="<?= (int)$minLength ?>">
<label for="slim_p2">Confirm password</label>
<input id="slim_p2" type="password" name="password_confirm" required autocomplete="new-password"
       minlength="<?= (int)$minLength ?>">
<p class="hint">Minimum <?= (int)$minLength ?> characters. Stored only as a bcrypt hash.</p>
<?php if (slim_config_inside_webroot()): ?>
<p class="hint"><strong>Setup required:</strong> create the private configuration directory outside
<code>public_html</code>, enter its absolute path in <code>config/EXTERNAL_CONFIG_PATH</code>, and reload this page.</p>
<?php else: ?>
<button type="submit">Create administrator account</button>
<?php endif; ?>
</form>
<?php else: ?>
<form method="post" autocomplete="off">
<input type="hidden" name="auth_csrf" value="<?= $token ?>">
<input type="hidden" name="slim_admin_login" value="1">
<label for="slim_u">Username</label>
<input id="slim_u" type="text" name="username" required autofocus autocomplete="username" maxlength="32">
<label for="slim_p">Password</label>
<input id="slim_p" type="password" name="password" required autocomplete="current-password">
<button type="submit">Sign in</button>
</form>
<?php endif; ?>
<div class="foot"><?= slim_admin_gate_escape(function_exists('slim_version') ? 'SLIM ' . slim_version() : 'SLIM') ?></div>
</div></div>
</body>
</html><?php
}

/**
 * Renders the signed-in badge plus a sign-out control. Admin pages can drop
 * this anywhere inside their markup.
 */
function slim_admin_session_bar(): string {
    $user = slim_admin_current_user();

    if ($user === null) {
        return '';
    }

    $token = slim_admin_gate_escape(slim_admin_auth_token());
    $name  = slim_admin_gate_escape($user);

    return '<div style="max-width:1100px;margin:10px auto 0;padding:0 20px;display:flex;'
         . 'justify-content:flex-end;align-items:center;gap:12px;font-size:.88rem;color:#555;">'
         . '<span>Signed in as <strong>' . $name . '</strong></span>'
         . '<form method="post" style="margin:0;display:inline">'
         . '<input type="hidden" name="auth_csrf" value="' . $token . '">'
         . '<input type="hidden" name="slim_admin_logout" value="1">'
         . '<button type="submit" style="padding:5px 11px;border:1px solid #bbb;border-radius:4px;'
         . 'background:#fff;color:#222;cursor:pointer;font-size:.88rem;">Sign out</button>'
         . '</form></div>';
}
