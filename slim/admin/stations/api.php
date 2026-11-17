<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }

$code=trim((string)($_GET['station'] ?? ''));
if($code==='') { http_response_code(400); exit('Station is required.'); }

try {
    $station=lakestat_station_config($code);
} catch(Throwable $e) {
    http_response_code(404); exit('Station not found.');
}

$https=slim_request_is_https();
$scheme=$https ? 'https' : 'http';
$host=(string)($_SERVER['HTTP_HOST'] ?? '');
$script=(string)($_SERVER['SCRIPT_NAME'] ?? '');
$base=preg_replace('~/admin/stations/api\.php$~','',$script) ?? '';
$endpoint=$scheme.'://'.$host.$base.'/ingest/';

$fields=is_array($station['fields'] ?? null) ? array_keys($station['fields']) : [];
$example=[
    'station'=>(string)$station['code'],
    'timestamp'=>gmdate('Y-m-d\TH:i:s\Z')
];
foreach(array_slice($fields,0,4) as $i=>$field) {
    $example[(string)$field]=round(18.4 + ($i * 0.3),1);
}
$json=json_encode($example,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>API Setup — <?= h((string)$station['code']) ?></title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:950px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
pre,code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
pre{background:#f5f5f5;border:1px solid #ccc;border-radius:6px;padding:14px;overflow:auto;white-space:pre-wrap}
dt{font-weight:bold;margin-top:12px} dd{margin:4px 0 0}
.warn{background:#fff4df;border:1px solid #b8862b;padding:12px;border-radius:6px}
.small{font-size:.92rem;color:#444}
</style>
</head>
<body><div class="wrap">
<p><?= slim_admin_session_bar() ?>
<a href="./">&larr; Station Directory</a> ·
<a href="../station/?station=<?= urlencode((string)$station['code']) ?>">Station Manager</a></p>

<div class="card">
<h1>API Setup: <?= h((string)($station['name'] ?? $station['code'])) ?></h1>
<p>Use these values when configuring a logger, modem, script, or other device to send observations to this station.</p>
<dl>
<dt>Ingestion endpoint</dt><dd><code><?= h($endpoint) ?></code></dd>
<dt>Station code</dt><dd><code><?= h((string)$station['code']) ?></code></dd>
<dt>Authentication header</dt><dd><code>X-API-Key: YOUR_STATION_API_KEY</code></dd>
<dt>Content type</dt><dd><code>application/json</code></dd>
</dl>
</div>

<div class="card">
<h2>Example JSON payload</h2>
<p class="small">The example uses this station's configured variables. Replace the example values and timestamp with observations from the field device.</p>
<pre><?= h((string)$json) ?></pre>

<h2>Example POST request</h2>
<pre>POST <?= h($endpoint) ?>
Content-Type: application/json
X-API-Key: YOUR_STATION_API_KEY


<?= h((string)$json) ?></pre>
<p class="small">API keys must be sent using the <code>X-API-Key</code> header. Existing keys are not recoverable from SLIM after creation.</p>
</div>

<div class="card">
<h2>Legacy ingestion</h2>
<p>JSON POST over HTTPS is the recommended ingestion method. The system can also permit form POST, GET ingestion, or operation without HTTPS when those compatibility options have been enabled by the administrator.</p>
<div class="warn"><strong>Security note:</strong> GET query strings may be retained in server or intermediary logs. Unencrypted HTTP can expose the station API key and observation data while in transit. Enable legacy methods only when required by monitoring hardware that cannot use the recommended method.</div>
</div>
</div></body></html>
