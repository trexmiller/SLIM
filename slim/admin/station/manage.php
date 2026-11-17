<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

lakestat_ensure_config_dir();

$stationSection=defined('SLIM_STATION_SECTION') ? SLIM_STATION_SECTION : 'all';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Build a safe SQL expression for a SLIM derived-variable definition.
 * The source and target identifiers are validated elsewhere; operands are
 * emitted from validated PHP floats rather than raw request text.
 */
function slim_derived_sql_expression(string $sourceColumn,array $steps,array $station): string {
    $sourceColumn=lakestat_safe_identifier($sourceColumn);
    $expr='`'.$sourceColumn.'`';

    foreach($steps as $step) {
        $operation=(string)($step['operation'] ?? '');
        $operandType=(string)($step['operand_type'] ?? 'constant');

        if($operandType==='variable') {
            $variable=(string)($step['variable'] ?? '');
            if($variable==='' || !isset($station['fields'][$variable])) {
                throw new RuntimeException('A derived-variable source variable is no longer available.');
            }
            $column=lakestat_safe_identifier(
                (string)($station['fields'][$variable]['column'] ?? $variable)
            );
            $operand='`'.$column.'`';
        } elseif($operandType==='constant') {
            $value=(float)($step['value'] ?? 0);
            if($operation==='divide' && $value==0.0) {
                throw new RuntimeException('A derived-variable calculation cannot divide by a constant zero.');
            }
            $operand=sprintf('%.17g',$value);
        } else {
            throw new RuntimeException('Unsupported derived-variable operand type.');
        }

        if($operation==='multiply') {
            $expr='('.$expr.' * '.$operand.')';
        } elseif($operation==='divide') {
            // NULLIF safely converts a zero variable denominator to NULL.
            $expr='('.$expr.' / NULLIF('.$operand.',0))';
        } elseif($operation==='add') {
            $expr='('.$expr.' + '.$operand.')';
        } elseif($operation==='subtract') {
            $expr='('.$expr.' - '.$operand.')';
        } else {
            throw new RuntimeException('Unsupported derived-variable calculation step.');
        }
    }

    return $expr;
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        throw new RuntimeException('Session validation failed. Reload the page and try again.');
    }
}

function sql_type_from_field_type(string $type): string {
    if ($type === 'integer') return 'BIGINT NULL';
    if ($type === 'text') return 'VARCHAR(255) NULL';
    return 'DOUBLE NULL';
}

/**
 * Return a built-in presentation-unit conversion for a configured units string.
 * These conversions are intentionally limited to common environmental-monitoring
 * SI/US-customary pairs and are stored using SLIM's normal derived-variable rules.
 */
function slim_standard_unit_conversion(string $units): ?array {
    $u=strtolower(trim(str_replace(['°',' '],['',''],$units)));
    $map=[
        'c'=>['key'=>'c_to_f','label'=>'Celsius to Fahrenheit','units'=>'°F','suffix'=>'f',
              'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>1.8],
                        ['operation'=>'add','operand_type'=>'constant','value'=>32.0]]],
        'degc'=>['key'=>'c_to_f','label'=>'Celsius to Fahrenheit','units'=>'°F','suffix'=>'f',
                 'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>1.8],
                           ['operation'=>'add','operand_type'=>'constant','value'=>32.0]]],
        'f'=>['key'=>'f_to_c','label'=>'Fahrenheit to Celsius','units'=>'°C','suffix'=>'c',
              'steps'=>[['operation'=>'subtract','operand_type'=>'constant','value'=>32.0],
                        ['operation'=>'multiply','operand_type'=>'constant','value'=>0.5555555555555556]]],
        'degf'=>['key'=>'f_to_c','label'=>'Fahrenheit to Celsius','units'=>'°C','suffix'=>'c',
                 'steps'=>[['operation'=>'subtract','operand_type'=>'constant','value'=>32.0],
                           ['operation'=>'multiply','operand_type'=>'constant','value'=>0.5555555555555556]]],
        'm/s'=>['key'=>'ms_to_mph','label'=>'m/s to mph','units'=>'mph','suffix'=>'mph',
                 'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>2.2369362920544]]],
        'mps'=>['key'=>'ms_to_mph','label'=>'m/s to mph','units'=>'mph','suffix'=>'mph',
                 'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>2.2369362920544]]],
        'mph'=>['key'=>'mph_to_ms','label'=>'mph to m/s','units'=>'m/s','suffix'=>'ms',
                 'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>0.44704]]],
        'm'=>['key'=>'m_to_ft','label'=>'meters to feet','units'=>'ft','suffix'=>'ft',
              'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>3.2808398950131]]],
        'meter'=>['key'=>'m_to_ft','label'=>'meters to feet','units'=>'ft','suffix'=>'ft',
                  'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>3.2808398950131]]],
        'meters'=>['key'=>'m_to_ft','label'=>'meters to feet','units'=>'ft','suffix'=>'ft',
                   'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>3.2808398950131]]],
        'ft'=>['key'=>'ft_to_m','label'=>'feet to meters','units'=>'m','suffix'=>'m',
               'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>0.3048]]],
        'foot'=>['key'=>'ft_to_m','label'=>'feet to meters','units'=>'m','suffix'=>'m',
                 'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>0.3048]]],
        'feet'=>['key'=>'ft_to_m','label'=>'feet to meters','units'=>'m','suffix'=>'m',
                 'steps'=>[['operation'=>'multiply','operand_type'=>'constant','value'=>0.3048]]],
    ];
    return $map[$u] ?? null;
}

function slim_standard_conversion_target(string $source,array $conversion): string {
    return lakestat_safe_identifier($source.(string)$conversion['suffix']);
}


/** Return all derived variables that directly or indirectly depend on a field. */
function slim_dependent_derived_variables(array $station,string $source): array {
    $rules=is_array($station['derived_variables'] ?? null) ? $station['derived_variables'] : [];
    $delete=[$source=>true];
    $dependents=[];
    $changed=true;
    while($changed) {
        $changed=false;
        foreach($rules as $target=>$rule) {
            if(isset($delete[$target])) continue;
            $uses=[];
            $base=(string)($rule['source'] ?? '');
            if($base!=='') $uses[$base]=true;
            foreach((array)($rule['steps'] ?? []) as $step) {
                if(($step['operand_type'] ?? '')==='variable') {
                    $operand=(string)($step['variable'] ?? $step['value'] ?? '');
                    if($operand!=='') $uses[$operand]=true;
                }
            }
            foreach($uses as $used=>$unused) {
                if(isset($delete[$used])) {
                    $delete[$target]=true;
                    $dependents[$target]=true;
                    $changed=true;
                    break;
                }
            }
        }
    }
    return array_keys($dependents);
}

/** True when a dashboard display contains a reference to one of the deleted fields. */
function slim_display_references_fields($value,array $deleted,string $parentKey=''): bool {
    if(!is_array($value)) return false;
    foreach($value as $key=>$item) {
        $keyString=(string)$key;
        if(is_string($item)) {
            $isFieldReference=in_array($keyString,[
                'field','direction_field','magnitude_field','source','variable'
            ],true);
            if($isFieldReference && isset($deleted[$item])) return true;
        } elseif(is_array($item) && slim_display_references_fields($item,$deleted,$keyString)) {
            return true;
        }
    }
    return false;
}

$error='';
$message='';
// Retain the submitted create-station draft on any validation or database error.
$createDraft=[];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create_station') {
    $createDraft=$_POST;
}
function create_draft_value(array $draft,string $name,string $default=''): string {
    return isset($draft[$name]) && is_scalar($draft[$name]) ? (string)$draft[$name] : $default;
}


$dbConfigured = is_file(lakestat_config_path('database.php'));
$systemConfigured = is_file(lakestat_config_path('system.php'));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';

        if ($action === 'regenerate_api_key') {
            $code = lakestat_station_code((string)($_POST['station'] ?? ''));
            $confirmation = (string)($_POST['confirm_station_code'] ?? '');
            if (!hash_equals($code, $confirmation)) {
                throw new RuntimeException('Enter the station code exactly to confirm API key replacement.');
            }
            $station = lakestat_station_config($code);
            $newApiKey = lakestat_random_api_key();
            $station['api_key_hash'] = slim_api_key_digest($newApiKey);
            // Reuse the existing atomic config writer and backup behavior.
            // The database table and historical observations are untouched.
            lakestat_write_php_config(
                lakestat_config_path('stations' . DIRECTORY_SEPARATOR . $code . '.php'),
                $station
            );
            $_SESSION['regenerated_station_api_key'] = [
                'code' => $code,
                'api_key' => $newApiKey,
            ];
            $_GET['station'] = $code;
            $message = 'API key replaced. Update the device before its next transmission. The old key is now invalid.';
        }

        if ($action === 'create_station') {
            $pdo = lakestat_db();

            $code = lakestat_station_code($_POST['station_code'] ?? '');
            $name = trim($_POST['station_name'] ?? '');
            $table = lakestat_safe_identifier($_POST['table_name'] ?? '');
            $latitude = (float)($_POST['latitude'] ?? 0);
            $longitude = (float)($_POST['longitude'] ?? 0);
            $stationTimezone=slim_valid_timezone((string)($_POST['station_timezone'] ?? (slim_system_config()['timezone'] ?? 'UTC')));

            if ($name === '') throw new RuntimeException('Station name is required.');

            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                throw new RuntimeException('Latitude or longitude is outside the valid range.');
            }

            if (lakestat_table_exists($pdo, $table)) {
                throw new RuntimeException(
                    "MySQL table '{$table}' already exists. SLIM will not overwrite or reuse it automatically."
                );
            }

            $stationPath = lakestat_config_path(
                'stations' . DIRECTORY_SEPARATOR . $code . '.php'
            );

            if (is_file($stationPath)) {
                throw new RuntimeException(
                    "Station code '{$code}' already exists."
                );
            }

            $keys = $_POST['field_key'] ?? [];
            $labels = $_POST['field_label'] ?? [];
            $units = $_POST['field_units'] ?? [];
            $types = $_POST['field_type'] ?? [];

            $fields = [];

            foreach ($keys as $i=>$rawKey) {
                $key = trim((string)$rawKey);
                if ($key === '') continue;

                $key = lakestat_safe_identifier($key);

                if (isset($fields[$key])) {
                    throw new RuntimeException("Duplicate field '{$key}'.");
                }

                $type = isset($types[$i]) ? (string)$types[$i] : 'number';
                if (!in_array($type, ['number','integer','text'], true)) {
                    $type = 'number';
                }

                $fields[$key] = [
                    'json_key' => $key,
                    'column' => $key,
                    'label' => trim((string)($labels[$i] ?? $key)),
                    'units' => trim((string)($units[$i] ?? '')),
                    'type' => $type,
                    'show_current' => true,
                    'show_graph' => $type !== 'text'
                ];
            }

            $autoDiscover=($_POST['field_setup_mode'] ?? 'auto')==='auto';
            if (!$autoDiscover && !$fields) throw new RuntimeException('Add at least one measurement field.');
            if ($autoDiscover) $fields=[];

            $station = [
                'code' => $code,
                'name' => $name,
                'table' => $table,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'timezone' => $stationTimezone,
                'api_key_hash' => slim_api_key_digest($newApiKey = lakestat_random_api_key()),
                // Stations remain hidden from the public home-page directory
                // until an administrator explicitly publishes them.
                'published' => false,
                'allow_legacy_http' => false,
                'fields' => $fields,
                'auto_discover' => $autoDiscover
            ];

            $columns = [
                "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY",
                "`observed_at` DATETIME NOT NULL",
                "`received_at` DATETIME NOT NULL"
            ];

            foreach ($fields as $field) {
                $columns[] = "`{$field['column']}` " . sql_type_from_field_type($field['type']);
            }

            $columns[] = "INDEX `idx_observed_at` (`observed_at`)";
            $columns[] = "INDEX `idx_received_at` (`received_at`)";

            $create = "CREATE TABLE `{$table}` (\n"
                    . implode(",\n", $columns)
                    . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $pdo->exec($create);

            try {
                lakestat_write_php_config($stationPath, $station);
            } catch (Throwable $configError) {
                try { $pdo->exec("DROP TABLE `{$table}`"); } catch (Throwable $cleanupError) {}
                throw $configError;
            }

            $_SESSION['created_station'] = array_merge($station, ['api_key' => $newApiKey]);
            $message = 'Station created successfully.';
            $createDraft=[];
        }

        if ($action === 'save_station_name') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);

            $newName=trim((string)($_POST['station_name_edit'] ?? ''));
            $timestampField=trim((string)($_POST['timestamp_field_edit'] ?? ''));

            if($newName==='') {
                throw new RuntimeException('Station name is required.');
            }

            $station['name']=$newName;
            $station['timezone']=slim_valid_timezone((string)($_POST['station_timezone_edit'] ?? ($station['timezone'] ?? (slim_system_config()['timezone'] ?? 'UTC'))));
            $station['published']=isset($_POST['published']);
            $station['allow_legacy_http']=isset($_POST['allow_legacy_http']);

            if($timestampField==='') {
                unset($station['timestamp_field']);
            } else {
                $station['timestamp_field']=$timestampField;
            }


            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message='Station details updated successfully.';
            $_GET['station']=$code;
        }

        if ($action === 'delete_station') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $mode=(string)($_POST['delete_mode'] ?? '');
            $typed=trim((string)($_POST['confirm_station_code'] ?? ''));

            if(!in_array($mode,['remove','permanent'],true)) {
                throw new RuntimeException('Choose a station deletion option.');
            }

            // Both deletion modes require typing the exact station code.
            if($typed!==$code) {
                throw new RuntimeException('Confirmation failed. Type the station code exactly as shown.');
            }

            if(!isset($_POST['confirm_delete'])) {
                throw new RuntimeException('You must check the confirmation box before deleting a station.');
            }

            $table=lakestat_safe_identifier((string)($station['table'] ?? ''));

            if($mode==='permanent') {
                if(!isset($_POST['confirm_drop_table'])) {
                    throw new RuntimeException('Permanent deletion requires confirmation that the MySQL data table will be deleted.');
                }

                $pdo=lakestat_db();

                if(lakestat_table_exists($pdo,$table)) {
                    // Identifier has already passed lakestat_safe_identifier().
                    $pdo->exec("DROP TABLE `{$table}`");
                }
            }

            $stationPath=lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php');
            // Dashboard configurations are stored in separate public and health
            // directories. Include the old flat path for legacy installations.
            $displayPaths=[
                lakestat_config_path('displays'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.$code.'.php'),
                lakestat_config_path('displays'.DIRECTORY_SEPARATOR.'health'.DIRECTORY_SEPARATOR.$code.'.php'),
                lakestat_config_path('displays'.DIRECTORY_SEPARATOR.$code.'.php'),
            ];

            // Remove station and all dashboard configurations, including the
            // .bak copies maintained by lakestat_write_php_config().
            $deletePaths=[$stationPath,$stationPath.'.bak'];
            foreach($displayPaths as $displayPath) {
                $deletePaths[]=$displayPath;
                $deletePaths[]=$displayPath.'.bak';
            }
            foreach($deletePaths as $deletePath) {
                if(is_file($deletePath) && !@unlink($deletePath)) {
                    throw new RuntimeException('Unable to remove SLIM configuration file: '.basename($deletePath));
                }
                clearstatcache(true,$deletePath);
                if(function_exists('opcache_invalidate')) @opcache_invalidate($deletePath,true);
            }

            // Dashboard image uploads are station-specific and no longer have
            // a valid owner after the station is removed.
            $assetDir=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'stations'.DIRECTORY_SEPARATOR.$code;
            if(is_dir($assetDir)) {
                $assetFiles=scandir($assetDir);
                if($assetFiles===false) {
                    throw new RuntimeException('Unable to inspect station asset directory during cleanup.');
                }
                foreach($assetFiles as $assetFile) {
                    if($assetFile==='.' || $assetFile==='..') continue;
                    $assetPath=$assetDir.DIRECTORY_SEPARATOR.$assetFile;
                    if(is_file($assetPath) || is_link($assetPath)) {
                        if(!@unlink($assetPath)) {
                            throw new RuntimeException('Unable to remove a station dashboard asset.');
                        }
                    }
                }
                @rmdir($assetDir);
            }

            // Remove queued webhook deliveries for a station that no longer
            // exists. This queue is SLIM operational state, not observation data.
            try {
                $pdo=$pdo ?? lakestat_db();
                if(lakestat_table_exists($pdo,'lakestat_webhook_queue')) {
                    $stmt=$pdo->prepare("DELETE FROM `lakestat_webhook_queue` WHERE `station`=:station");
                    $stmt->execute([':station'=>$code]);
                }
            } catch(Throwable $queueError) {
                // Do not make a completed station deletion fail solely because
                // an optional webhook queue could not be cleaned.
            }

            unset($_SESSION['created_station']);

            $message=$mode==='permanent'
                ? "Station {$code} and MySQL table {$table} were permanently deleted."
                : "Station {$code} was removed from SLIM. MySQL table {$table} was retained.";

            // Prevent the deleted station from being reselected later in this request.
            unset($_GET['station']);
        }

        if ($action === 'save_station_metadata') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);

            $names=is_array($_POST['metadata_name'] ?? null) ? $_POST['metadata_name'] : [];
            $values=is_array($_POST['metadata_value'] ?? null) ? $_POST['metadata_value'] : [];

            $metadata=[];
            $count=max(count($names),count($values));

            for($i=0;$i<$count;$i++) {
                $name=trim((string)($names[$i] ?? ''));
                $value=trim((string)($values[$i] ?? ''));

                // Completely blank rows are ignored.
                if($name==='' && $value==='') continue;
                if($name==='') {
                    throw new RuntimeException('Each custom station metadata value must have a field name.');
                }

                // Prevent duplicate labels from silently overwriting one another.
                if(array_key_exists($name,$metadata)) {
                    throw new RuntimeException('Duplicate custom metadata field: '.$name);
                }

                $metadata[$name]=$value;
            }

            if($metadata) {
                $station['metadata']=$metadata;
            } else {
                unset($station['metadata']);
            }

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message='Custom station metadata updated successfully.';
            $_GET['station']=$code;
        }

        if ($action === 'save_field_metadata') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);

            $labels=is_array($_POST['field_label_edit'] ?? null)
                ? $_POST['field_label_edit']
                : [];

            $units=is_array($_POST['field_units_edit'] ?? null)
                ? $_POST['field_units_edit']
                : [];

            foreach($station['fields'] as $key=>&$field) {
                $newLabel=trim((string)($labels[$key] ?? ($field['label'] ?? $key)));
                $newUnits=trim((string)($units[$key] ?? ($field['units'] ?? '')));

                if($newLabel==='') {
                    $newLabel=$key;
                }

                $field['label']=$newLabel;
                $field['units']=$newUnits;
            }
            unset($field);

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message='Variable labels and units updated successfully.';
            $_GET['station']=$code;
        }

        if ($action === 'save_variable_qc') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);

            $enabled=is_array($_POST['qc_enabled'] ?? null) ? $_POST['qc_enabled'] : [];
            $mins=is_array($_POST['qc_min'] ?? null) ? $_POST['qc_min'] : [];
            $maxs=is_array($_POST['qc_max'] ?? null) ? $_POST['qc_max'] : [];
            $diagnostics=is_array($_POST['qc_diagnostics'] ?? null) ? $_POST['qc_diagnostics'] : [];

            $schemaChanges=[];
            $newQc=[];

            foreach($station['fields'] as $key=>$field) {
                if(!empty($field['derived']) || ($field['type'] ?? 'number')==='text') continue;

                $isEnabled=isset($enabled[$key]);
                if(!$isEnabled) {
                    if(isset($field['qc'])) $station['fields'][$key]['qc']['enabled']=false;
                    continue;
                }

                $column=lakestat_safe_identifier((string)($field['column'] ?? $key));
                if(strlen($column)>58) {
                    throw new RuntimeException("Variable '{$key}' has a database column name too long to create QC companion columns.");
                }

                $rawMin=trim((string)($mins[$key] ?? ''));
                $rawMax=trim((string)($maxs[$key] ?? ''));
                if($rawMin!=='' && !is_numeric($rawMin)) throw new RuntimeException("QC minimum for '{$key}' must be numeric or blank.");
                if($rawMax!=='' && !is_numeric($rawMax)) throw new RuntimeException("QC maximum for '{$key}' must be numeric or blank.");

                $min=$rawMin==='' ? null : (float)$rawMin;
                $max=$rawMax==='' ? null : (float)$rawMax;
                if($min!==null && $max!==null && $min>$max) {
                    throw new RuntimeException("QC minimum for '{$key}' cannot be greater than its maximum.");
                }

                $codes=[];
                $lines=preg_split('/\R/',(string)($diagnostics[$key] ?? '')) ?: [];
                foreach($lines as $line) {
                    $line=trim($line);
                    if($line==='') continue;
                    $parts=preg_split('/\s*=\s*/',$line,2);
                    $rawValue=trim((string)($parts[0] ?? ''));
                    $meaning=trim((string)($parts[1] ?? 'Diagnostic code'));
                    if(!is_numeric($rawValue)) {
                        throw new RuntimeException("Diagnostic code '{$rawValue}' for '{$key}' must be numeric. Use one entry per line, for example -9998 = insufficient measurement time.");
                    }
                    $codes[]=['value'=>(float)$rawValue,'meaning'=>$meaning!=='' ? $meaning : 'Diagnostic code'];
                }

                $rawColumn=$column.'_raw';
                $qcColumn=$column.'_qc';
                if(!lakestat_column_exists($pdo,$table,$rawColumn)) {
                    $schemaChanges[]=['field'=>$key,'column'=>$column,'raw'=>$rawColumn,'qc'=>$qcColumn,'type'=>$field['type'] ?? 'number'];
                } elseif(!lakestat_column_exists($pdo,$table,$qcColumn)) {
                    $schemaChanges[]=['field'=>$key,'column'=>$column,'raw'=>null,'qc'=>$qcColumn,'type'=>$field['type'] ?? 'number'];
                }

                $newQc[$key]=[
                    'enabled'=>true,
                    'min'=>$min,
                    'max'=>$max,
                    'diagnostic_codes'=>$codes
                ];
            }

            if($schemaChanges) {
                lakestat_backup_station($pdo,$station);
                foreach($schemaChanges as $change) {
                    if($change['raw']!==null) {
                        $rawType=sql_type_from_field_type((string)$change['type']);
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$change['raw']}` {$rawType}");
                        $pdo->exec("UPDATE `{$table}` SET `{$change['raw']}`=`{$change['column']}` WHERE `{$change['raw']}` IS NULL");
                    }
                    if(!lakestat_column_exists($pdo,$table,(string)$change['qc'])) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$change['qc']}` VARCHAR(32) NULL");
                        $pdo->exec("UPDATE `{$table}` SET `{$change['qc']}`='legacy_unchecked' WHERE `{$change['column']}` IS NOT NULL");
                    }
                }
            }

            foreach($newQc as $key=>$qc) {
                $station['fields'][$key]['qc']=$qc;
            }

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message='Variable quality-control settings saved successfully.'
                .($schemaChanges ? ' A station backup was created before QC columns were added.' : '');
            $_GET['station']=$code;
        }

        if ($action === 'save_webhook' || $action === 'test_webhook') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);

            $url=trim((string)($_POST['webhook_url'] ?? ''));
            if($url!=='' && filter_var($url,FILTER_VALIDATE_URL)===false) {
                throw new RuntimeException('Webhook endpoint is not a valid URL.');
            }
            if($url!=='' && stripos($url,'https://')!==0) {
                throw new RuntimeException('Webhook endpoint must use HTTPS.');
            }

            if ($url !== '') slim_outbound_https_target($url);

            $headers=lakestat_webhook_headers(
                $_POST['webhook_header_name'] ?? [],
                $_POST['webhook_header_value'] ?? []
            );

            $fields=$_POST['webhook_fields'] ?? [];
            $allowedFields=[];
            foreach($station['fields'] as $key=>$field) {
                $allowedFields[]=(string)($field['json_key'] ?? $key);
            }
            $fields=array_values(array_intersect($allowedFields,array_map('strval',$fields)));

            $payloadMode=(string)($_POST['webhook_payload_mode'] ?? 'default');
            if(!in_array($payloadMode,['default','custom'],true)) $payloadMode='default';
            $jsonTemplate=trim((string)($_POST['webhook_json_template'] ?? ''));

            if($payloadMode==='custom') {
                $validationContext=['station'=>$code,'timestamp'=>'2026-01-01T00:00:00+00:00'];
                foreach($allowedFields as $jsonKey) $validationContext[$jsonKey]=null;
                lakestat_webhook_render_template($jsonTemplate,$validationContext);
            }

            $timeoutInput=trim((string)($_POST['webhook_timeout'] ?? '15'));
            if(!preg_match('/^[0-9]+$/D',$timeoutInput) || (int)$timeoutInput<1 || (int)$timeoutInput>30) {
                throw new RuntimeException('Webhook timeout must be a whole number between 1 and 30 seconds.');
            }
            $webhookTimeout=(int)$timeoutInput;

            $station['webhook']=[
                'enabled'=>isset($_POST['webhook_enabled']),
                'url'=>$url,
                'headers'=>$headers,
                'fields'=>$fields,
                'payload_mode'=>$payloadMode,
                'json_template'=>$jsonTemplate,
                'timeout'=>$webhookTimeout
            ];

            if($action==='save_webhook') {
                lakestat_write_php_config(
                    lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                    $station
                );
                $message='Webhook settings saved successfully.';
                $_GET['station']=$code;
            } else {
                if($url==='') throw new RuntimeException('Enter a webhook endpoint before sending a test.');

                $pdo=lakestat_db();
                $table=lakestat_safe_identifier((string)$station['table']);
                $latest=$pdo->query("SELECT * FROM `{$table}` ORDER BY `id` DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if(!$latest) throw new RuntimeException('No observations are available for a test webhook.');

                $testData=[];
                foreach($station['fields'] as $key=>$field) {
                    $jsonKey=(string)($field['json_key'] ?? $key);
                    $column=(string)($field['column'] ?? $key);
                    if(array_key_exists($column,$latest)) {
                        $value=$latest[$column];
                        if($value!==null) {
                            $type=(string)($field['type'] ?? 'number');
                            if($type==='number' && is_numeric($value)) $value=(float)$value;
                            elseif($type==='integer' && is_numeric($value)) $value=(int)$value;
                        }
                        $testData[$jsonKey]=$value;
                    }
                }
                $testObservedAt=!empty($latest['observed_at']) ? (string)$latest['observed_at'] : null;
                $payload=lakestat_webhook_payload($station,$testData,$testObservedAt);
                $payloadJson=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                if($payloadJson===false) throw new RuntimeException('Test webhook payload could not be encoded as JSON.');

                $result=lakestat_webhook_send(
                    $url,$headers,$payloadJson,$webhookTimeout
                );

                if(!$result['ok']) {
                    throw new RuntimeException(
                        'Test webhook failed. HTTP '.$result['http_code'].
                        ($result['error']!=='' ? ' — '.$result['error'] : '')
                    );
                }

                $message='Test webhook sent successfully. HTTP '.$result['http_code'].'.';
                $_GET['station']=$code;
            }
        }

        if ($action === 'delete_station_variable') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $fieldKey=lakestat_safe_identifier((string)($_POST['delete_field'] ?? ''));
            $typed=trim((string)($_POST['confirm_variable_name'] ?? ''));

            if(!isset($station['fields'][$fieldKey])) {
                throw new RuntimeException('The selected variable is no longer configured.');
            }
            if($typed!==$fieldKey) {
                throw new RuntimeException("Type the exact variable name '{$fieldKey}' to confirm deletion.");
            }

            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);
            $dependents=slim_dependent_derived_variables($station,$fieldKey);
            $deleteKeys=array_values(array_unique(array_merge([$fieldKey],$dependents)));
            $deleteSet=array_fill_keys($deleteKeys,true);

            // A station backup captures both the complete SQL table and station configuration
            // before any columns or references are removed.
            $didBackup=isset($_POST['backup_before_delete']);
            if($didBackup) lakestat_backup_station($pdo,$station);

            // Drop derived descendants first, then the requested source variable. Also remove
            // QC companion columns belonging to any deleted field.
            foreach(array_reverse($deleteKeys) as $deleteKey) {
                if(!isset($station['fields'][$deleteKey])) continue;
                $meta=$station['fields'][$deleteKey];
                $column=lakestat_safe_identifier((string)($meta['column'] ?? $deleteKey));
                foreach([$column.'_qc',$column.'_raw',$column] as $dropColumn) {
                    if(lakestat_column_exists($pdo,$table,$dropColumn)) {
                        $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$dropColumn}`");
                    }
                }
                unset($station['fields'][$deleteKey]);
                if(isset($station['derived_variables'][$deleteKey])) unset($station['derived_variables'][$deleteKey]);
                if(isset($station['variable_metadata'][$deleteKey])) unset($station['variable_metadata'][$deleteKey]);
            }
            if(isset($station['derived_variables']) && !$station['derived_variables']) unset($station['derived_variables']);
            if(isset($station['variable_metadata']) && !$station['variable_metadata']) unset($station['variable_metadata']);

            // Remove explicitly selected webhook JSON keys that belonged to deleted fields.
            $deletedWebhookKeys=[];
            foreach($deleteKeys as $deletedKey) {
                // The field may already have been removed above, so recover its ordinary key as
                // a safe fallback. Most SLIM fields use the field key as json_key.
                $deletedWebhookKeys[$deletedKey]=true;
            }
            // Use the pre-delete configuration for custom json_key values.
            $originalStation=lakestat_station_config($code);
            foreach($deleteKeys as $deletedKey) {
                if(isset($originalStation['fields'][$deletedKey])) {
                    $jsonKey=(string)($originalStation['fields'][$deletedKey]['json_key'] ?? $deletedKey);
                    $deletedWebhookKeys[$jsonKey]=true;
                }
            }
            if(isset($station['webhook']['fields']) && is_array($station['webhook']['fields'])) {
                $station['webhook']['fields']=array_values(array_filter(
                    $station['webhook']['fields'],
                    static function($key) use ($deletedWebhookKeys){ return !isset($deletedWebhookKeys[(string)$key]); }
                ));
            }

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            // Remove dashboard displays that would otherwise contain broken field references.
            $removedDisplays=0;
            foreach(['public','health'] as $kind) {
                $displayConfig=lakestat_display_config_for($code,$kind);
                $kept=[];
                foreach($displayConfig['displays'] as $display) {
                    if(slim_display_references_fields($display,$deleteSet)) {
                        $removedDisplays++;
                    } else {
                        $kept[]=$display;
                    }
                }
                if(count($kept)!==count($displayConfig['displays'])) {
                    foreach($kept as $i=>&$display) $display['order']=$i+1;
                    unset($display);
                    $displayConfig['displays']=$kept;
                    lakestat_write_php_config(lakestat_display_config_path($code,$kind),$displayConfig);
                }
            }

            $message="Variable '{$fieldKey}' and its stored SQL data were permanently deleted.";
            if($dependents) $message.=' Dependent derived variable'.(count($dependents)===1?'':'s').' also deleted: '.implode(', ',$dependents).'.';
            if($removedDisplays) $message.=' Removed '.number_format($removedDisplays).' dashboard display'.($removedDisplays===1?'':'s').' that referenced deleted variables.';
            $message.=$didBackup ? ' A station backup was created before deletion.' : ' No pre-deletion backup was requested.';
            $_GET['station']=$code;
        }

        if ($action === 'cleanup_orphan_field') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $fieldKey=lakestat_safe_identifier((string)($_POST['orphan_field'] ?? ''));

            if(!isset($station['fields'][$fieldKey])) {
                throw new RuntimeException('The selected station variable is no longer configured.');
            }

            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);
            $column=lakestat_safe_identifier((string)($station['fields'][$fieldKey]['column'] ?? $fieldKey));

            if(lakestat_column_exists($pdo,$table,$column)) {
                throw new RuntimeException("Variable '{$fieldKey}' is not orphaned because MySQL column '{$column}' exists.");
            }
            if(isset($station['derived_variables'][$fieldKey])) {
                throw new RuntimeException("Variable '{$fieldKey}' still has an active derived calculation. Remove the derived variable instead.");
            }

            lakestat_backup_station($pdo,$station);
            unset($station['fields'][$fieldKey]);

            if(isset($station['variable_metadata'][$fieldKey])) {
                unset($station['variable_metadata'][$fieldKey]);
                if(!$station['variable_metadata']) unset($station['variable_metadata']);
            }

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message="Orphaned variable definition '{$fieldKey}' was removed from the station configuration. No MySQL data were deleted.";
            $_GET['station']=$code;
        }

        if ($action === 'add_standard_conversions') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);
            $requested=is_array($_POST['standard_conversion'] ?? null)
                ? $_POST['standard_conversion'] : [];

            if(!$requested) {
                throw new RuntimeException('Select at least one standard unit conversion.');
            }

            lakestat_backup_station($pdo,$station);
            $created=[];

            foreach($requested as $sourceRaw) {
                $source=lakestat_safe_identifier((string)$sourceRaw);
                if(!isset($station['fields'][$source])) continue;
                $field=$station['fields'][$source];
                if(($field['type'] ?? 'number')==='text' || !empty($field['derived'])) continue;

                $conversion=slim_standard_unit_conversion((string)($field['units'] ?? ''));
                if($conversion===null) continue;

                $target=slim_standard_conversion_target($source,$conversion);
                if(isset($station['fields'][$target]) || lakestat_column_exists($pdo,$table,$target)) {
                    throw new RuntimeException(
                        "Cannot create standard conversion for '{$source}' because '{$target}' already exists."
                    );
                }

                $sourceColumn=lakestat_safe_identifier((string)($field['column'] ?? $source));
                $steps=$conversion['steps'];

                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$target}` DOUBLE NULL");

                $station['fields'][$target]=[
                    'json_key'=>$target,
                    'column'=>$target,
                    'label'=>(string)($field['label'] ?? $source),
                    'units'=>(string)$conversion['units'],
                    'type'=>'number',
                    'show_current'=>true,
                    'show_graph'=>true,
                    'derived'=>true,
                    // Convenience presentation conversions are not sent by default
                    // when a webhook is configured to send all fields.
                    'webhook_default_exclude'=>true,
                    'standard_conversion'=>(string)$conversion['key']
                ];
                if(!isset($station['derived_variables']) || !is_array($station['derived_variables'])) {
                    $station['derived_variables']=[];
                }
                $station['derived_variables'][$target]=[
                    'source'=>$source,
                    'steps'=>$steps,
                    'standard_conversion'=>(string)$conversion['key']
                ];

                // Backfill is automatic for built-in conversions.
                $expression=slim_derived_sql_expression($sourceColumn,$steps,$station);
                $pdo->exec("UPDATE `{$table}` SET `{$target}` = {$expression}");
                $created[]=$target;
            }

            if(!$created) {
                throw new RuntimeException('No standard unit conversions could be created.');
            }

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );
            $message='Created and backfilled standard unit conversion'.(count($created)===1?'':'s')
                .': '.implode(', ',$created).'.';
            $_GET['station']=$code;
        }

        if ($action === 'add_derived_timestamp') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);

            $target=lakestat_safe_identifier(trim((string)($_POST['timestamp_key'] ?? '')));
            $source=(string)($_POST['timestamp_source'] ?? 'observed_at');
            $format=(string)($_POST['timestamp_format'] ?? 'epoch_seconds');
            $label=trim((string)($_POST['timestamp_label'] ?? $target));

            if($target==='') throw new RuntimeException('Enter a new derived timestamp variable name.');
            if(!in_array($source,['observed_at','received_at'],true)) {
                throw new RuntimeException('Choose a valid canonical timestamp source.');
            }
            if($format!=='epoch_seconds') throw new RuntimeException('Unsupported derived timestamp format.');
            if(isset($station['fields'][$target])) throw new RuntimeException("Variable '{$target}' already exists.");
            if(lakestat_column_exists($pdo,$table,$target)) throw new RuntimeException("Column '{$target}' already exists in MySQL.");

            lakestat_backup_station($pdo,$station);
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$target}` BIGINT NULL");

            $station['fields'][$target]=[
                'json_key'=>$target, 'column'=>$target,
                'label'=>$label!=='' ? $label : $target,
                'units'=>'epoch s', 'type'=>'integer',
                'show_current'=>false, 'show_graph'=>false, 'derived'=>true
            ];
            if(!isset($station['derived_variables']) || !is_array($station['derived_variables'])) $station['derived_variables']=[];
            $station['derived_variables'][$target]=[
                'kind'=>'timestamp', 'source'=>$source, 'format'=>$format
            ];
            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),$station
            );

            $backfilled=0;
            if(isset($_POST['backfill_existing'])) {
                // observed_at/received_at are canonical UTC DATETIME columns. TIMESTAMPDIFF
                // avoids dependence on the hosting provider's MySQL session time zone.
                // NULL source timestamps remain NULL.
                $sourceColumn=$source==='received_at' ? 'received_at' : 'observed_at';
                $stmt=$pdo->prepare("UPDATE `{$table}` SET `{$target}` = TIMESTAMPDIFF(SECOND,'1970-01-01 00:00:00',`{$sourceColumn}`)");
                $stmt->execute();
                $backfilled=$stmt->rowCount();
            }
            $message="Derived timestamp '{$target}' added as Unix epoch seconds from {$source}.";
            if(isset($_POST['backfill_existing'])) $message.=" Existing observations were also calculated (".number_format($backfilled)." rows updated).";
            $_GET['station']=$code;
        }

        if ($action === 'add_derived_variable') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);

            $target=lakestat_safe_identifier(trim((string)($_POST['derived_key'] ?? '')));
            $source=lakestat_safe_identifier(trim((string)($_POST['derived_source'] ?? '')));
            $label=trim((string)($_POST['derived_label'] ?? $target));
            $units=trim((string)($_POST['derived_units'] ?? ''));

            if($target==='') throw new RuntimeException('Enter a new derived variable name.');
            if(!isset($station['fields'][$source])) {
                throw new RuntimeException('Choose an existing source variable.');
            }
            if(($station['fields'][$source]['type'] ?? 'number')==='text') {
                throw new RuntimeException('Derived variables require a numeric source variable.');
            }
            if(isset($station['fields'][$target])) {
                $existingColumn=lakestat_safe_identifier((string)($station['fields'][$target]['column'] ?? $target));
                if(!lakestat_column_exists($pdo,$table,$existingColumn)
                    && !isset($station['derived_variables'][$target])) {
                    throw new RuntimeException(
                        "Variable '{$target}' has an orphaned configuration definition. In the 'Orphaned Variable Definitions' section of Station Manager, click 'Clean Up Definition' for this variable, then add the derived variable again."
                    );
                }
                throw new RuntimeException("Variable '{$target}' already exists.");
            }
            if(lakestat_column_exists($pdo,$table,$target)) {
                throw new RuntimeException("Column '{$target}' already exists in MySQL.");
            }

            $operations=is_array($_POST['derived_step_operation'] ?? null)
                ? $_POST['derived_step_operation'] : [];
            $operandTypes=is_array($_POST['derived_step_operand_type'] ?? null)
                ? $_POST['derived_step_operand_type'] : [];
            $operands=is_array($_POST['derived_step_value'] ?? null)
                ? $_POST['derived_step_value'] : [];
            $operandVariables=is_array($_POST['derived_step_variable'] ?? null)
                ? $_POST['derived_step_variable'] : [];

            $steps=[];
            $usedVariables=[$source=>true];
            $stepCount=max(count($operations),count($operandTypes),count($operands),count($operandVariables));
            for($i=0;$i<$stepCount;$i++) {
                $stepOperation=(string)($operations[$i] ?? '');
                $operandType=(string)($operandTypes[$i] ?? 'constant');
                $rawOperand=trim((string)($operands[$i] ?? ''));
                $rawOperandVariable=trim((string)($operandVariables[$i] ?? ''));

                if($stepOperation==='') continue;
                if(!in_array($stepOperation,['multiply','divide','add','subtract'],true)) {
                    throw new RuntimeException('Each calculation step must use a supported operation.');
                }

                if($operandType==='variable') {
                    if($rawOperandVariable==='') {
                        throw new RuntimeException('Choose an existing variable for each variable calculation step.');
                    }
                    $operandVariable=lakestat_safe_identifier($rawOperandVariable);
                    if(!isset($station['fields'][$operandVariable])) {
                        throw new RuntimeException('Choose an existing variable for each variable calculation step.');
                    }
                    if(($station['fields'][$operandVariable]['type'] ?? 'number')==='text'
                        || !empty($station['fields'][$operandVariable]['derived'])) {
                        throw new RuntimeException('Derived-variable inputs must be existing non-derived numeric variables.');
                    }
                    $steps[]=[
                        'operation'=>$stepOperation,
                        'operand_type'=>'variable',
                        'variable'=>$operandVariable
                    ];
                    $usedVariables[$operandVariable]=true;
                } elseif($operandType==='constant') {
                    if($rawOperand==='' || !is_numeric($rawOperand)) {
                        throw new RuntimeException('Each constant calculation step requires a numeric value.');
                    }
                    $operand=(float)$rawOperand;
                    if($stepOperation==='divide' && $operand==0.0) {
                        throw new RuntimeException('A derived-variable calculation cannot divide by a constant zero.');
                    }
                    $steps[]=[
                        'operation'=>$stepOperation,
                        'operand_type'=>'constant',
                        'value'=>$operand
                    ];
                } else {
                    throw new RuntimeException('Each calculation step must use either a constant or a variable.');
                }
            }

            if(!$steps) {
                throw new RuntimeException('Add at least one calculation step.');
            }
            if(count($steps)>4) {
                throw new RuntimeException('A derived variable may contain at most 4 calculation steps.');
            }
            if(count($usedVariables)>4) {
                throw new RuntimeException('A derived variable may use at most 4 different source variables.');
            }

            lakestat_backup_station($pdo,$station);

            $pdo->exec(
                "ALTER TABLE `{$table}` ADD COLUMN `{$target}` DOUBLE NULL"
            );

            $station['fields'][$target]=[
                'json_key'=>$target,
                'column'=>$target,
                'label'=>$label!=='' ? $label : $target,
                'units'=>$units,
                'type'=>'number',
                'show_current'=>true,
                'show_graph'=>true,
                'derived'=>true
            ];

            if(!isset($station['derived_variables']) || !is_array($station['derived_variables'])) {
                $station['derived_variables']=[];
            }
            $station['derived_variables'][$target]=[
                'source'=>$source,
                'steps'=>$steps
            ];

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $backfilled=0;
            if(isset($_POST['backfill_existing'])) {
                $sourceColumn=lakestat_safe_identifier(
                    (string)($station['fields'][$source]['column'] ?? $source)
                );
                $expression=slim_derived_sql_expression($sourceColumn,$steps,$station);

                // SQL arithmetic preserves NULL: if the source observation is
                // NULL, the derived result remains NULL.
                $update=$pdo->prepare(
                    "UPDATE `{$table}` SET `{$target}` = {$expression}"
                );
                $update->execute();
                $backfilled=$update->rowCount();
            }

            $message="Derived variable '{$target}' added. It will be calculated for new incoming observations and CSV imports.";
            if(isset($_POST['backfill_existing'])) {
                $message.=" Existing observations were also calculated (".number_format($backfilled)." rows updated).";
            } else {
                $message.=" Existing observations were left unchanged.";
            }
            $_GET['station']=$code;
        }

        if ($action === 'delete_derived_variable') {
            $code=lakestat_station_code((string)($_POST['station'] ?? ''));
            $station=lakestat_station_config($code);
            $target=lakestat_safe_identifier((string)($_POST['derived_key'] ?? ''));

            if(!isset($station['derived_variables'][$target])) {
                throw new RuntimeException('Derived variable configuration was not found.');
            }

            $pdo=lakestat_db();
            $table=lakestat_safe_identifier((string)$station['table']);

            // Back up the station before removing a generated variable because
            // dropping its SQL column also removes its historical derived values.
            lakestat_backup_station($pdo,$station);

            if(lakestat_column_exists($pdo,$table,$target)) {
                $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$target}`");
            }

            unset($station['derived_variables'][$target]);
            unset($station['fields'][$target]);
            if(!$station['derived_variables']) unset($station['derived_variables']);

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message="Derived variable '{$target}' and its MySQL column were removed. You can now recreate it with a corrected calculation.";
            $_GET['station']=$code;
        }

        if ($action === 'add_fields') {
            $code = lakestat_station_code((string)($_POST['station'] ?? ''));
            $station = lakestat_station_config($code);
            $pdo = lakestat_db();
            $table = lakestat_safe_identifier((string)$station['table']);

            if (!lakestat_table_exists($pdo, $table)) {
                throw new RuntimeException("Configured MySQL table '{$table}' does not exist.");
            }

            $keys = $_POST['field_key'] ?? [];
            $labels = $_POST['field_label'] ?? [];
            $units = $_POST['field_units'] ?? [];
            $types = $_POST['field_type'] ?? [];

            $newFields = [];

            foreach ($keys as $i=>$rawKey) {
                $key = trim((string)$rawKey);
                if ($key === '') continue;

                $key = lakestat_safe_identifier($key);

                if (isset($station['fields'][$key])) {
                    throw new RuntimeException("Field '{$key}' already exists in the station configuration.");
                }

                if (lakestat_column_exists($pdo, $table, $key)) {
                    throw new RuntimeException(
                        "Column '{$key}' already exists in MySQL but is not in the station configuration."
                    );
                }

                $type = isset($types[$i]) ? (string)$types[$i] : 'number';
                if (!in_array($type, ['number','integer','text'], true)) $type='number';

                $newFields[$key] = [
                    'json_key'=>$key,
                    'column'=>$key,
                    'label'=>trim((string)($labels[$i] ?? $key)),
                    'units'=>trim((string)($units[$i] ?? '')),
                    'type'=>$type,
                    'show_current'=>true,
                    'show_graph'=>$type !== 'text'
                ];
            }

            if (!$newFields) throw new RuntimeException('Add at least one new field.');

            lakestat_backup_station($pdo, $station);

            foreach ($newFields as $key=>$meta) {
                $pdo->exec(
                    "ALTER TABLE `{$table}` ADD COLUMN `{$key}` "
                    . sql_type_from_field_type($meta['type'])
                );
            }

            foreach ($newFields as $key=>$meta) {
                $station['fields'][$key] = $meta;
            }

            lakestat_write_php_config(
                lakestat_config_path('stations'.DIRECTORY_SEPARATOR.$code.'.php'),
                $station
            );

            $message = 'New field(s) added. CSV and configuration backups were created first.';
        }
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$stations = $dbConfigured ? lakestat_list_stations() : [];
$selectedCode = '';

if (isset($_GET['station']) && $_GET['station'] !== '') {
    $selectedCode = lakestat_station_code((string)$_GET['station']);
} elseif ($stations) {
    $keys = array_keys($stations);
    $selectedCode = $keys[0];
}

$selectedStation = null;

if ($selectedCode !== '') {
    try {
        $selectedStation = lakestat_station_config($selectedCode);
    } catch (Throwable $e) {}
}

$regeneratedKey = $_SESSION['regenerated_station_api_key'] ?? null;
unset($_SESSION['regenerated_station_api_key']);
if (!is_array($regeneratedKey) || (string)($regeneratedKey['code'] ?? '') !== $selectedCode) {
    $regeneratedKey = null;
}
$createdStation = $_SESSION['created_station'] ?? null;
unset($_SESSION['created_station']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1050px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
h1,h2{margin-top:0}
label{display:block;margin:10px 0 5px;font-weight:600}
input,select{padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
input[type=text],input[type=password],input[type=number],input[type=url]{width:100%;max-width:520px}
.inline{display:flex;gap:12px;flex-wrap:wrap}.inline>div{flex:1;min-width:180px}
button{padding:9px 15px;border:0;border-radius:5px;cursor:pointer}
.primary{background:#222;color:#fff}.secondary{background:#e7e7e7}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.warn{background:#fff7dd;border:1px solid #e5cf86;padding:12px;margin-bottom:15px}
.danger-zone{margin-top:28px;padding:18px;border:2px solid #b42318;border-radius:8px;background:#fff7f6}
.danger-zone h3{color:#8f1d14;margin-top:0}
.delete-choice{font-weight:400;padding:10px;border:1px solid #ddd;border-radius:6px;background:#fff;margin:8px 0}
.delete-choice strong{color:#222}
.danger-button{background:#b42318;color:#fff;font-weight:700}
.danger-button:hover{background:#8f1d14}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}
table input,table select{width:100%;max-width:none}
textarea.webhook-template{width:100%;min-height:220px;font-family:monospace;box-sizing:border-box}
.hint{color:#666;font-size:.92rem}.webhook-secret{font-family:monospace}
code,pre{background:#f2f2f2}
pre{padding:12px;overflow:auto}
</style>
</head>
<body>
<?= slim_admin_session_bar() ?>
<div style="max-width:1100px;margin:12px auto 0;padding:0 20px;">
<a href="../" style="display:inline-block;text-decoration:none;font-weight:600;">&larr; Admin Home</a>
</div>

<div class="wrap">

<div class="card">
<h1>Station Manager</h1>
<p><a href="./">← Station Management</a></p>

<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>


</div>

<?php if(!$dbConfigured || !$systemConfigured): ?>
<div class="card">
<h2>SLIM Setup Required</h2>
<p class="hint">Database Connection and System Defaults are now managed on the separate <a href="../setup/"><strong>SLIM Setup</strong></a> page. Complete setup before creating or managing stations.</p>
</div>
<?php else: ?>
<?php if($stationSection==='create' || $stationSection==='all'): ?>
<div class="card">
<h2 id="create-station">Create new station</h2>

<form method="post" id="createStationForm">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="create_station">
<p id="createStationValidation" class="error" role="alert" hidden></p>

<div class="inline">
<div><label>Station code <?= slim_help_button('Station code','Short unique identifier tied to configuration files, the database table, ingestion, and API references.') ?></label><input type="text" name="station_code" placeholder="LGC" value="<?= h(create_draft_value($createDraft,'station_code')) ?>" required></div>
<div><label>MySQL table name</label><input type="text" name="table_name" placeholder="LGC" value="<?= h(create_draft_value($createDraft,'table_name')) ?>" required></div>
</div>

<label>Station name <?= slim_help_button('Station name','Human-readable station name shown to viewers. This can be edited later without changing the station code.') ?></label>
<input type="text" name="station_name" placeholder="Lake Geneva Chain" value="<?= h(create_draft_value($createDraft,'station_name')) ?>" required>

<div class="inline">
<div><label>Latitude <?= slim_help_button('Latitude','Station latitude in decimal degrees. North is positive; south is negative.') ?></label><input type="number" step="any" name="latitude" min="-90" max="90" value="<?= h(create_draft_value($createDraft,'latitude')) ?>" required></div>
<div><label>Longitude <?= slim_help_button('Longitude','Station longitude in decimal degrees. East is positive; west is negative.') ?></label><input type="number" step="any" name="longitude" min="-180" max="180" value="<?= h(create_draft_value($createDraft,'longitude')) ?>" required></div>
</div>

<label>Station time zone <?= slim_help_button('Station time zone','Local timezone at the station location. Used for its dashboard clock, observation times, charts and date-range selection. Independent of the system/headquarters timezone.') ?></label>
<select name="station_timezone" required><?php $newStationTimezone=create_draft_value($createDraft,'station_timezone',(string)(slim_system_config()['timezone'] ?? 'UTC')); foreach(slim_timezone_identifiers() as $tz): ?><option value="<?= h($tz) ?>" <?= $tz===$newStationTimezone?'selected':'' ?>><?= h($tz) ?></option><?php endforeach; ?></select>
<p class="hint">Defaults to the system timezone; choose the station location (for example, Asia/Bangkok).</p>

<label>Measurement field setup</label>
<select name="field_setup_mode" id="fieldSetupMode" onchange="document.getElementById('manualFieldsBlock').hidden=this.value==='auto'">
<option value="auto" <?= create_draft_value($createDraft,'field_setup_mode','auto')==='auto'?'selected':'' ?>>Automatically detect fields from first authenticated observation (recommended)</option>
<option value="manual" <?= create_draft_value($createDraft,'field_setup_mode','auto')==='manual'?'selected':'' ?>>Configure fields manually</option>
</select>
<p class="hint">Automatic discovery creates database columns from the first valid payload. Field names are preserved exactly; configure labels and units later.</p>
<div id="manualFieldsBlock" <?= create_draft_value($createDraft,'field_setup_mode','auto')==='auto'?'hidden':'' ?>>
<table id="createFields">
<thead><tr><th>Incoming field</th><th>Display label</th><th>Units</th><th>Type</th><th></th></tr></thead>
<tbody>
<?php
$draftKeys=is_array($createDraft['field_key'] ?? null) ? $createDraft['field_key'] : ['temp1'];
$draftLabels=is_array($createDraft['field_label'] ?? null) ? $createDraft['field_label'] : ['Water Temperature'];
$draftUnits=is_array($createDraft['field_units'] ?? null) ? $createDraft['field_units'] : ['°C'];
$draftTypes=is_array($createDraft['field_type'] ?? null) ? $createDraft['field_type'] : ['number'];
if (!$draftKeys) $draftKeys=[''];
foreach ($draftKeys as $i=>$draftKey):
$draftType=(string)($draftTypes[$i] ?? 'number');
?>
<tr>
<td><input name="field_key[]" value="<?= h((string)$draftKey) ?>" aria-label="Incoming field"></td>
<td><input name="field_label[]" value="<?= h((string)($draftLabels[$i] ?? '')) ?>" aria-label="Display label"></td>
<td><input name="field_units[]" value="<?= h((string)($draftUnits[$i] ?? '')) ?>" aria-label="Units"></td>
<td><select name="field_type[]" aria-label="Type"><?php foreach(['number'=>'Number','integer'=>'Integer','text'=>'Text'] as $v=>$label): ?><option value="<?= h($v) ?>" <?= $draftType===$v?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></td>
<td><button class="secondary" type="button" onclick="removeRow(this,'#createFields')">Remove</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p><button class="secondary" type="button" onclick="addRow('#createFields')">+ Add Measurement</button></p>
</div>
<p><button class="primary" type="submit">Create Station & Table</button></p>
</form>
</div>

<?php if($createdStation): ?>
<div class="card">
<h2>Most recently created station</h2>
<p><strong><?= h($createdStation['name']) ?></strong> (<?= h($createdStation['code']) ?>)</p>
<p><strong>API key:</strong></p>
<pre><?= h($createdStation['api_key']) ?></pre>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if($stationSection!=='create' && $stations): ?>
<div class="card">
<h2 id="manage-station"><?php
$sectionTitles=['details'=>'Station Details','metadata'=>'Station Metadata','variables'=>'Variables & Fields','qc'=>'Variable Quality Control','derived'=>'Derived Variables','webhook'=>'Webhook Setup','delete'=>'Delete Station'];
echo h($sectionTitles[$stationSection] ?? 'Manage existing station');
?></h2>

<form method="get">
<label>Station</label>
<select name="station" onchange="this.form.submit()">
<?php foreach($stations as $code=>$info): ?>
<option value="<?= h($code) ?>" <?= $selectedCode===$code?'selected':'' ?>>
<?= h($info['name']) ?> — <?= h($code) ?>
</option>
<?php endforeach; ?>
</select>
</form>

<?php if($selectedStation): ?>
<p>
<a href="../table/?station=<?= urlencode($selectedCode) ?>">View recent SQL table data</a>
<span class="hint"> — read-only view of the most recent rows stored for this station</span>
</p>

<?php if($stationSection==='variables' || $stationSection==='all'): ?>
<h3 id="variables">Variables</h3>
<p class="hint">
Edit the human-readable label and units associated with each database field.
The database column name and data type remain fixed so ingestion and saved
references are not broken.
</p>

<form method="post" id="fieldMetadataForm">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="save_field_metadata">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<table>
<thead>
<tr>
<th>Database field <?= slim_help_button('Database field','The actual MySQL column used by SLIM for storage and ingestion. It is intentionally read-only here because changing it could break data ingestion and saved display references.') ?></th>
<th>Display label <?= slim_help_button('Variable display label','Human-readable name used by SLIM when showing this variable, for example Surface Water Temperature instead of temp0.') ?></th>
<th>Units <?= slim_help_button('Variable units','Units associated with this variable, such as °C, m/s, mg/L, µg/L, or m. These units can be used by gauges, polar plots, legends, and other displays.') ?></th>
<th>Type <?= slim_help_button('Variable type','Storage/data type assigned when the variable was created. It is read-only here because changing it would require altering the MySQL table safely.') ?></th>
</tr>
</thead>
<tbody>
<?php foreach($selectedStation['fields'] as $key=>$field): ?>
<tr>
<td><code><?= h($key) ?></code></td>
<td>
<input
    type="text"
    name="field_label_edit[<?= h($key) ?>]"
    value="<?= h((string)($field['label'] ?? $key)) ?>"
>
</td>
<td>
<input
    type="text"
    name="field_units_edit[<?= h($key) ?>]"
    value="<?= h((string)($field['units'] ?? '')) ?>"
    placeholder="e.g. °C, m/s, mg/L"
>
</td>
<td><?= h((string)($field['type'] ?? 'number')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p><button class="primary" type="submit">Save Variable Labels &amp; Units</button></p>
<p id="fieldDraftNotice" class="hint" style="display:none;"></p>
</form>

<h3 style="margin-top:32px;">Delete Variable <?= slim_help_button('Delete Variable','Permanently removes the selected variable and its historical SQL data. Any derived variables that depend on it are also deleted. SLIM can create a complete station backup immediately before deletion.') ?></h3>
<p class="hint">Deletion is permanent. Type the exact variable name to confirm. If a variable has dependent derived variables, they are listed below and will be deleted at the same time. Dashboard displays and explicit webhook field selections that reference deleted variables are cleaned up automatically.</p>
<table>
<thead><tr><th>Variable</th><th>Label</th><th>Dependent derived variables</th><th>Delete</th></tr></thead>
<tbody>
<?php foreach($selectedStation['fields'] as $key=>$field):
    $deleteDependents=slim_dependent_derived_variables($selectedStation,(string)$key);
?>
<tr>
<td><code><?= h((string)$key) ?></code></td>
<td><?= h((string)($field['label'] ?? $key)) ?></td>
<td>
<?php if($deleteDependents): ?>
<strong>Warning:</strong> <?= h(implode(', ',$deleteDependents)) ?> will also be deleted.
<?php else: ?>
<span class="hint">None</span>
<?php endif; ?>
</td>
<td>
<form method="post" onsubmit="return confirm('Permanently delete variable <?= h((string)$key) ?> and all of its stored data<?= $deleteDependents ? ', including dependent derived variables' : '' ?>?');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="delete_station_variable">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="delete_field" value="<?= h((string)$key) ?>">
<label>Type <code><?= h((string)$key) ?></code> to confirm
<input type="text" name="confirm_variable_name" value="" autocomplete="off" required></label>
<label><input type="checkbox" name="backup_before_delete" value="1" checked> Back up station data and configuration first</label>
<button class="danger-button" type="submit">Delete Variable</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>
<?php if($stationSection==='qc' || $stationSection==='all'): ?>
<h3 id="quality-control">Variable Quality Control <?= slim_help_button('Variable Quality Control','Optional station-level quality control for individual numeric variables. SLIM preserves the received value in a companion raw column, stores NULL in the normal data column when a configured diagnostic code or range check fails, and records the QC result. Public displays and outbound webhooks use the quality-controlled value.') ?></h3>
<p class="hint">QC is optional for each variable. Diagnostic codes are checked before the acceptable range. Enter diagnostic values one per line as <code>value = meaning</code>, for example <code>-9998 = insufficient measurement time</code>. Blank minimum or maximum values disable that bound.</p>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="save_variable_qc">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<table>
<thead>
<tr>
<th>Variable</th>
<th>Enable QC</th>
<th>Minimum acceptable</th>
<th>Maximum acceptable</th>
<th>Diagnostic/error values</th>
</tr>
</thead>
<tbody>
<?php foreach($selectedStation['fields'] as $key=>$field):
    if(!empty($field['derived']) || ($field['type'] ?? 'number')==='text') continue;
    $qc=is_array($field['qc'] ?? null) ? $field['qc'] : [];
    $diagLines=[];
    foreach(($qc['diagnostic_codes'] ?? []) as $diag) {
        if(!is_array($diag) || !array_key_exists('value',$diag)) continue;
        $diagLines[]=(string)$diag['value'].' = '.(string)($diag['meaning'] ?? 'Diagnostic code');
    }
?>
<tr>
<td><strong><?= h((string)($field['label'] ?? $key)) ?></strong><br><code><?= h($key) ?></code></td>
<td><input type="checkbox" name="qc_enabled[<?= h($key) ?>]" value="1" <?= !empty($qc['enabled'])?'checked':'' ?>></td>
<td><input type="number" step="any" name="qc_min[<?= h($key) ?>]" value="<?= h(isset($qc['min']) && $qc['min']!==null ? (string)$qc['min'] : '') ?>" placeholder="No minimum"></td>
<td><input type="number" step="any" name="qc_max[<?= h($key) ?>]" value="<?= h(isset($qc['max']) && $qc['max']!==null ? (string)$qc['max'] : '') ?>" placeholder="No maximum"></td>
<td><textarea name="qc_diagnostics[<?= h($key) ?>]" rows="3" placeholder="-9998 = insufficient measurement time&#10;-9999 = sensor error"><?= h(implode("\n",$diagLines)) ?></textarea></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p><button class="primary" type="submit">Save Variable QC Settings</button></p>
<p class="hint">When QC is first enabled for a variable, SLIM creates <code>_raw</code> and <code>_qc</code> companion columns after first creating a station CSV/configuration backup. Existing observations are copied into the raw column and marked <code>legacy_unchecked</code>; they are not retroactively rejected.</p>
</form>

<?php endif; ?>
<?php if($stationSection==='details' || $stationSection==='all'): ?>
<h3 id="station-details">Station Details</h3>
<?php
$slimHttps=slim_request_is_https();
$slimScheme=$slimHttps ? 'https' : 'http';
$slimHost=(string)($_SERVER['HTTP_HOST'] ?? '');
$slimScript=(string)($_SERVER['SCRIPT_NAME'] ?? '');
$slimBase=preg_replace('~/admin/station/(?:manage|details|index|create)\.php$~','',$slimScript) ?? '';
$slimIngestUrl=$slimScheme.'://'.$slimHost.$slimBase.'/ingest/';
?>
<div style="background:#f5f7fa;border:1px solid #d6dde6;border-radius:7px;padding:14px;margin:14px 0 20px">
<strong>API Ingestion Endpoint</strong>
<p class="hint">Use this URL when configuring a device or forwarding service to send observations to SLIM. This URL is shared by all stations; authentication is station-specific.</p>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<input type="text" id="slimIngestEndpoint" readonly value="<?= h($slimIngestUrl) ?>" aria-label="Ingestion endpoint" style="flex:1;max-width:none;min-width:240px">
<button type="button" class="secondary" onclick="copySlimEndpoint(this)">Copy URL</button>
</div>
<p class="hint">Method: <code>POST</code> · Content-Type: <code>application/json</code> · Header: <code>X-API-Key: YOUR_STATION_API_KEY</code> · JSON station code: <code><?= h($selectedCode) ?></code>. Keep the API key private.</p>
<?php if(!$slimHttps): ?><p class="hint" style="color:#8f1d14">This request is using HTTP. Configure HTTPS before transmitting an API key.</p><?php endif; ?>
</div>

<?php if($regeneratedKey): ?>
<div class="warn" role="status">
<strong>New API key for <?= h($selectedCode) ?> — shown only once</strong>
<p>Copy this key now and update your station's <code>X-API-Key</code> header. The previous key no longer works. Do not include this key in screenshots, support requests, or source control.</p>
<pre id="newStationApiKey"><?= h((string)$regeneratedKey['api_key']) ?></pre>
<button class="secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newStationApiKey').textContent).then(()=>{this.textContent='Copied';}).catch(()=>{alert('Copy manually from the box above.');})">Copy new API key</button>
</div>
<?php endif; ?>


<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="save_station_name">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<div class="inline">
<div>
<label>Station code</label>
<input type="text" value="<?= h($selectedCode) ?>" disabled>
<p class="hint">Station code is not changed here because it is tied to the station configuration and database table.</p>
</div>

<div>
<label>Station name</label>
<input type="text" name="station_name_edit" value="<?= h((string)($selectedStation['name'] ?? $selectedCode)) ?>" required>
</div>
</div>

<div class="inline">
<div>
<label>Timestamp source field <?= slim_help_button('Timestamp source field','The name of the original incoming or CSV column used as the observation timestamp, such as TIMESTAMP, datetime, or sample_time. This is descriptive metadata only; SLIM stores normalized observation times internally in observed_at.') ?></label>
<input
    type="text"
    name="timestamp_field_edit"
    value="<?= h((string)($selectedStation['timestamp_field'] ?? '')) ?>"
    placeholder="e.g. TIMESTAMP or datetime"
>
<p class="hint">Enter the incoming field that contains the observation timestamp. Leave blank if the payload does not provide observation time.</p>
</div>
</div>

<label>Station time zone <?= slim_help_button('Station time zone','Local timezone at this station. Controls dashboard local time, chart timestamps and local date-range selection; does not change UTC storage.') ?></label>
<select name="station_timezone_edit" required><?php $currentStationTimezone=(string)($selectedStation['timezone'] ?? (slim_system_config()['timezone'] ?? 'UTC')); foreach(slim_timezone_identifiers() as $tz): ?><option value="<?= h($tz) ?>" <?= $tz===$currentStationTimezone?'selected':'' ?>><?= h($tz) ?></option><?php endforeach; ?></select>
<p class="hint">The system timezone is the monitoring program headquarters timezone. Existing stations without a configured timezone use that system timezone until you save a station timezone here.</p>

<div class="publish-control">
<label style="display:flex;align-items:center;gap:10px;font-weight:600;">
<input type="checkbox" name="published" value="1" style="width:auto;" <?= !empty($selectedStation['published'])?'checked':'' ?>>
Publish station <?= slim_help_button('Publish station','When enabled, this station appears on the public SLIM home-page station list and map with a link to its dashboard. Leave this disabled while configuring the station, confirming incoming data, and building its dashboard. Administrators can still preview the dashboard directly while the station is unpublished.') ?>
</label>
<p class="hint">
<?= !empty($selectedStation['published'])
    ? 'Published — this station can appear on the public home-page map and station list.'
    : 'Unpublished — this station is hidden from the public home-page map and station list while you configure and test it.' ?>
</p>
</div>

<div class="publish-control">
<label style="display:flex;align-items:center;gap:10px;font-weight:600;">
<input type="checkbox" name="allow_legacy_http" value="1" style="width:auto;" <?= !empty($selectedStation['allow_legacy_http'])?'checked':'' ?>>
Allow legacy HTTP ingestion (insecure)
</label>
<p class="hint" style="color:#8f1d14">Only enable for a station whose logger cannot use HTTPS. Its API key and observations are sent without encryption and can be intercepted or replayed. This exception applies only to the ingestion endpoint and only after station API-key authentication. HTTPS remains available for this station. Use a dedicated station key and replace it if exposed.</p>
</div>

<p><button class="primary" type="submit">Save Station Details</button></p>
</form>




<?php endif; ?>
<?php if($selectedStation && ($stationSection==='details' || $stationSection==='all')): ?>
<div class="danger-zone" id="regenerate-api-key">
<h3>Replace Station API Key</h3>
<p>Existing keys cannot be recovered. Replacing the key immediately invalidates the old key for this station. All stored observations and station settings are preserved.</p>
<p class="hint">The new key is displayed only once after replacement. Copy it before leaving this page and update every device that sends data for this station.</p>
<form method="post" onsubmit="return confirm('Replace the API key for station <?= h($selectedCode) ?>? The old key will immediately stop working.');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="regenerate_api_key">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<label>Type station code <code><?= h($selectedCode) ?></code> to confirm</label>
<input type="text" name="confirm_station_code" autocomplete="off" required>
<p><button class="danger-button" type="submit">Regenerate API Key</button></p>
</form>
</div>
<?php endif; ?>

<?php if($stationSection==='metadata' || $stationSection==='all'): ?>
<h3 id="station-metadata">Custom Station Metadata <?= slim_help_button('Custom Station Metadata','Optional descriptive information about this station that does not belong in the fixed SLIM station fields. Examples include platform type, waterbody, managing agency, deployment year, or station purpose. These values are administrative metadata and do not alter the observation database table.') ?></h3>
<p class="hint">
Add optional descriptive name/value pairs for this station. These entries will
appear under <strong>Additional information</strong> in Station Directory.
They do not change the station database schema or observation data.
</p>

<?php
$stationMetadata=is_array($selectedStation['metadata'] ?? null)
    ? $selectedStation['metadata']
    : [];
if(!$stationMetadata) $stationMetadata=[''=>''];
?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="save_station_metadata">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<table id="stationMetadata">
<thead>
<tr>
<th>Field name <?= slim_help_button('Metadata field name','Descriptive label for this station property, for example Platform type, Waterbody, Managing agency, Deployment year, or Station purpose.') ?></th>
<th>Value <?= slim_help_button('Metadata value','Descriptive value associated with this field. Custom metadata is stored in the station configuration and shown in Station Directory.') ?></th>
<th></th>
</tr>
</thead>
<tbody>
<?php foreach($stationMetadata as $metadataName=>$metadataValue): ?>
<tr>
<td><input type="text" name="metadata_name[]" value="<?= h((string)$metadataName) ?>" placeholder="e.g. Platform type"></td>
<td><input type="text" name="metadata_value[]" value="<?= h((string)$metadataValue) ?>" placeholder="e.g. Panther Buoy"></td>
<td><button class="secondary" type="button" onclick="removeMetadataRow(this)">Remove</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p><button class="secondary" type="button" onclick="addMetadataRow()">+ Add Information</button></p>
<p><button class="primary" type="submit">Save Custom Metadata</button></p>
</form>

<?php endif; ?>
<?php if($stationSection==='variables' || $stationSection==='all'): ?>
<h3 id="add-variables">Add variables</h3>
<div class="warn">
SLIM will create a CSV backup and configuration backup before adding new MySQL columns.
Existing columns are never dropped or overwritten automatically.
</div>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="add_fields">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<table id="newFields">
<thead><tr><th>Incoming field</th><th>Display label</th><th>Units</th><th>Type</th><th></th></tr></thead>
<tbody>
<tr>
<td><input name="field_key[]" placeholder="ws"></td>
<td><input name="field_label[]" placeholder="Wind Speed"></td>
<td><input name="field_units[]" placeholder="m/s"></td>
<td><select name="field_type[]"><option value="number">Number</option><option value="integer">Integer</option><option value="text">Text</option></select></td>
<td><button class="secondary" type="button" onclick="removeRow(this,'#newFields')">Remove</button></td>
</tr>
</tbody>
</table>

<p><button class="secondary" type="button" onclick="addRow('#newFields')">+ Add Variable</button></p>
<p><button class="primary" type="submit" onclick="return confirm('Back up the station and add these fields?');">Back Up & Add Variables</button></p>
</form>

<?php endif; ?>
<?php if($stationSection==='webhook' || $stationSection==='all'):
$webhook=lakestat_webhook_config($selectedStation);

/*
 * A test webhook intentionally does not save configuration. After the POST,
 * render the submitted draft rather than reloading the last saved webhook so
 * the administrator can adjust/retest or save without re-entering everything.
 */
if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'
    && (string)($_POST['action'] ?? '')==='test_webhook'
    && (string)($_POST['station'] ?? '')===$selectedCode) {
    $draftHeaders=lakestat_webhook_headers(
        $_POST['webhook_header_name'] ?? [],
        $_POST['webhook_header_value'] ?? []
    );
    $webhook=[
        'enabled'=>isset($_POST['webhook_enabled']),
        'url'=>(string)($_POST['webhook_url'] ?? ''),
        'headers'=>$draftHeaders,
        'fields'=>array_values(array_map('strval',$_POST['webhook_fields'] ?? [])),
        'payload_mode'=>in_array((string)($_POST['webhook_payload_mode'] ?? 'default'),['default','custom'],true)
            ? (string)$_POST['webhook_payload_mode'] : 'default',
        'json_template'=>(string)($_POST['webhook_json_template'] ?? ''),
        'timeout'=>(int)($_POST['webhook_timeout'] ?? 15)
    ];
}
$webhookHeaders=is_array($webhook['headers'] ?? null) ? $webhook['headers'] : [];
if(!$webhookHeaders) $webhookHeaders=[['name'=>'X-API-Key','value'=>'']];
$webhookFields=is_array($webhook['fields'] ?? null) ? $webhook['fields'] : [];
?>
<h3 id="webhook" style="margin-top:30px;">Outbound webhook</h3>
<p class="hint">When an observation is stored, SLIM can forward a JSON POST to another HTTPS endpoint. Delivery is asynchronous: ingestion stores the observation and queues delivery. One installation-wide cron task sends queued webhooks independently. One automatic attempt, with no automatic retries.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<label>
<input type="checkbox" name="webhook_enabled" <?= !empty($webhook['enabled'])?'checked':'' ?>>
Enable outbound webhook for this station
<?= slim_help_button('Enable outbound webhook','When enabled, SLIM queues a delivery for the independent cron dispatcher. The station is acknowledged without waiting for the receiver.') ?>
</label>
<label>HTTPS endpoint <?= slim_help_button('Webhook HTTPS endpoint','Complete HTTPS URL receiving the JSON POST. SLIM queues delivery for the independent cron task, with no automatic retries.') ?></label>
<input type="url" name="webhook_url" value="<?= h((string)($webhook['url'] ?? '')) ?>" placeholder="https://example.org/api/data">
<label for="webhook_timeout">HTTP timeout (seconds) <?= slim_help_button('Webhook HTTP timeout','Maximum time SLIM waits for the receiving server to complete the webhook request. Choose 1–30 seconds. Longer timeouts may slow the independent cron dispatcher, but do not delay station ingestion.') ?></label>
<input type="number" id="webhook_timeout" name="webhook_timeout" min="1" max="30" step="1" required value="<?= h((string)($webhook['timeout'] ?? 15)) ?>" style="max-width:120px;">
<p class="hint">Default: 15 seconds. A timeout does not necessarily mean the receiving server failed to store the data. SLIM does not retry automatically.</p>

<h4>HTTP headers <?= slim_help_button('Webhook HTTP headers','Optional request headers required by the receiving service, such as X-API-Key or Authorization. Header values may contain credentials and should be treated as secrets.') ?></h4>
<p class="hint">Add whatever headers the receiving endpoint requires, such as X-API-Key or Authorization. Content-Type: application/json is added automatically unless you specify it here.</p>

<table id="webhookHeaders">
<thead><tr><th>Header name <?= slim_help_button('Header name','HTTP request-header name expected by the receiving endpoint, such as X-API-Key or Authorization.') ?></th><th>Header value <?= slim_help_button('Header value','Value sent with this HTTP header. This may be an API key or other secret supplied by the receiving service.') ?></th><th></th></tr></thead>
<tbody>
<?php foreach($webhookHeaders as $header): ?>
<tr>
<td><input name="webhook_header_name[]" value="<?= h((string)($header['name'] ?? '')) ?>" placeholder="X-API-Key"></td>
<td><input class="webhook-secret" name="webhook_header_value[]" value="<?= h((string)($header['value'] ?? '')) ?>" autocomplete="off"></td>
<td><button class="secondary" type="button" onclick="removeRow(this,'#webhookHeaders')">Remove</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><button class="secondary" type="button" onclick="addWebhookHeader()">+ Add Header</button></p>

<h4>JSON payload <?= slim_help_button('Webhook JSON payload','Use the SLIM default payload or define a custom JSON template for services that require a particular JSON structure. Custom placeholders are replaced with JSON-encoded values from the current observation.') ?></h4>
<label><input type="radio" name="webhook_payload_mode" value="default" <?= (($webhook['payload_mode'] ?? 'default')!=='custom')?'checked':'' ?>> Use SLIM default JSON payload</label>
<label><input type="radio" name="webhook_payload_mode" value="custom" <?= (($webhook['payload_mode'] ?? 'default')==='custom')?'checked':'' ?>> Use custom JSON template</label>

<label for="webhook_json_template">Custom JSON template <?= slim_help_button('Custom JSON template','Enter valid JSON and use placeholders such as {{station}}, {{timestamp}}, or a station field JSON key. Placeholders must appear as JSON values, without surrounding quotation marks; SLIM performs the required JSON quoting and escaping.') ?></label>
<textarea class="webhook-template" id="webhook_json_template" name="webhook_json_template" placeholder='{
  &quot;device&quot;: {{station}},
  &quot;timestamp&quot;: {{timestamp}}
}'><?= h((string)($webhook['json_template'] ?? '')) ?></textarea>
<p class="hint"><strong>Available placeholders:</strong> <code>{{station}}</code>, <code>{{timestamp}}</code><?php foreach($selectedStation['fields'] as $key=>$field): $jsonKey=(string)($field['json_key'] ?? $key); ?>, <code>{{<?= h($jsonKey) ?>}}</code><?php endforeach; ?>. Placeholders are inserted as JSON values, so use <code>&quot;device&quot;: {{station}}</code>, not <code>&quot;device&quot;: &quot;{{station}}&quot;</code>. Missing measurement values become <code>null</code>.</p>

<h4>Default payload fields <?= slim_help_button('Default payload fields','These field selections apply only when the SLIM default JSON payload is selected. If none are selected, SLIM forwards all normal measurement fields present in each observation.') ?></h4>
<p class="hint">Used only with the SLIM default payload. If no fields are selected, SLIM forwards all normal measurement fields present in the observation.</p>
<div class="inline">
<?php foreach($selectedStation['fields'] as $key=>$field):
$jsonKey=(string)($field['json_key'] ?? $key); ?>
<div>
<label>
<input type="checkbox" name="webhook_fields[]" value="<?= h($jsonKey) ?>"
<?= in_array($jsonKey,$webhookFields,true)?'checked':'' ?>>
<?= h($field['label'] ?? $jsonKey) ?> <code><?= h($jsonKey) ?></code>
</label>
</div>
<?php endforeach; ?>
</div>

<p>
<button class="primary" type="submit" name="action" value="save_webhook">Save Webhook</button>
<button class="secondary" type="submit" name="action" value="test_webhook">Send Test Webhook</button> <?= slim_help_button('Test webhook','Sends the station\'s most recent stored observation using the settings currently shown. Use this to verify the receiving endpoint before relying on automatic forwarding.') ?>
</p>
<p class="hint">Test Webhook sends the station's most recent stored observation using the settings currently shown in this form.</p>
<p><a href="../webhook/">View Webhook Deliveries</a> <span class="hint">— review webhook delivery results</span></p>
</form>
<?php endif; ?>

<?php endif; ?>
</div>
<?php endif; ?>


<?php if(($stationSection==='delete' || $stationSection==='all') && $selectedStation): ?>
<div class="danger-zone">
<h3 id="delete-station">Delete Station <?= slim_help_button('Delete Station','Remove an unused station from SLIM. The safer option removes SLIM configuration but keeps the MySQL observation table. Permanent deletion also drops the station data table and cannot be undone.') ?></h3>
<p>
Deleting a station removes its SLIM station configuration, dashboard/display
configuration, station dashboard images, and queued webhook deliveries.
</p>

<form method="post" onsubmit="return confirmStationDeletion(this);">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="delete_station">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<label>Deletion option</label>
<label class="delete-choice">
<input type="radio" name="delete_mode" value="remove" checked onchange="updateDeleteWarning(this.form)">
<strong>Remove station from SLIM only</strong><br>
<span class="hint">Removes SLIM configuration and dashboard information but keeps MySQL table <code><?= h((string)($selectedStation['table'] ?? '')) ?></code> and its observations.</span>
</label>

<label class="delete-choice">
<input type="radio" name="delete_mode" value="permanent" onchange="updateDeleteWarning(this.form)">
<strong>Permanently delete station and data table</strong><br>
<span class="hint">Also drops MySQL table <code><?= h((string)($selectedStation['table'] ?? '')) ?></code>. Observation data in that table cannot be recovered by SLIM.</span>
</label>

<div id="permanentDeleteConfirm" style="display:none;">
<label>
<input type="checkbox" name="confirm_drop_table" value="1">
I understand that MySQL table <code><?= h((string)($selectedStation['table'] ?? '')) ?></code> and all observations stored in it will be permanently deleted.
</label>
</div>

<label>Type station code <code><?= h($selectedCode) ?></code> to confirm</label>
<input type="text" name="confirm_station_code" value="" autocomplete="off" required>

<label>
<input type="checkbox" name="confirm_delete" value="1" required>
I understand that this station will be removed from SLIM.
</label>

<p class="hint">
This operation does not affect other stations. The permanent option cannot be undone.
Consider exporting or backing up important observations before permanently deleting a data table.
</p>

<p><button class="danger-button" type="submit">Delete Station</button></p>
</form>
</div>
<?php endif; ?>

<?php if(($stationSection==='variables' || $stationSection==='all') && $selectedStation): ?>
<?php
$orphanFields=[];
$orphanCheckErrorMessage='';

try {
    $orphanPdo=lakestat_db();
    $orphanTable=lakestat_safe_identifier((string)$selectedStation['table']);
    $activeDerived=is_array($selectedStation['derived_variables'] ?? null)
        ? $selectedStation['derived_variables'] : [];

    // Fetch the table schema once instead of performing one metadata query per
    // configured variable. This also makes the comparison independent of the
    // helper's implementation.
    $actualColumnRows=$orphanPdo->query("SHOW COLUMNS FROM `{$orphanTable}`")->fetchAll();
    $actualColumns=[];
    foreach($actualColumnRows as $actualColumnRow) {
        if(isset($actualColumnRow['Field'])) {
            $actualColumns[(string)$actualColumnRow['Field']]=true;
        }
    }

    $configuredFields=is_array($selectedStation['fields'] ?? null)
        ? $selectedStation['fields'] : [];

    foreach($configuredFields as $orphanKey=>$orphanMeta) {
        $orphanColumn=lakestat_safe_identifier(
            (string)($orphanMeta['column'] ?? $orphanKey)
        );

        if(!isset($actualColumns[$orphanColumn])
            && !isset($activeDerived[$orphanKey])) {
            $orphanFields[$orphanKey]=[
                'column'=>$orphanColumn,
                'label'=>(string)($orphanMeta['label'] ?? $orphanKey)
            ];
        }
    }
} catch(Throwable $orphanCheckError) {
    // Do not silently suppress a schema-comparison failure. If cleanup
    // detection itself fails, tell the administrator why.
    $orphanCheckErrorMessage=$orphanCheckError->getMessage();
}
?>

<?php if($orphanCheckErrorMessage!==''): ?>
<div class="warn">
<strong>Variable cleanup check could not be completed.</strong>
<p><?= h($orphanCheckErrorMessage) ?></p>
</div>
<?php endif; ?>

<?php if($orphanFields): ?>
<div class="warn">
<h3 id="orphaned-variables">Orphaned Variable Definitions <?= slim_help_button('Orphaned variable definitions','SLIM found one or more variables that remain in this station configuration even though their corresponding MySQL columns no longer exist. This can happen after a database column is manually removed or after an older derived-variable calculation is removed. Cleanup removes only the stale configuration entry; it does not delete MySQL data.') ?></h3>
<p>SLIM found configured variables whose MySQL columns no longer exist. These stale definitions can prevent a variable name from being reused.</p>
<table>
<thead><tr><th>Variable</th><th>Label</th><th>Missing MySQL column</th><th></th></tr></thead>
<tbody>
<?php foreach($orphanFields as $orphanKey=>$orphanInfo): ?>
<tr>
<td><code><?= h((string)$orphanKey) ?></code></td>
<td><?= h((string)$orphanInfo['label']) ?></td>
<td><code><?= h((string)$orphanInfo['column']) ?></code></td>
<td>
<form method="post" onsubmit="return confirm('Remove this orphaned variable definition from SLIM configuration? No MySQL data will be deleted because its column does not exist.');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="cleanup_orphan_field">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="orphan_field" value="<?= h((string)$orphanKey) ?>">
<button class="secondary" type="submit">Clean Up Definition</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if(($stationSection==='derived' || $stationSection==='all') && $selectedStation): ?>
<h3 id="derived-variables">Derived Variables <?= slim_help_button('Derived Variables','Create a new numeric station variable from one or more existing numeric variables. Calculations can combine up to four source variables using addition, subtraction, multiplication, or division, and may also include numeric constants. SLIM stores the result as a normal database column so it can be graphed, exported, labeled, and forwarded like other variables. Calculations use safe predefined operations rather than arbitrary PHP or SQL expressions.') ?></h3>
<p class="hint">
Derived variables are calculated when new observations arrive through the ingestion API
and when rows are imported into an existing station from CSV. The original source values
are retained. Operations are applied from top to bottom. A calculation may use up to four
different source variables and up to four calculation steps. If a required source value is
missing or nonnumeric, or if a variable denominator is zero, the derived result is stored as NULL.
</p>

<?php
$derivedRules=is_array($selectedStation['derived_variables'] ?? null)
    ? $selectedStation['derived_variables'] : [];
$numericSourceFields=[];
foreach($selectedStation['fields'] as $fieldKey=>$fieldMeta) {
    if(($fieldMeta['type'] ?? 'number')!=='text' && empty($fieldMeta['derived'])) {
        $numericSourceFields[$fieldKey]=$fieldMeta;
    }
}
?>


<?php
$standardConversionFields=[];
foreach($numericSourceFields as $fieldKey=>$fieldMeta) {
    $conversion=slim_standard_unit_conversion((string)($fieldMeta['units'] ?? ''));
    if($conversion===null) continue;
    $suggested=slim_standard_conversion_target((string)$fieldKey,$conversion);
    $alreadyExists=isset($selectedStation['fields'][$suggested]);
    $standardConversionFields[$fieldKey]=[
        'field'=>$fieldMeta,
        'conversion'=>$conversion,
        'target'=>$suggested,
        'exists'=>$alreadyExists
    ];
}
?>

<h4>Standard Unit Conversions <?= slim_help_button('Standard Unit Conversions','Create common presentation-unit conversions without sending duplicate measurements from the field station. SLIM creates a derived database column, automatically calculates all existing observations, and continues calculating new observations during ingestion and CSV import. The original sensor variable remains unchanged.') ?></h4>
<p class="hint">
SLIM recognizes common temperature, speed, and length units and can create °C ↔ °F,
m/s ↔ mph, and m ↔ ft conversions. Existing observations are backfilled automatically.
These convenience conversions are excluded from an unfiltered webhook by default; they can
still be explicitly selected in Webhook Setup if desired.
</p>
<?php if($standardConversionFields): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="add_standard_conversions">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<div class="table-wrap">
<table>
<thead><tr><th>Create</th><th>Source variable</th><th>Current units</th><th>Conversion</th><th>New variable</th><th>New units</th></tr></thead>
<tbody>
<?php foreach($standardConversionFields as $fieldKey=>$item):
    $fieldMeta=$item['field']; $conversion=$item['conversion'];
?>
<tr>
<td>
<?php if($item['exists']): ?>
<span class="hint">Created</span>
<?php else: ?>
<input type="checkbox" name="standard_conversion[]" value="<?= h((string)$fieldKey) ?>" aria-label="Create <?= h((string)$conversion['label']) ?> conversion for <?= h((string)$fieldKey) ?>">
<?php endif; ?>
</td>
<td><code><?= h((string)$fieldKey) ?></code><br><?= h((string)($fieldMeta['label'] ?? $fieldKey)) ?></td>
<td><?= h((string)($fieldMeta['units'] ?? '')) ?></td>
<td><?= h((string)$conversion['label']) ?></td>
<td><code><?= h((string)$item['target']) ?></code></td>
<td><?= h((string)$conversion['units']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p><button type="submit">Create Selected Conversions</button></p>
</form>
<?php else: ?>
<p class="hint">No non-derived numeric variables currently use recognized °C, °F, m/s, mph, m, or ft units.</p>
<?php endif; ?>

<h4>Derived Timestamps <?= slim_help_button('Derived Timestamps','Create an ordinary station variable from one of SLIM’s canonical UTC timestamps. The value is stored in the station table and can then be exported or used in a custom webhook like any other variable. Unix epoch seconds are seconds since 1970-01-01 00:00:00 UTC.') ?></h4>
<p class="hint">
Convert canonical timestamp to epoch time. The derived value is stored as a new normal <code>BIGINT</code> station column.
</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="add_derived_timestamp">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<div class="inline">
<div><label>New variable name</label><input type="text" name="timestamp_key" placeholder="observed_epoch" required></div>
<div><label>Display label</label><input type="text" name="timestamp_label" placeholder="Observed time (epoch)"></div>
</div>
<div class="inline">
<div><label>Timestamp source</label><select name="timestamp_source"><option value="observed_at">observed_at</option><option value="received_at">received_at</option></select></div>
<div><label>Output format</label><select name="timestamp_format"><option value="epoch_seconds">Unix epoch seconds</option></select></div>
</div>
<label style="display:flex;align-items:flex-start;gap:9px;font-weight:600;"><input type="checkbox" name="backfill_existing" value="1" checked style="width:auto;margin-top:3px;"><span>Apply conversion to existing observations</span></label>
<p><button class="primary" type="submit" onclick="return confirm('Back up the station, create the derived timestamp column, and backfill existing observations if selected?');">Back Up &amp; Add Derived Timestamp</button></p>
</form>

<h4>Custom Derived Variables</h4>
<?php if($derivedRules): ?>
<table>
<thead><tr><th>Derived variable</th><th>Calculation</th><th>Units</th><th></th></tr></thead>
<tbody>
<?php foreach($derivedRules as $derivedKey=>$rule):
    $meta=$selectedStation['fields'][$derivedKey] ?? [];
    $sourceKey=(string)($rule['source'] ?? '');
    $calculation=$sourceKey!=='' ? $sourceKey : 'x';
    if(($rule['kind'] ?? '')==='timestamp') {
        $calculation=$sourceKey.' → Unix epoch seconds';
    } elseif(isset($rule['steps']) && is_array($rule['steps'])) {
        foreach($rule['steps'] as $step) {
            $op=(string)($step['operation'] ?? '');
            $symbols=['multiply'=>'×','divide'=>'÷','add'=>'+','subtract'=>'−'];
            if(!isset($symbols[$op])) continue;
            $operandType=(string)($step['operand_type'] ?? 'constant');
            if($operandType==='variable') {
                $operand=(string)($step['variable'] ?? '?');
            } else {
                $operand=(string)($step['value'] ?? '');
            }
            $calculation.=' '.$symbols[$op].' '.$operand;
        }
    }
?>
<tr>
<td><code><?= h((string)$derivedKey) ?></code><br><?= h((string)($meta['label'] ?? $derivedKey)) ?></td>
<td><code><?= h($calculation) ?></code></td>
<td><?= h((string)($meta['units'] ?? '')) ?></td>
<td>
<form method="post" onsubmit="return confirm('Remove this derived variable and its MySQL column? Historical values calculated for this derived variable will be deleted. Source variables are not affected.');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="delete_derived_variable">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="derived_key" value="<?= h((string)$derivedKey) ?>">
<button class="secondary" type="submit">Remove Derived Variable</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<form method="post" id="derivedVariableForm">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="add_derived_variable">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">

<div class="inline">
<div>
<label>New variable name <?= slim_help_button('New variable name','Database-safe name for the calculated variable, for example wsmph, tempf, or wind_humidity. SLIM creates a new DOUBLE column with this name.') ?></label>
<input type="text" name="derived_key" placeholder="wsmph" required>
</div>
<div>
<label>Display label</label>
<input type="text" name="derived_label" placeholder="Wind Speed">
</div>
</div>

<div class="inline">
<div>
<label>Starting variable <?= slim_help_button('Starting variable','Existing numeric station variable used as the starting value in the calculation. Additional calculation steps can use either numeric constants or other existing numeric variables. Source measurements are retained unchanged.') ?></label>
<select name="derived_source" required>
<option value="">Select starting variable</option>
<?php foreach($numericSourceFields as $fieldKey=>$fieldMeta): ?>
<option value="<?= h((string)$fieldKey) ?>"><?= h((string)($fieldMeta['label'] ?? $fieldKey)) ?> (<?= h((string)$fieldKey) ?><?= !empty($fieldMeta['units']) ? ', '.h((string)$fieldMeta['units']) : '' ?>)</option>
<?php endforeach; ?>
</select>
</div>
<div>
<label>New units</label>
<input type="text" name="derived_units" placeholder="mph">
</div>
</div>

<label>Calculation steps <?= slim_help_button('Calculation steps','Operations are applied from top to bottom. Each step can use a numeric constant or another existing numeric variable. For example, Celsius to Fahrenheit is multiply by constant 1.8, then add constant 32. To multiply wind speed by humidity, choose wind speed as the starting variable, then multiply by the humidity variable. Up to four calculation steps and four different source variables are allowed.') ?></label>
<p class="hint">
Use constants for unit conversions and calibration coefficients, or select variables to create simple multivariable calculations.
Division by a variable that is zero produces NULL for that observation rather than an error.
</p>

<table id="derivedSteps">
<thead><tr><th>Operation</th><th>Operand type</th><th>Constant / variable</th><th></th></tr></thead>
<tbody>
<tr>
<td>
<select name="derived_step_operation[]" required>
<option value="multiply">Multiply by</option>
<option value="divide">Divide by</option>
<option value="add">Add</option>
<option value="subtract">Subtract</option>
</select>
</td>
<td>
<select name="derived_step_operand_type[]" class="derived-operand-type" onchange="updateDerivedOperandRow(this)" required>
<option value="constant">Constant</option>
<option value="variable">Variable</option>
</select>
</td>
<td>
<input type="number" step="any" name="derived_step_value[]" class="derived-constant" value="1">
<select name="derived_step_variable[]" class="derived-variable" style="display:none;">
<option value="">Select variable</option>
<?php foreach($numericSourceFields as $fieldKey=>$fieldMeta): ?>
<option value="<?= h((string)$fieldKey) ?>"><?= h((string)($fieldMeta['label'] ?? $fieldKey)) ?> (<?= h((string)$fieldKey) ?>)</option>
<?php endforeach; ?>
</select>
</td>
<td><button class="secondary" type="button" onclick="removeDerivedStep(this)">Remove</button></td>
</tr>
</tbody>
</table>
<p><button class="secondary" type="button" onclick="addDerivedStep()">+ Add Calculation Step</button></p>

<label style="display:flex;align-items:flex-start;gap:9px;font-weight:600;">
<input type="checkbox" name="backfill_existing" value="1" checked style="width:auto;margin-top:3px;">
<span>Apply calculation to existing observations
<?= slim_help_button('Apply calculation to existing observations','Recommended. SLIM immediately calculates this derived variable for observations already stored in the station table. Future incoming observations and CSV imports always use the derived calculation whether or not this option is checked. Missing source values and division by a zero variable produce NULL.') ?></span>
</label>
<p class="hint">
Recommended for most derived variables so historical and future observations use the same calculation.
Uncheck only if you intentionally want the derived variable to begin with new observations.
</p>

<p><button class="primary" type="submit" onclick="return confirm('Back up the station, create the new MySQL column, enable this derived calculation, and apply it to existing observations if selected?');">Back Up &amp; Add Derived Variable</button></p>
</form>
<?php endif; ?>

<?php endif; ?>

</div>


<script>
// Detect duplicate or invalid incoming fields before submission; PHP remains authoritative.
(function(){
    const form=document.getElementById('createStationForm');
    if(!form) return;
    form.addEventListener('submit',function(event){
        const message=document.getElementById('createStationValidation');
        if(form.querySelector('[name=field_setup_mode]').value==='auto') return;
        const seen=new Set();
        let issue='';
        form.querySelectorAll('[name="field_key[]"]').forEach(function(input){
            input.style.borderColor='';
            const key=input.value.trim();
            if(!key) return;
            if(!/^[A-Za-z][A-Za-z0-9_]*$/.test(key) || key.length>64){
                issue=issue || 'Incoming field "'+key+'" must begin with a letter and contain only letters, numbers, or underscores (maximum 64 characters).';
                input.style.borderColor='#b42318';
            } else if(seen.has(key.toLowerCase())){
                issue=issue || 'Duplicate incoming field "'+key+'". Rename or remove the duplicate; your other entries will be preserved.';
                input.style.borderColor='#b42318';
            }
            seen.add(key.toLowerCase());
        });
        if(issue){ event.preventDefault(); message.textContent=issue; message.hidden=false; message.scrollIntoView({block:'center'}); }
        else {message.hidden=true;}
    });
})();
function copySlimEndpoint(button){
    const input=document.getElementById('slimIngestEndpoint');
    if(!input) return;
    const fallback=function(){input.focus();input.select();};
    if(navigator.clipboard && window.isSecureContext){
        navigator.clipboard.writeText(input.value).then(function(){button.textContent='Copied';},fallback);
    } else fallback();
}
function addRow(selector){
    var tbody=document.querySelector(selector+' tbody');
    var first=tbody.querySelector('tr');
    var clone=first.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(el){el.value='';});
    clone.querySelector('select').selectedIndex=0;
    tbody.appendChild(clone);
}

function derivedVariableOptions(){
    var first=document.querySelector('#derivedSteps select.derived-variable');
    return first ? first.innerHTML : '<option value="">Select variable</option>';
}

function updateDerivedOperandRow(select){
    var row=select.closest('tr');
    if(!row) return;
    var constant=row.querySelector('.derived-constant');
    var variable=row.querySelector('.derived-variable');
    var useVariable=select.value==='variable';
    if(constant){
        constant.style.display=useVariable?'none':'';
        constant.required=!useVariable;
    }
    if(variable){
        variable.style.display=useVariable?'':'none';
        variable.required=useVariable;
    }
}

function addDerivedStep(){
    var tbody=document.querySelector('#derivedSteps tbody');
    if(!tbody) return;
    if(tbody.querySelectorAll('tr').length>=4){
        alert('A derived variable may contain at most 4 calculation steps.');
        return;
    }
    var row=document.createElement('tr');
    row.innerHTML='<td><select name="derived_step_operation[]" required>'+ 
        '<option value="multiply">Multiply by</option>'+ 
        '<option value="divide">Divide by</option>'+ 
        '<option value="add">Add</option>'+ 
        '<option value="subtract">Subtract</option>'+ 
        '</select></td>'+ 
        '<td><select name="derived_step_operand_type[]" class="derived-operand-type" onchange="updateDerivedOperandRow(this)" required>'+ 
        '<option value="constant">Constant</option><option value="variable">Variable</option></select></td>'+ 
        '<td><input type="number" step="any" name="derived_step_value[]" class="derived-constant" value="1">'+ 
        '<select name="derived_step_variable[]" class="derived-variable" style="display:none;">'+derivedVariableOptions()+'</select></td>'+ 
        '<td><button class="secondary" type="button" onclick="removeDerivedStep(this)">Remove</button></td>';
    tbody.appendChild(row);
}

function removeDerivedStep(button){
    var tbody=document.querySelector('#derivedSteps tbody');
    if(!tbody) return;
    var rows=tbody.querySelectorAll('tr');
    if(rows.length>1){
        button.closest('tr').remove();
    }else{
        rows[0].querySelector('select[name="derived_step_operation[]"]').value='multiply';
        var type=rows[0].querySelector('.derived-operand-type');
        type.value='constant';
        rows[0].querySelector('.derived-constant').value='1';
        rows[0].querySelector('.derived-variable').value='';
        updateDerivedOperandRow(type);
    }
}

function updateDeleteWarning(form){
    var permanent=form.querySelector('input[name="delete_mode"][value="permanent"]').checked;
    var box=document.getElementById('permanentDeleteConfirm');
    if(box) box.style.display=permanent?'block':'none';
    var cb=form.querySelector('input[name="confirm_drop_table"]');
    if(cb) cb.required=permanent;
}

function confirmStationDeletion(form){
    var mode=form.querySelector('input[name="delete_mode"]:checked');
    var typed=form.querySelector('input[name="confirm_station_code"]').value;
    var station=form.querySelector('input[name="station"]').value;

    if(typed!==station){
        alert('Type the station code exactly as shown to confirm deletion.');
        return false;
    }

    if(mode && mode.value==='permanent'){
        return window.confirm(
            'PERMANENT DELETE: This will remove station '+station+
            ' from SLIM and permanently delete its MySQL observation table. This cannot be undone. Continue?'
        );
    }

    return window.confirm(
        'Remove station '+station+' from SLIM? Its MySQL observation table will be kept.'
    );
}

function addMetadataRow(){
    var tbody=document.querySelector('#stationMetadata tbody');
    var row=document.createElement('tr');
    row.innerHTML='<td><input type="text" name="metadata_name[]" placeholder="e.g. Platform type"></td>'+
                  '<td><input type="text" name="metadata_value[]" placeholder="e.g. Panther Buoy"></td>'+
                  '<td><button class="secondary" type="button" onclick="removeMetadataRow(this)">Remove</button></td>';
    tbody.appendChild(row);
}

function removeMetadataRow(button){
    var tbody=document.querySelector('#stationMetadata tbody');
    var rows=tbody.querySelectorAll('tr');
    if(rows.length>1){
        button.closest('tr').remove();
    }else{
        rows[0].querySelectorAll('input').forEach(function(el){el.value='';});
    }
}

function addWebhookHeader(){
    var tbody=document.querySelector('#webhookHeaders tbody');
    var row=document.createElement('tr');
    row.innerHTML='<td><input name="webhook_header_name[]" placeholder="X-API-Key"></td>'+
                  '<td><input class="webhook-secret" name="webhook_header_value[]" autocomplete="off"></td>'+
                  '<td><button class="secondary" type="button" onclick="removeRow(this,\'#webhookHeaders\')">Remove</button></td>';
    tbody.appendChild(row);
}

function removeRow(button,selector){
    var rows=document.querySelectorAll(selector+' tbody tr');
    if(rows.length>1)button.closest('tr').remove();
}

// Preserve unsaved variable labels/units in this browser.
// If a save succeeds, the newly rendered server values match the draft and
// the draft is automatically discarded. If a session expires or the page is
// reloaded before saving, differing draft values are restored.
(function(){
    const form=document.getElementById('fieldMetadataForm');
    if(!form)return;

    const stationInput=form.querySelector('input[name="station"]');
    const stationCode=stationInput ? stationInput.value : '';
    if(!stationCode)return;

    const storageKey='slim-variable-metadata-draft:'+stationCode;
    const notice=document.getElementById('fieldDraftNotice');
    let saveTimer=null;

    function readForm(){
        const values={labels:{},units:{}};

        form.querySelectorAll('input[name^="field_label_edit["]').forEach(function(el){
            const m=el.name.match(/^field_label_edit\[(.+)\]$/);
            if(m)values.labels[m[1]]=el.value;
        });

        form.querySelectorAll('input[name^="field_units_edit["]').forEach(function(el){
            const m=el.name.match(/^field_units_edit\[(.+)\]$/);
            if(m)values.units[m[1]]=el.value;
        });

        return values;
    }

    function sameValues(a,b){
        return JSON.stringify(a)===JSON.stringify(b);
    }

    function saveDraft(){
        try{
            localStorage.setItem(storageKey,JSON.stringify({
                savedAt:Date.now(),
                values:readForm()
            }));
            if(notice){
                notice.textContent='Unsaved edits are being preserved in this browser.';
                notice.style.display='block';
            }
        }catch(e){}
    }

    function scheduleSave(){
        window.clearTimeout(saveTimer);
        saveTimer=window.setTimeout(saveDraft,350);
    }

    try{
        const raw=localStorage.getItem(storageKey);

        if(raw){
            const draft=JSON.parse(raw);
            const current=readForm();

            if(draft && draft.values){
                if(sameValues(current,draft.values)){
                    // Successful prior save: server values now equal the draft.
                    localStorage.removeItem(storageKey);
                }else{
                    Object.keys(draft.values.labels || {}).forEach(function(key){
                        const el=form.querySelector('[name="field_label_edit['+CSS.escape(key)+']"]');
                        if(el)el.value=draft.values.labels[key];
                    });

                    Object.keys(draft.values.units || {}).forEach(function(key){
                        const el=form.querySelector('[name="field_units_edit['+CSS.escape(key)+']"]');
                        if(el)el.value=draft.values.units[key];
                    });

                    if(notice){
                        notice.textContent='Unsaved variable edits were restored from this browser.';
                        notice.style.display='block';
                    }
                }
            }
        }
    }catch(e){}

    form.addEventListener('input',scheduleSave);
})();
</script>

<?= slim_admin_help_assets() ?>
</body>
</html>
