<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';


function h(string $value): string {
    return htmlspecialchars($value,ENT_QUOTES,'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Station Management</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#172033;margin:0}
.wrap{max-width:1100px;margin:0 auto;padding:28px 20px 50px}
a{color:#075985}
.topnav{margin-bottom:18px}
.card{background:#fff;border:1px solid #d8e0ea;border-radius:12px;padding:22px;margin-bottom:20px;box-shadow:0 2px 8px rgba(15,23,42,.05)}
h1{margin-top:0}
label{display:block;font-weight:700;margin-bottom:7px}
select{width:100%;max-width:560px;padding:10px 12px;font:inherit;border:1px solid #9aa8b8;border-radius:7px;background:#fff}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px}
.tile{display:block;padding:18px;border:1px solid #cbd5e1;border-radius:10px;text-decoration:none;background:#fff;color:#172033}
.tile:hover,.tile:focus{border-color:#0f6b8d;box-shadow:0 2px 8px rgba(15,23,42,.08);outline:3px solid transparent}
.tile:focus-visible{outline:3px solid #0f6b8d;outline-offset:2px}
.tile strong{display:block;font-size:18px;margin-bottom:5px}
.tile small{display:block;color:#526173;font-size:14px;line-height:1.4}
.hint{color:#526173}
.danger-tile{border-color:#e2b4b4}
</style>
</head>
<body>
<div class="wrap">
<nav class="topnav"><a href="../">← Administration</a></nav>
<div class="card">
<h1>Station Management</h1>
<p class="hint">Choose the station-management task you want to perform. For station-specific tasks, select the station on the next page.</p>
</div>

<div class="grid">
<a class="tile" href="create.php"><strong>Create Station</strong><small>Create a new monitoring station and its initial database configuration.</small></a>
<a class="tile" href="details.php"><strong>Station Details</strong><small>Select a station and manage its name, location, publication status, and basic settings.</small></a>
<a class="tile" href="metadata.php"><strong>Station Metadata</strong><small>Select a station and manage optional descriptive information such as platform type, waterbody, agency, or deployment year.</small></a>
<a class="tile" href="variables.php"><strong>Variables &amp; Fields</strong><small>Select a station to edit labels and units, add incoming variables, and clean up orphaned definitions.</small></a>
<a class="tile" href="qc.php"><strong>Variable Quality Control</strong><small>Select a station to configure acceptable ranges and instrument diagnostic/error codes for individual variables.</small></a>
<a class="tile" href="derived.php"><strong>Derived Variables</strong><small>Select a station to create and manage calculated variables based on incoming observations.</small></a>
<a class="tile" href="webhook.php"><strong>Webhook Setup</strong><small>Select a station to configure and test outbound JSON delivery.</small></a>
<a class="tile" href="../table/"><strong>Recent Station Data</strong><small>Select a station and view recently stored SQL table observations.</small></a>
<a class="tile danger-tile" href="delete.php"><strong>Delete Station</strong><small>Select a station to remove it from SLIM or permanently delete its station data table.</small></a>
</div>
</div>
</body>
</html>
