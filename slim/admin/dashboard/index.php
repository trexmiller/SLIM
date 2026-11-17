<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$slimDashboardMode='public';
require dirname(__DIR__, 2) . '/lib/dashboard_builder.php';
