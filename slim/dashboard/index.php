<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_optional_session.php';
slim_rate_limit('dashboard');

$slimDashboardMode='public';
require dirname(__DIR__) . '/lib/dashboard_renderer.php';
