<?php
declare(strict_types=1);
/** First-payload schema initialization; called only after station authentication. */
function slim_initialize_station_fields(array $station, array $payload): array {
    if (empty($station['auto_discover']) || !empty($station['fields'])) return $station;
    $code=lakestat_station_code((string)$station['code']);
    $lockDir=lakestat_config_path('runtime');
    if(!is_dir($lockDir) && !mkdir($lockDir,0700,true) && !is_dir($lockDir)) throw new RuntimeException('Cannot create initialization lock directory.');
    $lock=fopen($lockDir.'/init_'.hash('sha256',$code).'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Unable to lock station initialization.');
    try {
        $station=lakestat_station_config($code);
        if(empty($station['auto_discover']) || !empty($station['fields'])) return $station;
        $excluded=['station'=>true,'timestamp'=>true,'api_key'=>true,'observed_at'=>true,'received_at'=>true,'id'=>true];
        $timestampField=(string)($station['timestamp_field'] ?? '');
        if($timestampField!=='') $excluded[$timestampField]=true;
        $fields=[];
        foreach($payload as $key=>$value) {
            if(!is_string($key) || isset($excluded[$key])) continue;
            // Do not silently change names sent by the device.
            lakestat_safe_identifier($key);
            if(str_ends_with($key,'_raw') || str_ends_with($key,'_qc')) throw new InvalidArgumentException('Reserved field suffix: '.$key);
            if(is_array($value) || is_object($value) || is_bool($value)) throw new InvalidArgumentException('Unsupported value type for '.$key);
            $type=is_string($value) && $value!=='' && !is_numeric($value) ? 'text' : 'number';
            if($value!==null && $type==='number' && !is_numeric($value)) throw new InvalidArgumentException('Unsupported numeric value for '.$key);
            $fields[$key]=['json_key'=>$key,'column'=>$key,'label'=>$key,'units'=>'','type'=>$type,'show_current'=>true,'show_graph'=>$type!=='text'];
            if(count($fields)>100) throw new InvalidArgumentException('First payload exceeds the 100-field discovery limit.');
        }
        if(!$fields) throw new InvalidArgumentException('First observation contains no discoverable measurement fields.');
        $pdo=lakestat_db();
        $table=lakestat_safe_identifier((string)$station['table']);
        $existing=[];
        foreach($pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[(string)$row['Field']]=true;
        foreach($fields as $key=>$field) {
            if(isset($existing[$key])) continue; // Recovery from an interrupted schema update.
            $sqlType=$field['type']==='text' ? 'VARCHAR(255) NULL' : 'DOUBLE NULL';
            $pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$key.'` '.$sqlType);
        }
        $station['fields']=$fields;
        $station['auto_discover']=false;
        lakestat_write_php_config(lakestat_config_path('stations/'.$code.'.php'),$station);
        return $station;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
