<?php
declare(strict_types=1);

// Read an existing SLIM administrator session from a public-facing page
// without invoking the administrator login gate. This is used only where a
// public endpoint needs to distinguish an authenticated administrator from a
// visitor (for example, previewing an unpublished dashboard).
require_once __DIR__ . '/admin_auth.php';

function slim_optional_admin_authenticated(): bool {
    // Do not create PHP sessions for ordinary public visitors. Only inspect an
    // administrator session when the browser actually presents SLIM's admin cookie.
    if(session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE['SLIMADMINSESSID2'])) return false;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $script=(string)($_SERVER['SCRIPT_NAME'] ?? '/');
        $root='/';
        foreach(['/admin/','/dashboard/'] as $marker) {
            $pos=strpos($script,$marker);
            if($pos!==false) {
                $root=substr($script,0,$pos).'/';
                break;
            }
        }

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.gc_maxlifetime', (string)SLIM_ADMIN_IDLE_TIMEOUT);
        slim_configure_private_sessions();
        session_name('SLIMADMINSESSID2');
        $https=slim_request_is_https();
        session_set_cookie_params([
            'lifetime'=>SLIM_ADMIN_IDLE_TIMEOUT,
            'path'=>$root,
            'secure'=>$https,
            'httponly'=>true,
            'samesite'=>'Lax'
        ]);
        session_start();
    }

    if(slim_admin_current_user()===null) return false;
    if(slim_admin_session_expired()) return false;

    return true;
}
