<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/lib/admin_session.php';

// Keep the session file from being garbage-collected, without extending
// the administrator's logical idle timeout.
$_SESSION['_slim_admin_keepalive']=time();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
http_response_code(204);
