<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/lib/admin_session.php';

// Writing a fresh value guarantees the session storage modification time moves.
$_SESSION['_slim_admin_keepalive']=time();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
http_response_code(204);
