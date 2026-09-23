<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

function h(string $v): string {
    return htmlspecialchars($v,ENT_QUOTES,'UTF-8');
}

function station_extra_info(array $station): array {
    $known=[
        'code','name','table','latitude','longitude','api_key',
        'timestamp_field','fields','webhook','metadata','published'
    ];

    $extra=[];

    // User-defined custom station metadata has first-class support.
    $metadata=$station['metadata'] ?? [];
    if(is_array($metadata)) {
        foreach($metadata as $key=>$value) {
            if(is_scalar($value) || $value===null) {
                $extra[(string)$key]=$value;
            }
        }
    }


    return $extra;
}

$error='';
$rows=[];
$dbName='';

// Derive this SLIM installation's ingestion endpoint from the current request.
// This remains correct when SLIM is installed in a subdirectory.
$https=!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off';
$scheme=$https?'https':'http';
$host=(string)($_SERVER['HTTP_HOST'] ?? '');
$script=(string)($_SERVER['SCRIPT_NAME'] ?? '');
$adminPos=strpos($script,'/admin/');
$basePath=$adminPos!==false ? substr($script,0,$adminPos) : '';
$ingestionEndpoint=$host!=='' ? $scheme.'://'.$host.$basePath.'/ingest/' : $basePath.'/ingest/';

try {
    $dbConfig=lakestat_load_config('database.php');
    $dbName=(string)($dbConfig['database'] ?? '');

    $stations=lakestat_list_stations();
    $pdo=lakestat_db();

    foreach($stations as $code=>$summary) {
        $station=lakestat_station_config((string)$code);
        $webhook=lakestat_webhook_config($station);

        $observationCount=null;
        try {
            $table=lakestat_safe_identifier((string)($station['table'] ?? ''));
            if($table!=='') {
                $countStmt=$pdo->query("SELECT COUNT(*) FROM `{$table}`");
                $observationCount=(int)$countStmt->fetchColumn();
            }
        } catch(Throwable $countError) {
            // Keep the directory usable if one station table is unavailable.
            $observationCount=null;
        }

        $rows[]=[
            'code'=>(string)$station['code'],
            'name'=>(string)($station['name'] ?? $code),
            'database'=>$dbName,
            'table'=>(string)($station['table'] ?? ''),
            'latitude'=>$station['latitude'] ?? null,
            'longitude'=>$station['longitude'] ?? null,
            'api_key'=>(string)($station['api_key'] ?? ''),
            'timestamp_field'=>(string)($station['timestamp_field'] ?? ''),
            'observation_count'=>$observationCount,
            'field_count'=>is_array($station['fields'] ?? null) ? count($station['fields']) : 0,
            'webhook_enabled'=>!empty($webhook['enabled']),
            'webhook_url'=>(string)($webhook['url'] ?? ''),
            'extra'=>station_extra_info($station)
        ];
    }

    usort($rows,function($a,$b){
        return strcasecmp($a['name'],$b['name']);
    });

} catch(Throwable $e) {
    $error=$e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SLIM Station Directory</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1500px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:1350px}
th,td{padding:9px 10px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
th{background:#f8f8f8;white-space:nowrap}
code{font-size:.88em}
.api-key{font-family:monospace;word-break:break-all;min-width:230px}
.coords{white-space:nowrap}
.small{font-size:.88rem;color:#555}
.badge{display:inline-block;padding:3px 8px;border-radius:12px;background:#eee;font-size:.8rem}
.badge.on{background:#e6f5e6}
.actions a{white-space:nowrap}
</style>
</head>
<body>
<div class="wrap">

<p><?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a> · <a href="../station/">Station Manager</a></p>

<div class="card">
<h1>Station Directory</h1>
<p class="hint">
Protected administrative inventory of all configured stations. API keys are
shown here because this page is intended only for the password-protected Admin area.
The ingestion endpoint, station code, and station-specific API key shown here are the
three values needed by the Test Data Generator and by devices posting observations to SLIM.
Custom descriptive metadata entered in Station Manager is shown under Additional information.
</p>

<?php if($error): ?>
<div class="error"><?= h($error) ?></div>
<?php elseif(!$rows): ?>
<p>No stations are configured.</p>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>Station name</th>
<th>Code</th>
<th>Database</th>
<th>Table</th>
<th>Coordinates</th>
<th>Ingestion endpoint</th>
<th>API key</th>
<th>Timestamp source field</th>
<th>Observations</th>
<th>Columns</th>
<th>Webhook</th>
<th>Additional information</th>
<th>Actions</th>
</tr>
</thead>
<tbody>

<?php foreach($rows as $row): ?>
<tr>
<td><strong><?= h($row['name']) ?></strong></td>
<td><code><?= h($row['code']) ?></code></td>
<td><code><?= h($row['database']) ?></code></td>
<td><code><?= h($row['table']) ?></code></td>

<td class="coords">
<?php if($row['latitude']!==null && $row['longitude']!==null): ?>
<?= h(number_format((float)$row['latitude'],6)) ?><br>
<?= h(number_format((float)$row['longitude'],6)) ?>
<?php else: ?>
—
<?php endif; ?>
</td>

<td class="small"><code><?= h($ingestionEndpoint) ?></code></td>
<td class="api-key"><?= h($row['api_key']) ?></td>
<td>
<?php if($row['timestamp_field']!==''): ?>
<code><?= h($row['timestamp_field']) ?></code>
<?php else: ?>
<span class="small">Not recorded</span>
<?php endif; ?>
</td>
<td>
<?php if($row['observation_count']!==null): ?>
<?= number_format((int)$row['observation_count']) ?>
<?php else: ?>
<span class="small">Unavailable</span>
<?php endif; ?>
</td>
<td><?= (int)$row['field_count'] ?></td>

<td>
<?php if($row['webhook_enabled']): ?>
<span class="badge on">Enabled</span>
<?php if($row['webhook_url']!==''): ?>
<div class="small"><?= h($row['webhook_url']) ?></div>
<?php endif; ?>
<?php else: ?>
<span class="badge">Off</span>
<?php endif; ?>
</td>

<td>
<?php if($row['extra']): ?>
<?php foreach($row['extra'] as $key=>$value): ?>
<div class="small">
<strong><?= h($key) ?>:</strong>
<?= h($value===null ? '' : (string)$value) ?>
</div>
<?php endforeach; ?>
<?php else: ?>
—
<?php endif; ?>
</td>

<td class="actions">
<a href="../station/?station=<?= urlencode($row['code']) ?>">Manage</a>
&nbsp;·&nbsp;
<a href="../table/?station=<?= urlencode($row['code']) ?>">View Data</a>
&nbsp;·&nbsp;
<a href="../health/?station=<?= urlencode($row['code']) ?>">Health Dashboard</a>
&nbsp;·&nbsp;
<a href="../health/setup.php?station=<?= urlencode($row['code']) ?>">Health Setup</a>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>
</div>
</div>
</body>
</html>
