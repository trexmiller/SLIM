<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Capture the application arrival instant before request parsing/authentication.
// This is the closest practical server-side representation of when SLIM
// received the observation.
$slimArrivalUtc=new DateTimeImmutable('now',new DateTimeZone('UTC'));

function reply_json(int $status, array $body) {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $system = lakestat_load_config('system.php');

    // Throughput limiter: caps total requests/minute from one IP across all
    // stations. Runs before authentication so it also bounds the cost of
    // repeated failed attempts, not just successful ingestion.
    // No IP-wide ingest throttle: carrier NAT can put unrelated stations
    // behind the same public IP. Valid station traffic must not be blocked
    // by another device's authentication failures or traffic volume.

    if (($system['require_https'] ?? true)) {
        $https = slim_request_is_https();

        if (!$https) {
            reply_json(400, ['ok'=>false,'error'=>'HTTPS is required.']);
        }
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));

    if ($method === 'POST' && $contentType === 'application/json') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            reply_json(400, ['ok'=>false,'error'=>'Malformed JSON.']);
        }
    } elseif ($method === 'POST' && ($system['allow_form_post'] ?? false)) {
        $data = $_POST;
    } elseif ($method === 'GET' && ($system['allow_get'] ?? false)) {
        $data = $_GET;
    } else {
        reply_json(405, ['ok'=>false,'error'=>'Unsupported ingestion method.']);
    }

    $stationCode = (string)($data['station'] ?? '');

    if ($stationCode === '') {
        reply_json(400, ['ok'=>false,'error'=>'station is required.']);
    }

    // Credentials are accepted only from the header. Never accept secrets in
    // query strings or request bodies, where they may be logged.
    if (array_key_exists('api_key', $_GET) || array_key_exists('api_key', $data)) {
        reply_json(400, ['ok'=>false,'error'=>'API keys must be sent in the X-API-Key header only.']);
    }
    try {
        $station = lakestat_station_config($stationCode);
    } catch (Throwable $unknownStationError) {
        $station = null;
    }
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $authenticated = slim_api_key_matches($station ?? [], is_string($apiKey) ? $apiKey : '');
    if ($station === null || !$authenticated) {
        reply_json(401, ['ok'=>false,'error'=>'Invalid API key.']);
    }

    $table = lakestat_safe_identifier((string)$station['table']);
    $columns = [];
    $params = [];

    // Use the station's configured timestamp source. If none is configured,
    // accept the conventional "timestamp" payload key when present.
    $timestampField=trim((string)($station['timestamp_field'] ?? ''));
    $timestamp=null;

    if($timestampField!=='' && array_key_exists($timestampField,$data)) {
        $timestamp=$data[$timestampField];
    } elseif(array_key_exists('timestamp',$data)) {
        $timestamp=$data['timestamp'];
    }

    $observedAt=null;

    // Missing timestamps retain the documented receipt-time fallback. Present
    // but invalid timestamps are rejected before any observation is inserted.
    if ($timestamp !== null && $timestamp !== '') {
        try {
            if (!is_string($timestamp) && !is_int($timestamp) && !is_float($timestamp)) {
                throw new InvalidArgumentException('Timestamp must be a string or number.');
            }
            $rawTimestamp=trim((string)$timestamp);
            if ($rawTimestamp === '') {
                throw new InvalidArgumentException('Timestamp is empty.');
            }
            if (is_numeric($rawTimestamp)) {
                // Only 10-digit epoch seconds or 13-digit epoch milliseconds.
                // Shorter numeric strings (including YYYYMMDD) are ambiguous.
                if (!preg_match('/^-?(?:[0-9]{10}|[0-9]{13})$/D', $rawTimestamp)) {
                    throw new InvalidArgumentException('Epoch timestamp must use 10-digit seconds or 13-digit milliseconds.');
                }
                $milliseconds=(strlen(ltrim($rawTimestamp,'-'))===13);
                $seconds=$milliseconds ? (int)floor(((float)$rawTimestamp)/1000.0) : (int)$rawTimestamp;
                $dt=new DateTimeImmutable('@'.$seconds);
            } else {
                $timestampTimezone=(string)($station['timezone'] ?? (slim_system_config()['timezone'] ?? 'UTC'));
                $timestampTimezone=slim_valid_timezone($timestampTimezone);
                $dt=new DateTimeImmutable($rawTimestamp,new DateTimeZone($timestampTimezone));
                // PHP sometimes accepts malformed dates with rollover warnings.
                $parseErrors=DateTimeImmutable::getLastErrors();
                if (is_array($parseErrors) && ($parseErrors['warning_count']>0 || $parseErrors['error_count']>0)) {
                    throw new InvalidArgumentException('Invalid date or time.');
                }
            }
            $dt=$dt->setTimezone(new DateTimeZone('UTC'));
            // Bounds apply only to live ingestion. Historical CSV import is separate.
            // Allow 2000-01-01 onward and up to 24 hours ahead of server receipt.
            $earliest=new DateTimeImmutable('2000-01-01T00:00:00Z');
            $latest=$slimArrivalUtc->modify('+24 hours');
            if ($dt<$earliest || $dt>$latest) {
                throw new InvalidArgumentException('Timestamp outside allowed range (2000-01-01 UTC to 24 hours in the future).');
            }
            $observedAt=$dt->format('Y-m-d H:i:s');
        } catch (Throwable $timestampError) {
            reply_json(422,['ok'=>false,'error'=>'Invalid station timestamp: '.$timestampError->getMessage()]);
        }
    }

    foreach ($station['fields'] as $field) {
        // Derived fields are server-calculated; never accept client-supplied values.
        if (!empty($field['derived'])) {
            if (isset($field['json_key'])) {
                unset($data[(string)$field['json_key']]);
            }
            continue;
        }
        $jsonKey = $field['json_key'];

        if (!array_key_exists($jsonKey, $data)) {
            continue;
        }

        $column = lakestat_safe_identifier((string)$field['column']);
        $type = $field['type'] ?? 'number';
        $value = $data[$jsonKey];

        try {
            $normalized=slim_observation_normalize_field($field,$value);
        } catch(InvalidArgumentException $e) {
            reply_json(422,['ok'=>false,'error'=>"Field {$jsonKey}: ".$e->getMessage()]);
        }
        $value=$normalized['value'];
        if(array_key_exists('qc',$normalized)) {
            $rawColumn=lakestat_safe_identifier($column.'_raw');
            $qcColumn=lakestat_safe_identifier($column.'_qc');
            $columns[]=$rawColumn;
            $params[$rawColumn]=$normalized['raw'];
            $columns[]=$qcColumn;
            $params[$qcColumn]=$normalized['qc'];
        }

        $columns[] = $column;
        $params[$column] = $value;

        // Downstream webhook payloads use the quality-controlled value.
        $data[$jsonKey]=$value;
    }

    if (!$columns) {
        reply_json(422, ['ok'=>false,'error'=>'No configured measurement fields were supplied.']);
    }

    // Resolve SLIM's canonical UTC timestamps before derived calculations so
    // timestamp-derived variables can use the same observed/received times that
    // will be stored for this observation.
    $receivedAtUtc=$slimArrivalUtc->format('Y-m-d H:i:s');
    if($observedAt===null || $observedAt==='') {
        $observedAt=$receivedAtUtc;
    }

    // Calculate station-level derived variables from normalized incoming values.
    // Derived results become normal stored fields and may also be forwarded by webhook.
    $params=lakestat_apply_derived_variables($station,$params,[
        'observed_at'=>$observedAt,
        'received_at'=>$receivedAtUtc
    ]);
    $columns=array_keys($params);

    // Add derived values to the normalized payload used by outbound webhooks.
    foreach(($station['derived_variables'] ?? []) as $derivedKey=>$rule) {
        if(!isset($station['fields'][$derivedKey])) continue;
        $derivedColumn=(string)($station['fields'][$derivedKey]['column'] ?? $derivedKey);
        $derivedJsonKey=(string)($station['fields'][$derivedKey]['json_key'] ?? $derivedKey);
        if(array_key_exists($derivedColumn,$params)) {
            $data[$derivedJsonKey]=$params[$derivedColumn];
        }
    }

    $pdo = lakestat_db();

    // received_at is SLIM's single canonical data-arrival timestamp.
    // It is captured by the application and stored explicitly as UTC, avoiding
    // dependence on the hosting provider/MySQL session time zone.
    $sqlColumns = ['observed_at','received_at'];
    $sqlValues = [':observed_at',':received_at'];
    $bind = [
        ':observed_at'=>$observedAt,
        ':received_at'=>$receivedAtUtc
    ];

    foreach ($columns as $column) {
        $sqlColumns[] = "`{$column}`";
        $placeholder = ':f_' . $column;
        $sqlValues[] = $placeholder;
        $bind[$placeholder] = $params[$column];
    }

    $sql = "INSERT INTO `{$table}` ("
         . implode(', ', $sqlColumns)
         . ") VALUES ("
         . implode(', ', $sqlValues)
         . ")";

    // Observation persistence must never depend on webhook configuration or queue health.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $observationRecordId=$pdo->lastInsertId();
        $pdo->commit();
    } catch(Throwable $storageError) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $storageError;
    }

    // Best effort only: malformed templates and queue errors cannot reject a
    // successfully committed observation. No webhook DDL is performed here.
    try {
        $webhookConfig=lakestat_webhook_config($station);
        if(!empty($webhookConfig['enabled']) && trim((string)($webhookConfig['url'] ?? ''))!=='') {
            $webhookPayload=lakestat_webhook_payload($station,$data,$observedAt);
            lakestat_webhook_enqueue($pdo,$station,$webhookPayload);
        }
    } catch(Throwable $webhookError) {
        error_log('SLIM webhook queue failure for station '.(string)$station['code']
            .' (observation '.(string)$observationRecordId.'): '.$webhookError->getMessage());
    }

    reply_json(201,['ok'=>true,'station'=>$station['code'],
        'record_id'=>$observationRecordId,'received_fields'=>$columns]);

} catch (Throwable $e) {
    error_log('LakeStat ingest error: ' . $e->getMessage());
    reply_json(500, ['ok'=>false,'error'=>'Server error.']);
}
