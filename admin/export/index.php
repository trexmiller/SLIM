<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

if (empty($_SESSION['export_csrf'])) {
    $_SESSION['export_csrf'] = bin2hex(random_bytes(24));
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function export_date_to_utc(string $date, bool $end=false): string {
    $date=trim($date);

    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
        throw new RuntimeException('Start and end dates must use YYYY-MM-DD.');
    }

    $dt=DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone('UTC')
    );

    if(!$dt) {
        throw new RuntimeException('One of the export dates is invalid.');
    }

    if($end) {
        $dt=$dt->modify('+1 day');
    }

    return $dt->format('Y-m-d H:i:s');
}

function export_date_days(string $startDate, string $endDate): int {
    $start=new DateTimeImmutable($startDate,new DateTimeZone('UTC'));
    $end=new DateTimeImmutable($endDate,new DateTimeZone('UTC'));

    if($end<$start) {
        throw new RuntimeException('End date must be on or after start date.');
    }

    return (int)$start->diff($end)->format('%a') + 1;
}

function stream_station_csv(
    PDO $pdo,
    array $station,
    string $startUtc,
    string $endUtc,
    $output
): void {
    lakestat_optimize_station_table($pdo,$station);
    $table=lakestat_safe_identifier((string)$station['table']);
    $columns=slim_export_station_columns($pdo,$station);

    if(!$columns) {
        throw new RuntimeException('No columns were found for station table.');
    }

    fputcsv($output,$columns);

    $columnSql=implode(
        ',',
        array_map(function($column){
            return '`'.str_replace('`','``',$column).'`';
        },$columns)
    );

    $sql="SELECT {$columnSql}
          FROM `{$table}`
          WHERE `observed_at` >= :start
            AND `observed_at` < :end
          ORDER BY `observed_at` ASC";

    $stmt=$pdo->prepare($sql);
    $stmt->execute([
        ':start'=>$startUtc,
        ':end'=>$endUtc
    ]);

    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $values=[];
        foreach($columns as $column) {
            $value=$row[$column] ?? null;
            $values[]=slim_csv_safe_value($value, is_string($value) && !is_numeric($value));
        }
        fputcsv($output,$values);
    }
}

$error='';
$message='';
$estimate=null;
$stations=lakestat_list_stations();
$limits=slim_export_limits();

$defaultEnd=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');
$defaultStart=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
    ->modify('-30 days')
    ->format('Y-m-d');

$startDate=(string)($_POST['start_date'] ?? $defaultStart);
$endDate=(string)($_POST['end_date'] ?? $defaultEnd);
$selectedStations=$_POST['stations'] ?? [];

try {
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($_SESSION['export_csrf'] ?? '',$_POST['csrf'] ?? '')) {
            throw new RuntimeException('Session validation failed. Reload and try again.');
        }

        $selectedStations=array_values(array_unique(array_map('strval',$selectedStations)));

        if(!$selectedStations) {
            throw new RuntimeException('Select at least one station.');
        }

        if(count($selectedStations)>$limits['max_stations']) {
            throw new RuntimeException(
                'This export includes '.count($selectedStations).
                ' stations; the current maximum is '.$limits['max_stations'].'.'
            );
        }

        $days=export_date_days($startDate,$endDate);

        if($days>$limits['max_days']) {
            throw new RuntimeException(
                "Requested date range is {$days} days; the current maximum is ".
                $limits['max_days'].' days.'
            );
        }

        $startUtc=export_date_to_utc($startDate,false);
        $endUtc=export_date_to_utc($endDate,true);

        $pdo=lakestat_db();
        $details=[];
        $totalRows=0;

        foreach($selectedStations as $code) {
            $station=lakestat_station_config($code);
            $count=slim_export_station_row_count($pdo,$station,$startUtc,$endUtc);

            if($count>$limits['max_rows_per_station']) {
                throw new RuntimeException(
                    "Station {$code} contains {$count} rows in the selected period; ".
                    "the per-station maximum is {$limits['max_rows_per_station']}."
                );
            }

            $totalRows+=$count;

            if($totalRows>$limits['max_total_rows']) {
                throw new RuntimeException(
                    "The selected export contains at least {$totalRows} rows; ".
                    "the total export maximum is {$limits['max_total_rows']}."
                );
            }

            $details[]=[
                'code'=>$code,
                'name'=>$station['name'] ?? $code,
                'rows'=>$count
            ];
        }

        $estimate=[
            'stations'=>$details,
            'total_rows'=>$totalRows,
            'days'=>$days,
            'start_utc'=>$startUtc,
            'end_utc'=>$endUtc
        ];

        $action=(string)($_POST['action'] ?? 'estimate');

        if($action==='download') {
            if(count($details)===1) {
                $code=$details[0]['code'];
                $station=lakestat_station_config($code);

                $filename='SLIM_'.$code.'_'.$startDate.'_to_'.$endDate.'.csv';

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="'.$filename.'"');
                header('Cache-Control: no-store');

                $out=fopen('php://output','w');
                stream_station_csv($pdo,$station,$startUtc,$endUtc,$out);
                fclose($out);
                exit;
            }

            if(!class_exists('ZipArchive')) {
                throw new RuntimeException(
                    'Multi-station ZIP export requires the PHP ZipArchive extension.'
                );
            }

            $tmp=tempnam(sys_get_temp_dir(),'slim_export_');

            if($tmp===false) {
                throw new RuntimeException('Unable to create temporary ZIP file.');
            }

            $zipPath=$tmp.'.zip';
            @unlink($zipPath);

            $zip=new ZipArchive();

            if($zip->open($zipPath,ZipArchive::CREATE)!==true) {
                @unlink($tmp);
                throw new RuntimeException('Unable to create ZIP export.');
            }

            foreach($details as $detail) {
                $code=$detail['code'];
                $station=lakestat_station_config($code);

                $csvTmp=tempnam(sys_get_temp_dir(),'slim_csv_');
                if($csvTmp===false) {
                    $zip->close();
                    @unlink($zipPath);
                    @unlink($tmp);
                    throw new RuntimeException('Unable to create temporary CSV file.');
                }

                $fh=fopen($csvTmp,'w');
                stream_station_csv($pdo,$station,$startUtc,$endUtc,$fh);
                fclose($fh);

                $zip->addFile(
                    $csvTmp,
                    $code.'_'.$startDate.'_to_'.$endDate.'.csv'
                );
            }

            $manifest=
                "SLIM manual backup export\n".
                "Created UTC: ".gmdate('c')."\n".
                "Date range: {$startDate} through {$endDate}\n".
                "Stations: ".implode(', ',array_column($details,'code'))."\n".
                "Total rows: {$totalRows}\n";

            $zip->addFromString('manifest.txt',$manifest);
            $zip->close();

            $filename='SLIM_backup_'.$startDate.'_to_'.$endDate.'.zip';

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('Content-Length: '.filesize($zipPath));
            header('Cache-Control: no-store');

            readfile($zipPath);

            @unlink($zipPath);
            @unlink($tmp);

            foreach(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'slim_csv_*') as $candidate) {
                if(@filemtime($candidate)!==false && @filemtime($candidate)<time()+60) {
                    @unlink($candidate);
                }
            }

            exit;
        }
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
<title>Data Export / Backup</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1100px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
label{display:block;margin:10px 0 5px;font-weight:600}
input[type=date]{padding:8px;border:1px solid #bbb;border-radius:4px}
.inline{display:flex;gap:16px;flex-wrap:wrap}.inline>div{flex:1;min-width:220px}
.station-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:10px}
.station-option{border:1px solid #ddd;border-radius:6px;padding:10px;background:#fafafa}
button{padding:9px 15px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer}
.secondary{background:#666}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}
</style>
</head>
<body>
<div class="wrap">

<p><?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a></p>

<div class="card">
<h1>Data Export / Backup</h1>

<p class="hint">
This tool is available only inside the password-protected Admin area.
Exports include all database columns for the selected station table(s).
</p>

<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['export_csrf']) ?>">

<div class="inline">
<div>
<label>Start date (UTC)</label>
<input type="date" name="start_date" value="<?= h($startDate) ?>" required>
</div>
<div>
<label>End date (UTC)</label>
<input type="date" name="end_date" value="<?= h($endDate) ?>" required>
</div>
</div>

<h2>Stations</h2>
<div class="station-grid">
<?php foreach($stations as $code=>$summary): ?>
<div class="station-option">
<label>
<input
    type="checkbox"
    name="stations[]"
    value="<?= h($code) ?>"
    <?= in_array($code,$selectedStations,true)?'checked':'' ?>
>
<?= h($summary['name'] ?? $code) ?> <code><?= h($code) ?></code>
</label>
</div>
<?php endforeach; ?>
</div>

<p class="hint">
Current limits:
<?= (int)$limits['max_days'] ?> days,
<?= number_format((int)$limits['max_rows_per_station']) ?> rows/station,
<?= number_format((int)$limits['max_total_rows']) ?> total rows,
<?= (int)$limits['max_stations'] ?> stations.
</p>

<p>
<button type="submit" name="action" value="estimate">Estimate Export</button>
</p>

<?php if($estimate): ?>
<h2>Export Estimate</h2>

<table>
<thead><tr><th>Station</th><th>Rows</th></tr></thead>
<tbody>
<?php foreach($estimate['stations'] as $detail): ?>
<tr>
<td><?= h($detail['name']) ?> <code><?= h($detail['code']) ?></code></td>
<td><?= number_format((int)$detail['rows']) ?></td>
</tr>
<?php endforeach; ?>
<tr>
<th>Total</th>
<th><?= number_format((int)$estimate['total_rows']) ?></th>
</tr>
</tbody>
</table>

<p>
<button type="submit" name="action" value="download">
<?= count($estimate['stations'])===1 ? 'Download CSV' : 'Download ZIP' ?>
</button>
</p>
<?php endif; ?>

</form>
</div>

<div class="card">
<h2>Export behavior</h2>
<p>
A single selected station downloads as one CSV file. Multiple selected stations
download as a ZIP archive containing one CSV per station plus a small manifest.
</p>
<p>
The export is rejected before download if the selected date range, row count,
total row count, or station count exceeds the configured limits.
</p>
</div>

</div>
<?= slim_admin_help_assets() ?>
</body>
</html>
