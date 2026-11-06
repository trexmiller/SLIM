<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

if(empty($_SESSION['test_data_csrf'])) {
    $_SESSION['test_data_csrf']=bin2hex(random_bytes(24));
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function post_json(string $url, string $apiKey, array $payload): array {
    $json=json_encode($payload);

    if($json===false)return ['ok'=>false,'status'=>0,'body'=>'Unable to encode JSON.'];

    $headers=['Content-Type: application/json','X-API-Key: '.$apiKey];

    if(function_exists('curl_init')) {
        try { slim_outbound_https_target($url); }
        catch (Throwable $e) { return ['ok'=>false,'status'=>0,'body'=>'Unsafe or unreachable HTTPS destination.']; }
        $ch=curl_init($url);
        slim_curl_secure_destination($ch, $url);

        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$json,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2
        ]);

        $body=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err=curl_error($ch);
        curl_close($ch);

        if($body===false)return ['ok'=>false,'status'=>$status,'body'=>'Connection failed.'];

        return ['ok'=>$status>=200&&$status<300,'status'=>$status,'body'=>($status>=200&&$status<300 ? '' : 'Destination returned HTTP '.$status)];
    }

    return ['ok'=>false,'status'=>0,'body'=>'cURL is required for this test utility.'];
}

$defaults=[
    'endpoint'=>'',
    'station'=>'',
    'api_key'=>'',
    'count'=>24,
    'interval_minutes'=>15,
    'include_temperature'=>'1',
    'field1'=>'t0','start1'=>24.0,'trend1'=>-0.03,
    'field2'=>'t1','start2'=>23.0,'trend2'=>-0.02,
    'field3'=>'t2','start3'=>22.0,'trend3'=>-0.01,
    'field4'=>'t3','start4'=>20.5,'trend4'=>0.00,
    'include_wind'=>'1',
    'wind_speed_field'=>'ws',
    'wind_direction_field'=>'wd',
    'wind_speed_mean'=>4.0,
    'wind_speed_amplitude'=>2.0,
    'wind_direction_start'=>210.0,
    'wind_direction_swing'=>55.0
];

foreach($defaults as $k=>$v)if(!isset($_POST[$k]))$_POST[$k]=$v;

$error='';$message='';$results=[];

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['generate'])) {
    try {
        if(!hash_equals((string)($_SESSION['test_data_csrf'] ?? ''),(string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session validation failed. Reload the page and try again.');
        }

        $endpoint=trim((string)$_POST['endpoint']);
        $station=trim((string)$_POST['station']);
        $apiKey=trim((string)$_POST['api_key']);
        $count=max(1,min(500,(int)$_POST['count']));
        $interval=max(1,min(1440,(int)$_POST['interval_minutes']));

        if($endpoint===''||$station===''||$apiKey==='')throw new RuntimeException('Endpoint, station code, and API key are required.');
        if(stripos($endpoint,'https://')!==0)throw new RuntimeException('Use an HTTPS endpoint.');

        $includeTemperature=isset($_POST['include_temperature']);
        $includeWind=isset($_POST['include_wind']);

        if(!$includeTemperature && !$includeWind)throw new RuntimeException('Select at least one data group.');

        $tempFields=[];

        if($includeTemperature) {
            for($i=1;$i<=4;$i++) {
                $name=trim((string)($_POST['field'.$i] ?? ''));
                if($name==='')continue;
                $tempFields[]=[
                    'name'=>$name,
                    'start'=>(float)($_POST['start'.$i] ?? 0),
                    'trend'=>(float)($_POST['trend'.$i] ?? 0)
                ];
            }
        }

        $windSpeedField=trim((string)($_POST['wind_speed_field'] ?? 'ws'));
        $windDirectionField=trim((string)($_POST['wind_direction_field'] ?? 'wd'));
        $windMean=(float)($_POST['wind_speed_mean'] ?? 4.0);
        $windAmp=max(0.0,(float)($_POST['wind_speed_amplitude'] ?? 2.0));
        $windDirStart=(float)($_POST['wind_direction_start'] ?? 210.0);
        $windDirSwing=max(0.0,(float)($_POST['wind_direction_swing'] ?? 55.0));

        $end=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $startTime=$end->sub(new DateInterval('PT'.(($count-1)*$interval).'M'));

        for($row=0;$row<$count;$row++) {
            $timestamp=$startTime->add(new DateInterval('PT'.($row*$interval).'M'));

            $payload=[
                'station'=>$station,
                'timestamp'=>$timestamp->format(DateTime::ATOM)
            ];

            if($includeTemperature) {
                foreach($tempFields as $idx=>$field) {
                    $wave=sin(($row/max(1,$count-1))*2*M_PI+($idx*0.55))*(0.35+$idx*0.08);
                    $payload[$field['name']]=round($field['start']+($field['trend']*$row)+$wave,3);
                }
            }

            if($includeWind) {
                $phase=($row/max(1,$count-1))*2*M_PI;
                $ws=$windMean+$windAmp*sin($phase)+0.35*$windAmp*sin(2*$phase+0.7);
                if($ws<0)$ws=0;

                $wd=$windDirStart+$windDirSwing*sin($phase*0.85)+18.0*sin(2*$phase+0.4);
                $wd=fmod($wd,360.0);if($wd<0)$wd+=360.0;

                $payload[$windSpeedField]=round($ws,3);
                $payload[$windDirectionField]=round($wd,1);
            }

            $response=post_json($endpoint,$apiKey,$payload);

            $results[]=[
                'row'=>$row+1,
                'timestamp'=>$payload['timestamp'],
                'status'=>$response['status'],
                'ok'=>$response['ok']
            ];

            if(!$response['ok']) {
                throw new RuntimeException(
                    'Stopped at observation '.($row+1).
                    '. HTTP status: '.$response['status'].
                    '. Response: '.$response['body']
                );
            }
        }

        $message='Successfully posted '.count($results).' synthetic observations.';

    } catch(Throwable $e) {
        $error=$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test Data</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1000px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
label{display:block;margin:10px 0 5px;font-weight:600}
input{width:100%;max-width:520px;padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
.inline{display:flex;gap:12px;flex-wrap:wrap}.inline>div{flex:1;min-width:180px}
button{padding:10px 18px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
fieldset{border:1px solid #ddd;border-radius:6px;margin:20px 0;padding:18px}

.test-notice{background:#fff8df;border:1px solid #e0c56e;border-left:5px solid #c39b24;border-radius:6px;padding:14px 16px;margin:12px 0 20px;line-height:1.5;color:#333}
.test-notice strong{display:block;margin-bottom:4px}
</style>
</head>
<body>
<?= slim_admin_session_bar() ?>
<div style="max-width:1100px;margin:12px auto 0;padding:0 20px;">
<a href="../" style="display:inline-block;text-decoration:none;font-weight:600;">&larr; Admin Home</a>
</div>

<div class="wrap">
<div class="card">
<h1>SLIM Test Data Generator</h1>
<div class="test-notice">
<strong>For testing purposes only.</strong>
This generator provides a simple mechanism for adding randomized fictional data to a station database table so that SLIM features can be tested, including data ingestion and dashboard setup. The generated values are not intended to represent scientifically realistic environmental data or to simulate a real monitoring system.
<br><br>
Before using this utility, first create a test station under <strong>Station Setup</strong>. You will need the test station's <strong>station code</strong>, <strong>HTTPS ingestion endpoint</strong>, and <strong>API key</strong>. These values can all be obtained from the <a href="../stations/"><strong>Station Directory</strong></a>.
</div>


<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h((string)$_SESSION['test_data_csrf']) ?>">

<label>HTTPS ingestion endpoint</label>
<input type="text" name="endpoint" value="<?= h((string)$_POST['endpoint']) ?>" required>

<div class="inline">
<div><label>Station code</label><input type="text" name="station" value="<?= h((string)$_POST['station']) ?>" required></div>
<div><label>API key</label><input type="text" name="api_key" value="<?= h((string)$_POST['api_key']) ?>" required></div>
</div>

<div class="inline">
<div><label>Number of observations</label><input type="number" name="count" value="<?= h((string)$_POST['count']) ?>"></div>
<div><label>Interval (minutes)</label><input type="number" name="interval_minutes" value="<?= h((string)$_POST['interval_minutes']) ?>"></div>
</div>

<fieldset>
<legend>Temperature data</legend>
<label><input type="checkbox" name="include_temperature" <?= isset($_POST['include_temperature'])?'checked':'' ?>> Include temperature</label>

<?php for($i=1;$i<=4;$i++): ?>
<div class="inline">
<div><label>Field <?= $i ?></label><input type="text" name="field<?= $i ?>" value="<?= h((string)$_POST['field'.$i]) ?>"></div>
<div><label>Start</label><input type="number" step="any" name="start<?= $i ?>" value="<?= h((string)$_POST['start'.$i]) ?>"></div>
<div><label>Trend</label><input type="number" step="any" name="trend<?= $i ?>" value="<?= h((string)$_POST['trend'.$i]) ?>"></div>
</div>
<?php endfor; ?>
</fieldset>

<fieldset>
<legend>Wind data</legend>
<label><input type="checkbox" name="include_wind" <?= isset($_POST['include_wind'])?'checked':'' ?>> Include wind</label>

<div class="inline">
<div><label>Wind speed field</label><input type="text" name="wind_speed_field" value="<?= h((string)$_POST['wind_speed_field']) ?>"></div>
<div><label>Wind direction field</label><input type="text" name="wind_direction_field" value="<?= h((string)$_POST['wind_direction_field']) ?>"></div>
</div>

<div class="inline">
<div><label>Mean wind speed</label><input type="number" step="any" name="wind_speed_mean" value="<?= h((string)$_POST['wind_speed_mean']) ?>"></div>
<div><label>Wind speed variation</label><input type="number" step="any" name="wind_speed_amplitude" value="<?= h((string)$_POST['wind_speed_amplitude']) ?>"></div>
</div>

<div class="inline">
<div><label>Starting direction</label><input type="number" step="any" name="wind_direction_start" value="<?= h((string)$_POST['wind_direction_start']) ?>"></div>
<div><label>Directional swing</label><input type="number" step="any" name="wind_direction_swing" value="<?= h((string)$_POST['wind_direction_swing']) ?>"></div>
</div>
</fieldset>

<p><button type="submit" name="generate" value="1">Generate & Post Test Data</button></p>
</form>
</div>
</div>
</body>
</html>
