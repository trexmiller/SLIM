<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

function h(string $v): string {
    return htmlspecialchars($v,ENT_QUOTES,'UTF-8');
}

// Deletion inputs are local wall-clock times; reject DST gaps and repeated hours.
function slim_delete_local_to_utc(string $value, DateTimeZone $zone): DateTimeImmutable {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)) {
        throw new RuntimeException('Enter a valid local date and time.');
    }
    $local=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$zone);
    if(!$local || $local->format('Y-m-d\TH:i')!==$value) {
        throw new RuntimeException('The local time is invalid or falls in a daylight-saving time gap.');
    }
    $utc=$local->setTimezone(new DateTimeZone('UTC'));
    // At the fall DST transition, one wall-clock time can refer to two UTC instants.
    $transitions=$zone->getTransitions($utc->getTimestamp()-86400,$utc->getTimestamp()+86400);
    if(is_array($transitions)) {
        foreach(array_slice($transitions,1) as $t) {
            $before=$zone->getOffset((new DateTimeImmutable('@'.($t['ts']-1))));
            $after=(int)$t['offset'];
            if($after >= $before) continue;
            $other=$utc->modify(($before-$after).' seconds');
            if($other->setTimezone($zone)->format('Y-m-d\TH:i')===$value) {
                throw new RuntimeException('This local time occurs twice during the daylight-saving fall transition. Choose a range outside the repeated hour.');
            }
            $other=$utc->modify('-'.($before-$after).' seconds');
            if($other->setTimezone($zone)->format('Y-m-d\TH:i')===$value) {
                throw new RuntimeException('This local time occurs twice during the daylight-saving fall transition. Choose a range outside the repeated hour.');
            }
        }
    }
    return $utc;
}

$deleteZone=new DateTimeZone(slim_valid_timezone((string)(slim_system_config()['timezone'] ?? 'UTC')));
$deleteZoneName=$deleteZone->getName();
// Presentation only: canonical timestamps remain stored in UTC.
$systemZone=$deleteZone;
$stationZone=$systemZone;

function slim_delete_display_local(?string $utc, DateTimeZone $zone): string {
    if(!$utc) return '—';
    return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d g:i:s A T');
}

if(empty($_SESSION['table_delete_csrf'])) {
    $_SESSION['table_delete_csrf']=bin2hex(random_bytes(24));
}
$error='';
$message='';
$deletePreview=null;
$station=null;
$rows=[];
$columns=[];
$stationCode='';
$table='';
$limit=500;
$stations=lakestat_list_stations();

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        $provided=(string)($_POST['csrf'] ?? '');
        $expected=(string)($_SESSION['table_delete_csrf'] ?? '');
        if($provided==='' || $expected==='' || !hash_equals($expected,$provided)) {
            throw new RuntimeException('Session validation failed. Reload the page and try again.');
        }

        $stationCode=lakestat_station_code((string)($_POST['station'] ?? ''));
        $station=lakestat_station_config($stationCode);
        $stationZoneName=(string)($station['timezone'] ?? '');
        try {
            $stationZone=new DateTimeZone($stationZoneName!=='' ? slim_valid_timezone($stationZoneName) : $systemZone->getName());
        } catch(Throwable $ignored) {
            $stationZone=$systemZone;
        }
        $table=lakestat_safe_identifier((string)($station['table'] ?? ''));
        $pdo=lakestat_db();
        if(!lakestat_table_exists($pdo,$table)) {
            throw new RuntimeException("The configured MySQL table '{$table}' does not exist.");
        }

        $start=trim((string)($_POST['delete_start'] ?? ''));
        $end=trim((string)($_POST['delete_end'] ?? ''));
        $startDt=slim_delete_local_to_utc($start,$deleteZone);
        $endDt=slim_delete_local_to_utc($end,$deleteZone);
        if($endDt < $startDt) {
            throw new RuntimeException('The end of the deletion range must not be earlier than the start.');
        }
        $startSql=$startDt->format('Y-m-d H:i:00');
        $endSql=$endDt->format('Y-m-d H:i:59');

        $stats=$pdo->prepare(
            "SELECT COUNT(*) AS n, MIN(`observed_at`) AS first_time, MAX(`observed_at`) AS last_time
             FROM `{$table}` WHERE `observed_at` BETWEEN :start AND :end"
        );
        $stats->execute([':start'=>$startSql,':end'=>$endSql]);
        $match=$stats->fetch(PDO::FETCH_ASSOC) ?: [];
        $matchCount=(int)($match['n'] ?? 0);

        if((string)($_POST['action'] ?? '')==='delete_observations_confirm') {
            $typed=trim((string)($_POST['confirm_delete'] ?? ''));
            if($matchCount<1) throw new RuntimeException('No observations currently match this range.');
            if($typed!=='DELETE '.$matchCount) {
                throw new RuntimeException("Type exactly DELETE {$matchCount} to confirm deletion.");
            }
            if(isset($_POST['backup_before_delete'])) {
                lakestat_backup_station($pdo,$station);
            }
            $del=$pdo->prepare(
                "DELETE FROM `{$table}` WHERE `observed_at` BETWEEN :start AND :end"
            );
            $del->execute([':start'=>$startSql,':end'=>$endSql]);
            $deleted=$del->rowCount();
            $message=number_format($deleted).' observation'.($deleted===1?'':'s').' deleted from '.$stationCode.'.';
            $_POST=[];
            $_GET['station']=$stationCode;
        } else {
            $preview=$pdo->prepare(
                "SELECT * FROM `{$table}` WHERE `observed_at` BETWEEN :start AND :end
                 ORDER BY `observed_at` ASC LIMIT 40"
            );
            $preview->execute([':start'=>$startSql,':end'=>$endSql]);
            $deletePreview=[
                'count'=>$matchCount,
                'first'=>$match['first_time'] ?? null,
                'last'=>$match['last_time'] ?? null,
                'start_input'=>$start,
                'end_input'=>$end,
                'rows'=>$preview->fetchAll(PDO::FETCH_ASSOC),
                'start_utc'=>$startSql,
                'end_utc'=>$endSql
            ];
            $_GET['station']=$stationCode;
        }
    }


    if(isset($_GET['station']) && (string)$_GET['station']!=='') {
        $stationCode=lakestat_station_code((string)$_GET['station']);
        $station=lakestat_station_config($stationCode);
        $stationZoneName=(string)($station['timezone'] ?? '');
        try {
            $stationZone=new DateTimeZone($stationZoneName!=='' ? slim_valid_timezone($stationZoneName) : $systemZone->getName());
        } catch(Throwable $ignored) {
            $stationZone=$systemZone;
        }
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
    }

} catch(Throwable $e) {
    $error=$e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recent Station Data</title>
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
.warning{background:#fff4df;border:1px solid #b8862b;padding:12px;border-radius:6px}
.success{background:#e7f6e7;border:1px solid #82b882;padding:12px;margin-bottom:15px}
.danger{border-color:#b84a4a}
input[type="datetime-local"],input[type="text"]{padding:8px 10px}
</style>
</head>
<body>
<div class="wrap">

<p>
<?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a>
 · <a href="../station/">Station Management</a>
<?php if($stationCode!==''): ?>
 · <a href="../stations/">Station Directory</a>
<?php endif; ?>
</p>

<div class="card">
<h1>Recent Station Data</h1>

<?php if($stations): ?>
<form method="get" class="toolbar">
<div>
<label for="station">Station</label>
<select name="station" id="station" onchange="this.form.submit()">
<option value="">Select a station</option>
<?php foreach($stations as $code=>$info): ?>
<option value="<?= h((string)$code) ?>" <?= $stationCode===$code?'selected':'' ?>>
<?= h((string)($info['name'] ?? $code)) ?> — <?= h((string)$code) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php if($stationCode!==''): ?>
<input type="hidden" name="limit" value="<?= (int)$limit ?>">
<?php endif; ?>
<noscript><button type="submit">Select Station</button></noscript>
</form>
<?php else: ?>
<p>No stations have been configured.</p>
<?php endif; ?>

<?php if($message): ?><div class="success"><?= h($message) ?></div><?php endif; ?>
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
<code>received_at</code> = arrival at the server (UTC).
</p>

<p class="hint">
Observed (system) uses <?= h($systemZone->getName()) ?>; Observed (station) uses <?= h($stationZone->getName()) ?>.
Both are display-only conversions of Observed (UTC).
</p>
<p class="hint">
Administrative view of the most recently stored observations.
Rows are shown newest first. Individual values are not edited here. Known-invalid
observations can be removed by UTC <code>observed_at</code> range using the protected
deletion workflow below.
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


<div class="card danger">
<h2>Delete observations</h2>
<p>Use this only to remove known-invalid records. Enter times in <strong><?= h($deleteZoneName) ?> local time</strong>. The system converts the range to UTC for matching against <code>observed_at</code>. Both endpoints are included. Ambiguous DST times are rejected.</p>
<form method="post" class="toolbar">
<input type="hidden" name="csrf" value="<?= h((string)$_SESSION['table_delete_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($stationCode) ?>">
<input type="hidden" name="action" value="preview_delete_observations">
<div><label for="delete_start">Start observed (local)</label>
<input type="datetime-local" id="delete_start" name="delete_start" required value="<?= h((string)($deletePreview['start_input'] ?? '')) ?>"></div>
<div><label for="delete_end">End observed (local)</label>
<input type="datetime-local" id="delete_end" name="delete_end" required value="<?= h((string)($deletePreview['end_input'] ?? '')) ?>"></div>
<div><button type="submit">Preview deletion</button></div>
</form>

<?php if($deletePreview!==null): ?>
<div class="warning">
<strong><?= number_format((int)$deletePreview['count']) ?> observation<?= (int)$deletePreview['count']===1?'':'s' ?> match.</strong>
<?php if($deletePreview['count']>0): ?>
Matched observations (local): <code><?= h(slim_delete_display_local($deletePreview['first'], $deleteZone)) ?></code> through <code><?= h(slim_delete_display_local($deletePreview['last'], $deleteZone)) ?></code>.
<?php endif; ?>
<p class="hint">UTC query boundaries: <?= h((string)$deletePreview['start_utc']) ?> through <?= h((string)$deletePreview['end_utc']) ?> UTC.</p>
</div>

<?php if($deletePreview['rows']): ?>
<h3>Preview of matching observations</h3>
<p class="hint">Showing up to the first 40 matching rows in chronological order. Times in the original data columns remain unchanged.</p>
<div class="table-wrap" style="max-height:300px"><table><thead><tr>
<?php foreach(array_keys($deletePreview['rows'][0]) as $c): ?><th><?= h((string)$c) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach($deletePreview['rows'] as $r): ?><tr>
<?php foreach($r as $v): ?><td><?= $v===null?'<span class="null">NULL</span>':h((string)$v) ?></td><?php endforeach; ?>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php if($deletePreview['count']>0): ?>
<form method="post" style="margin-top:16px">
<input type="hidden" name="csrf" value="<?= h((string)$_SESSION['table_delete_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($stationCode) ?>">
<input type="hidden" name="action" value="delete_observations_confirm">
<input type="hidden" name="delete_start" value="<?= h((string)$deletePreview['start_input']) ?>">
<input type="hidden" name="delete_end" value="<?= h((string)$deletePreview['end_input']) ?>">
<p><label><input type="checkbox" name="backup_before_delete" value="1" checked> Create a station backup before deletion</label></p>
<p><label for="confirm_delete">To permanently delete these records, type <strong>DELETE <?= (int)$deletePreview['count'] ?></strong></label><br>
<input type="text" id="confirm_delete" name="confirm_delete" autocomplete="off" required></p>
<p><button type="submit">Permanently delete <?= number_format((int)$deletePreview['count']) ?> observation<?= (int)$deletePreview['count']===1?'':'s' ?></button></p>
</form>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if(!$rows): ?>
<p>No rows are currently stored in this table.</p>
<?php else: ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<?php foreach($columns as $column): ?>
<?php if($column==='observed_at'): ?><th>Observed (system)</th><th>Observed (station)</th><?php endif; ?>
<th><?= h($column==='observed_at' ? 'Observed (UTC)' : ($column==='received_at' ? 'Received (UTC)' : $column)) ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach($rows as $row): ?>
<tr>
<?php foreach($columns as $column): ?>
<?php if($column==='observed_at'): ?>
<?php
$rawObserved=$row['observed_at'] ?? null;
$systemObserved='<span class="null">NULL</span>';
$stationObserved='<span class="null">NULL</span>';
if($rawObserved!==null && $rawObserved!=='') {
    try {
        $utcObserved=new DateTimeImmutable((string)$rawObserved,new DateTimeZone('UTC'));
        $systemObserved=h($utcObserved->setTimezone($systemZone)->format('Y-m-d g:i:s A T'));
        $stationObserved=h($utcObserved->setTimezone($stationZone)->format('Y-m-d g:i:s A T'));
    } catch(Throwable $timeError) {
        $systemObserved='<span class="null">—</span>';
        $stationObserved='<span class="null">—</span>';
    }
}
?>
<td><?= $systemObserved ?></td>
<td><?= $stationObserved ?></td>
<?php endif; ?>
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
