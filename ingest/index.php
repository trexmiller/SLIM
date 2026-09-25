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
    $ingestLimits = slim_ingest_limits();
    slim_rate_limit('ingest', $ingestLimits['rate_limit_per_minute'], $ingestLimits['rate_limit_enabled']);

    if (($system['require_https'] ?? true)) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

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

    // Failed-authentication lockout, keyed by IP. This is separate from the
    // throughput limiter above: it specifically targets sustained API-key
    // guessing, which a generous throughput limit alone would not catch.
    $authLockRemaining = slim_ingest_auth_locked();

    if ($authLockRemaining > 0) {
        if (!headers_sent()) {
            header('Retry-After: ' . (string)$authLockRemaining);
        }
        reply_json(429, ['ok'=>false,'error'=>'Too many failed authentication attempts. Try again later.']);
    }

    // Unknown station and wrong API key deliberately produce the identical
    // status code, JSON body, and (via the dummy-key comparison below)
    // similar response timing. Distinguishing between them would let a
    // caller enumerate valid station codes without ever supplying a key.
    try {
        $station = lakestat_station_config($stationCode);
    } catch (Throwable $unknownStationError) {
        $station = null;
    }

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($data['api_key'] ?? '');
    $expectedKey = $station !== null ? (string)($station['api_key'] ?? '') : '';

    if ($expectedKey === '') {
        $expectedKey = SLIM_INGEST_DUMMY_KEY;
    }

    if ($station === null || !is_string($apiKey) || !hash_equals($expectedKey, $apiKey)) {
        slim_ingest_record_auth_failure();
        reply_json(401, ['ok'=>false,'error'=>'Invalid API key.']);
    }

    slim_ingest_clear_auth_failures();

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

    if($timestamp!==null && $timestamp!=='') {
        try {
            if(is_numeric($timestamp)) {
                $numericTimestamp=(float)$timestamp;

                // Unix epoch milliseconds are normally 13 digits. Treat any
                // value >= 100 billion as milliseconds; epoch seconds remain
                // well below that threshold for centuries.
                if(abs($numericTimestamp)>=100000000000) {
                    $numericTimestamp/=1000.0;
                }

                $seconds=(int)floor($numericTimestamp);
                $dt=(new DateTimeImmutable('@'.$seconds))
                    ->setTimezone(new DateTimeZone('UTC'));
            } else {
                // Timestamp strings without an explicit zone are interpreted
                // using the station's configured time zone when available,
                // otherwise the SLIM system time zone.
                $timestampTimezone=(string)(
                    $station['timezone']
                    ?? (slim_system_config()['timezone'] ?? 'UTC')
                );
                $timestampTimezone=slim_valid_timezone($timestampTimezone);

                $dt=new DateTimeImmutable(
                    (string)$timestamp,
                    new DateTimeZone($timestampTimezone)
                );
                $dt=$dt->setTimezone(new DateTimeZone('UTC'));
            }

            $observedAt=$dt->format('Y-m-d H:i:s');
        } catch(Throwable $timestampError) {
            // A malformed station timestamp should not prevent otherwise valid
            // environmental data from being ingested. observed_at remains NULL.
            $observedAt=null;
        }
    }

    foreach ($station['fields'] as $field) {
        $jsonKey = $field['json_key'];

        if (!array_key_exists($jsonKey, $data)) {
            continue;
        }

        $column = lakestat_safe_identifier((string)$field['column']);
        $type = $field['type'] ?? 'number';
        $value = $data[$jsonKey];

        if ($value === '' || $value === null) {
            $value = null;
        } elseif ($type === 'number') {
            if (!is_numeric($value)) {
                reply_json(422, ['ok'=>false,'error'=>"Field {$jsonKey} must be numeric."]);
            }
            $value = (float)$value;
        } elseif ($type === 'integer') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                reply_json(422, ['ok'=>false,'error'=>"Field {$jsonKey} must be an integer."]);
            }
            $value = (int)$value;
        } else {
            $value = substr((string)$value, 0, 255);
        }

        $columns[] = $column;
        $params[$column] = $value;
    }

    if (!$columns) {
        reply_json(422, ['ok'=>false,'error'=>'No configured measurement fields were supplied.']);
    }

    // Calculate station-level derived variables from normalized incoming values.
    // Derived results become normal stored fields and may also be forwarded by webhook.
    $params=lakestat_apply_derived_variables($station,$params);
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
    $receivedAtUtc=$slimArrivalUtc->format('Y-m-d H:i:s');

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

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);

    // Queue outbound webhook only after the observation has been stored.
    // Webhook delivery is handled separately so a remote endpoint cannot
    // make ingestion fail or wait for a network timeout.
    try {
        $webhookPayload=lakestat_webhook_payload($station,$data,$observedAt);
        lakestat_webhook_enqueue($pdo,$station,$webhookPayload);
    } catch(Throwable $webhookError) {
        error_log('LakeStat webhook queue error: '.$webhookError->getMessage());
    }

    reply_json(201, [
        'ok'=>true,
        'station'=>$station['code'],
        'record_id'=>$pdo->lastInsertId(),
        'received_fields'=>$columns
    ]);

} catch (Throwable $e) {
    error_log('LakeStat ingest error: ' . $e->getMessage());
    reply_json(500, ['ok'=>false,'error'=>'Server error.']);
}
