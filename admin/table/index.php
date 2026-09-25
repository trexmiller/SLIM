<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

function h(string $v): string {
    return htmlspecialchars($v,ENT_QUOTES,'UTF-8');
}

$error='';
$station=null;
$rows=[];
$columns=[];
$stationCode='';
$table='';
$limit=500;

try {
    $stationCode=lakestat_station_code((string)($_GET['station'] ?? ''));
    $station=lakestat_station_config($stationCode);
    $table=lakestat_safe_identifier((string)($station['table'] ?? ''));

    $requestedLimit=(int)($_GET['limit'] ?? 500);
    $allowedLimits=[50,100,250,500];
    $limit=in_array($requestedLimit,$allowedLimits,true) ? $requestedLimit : 500;

    $pdo=lakestat_db();

    if(!lakestat_table_exists($pdo,$table)) {
        throw new RuntimeException("The configured MySQL table '{$table}' does not exist.");
    }

    // Read column names from MySQL so this viewer also shows internal,
    // derived, arrival-time, and other columns not represented in fields[].
    $columnRows=$pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
    foreach($columnRows as $columnRow) {
        if(isset($columnRow['Field'])) {
            $columns[]=(string)$columnRow['Field'];
        }
    }

    if(!$columns) {
        throw new RuntimeException('No columns were found in the station table.');
    }

    if(!in_array('id',$columns,true)) {
        throw new RuntimeException('Station table is missing the required id column.');
    }
    $orderColumn='id';

    // LIMIT is selected only from the fixed allow-list above.
    $stmt=$pdo->query(
        "SELECT * FROM `{$table}` ORDER BY `{$orderColumn}` DESC LIMIT {$limit}"
    );
    $rows=$stmt->fetchAll();

} catch(Throwable $e) {
    $error=$e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SLIM Recent Station Data</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1800px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
.toolbar{display:flex;gap:16px;align-items:end;flex-wrap:wrap;margin:18px 0}
.toolbar label{display:block;font-weight:600;margin-bottom:5px}
select{padding:8px 10px}
.table-wrap{overflow:auto;max-height:72vh;border:1px solid #ddd}
table{border-collapse:collapse;min-width:100%;font-size:.88rem}
th,td{padding:7px 9px;border-right:1px solid #e4e4e4;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;white-space:nowrap}
th{position:sticky;top:0;background:#f1f3f5;z-index:2;font-weight:700}
tbody tr:nth-child(even){background:#fafafa}
code{font-size:.9em}
.null{color:#999;font-style:italic}
.count{font-weight:600}
</style>
</head>
<body>
<div class="wrap">

<p>
<?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a>
<?php if($stationCode!==''): ?>
 · <a href="../station/?station=<?= urlencode($stationCode) ?>">Station Manager</a>
 · <a href="../stations/">Station Directory</a>
<?php endif; ?>
</p>

<div class="card">
<h1>Recent Station Data</h1>

<?php if($error): ?>
<div class="error"><?= h($error) ?></div>
<?php elseif($station): ?>

<p>
<strong><?= h((string)($station['name'] ?? $stationCode)) ?></strong>
(<code><?= h($stationCode) ?></code>)
— MySQL table <code><?= h($table) ?></code>
</p>

<p class="hint">
<strong>Timestamp convention:</strong> <code>observed_at</code> = observation time (UTC);
<code>received_at</code> = arrival at SLIM (UTC).
</p>

<p class="hint">
Read-only administrative view of the most recently stored observations.
Rows are shown newest first. This page does not provide editing or deletion
controls and is intended for checking ingestion, routing, timestamps, derived
variables, and other stored values without opening phpMyAdmin.
</p>

<form method="get" class="toolbar">
<input type="hidden" name="station" value="<?= h($stationCode) ?>">
<div>
<label for="limit">Rows to show</label>
<select name="limit" id="limit" onchange="this.form.submit()">
<?php foreach([50,100,250,500] as $option): ?>
<option value="<?= $option ?>" <?= $limit===$option?'selected':'' ?>><?= $option ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="count"><?= count($rows) ?> row<?= count($rows)===1?'':'s' ?> displayed</div>
</form>

<?php if(!$rows): ?>
<p>No rows are currently stored in this table.</p>
<?php else: ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<?php foreach($columns as $column): ?>
<th><?= h($column) ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach($rows as $row): ?>
<tr>
<?php foreach($columns as $column): ?>
<td>
<?php if(!array_key_exists($column,$row) || $row[$column]===null): ?>
<span class="null">NULL</span>
<?php else: ?>
<?= h((string)$row[$column]) ?>
<?php endif; ?>
</td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
</div>
</body>
</html>
