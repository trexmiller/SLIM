<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/admin_session.php';
require_once dirname(__DIR__) . '/lib/bootstrap.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$deployWarnings = [];

if (slim_config_inside_webroot()) {
    $deployWarnings[] = 'Configuration is stored inside the published web directory '
        . '(' . h(lakestat_home()) . '). It holds administrator password hashes, database '
        . 'credentials, station API keys, and backups, and is protected only by config/.htaccess. '
        . 'Moving it outside the document root is strongly recommended.';
}

if (slim_htaccess_likely_ignored()) {
    $deployWarnings[] = 'This server does not identify as Apache or LiteSpeed, so SLIM\'s '
        . '.htaccess files are probably ignored. Add equivalent deny rules for /config/ and '
        . 'PHP-execution blocking for /assets/ in your web-server configuration.';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administration</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:900px;margin:45px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:28px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:20px}
.tile{display:block;padding:18px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#222;background:#fafafa}
.tile:hover{border-color:#999;background:#fff}
.warn{background:#fff7dd;border:1px solid #e5cf86;padding:12px;border-radius:5px;margin-bottom:12px;font-size:.92rem}
h1{margin-top:0}.note{color:#666}
</style>
</head>
<body>
<?= slim_admin_session_bar() ?>
<div class="wrap"><div class="card">
<h1>Administration</h1>
<?php foreach ($deployWarnings as $warning): ?>
<div class="warn"><?= $warning ?></div>
<?php endforeach; ?>
<div class="grid">
<a class="tile" href="setup/"><strong>Setup</strong><br><small>Database connection and system defaults</small></a>
<a class="tile" href="station/"><strong>Station Management</strong><br><small>Create, configure, and manage stations</small></a>
<a class="tile" href="dashboard/"><strong>Dashboard Creator</strong><br><small>Add, remove, and reorder displays</small></a>
<a class="tile" href="health/setup.php"><strong>Station Health Dashboards</strong><br><small>Build private engineering and station diagnostic displays</small></a>
<a class="tile" href="test_data/"><strong>Test Data Generator</strong><br><small>Send synthetic observations</small></a>
<a class="tile" href="appearance/"><strong>Appearance & Branding</strong><br><small>Theme, colors, and logo</small></a>
<a class="tile" href="site/"><strong>Public Site Configuration</strong><br><small>Home page text, links, station map, and station list</small></a>
<a class="tile" href="webhook/"><strong>Webhook Deliveries</strong><br><small>Review outbound webhook delivery history</small></a>
<a class="tile" href="export/"><strong>Data Export / Backup</strong><br><small>Download protected CSV or multi-station ZIP backups</small></a>
<a class="tile" href="import/"><strong>CSV Import</strong><br><small>Add historical data or create a new station from CSV</small></a>


<a class="tile" href="account/"><strong>Administrator Accounts</strong><br><small>Change your password and manage admin logins</small></a>

<a class="tile" href="stations/"><strong>Station Directory</strong><br><small>View names, database details, coordinates, API keys, and station metadata</small></a>

</div>
</div>
<p style="margin:16px 0 0;text-align:center"><a href="../">Return to Home Page</a></p>
</div>
</body>
</html>
