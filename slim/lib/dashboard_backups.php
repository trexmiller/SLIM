<?php
declare(strict_types=1);
function slim_dashboard_backup_path(string $code,string $kind,string $suffix='current'): string {
    $code=lakestat_station_code($code);
    if(!in_array($kind,['public','health'],true) || !in_array($suffix,['current','previous'],true)) throw new InvalidArgumentException('Invalid dashboard backup selector.');
    return lakestat_config_path('dashboard_backups/'.$kind.'_'.$code.'_'.$suffix.'.json');
}
function slim_dashboard_backup_save(string $code,string $kind,array $config): void {
    $dir=lakestat_config_path('dashboard_backups');
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cannot create private dashboard backup directory.');
    $lock=fopen($dir.'/'.hash('sha256',$kind.':'.$code).'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock dashboard backup.');
    try {
        $current=slim_dashboard_backup_path($code,$kind);
        $previous=slim_dashboard_backup_path($code,$kind,'previous');
        $json=json_encode(['format'=>'slim-dashboard','format_version'=>1,'kind'=>$kind,'station'=>$code,'saved_at'=>gmdate('c'),'configuration'=>$config],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(is_file($current)) {
            $old=file_get_contents($current);
            if($old===false) throw new RuntimeException('Cannot read previous dashboard backup.');
            slim_private_write_file($previous,$old,0600);
        }
        slim_private_write_file($current,$json."\n",0600);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function slim_dashboard_backup_parse(string $json,string $kind): array {
    if(strlen($json)>1048576) throw new RuntimeException('Dashboard backup exceeds 1 MB.');
    $doc=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($doc) || ($doc['format']??'')!=='slim-dashboard' || ($doc['format_version']??null)!==1 || ($doc['kind']??'')!==$kind || !is_array($doc['configuration']??null) || !is_array($doc['configuration']['displays']??null)) throw new RuntimeException('Invalid or incompatible dashboard backup.');
    if(count($doc['configuration']['displays'])>100) throw new RuntimeException('Too many dashboard displays.');
    return $doc['configuration'];
}
