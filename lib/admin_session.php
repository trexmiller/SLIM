<?php
declare(strict_types=1);

// SLIM Admin session and access gate.
//
// Every script under /admin/ includes this file, so the authentication check
// below applies to all of them without each page having to remember. Adding a
// new Admin page automatically inherits protection.
//
// Web-server-level access control (cPanel Directory Privacy, HTTP Basic auth)
// remains a recommended additional layer, but SLIM no longer depends on it.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_auth.php';

// Idle time is updated by real administrator requests, not keepalive pings.
$slimAdminSessionLifetime = SLIM_ADMIN_IDLE_TIMEOUT;

if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.gc_maxlifetime', (string)$slimAdminSessionLifetime);
    @ini_set('session.use_strict_mode', '1');

    $script   = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $adminPos = strpos($script, '/admin/');
    // Scope the administrator session to the SLIM application root rather
    // than /admin/ so an authenticated administrator can preview an
    // unpublished public dashboard without a bypass token.
    $cookiePath = $adminPos !== false
        ? substr($script, 0, $adminPos) . '/'
        : '/';

    slim_configure_private_sessions();
    session_name('SLIMADMINSESSID2');

    $https = slim_request_is_https();

    session_set_cookie_params([
        'lifetime' => $slimAdminSessionLifetime,
        'path'     => $cookiePath,
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// ---------------------------------------------------------------------------
// Access gate
// ---------------------------------------------------------------------------

$slimAdminScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// Endpoints that answer machines rather than browsers. These receive a status
// code instead of a login page so the caller does not try to parse HTML.
$slimAdminApiEndpoints = ['keepalive.php', 'upload_image.php'];
$slimAdminIsApi = in_array($slimAdminScript, $slimAdminApiEndpoints, true);

$slimAdminError  = '';
$slimAdminNotice = '';

// A misconfigured config/EXTERNAL_CONFIG_PATH throws rather than silently
// falling back to the web root (see lakestat_home()). Surface that here as a
// plain, actionable message instead of a bare 500.
try {
    $slimAdminHasAccounts = slim_admin_has_accounts();
} catch (Throwable $slimConfigError) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html><head><meta charset="utf-8">'
       . '<title>SLIM Configuration Error</title>'
       . '<style>body{font-family:Arial,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#222}'
       . '.box{background:#fdeaea;border:1px solid #e4aaaa;border-radius:6px;padding:18px}</style></head><body>'
       . '<h2>SLIM Configuration Error</h2><div class="box">'
       . htmlspecialchars($slimConfigError->getMessage(), ENT_QUOTES, 'UTF-8')
       . '</div></body></html>';
    exit;
}

// Revocation check is performed on every authenticated request, including keepalive.
// Deleted users, password changes and deleted/recreated usernames invalidate
// all sessions; legacy sessions without fingerprints must sign in again.
if (slim_admin_current_user() !== null && !slim_admin_session_authorized()) {
    slim_admin_logout();
    session_start();
    $slimAdminNotice = 'Your administrator session was revoked. Please sign in again.';
}

// Expire stale sessions before anything else looks at them.
if (slim_admin_current_user() !== null && slim_admin_session_expired()) {
    slim_admin_logout();
    session_start();
    $slimAdminNotice = 'Your session expired. Please sign in again.';
}

$slimAdminPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// --- Sign out -------------------------------------------------------------
if ($slimAdminPost && isset($_POST['slim_admin_logout'])) {
    if (slim_admin_auth_token_valid()) {
        slim_admin_logout();
    }

    header('Location: ' . (string)($_SERVER['SCRIPT_NAME'] ?? './'), true, 303);
    exit;
}

// --- First-run ownership verification and account creation -----------------
if (!$slimAdminHasAccounts) {
    if (slim_config_inside_webroot()) {
        if ($slimAdminIsApi) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>'Initial administrator setup requires private configuration outside the web root.']);
            exit;
        }
        slim_admin_render_gate('first_run',
            'Administrator creation is disabled until SLIM private configuration is stored outside the published web directory. Create the external directory, set its absolute path in config/EXTERNAL_CONFIG_PATH, and reload this page.',
            $slimAdminNotice);
        exit;
    }

    try { slim_admin_ensure_claim_code(); }
    catch (Throwable $claimFileError) { $slimAdminError=$claimFileError->getMessage(); }

    if ($slimAdminPost && isset($_POST['slim_admin_verify_claim'])) {
        try {
            if (!slim_admin_auth_token_valid()) throw new RuntimeException('Session validation failed. Reload the page and try again.');
            if (!slim_admin_verify_claim_code((string)($_POST['setup_code'] ?? ''))) {
                usleep(400000);
                throw new RuntimeException('The one-time setup code is incorrect.');
            }
            header('Location: '.(string)($_SERVER['SCRIPT_NAME'] ?? './'),true,303);
            exit;
        } catch (Throwable $e) { $slimAdminError=$e->getMessage(); }
    }

    if (!slim_admin_claim_verified()) {
        if ($slimAdminIsApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>'SLIM installation ownership has not yet been verified.']);
            exit;
        }
        slim_admin_render_gate('claim',$slimAdminError,$slimAdminNotice);
        exit;
    }

    if ($slimAdminPost && isset($_POST['slim_admin_create_first'])) {
        try {
            if (!slim_admin_auth_token_valid()) throw new RuntimeException('Session validation failed. Reload the page and try again.');
            if (!slim_admin_claim_verified()) throw new RuntimeException('Installation ownership must be verified before creating an administrator.');
            $newUser=(string)($_POST['username'] ?? '');
            $newPass=(string)($_POST['password'] ?? '');
            $confirm=(string)($_POST['password_confirm'] ?? '');
            if (!hash_equals($newPass,$confirm)) throw new RuntimeException('The two passwords do not match.');

            slim_admin_create_user($newUser,$newPass);
            slim_admin_remove_claim_code();
            slim_admin_attempt_login($newUser,$newPass);
            header('Location: '.(string)($_SERVER['SCRIPT_NAME'] ?? './'),true,303);
            exit;
        } catch (Throwable $e) { $slimAdminError=$e->getMessage(); }
    }

    if (slim_admin_current_user() === null) {
        if ($slimAdminIsApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>'SLIM administrator account not yet created.']);
            exit;
        }
        slim_admin_render_gate('first_run',$slimAdminError,$slimAdminNotice);
        exit;
    }
}

// --- Sign in --------------------------------------------------------------
if (slim_admin_current_user() === null) {
    if ($slimAdminPost && isset($_POST['slim_admin_login'])) {
        $lockRemaining = slim_admin_login_locked();

        if ($lockRemaining > 0) {
            $slimAdminError = 'Too many failed sign-in attempts. Try again in '
                            . (int)ceil($lockRemaining / 60) . ' minute(s).';
        } elseif (!slim_admin_auth_token_valid()) {
            $slimAdminError = 'Session validation failed. Reload the page and try again.';
        } elseif (slim_admin_attempt_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            $target = (string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? './'));
            header('Location: ' . $target, true, 303);
            exit;
        } else {
            usleep(400000);
            $slimAdminError = 'Invalid username or password.';
        }
    }

    if ($slimAdminIsApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not signed in.']);
        exit;
    }

    slim_admin_render_gate('login', $slimAdminError, $slimAdminNotice);
    exit;
}

// ---------------------------------------------------------------------------
// Authenticated from here on
// ---------------------------------------------------------------------------

if ($slimAdminScript !== 'keepalive.php') {
    $_SESSION['_slim_admin_last_activity'] = time();
}

// Refresh the rolling cookie expiration as well.
if (!headers_sent() && session_id() !== '') {
    $params = session_get_cookie_params();

    setcookie(session_name(), session_id(), [
        'expires'  => time() + $slimAdminSessionLifetime,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// Admin pages are never cached or framed.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
}
