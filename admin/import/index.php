<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

if(empty($_SESSION['import_csrf'])) {
    $_SESSION['import_csrf']=bin2hex(random_bytes(24));
}

function h(string $v): string {
    return htmlspecialchars($v,ENT_QUOTES,'UTF-8');
}

/**
 * Parse a CSV observation timestamp and normalize it to UTC for storage.
 * Numeric values are Unix epoch seconds (or milliseconds) and therefore
 * never receive a local-time offset.
 */
function import_parse_timestamp_utc(string $value, string $stationTimezone = 'UTC'): ?string {
    $value=trim($value);
    if($value==='') return null;

    try {
        if(is_numeric($value)) {
            $numeric=(float)$value;

            // Accept both Unix epoch seconds and the common 13-digit
            // millisecond representation.
            if(abs($numeric)>=100000000000) {
                $numeric/=1000.0;
            }

            $seconds=(int)floor($numeric);

            // Reject values outside MySQL DATETIME's practical supported range.
            if($seconds<-62135596800 || $seconds>253402300799) {
                return null;
            }

            $dt=(new DateTimeImmutable('@'.$seconds))
                ->setTimezone(new DateTimeZone('UTC'));

            return $dt->format('Y-m-d H:i:s');
        }

        // Non-numeric CSV timestamps are interpreted using the configured
        // SLIM system timezone, then normalized to UTC.
        $tz=$stationTimezone;
        $tz=slim_valid_timezone($tz);
        $dt=new DateTimeImmutable($value,new DateTimeZone($tz));

        return $dt->setTimezone(new DateTimeZone('UTC'))
                  ->format('Y-m-d H:i:s');
    } catch(Throwable $e) {
        return null;
    }
}

function import_tmp_dir(): string {
    $dir=lakestat_config_path('runtime'.DIRECTORY_SEPARATOR.'imports');

    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create protected import working directory.');
    }

    return $dir;
}

function import_store_upload(array $file,array $limits): array {
    if(!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid CSV upload.');
    }

    if((int)$file['error']!==UPLOAD_ERR_OK) {
        throw new RuntimeException('CSV upload failed with PHP error code '.(int)$file['error'].'.');
    }

    if((int)$file['size']>$limits['max_file_bytes']) {
        throw new RuntimeException(
            'CSV exceeds the current size limit of '.
            number_format($limits['max_file_bytes']/1048576,1).' MB.'
        );
    }

    if(!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('CSV did not pass PHP upload validation.');
    }

    $dir=import_tmp_dir();
    $token=bin2hex(random_bytes(12));
    $path=$dir.DIRECTORY_SEPARATOR.$token.'.csv';

    if(!move_uploaded_file($file['tmp_name'],$path)) {
        throw new RuntimeException('Unable to save uploaded CSV.');
    }

    @chmod($path,0640);

    $_SESSION['import_file_token']=$token;

    return [
        'token'=>$token,
        'path'=>$path,
        'size'=>(int)$file['size'],
        'original_name'=>(string)($file['name'] ?? 'upload.csv')
    ];
}

function import_path_from_token(string $token): string {
    if(!preg_match('/^[a-f0-9]{24}$/',$token)) throw new RuntimeException('Import file token is invalid.');
    $path=import_tmp_dir().DIRECTORY_SEPARATOR.$token.'.csv';
    if(!is_file($path)) throw new RuntimeException('Uploaded CSV is no longer available. Upload it again.');
    return $path;
}

function import_existing_station(
    PDO $pdo,
    array $station,
    string $csvPath,
    array $header,
    array $mapping,
    string $timestampColumn,
    string $duplicateMode
): array {
    $table=lakestat_safe_identifier((string)$station['table']);
    $headerIndex=array_flip($header);
    if(!isset($headerIndex[$timestampColumn])) {
        throw new RuntimeException('Timestamp column is not present in CSV.');
    }

    // Build one stable insert-column list for the entire import. This lets
    // SLIM insert observations in batches instead of preparing/executing one
    // INSERT statement for every CSV row.
    $fieldColumns=[];
    foreach($station['fields'] ?? [] as $fieldKey=>$meta) {
        $column=lakestat_safe_identifier((string)($meta['column'] ?? $fieldKey));
        $fieldColumns[$fieldKey]=$column;
    }

    $insertColumns=['observed_at','received_at'];
    foreach(($station['fields'] ?? []) as $fieldKey=>$meta) {
        $column=$fieldColumns[$fieldKey];
        if(!in_array($column,$insertColumns,true)) $insertColumns[]=$column;
        $qc=is_array($meta['qc'] ?? null) ? $meta['qc'] : [];
        if(!empty($qc['enabled']) && ($meta['type'] ?? 'number')!=='text' && empty($meta['derived'])) {
            foreach([$column.'_raw',$column.'_qc'] as $extra) {
                if(!in_array($extra,$insertColumns,true)) $insertColumns[]=$extra;
            }
        }
    }

    $batchSize=500;
    $batch=[];
    $inserted=0;
    $skipped=0;
    $receivedAt=gmdate('Y-m-d H:i:s');

    $insertBatch=function(array $rows) use (
        $pdo,$table,$insertColumns,$duplicateMode,&$inserted,&$skipped
    ): void {
        if(!$rows) return;

        // For "skip duplicate timestamp", check the batch in one SELECT
        // rather than issuing one SELECT per CSV row.
        if($duplicateMode==='skip_timestamp') {
            $timestamps=array_values(array_unique(array_column($rows,'observed_at')));
            $existing=[];

            foreach(array_chunk($timestamps,500) as $timestampChunk) {
                $ph=[];
                $bind=[];
                foreach($timestampChunk as $i=>$ts) {
                    $key=':dup_'.$i;
                    $ph[]=$key;
                    $bind[$key]=$ts;
                }
                if($ph) {
                    $sql="SELECT `observed_at` AS ts
                          FROM `{$table}`
                          WHERE `observed_at` IN (".
                          implode(',',$ph).")";
                    $stmt=$pdo->prepare($sql);
                    $stmt->execute($bind);
                    while(($ts=$stmt->fetchColumn())!==false) {
                        $existing[(string)$ts]=true;
                    }
                }
            }

            $seen=[];
            $filtered=[];
            foreach($rows as $row) {
                $ts=(string)$row['observed_at'];
                if(isset($existing[$ts]) || isset($seen[$ts])) {
                    $skipped++;
                    continue;
                }
                $seen[$ts]=true;
                $filtered[]=$row;
            }
            $rows=$filtered;
            if(!$rows) return;
        }

        $quotedColumns=implode(',',array_map(
            static fn($c)=>'`'.str_replace('`','``',$c).'`',
            $insertColumns
        ));

        // Keep each SQL statement comfortably below common placeholder and
        // packet limits even if a station has many variables.
        $columnCount=max(1,count($insertColumns));
        $maxRowsPerStatement=max(1,min(500,(int)floor(50000/$columnCount)));

        foreach(array_chunk($rows,$maxRowsPerStatement) as $chunk) {
            $valueGroups=[];
            $bind=[];
            $n=0;

            foreach($chunk as $row) {
                $placeholders=[];
                foreach($insertColumns as $column) {
                    $key=':v'.$n++;
                    $placeholders[]=$key;
                    $bind[$key]=$row[$column] ?? null;
                }
                $valueGroups[]='('.implode(',',$placeholders).')';
            }

            $sql="INSERT INTO `{$table}` ({$quotedColumns}) VALUES ".
                 implode(',',$valueGroups);
            $stmt=$pdo->prepare($sql);
            $stmt->execute($bind);
            $inserted+=count($chunk);
        }
    };

    $pdo->beginTransaction();
    try {
        foreach(slim_import_stream_rows($csvPath,$header) as $row) {
            $timestamp=import_parse_timestamp_utc(
                (string)$row[$headerIndex[$timestampColumn]],
                slim_valid_timezone((string)($station['timezone'] ?? (slim_system_config()['timezone'] ?? 'UTC')))
            );
            if($timestamp===null) {
                $skipped++;
                continue;
            }

            $rowValues=[];
            foreach($mapping as $csvColumn=>$stationField) {
                if(
                    $stationField==='' ||
                    !isset($headerIndex[$csvColumn]) ||
                    !isset($station['fields'][$stationField])
                ) continue;

                $meta=$station['fields'][$stationField];
                $column=$fieldColumns[$stationField];
                $raw=$row[$headerIndex[$csvColumn]] ?? null;

                // Preserve QC diagnostics and raw values just like live ingestion.
                if(!empty($meta['derived'])) continue;
                try {
                    $normalized=slim_observation_normalize_field($meta, $raw===null || trim((string)$raw)==='' ? null : $raw);
                } catch(InvalidArgumentException $invalidValue) {
                    // Match live ingest's rejection of malformed numeric fields.
                    throw new RuntimeException('CSV field '.(string)$csvColumn.': '.$invalidValue->getMessage(),0,$invalidValue);
                }
                $rowValues[$column]=$normalized['value'];
                if(array_key_exists('qc',$normalized)) {
                    $rowValues[$column.'_raw']=$normalized['raw'];
                    $rowValues[$column.'_qc']=$normalized['qc'];
                }
            }

            $rowValues=lakestat_apply_derived_variables($station,$rowValues,['observed_at'=>$timestamp,'received_at'=>$receivedAt]);

            // Start with all configured columns as NULL so every row in a
            // batch has the same shape.
            $prepared=[
                'observed_at'=>$timestamp,
                'received_at'=>$receivedAt
            ];
            foreach($insertColumns as $column) {
                if($column==='observed_at' || $column==='received_at') continue;
                $prepared[$column]=$rowValues[$column] ?? null;
            }

            $batch[]=$prepared;
            if(count($batch)>=$batchSize) {
                $insertBatch($batch);
                $batch=[];
            }
        }

        if($batch) $insertBatch($batch);

        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ['inserted'=>$inserted,'skipped'=>$skipped];
}

function import_create_station(
    PDO $pdo,
    string $code,
    string $name,
    float $lat,
    float $lon,
    string $csvPath,
    array $header,
    string $timestampColumn,
    array $newFields,
    string $duplicateMode
): array {
    $code=lakestat_station_code($code);

    $stationPath=lakestat_config_path(
        'stations'.DIRECTORY_SEPARATOR.$code.'.php'
    );

    if(is_file($stationPath)) {
        throw new RuntimeException('Station '.$code.' already exists.');
    }

    $table=lakestat_safe_identifier($code);

    $fields=[];
    $columnDefs=[];

    foreach($newFields as $field) {
        if(empty($field['include'])) continue;

        $key=slim_import_safe_field_name((string)$field['field']);

        if($key==='' || isset($fields[$key])) {
            continue;
        }

        $type=($field['type'] ?? 'number')==='text' ? 'text' : 'number';
        $column=$key;

        $fields[$key]=[
            'column'=>$column,
            'json_key'=>$key,
            'label'=>trim((string)($field['label'] ?? $key)),
            'units'=>trim((string)($field['units'] ?? '')),
            'type'=>$type
        ];

        $columnDefs[]='`'.$column.'` '.($type==='text' ? 'TEXT NULL' : 'DOUBLE NULL');
    }

    if(!$fields) {
        throw new RuntimeException('Select at least one CSV data column for the new station.');
    }

    $sql="CREATE TABLE `{$table}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `observed_at` DATETIME NOT NULL,
        `received_at` DATETIME NOT NULL,
        ".implode(",\n        ",$columnDefs).",
        INDEX `idx_observed_at` (`observed_at`),
        INDEX `idx_received_at` (`received_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);

    $apiKey=bin2hex(random_bytes(24));

    $station=[
        'code'=>$code,
        'name'=>$name!=='' ? $name : $code,
        'table'=>$table,
        'latitude'=>$lat,
        'longitude'=>$lon,
        'api_key_hash'=>slim_api_key_digest($apiKey),
        // Original source column selected as the observation timestamp during CSV import.
        // SLIM stores normalized observation time internally in `observed_at`.
        'timestamp_field'=>$timestampColumn,
        'fields'=>$fields
    ];

    lakestat_write_php_config($stationPath,$station);

    $mapping=[];
    foreach($newFields as $field) {
        if(empty($field['include'])) continue;
        $key=slim_import_safe_field_name((string)$field['field']);
        if($key!=='') $mapping[(string)$field['csv']]=$key;
    }

    $result=import_existing_station(
        $pdo,$station,$csvPath,$header,$mapping,$timestampColumn,$duplicateMode
    );

    $result['api_key']=$apiKey;
    return $result;
}

$error='';
$message='';
$preview=null;
$limits=slim_import_limits();
$stations=lakestat_list_stations();

$mode=(string)($_POST['mode'] ?? 'existing');
$stage=(string)($_POST['stage'] ?? 'upload');

try {
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($_SESSION['import_csrf'] ?? '',$_POST['csrf'] ?? '')) {
            throw new RuntimeException('Session validation failed. Reload and try again.');
        }

        if($stage==='upload') {
            $stored=import_store_upload($_FILES['csv_file'] ?? [],$limits);
            $scan=slim_import_scan_csv(
                $stored['path'],
                $limits['max_rows'],
                $limits['max_columns'],
                20
            );

            $_SESSION['import_preview']=[
                'token'=>$stored['token'],
                'original_name'=>$stored['original_name'],
                'file_size'=>$stored['size'],
                'header'=>$scan['header'],
                'preview_rows'=>$scan['preview_rows'],
                'row_count'=>$scan['row_count'],
                'mode'=>$mode
            ];
            $preview=$_SESSION['import_preview'];

            $stage='map';

        } elseif($stage==='commit') {
            if(empty($_SESSION['import_preview'])) {
                throw new RuntimeException('Import preview expired. Upload the CSV again.');
            }

            $csv=$_SESSION['import_preview'];
            $csvPath=import_path_from_token((string)$csv['token']);
            $pdo=lakestat_db();
            $timestampColumn=(string)($_POST['timestamp_column'] ?? '');
            $duplicateMode=(string)($_POST['duplicate_mode'] ?? 'append');

            if($duplicateMode!=='append' && $duplicateMode!=='skip_timestamp') {
                $duplicateMode='append';
            }

            if($mode==='existing') {
                $code=lakestat_station_code((string)($_POST['station'] ?? ''));
                $station=lakestat_station_config($code);

                $mapping=[];
                foreach($csv['header'] as $column) {
                    $mapping[$column]=(string)($_POST['map_'.$column] ?? '');
                }

                $result=import_existing_station(
                    $pdo,$station,$csvPath,$csv['header'],$mapping,$timestampColumn,$duplicateMode
                );

                $message=
                    'Import complete. Inserted '.$result['inserted'].
                    ' rows; skipped '.$result['skipped'].' rows.';

            } else {
                $fields=[];

                foreach($csv['header'] as $column) {
                    if($column===$timestampColumn) continue;

                    $safe=slim_import_safe_field_name($column);

                    $values=[];
                    $idx=array_search($column,$csv['header'],true);

                    foreach(array_slice($csv['preview_rows'],0,25) as $row) {
                        $values[]=$row[$idx] ?? '';
                    }

                    $fields[]=[
                        'csv'=>$column,
                        'include'=>isset($_POST['include_'.$column]),
                        'field'=>(string)($_POST['field_'.$column] ?? $safe),
                        'label'=>(string)($_POST['label_'.$column] ?? $column),
                        'units'=>(string)($_POST['units_'.$column] ?? ''),
                        'type'=>(string)($_POST['type_'.$column] ?? slim_import_detect_type($values))
                    ];
                }

                $result=import_create_station(
                    $pdo,
                    (string)($_POST['new_station_code'] ?? ''),
                    trim((string)($_POST['new_station_name'] ?? '')),
                    (float)($_POST['new_latitude'] ?? 0),
                    (float)($_POST['new_longitude'] ?? 0),
                    $csvPath,
                    $csv['header'],
                    $timestampColumn,
                    $fields,
                    $duplicateMode
                );

                $message=
                    'Station created and import complete. Inserted '.
                    $result['inserted'].' rows; skipped '.$result['skipped'].
                    ' rows. API key: '.$result['api_key'];
            }

            @unlink($csvPath);
            unset($_SESSION['import_preview'],$_SESSION['import_file_token']);
            $stage='done';
        }
    }
} catch(Throwable $e) {
    $error=$e->getMessage();
}

$preview = $preview ?: ($_SESSION['import_preview'] ?? null);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CSV Import</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1200px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
label{display:block;margin:10px 0 5px;font-weight:600}
input,select{padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
input[type=text],input[type=number],select{width:100%;max-width:520px}
.inline{display:flex;gap:16px;flex-wrap:wrap}.inline>div{flex:1;min-width:220px}
button{padding:9px 15px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer}
.secondary{background:#666}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
.preview{overflow:auto;max-height:360px}
code{font-size:.9em}
</style>
</head>
<body>
<div class="wrap">
<p><?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a></p>

<div class="card">
<h1>CSV Import</h1>
<p class="hint">
Two-stage workflow: upload and preview first, then explicitly commit after
mapping columns and validating the import.
</p>

<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>

<?php if($stage==='upload' || $stage==='done'): ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['import_csrf']) ?>">
<input type="hidden" name="stage" value="upload">

<label>Import mode <?= slim_help_button('Import mode','Choose whether the CSV adds data to an existing station or creates a new station.') ?></label>
<select name="mode">
<option value="existing">Add data to an existing station</option>
<option value="new">Create a new station from CSV</option>
</select>

<label>CSV file <?= slim_help_button('CSV file','Upload a CSV with a header row. The importer previews the file first and streams rows during import.') ?></label>
<input type="file" name="csv_file" accept=".csv,text/csv" required>

<p class="hint">
Import limits:
<?= number_format($limits['max_file_bytes']/1048576,1) ?> MB,
<?= number_format($limits['max_rows']) ?> rows,
<?= (int)$limits['max_columns'] ?> columns.<br>
Server PHP limits: upload_max_filesize=<?= h((string)ini_get('upload_max_filesize')) ?>,
post_max_size=<?= h((string)ini_get('post_max_size')) ?>,
memory_limit=<?= h((string)ini_get('memory_limit')) ?>.
</p>

<p><button type="submit">Upload & Preview</button></p>
</form>
<?php endif; ?>

<?php if($stage==='map' && $preview): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['import_csrf']) ?>">
<input type="hidden" name="stage" value="commit">
<input type="hidden" name="mode" value="<?= h((string)($_SESSION['import_preview']['mode'] ?? $mode)) ?>">

<h2>Preview</h2>
<p>
File: <strong><?= h((string)($preview['original_name'] ?? 'upload.csv')) ?></strong><br>
Size: <?= number_format((int)($preview['file_size'] ?? 0)/1048576,2) ?> MB<br>
Rows detected: <?= number_format((int)$preview['row_count']) ?>
</p>

<div class="preview">
<table>
<thead><tr>
<?php foreach($preview['header'] as $column): ?>
<th><?= h($column) ?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach(array_slice($preview['preview_rows'],0,20) as $row): ?>
<tr>
<?php foreach($row as $value): ?><td><?= h((string)$value) ?></td><?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<label>Timestamp column <?= slim_help_button('Timestamp column','CSV column containing the observation date/time used for imported rows.') ?></label>
<select name="timestamp_column" required>
<option value="">Choose...</option>
<?php foreach($preview['header'] as $column): ?>
<option value="<?= h($column) ?>"><?= h($column) ?></option>
<?php endforeach; ?>
</select>

<label>Duplicate handling <?= slim_help_button('Duplicate handling','Append all valid rows or skip rows whose observation timestamp already exists. SLIM does not silently overwrite existing rows.') ?></label>
<select name="duplicate_mode">
<option value="append">Append all valid rows</option>
<option value="skip_timestamp">Skip rows whose timestamp already exists</option>
</select>

<?php if(($_SESSION['import_preview']['mode'] ?? $mode)==='existing'): ?>
<h2>Existing Station Mapping</h2>

<label>Station</label>
<select name="station" required>
<option value="">Choose...</option>
<?php foreach($stations as $code=>$summary): ?>
<option value="<?= h($code) ?>"><?= h($summary['name'] ?? $code) ?> — <?= h($code) ?></option>
<?php endforeach; ?>
</select>

<p class="hint">Map CSV columns only to fields that already exist in the selected station. Unmapped CSV columns are ignored.</p>

<?php foreach($preview['header'] as $column): ?>
<label><?= h($column) ?></label>
<select name="map_<?= h($column) ?>">
<option value="">Ignore this column</option>
<?php
foreach($stations as $code=>$summary):
    try { $st=lakestat_station_config($code); } catch(Throwable $e) { continue; }
    foreach($st['fields'] as $fieldKey=>$meta):
?>
<option value="<?= h($fieldKey) ?>"><?= h($code) ?>: <?= h($meta['label'] ?? $fieldKey) ?> (<?= h($fieldKey) ?>)</option>
<?php endforeach; endforeach; ?>
</select>
<?php endforeach; ?>

<?php else: ?>
<h2>New Station</h2>

<div class="inline">
<div><label>Station code</label><input type="text" name="new_station_code" required></div>
<div><label>Station name</label><input type="text" name="new_station_name" required></div>
</div>

<div class="inline">
<div><label>Latitude</label><input type="number" step="any" name="new_latitude" required></div>
<div><label>Longitude</label><input type="number" step="any" name="new_longitude" required></div>
</div>

<h2>New Station Fields</h2>
<table>
<thead><tr><th>Use</th><th>CSV column</th><th>Field name</th><th>Label</th><th>Units</th><th>Type</th></tr></thead>
<tbody>
<?php foreach($preview['header'] as $column):
$safe=slim_import_safe_field_name($column);
$idx=array_search($column,$preview['header'],true);
$values=[];
foreach(array_slice($preview['preview_rows'],0,25) as $row){$values[]=$row[$idx] ?? '';}
$type=slim_import_detect_type($values);
?>
<tr>
<td><input type="checkbox" name="include_<?= h($column) ?>" checked></td>
<td><?= h($column) ?></td>
<td><input type="text" name="field_<?= h($column) ?>" value="<?= h($safe) ?>"></td>
<td><input type="text" name="label_<?= h($column) ?>" value="<?= h($column) ?>"></td>
<td><input type="text" name="units_<?= h($column) ?>"></td>
<td>
<select name="type_<?= h($column) ?>">
<option value="number" <?= $type==='number'?'selected':'' ?>>Number</option>
<option value="text" <?= $type==='text'?'selected':'' ?>>Text</option>
</select>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="hint">Choose the timestamp column above; you can uncheck it here if it should not also become a data field.</p>
<?php endif; ?>

<p style="margin-top:20px;">
<button type="submit">Commit Import</button>
<a href="./" style="margin-left:12px;">Cancel</a>
</p>
</form>
<?php endif; ?>

</div>
</div>
<?= slim_admin_help_assets() ?>
</body>
</html>
