<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
slim_rate_limit('dashboard');

$slimDashboardMode='public';
require dirname(__DIR__) . '/lib/dashboard_renderer.php';
