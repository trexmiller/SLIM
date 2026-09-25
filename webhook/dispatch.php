<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/lib/bootstrap.php';

// The dispatcher must never run from an unauthenticated web request. This
// check runs BEFORE any queue work so a browser request cannot drain the
// queue and merely receive a 403 afterwards.
if(PHP_SAPI!=='cli') {
    $dispatchToken=(string)(slim_system_config()['webhook_dispatch_token'] ?? '');
    $suppliedToken=(string)($_GET['token'] ?? ($_SERVER['HTTP_X_SLIM_DISPATCH_TOKEN'] ?? ''));

    if($dispatchToken==='' || !hash_equals($dispatchToken,$suppliedToken)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Run this dispatcher from cron/CLI.\n");
    }
}

$pdo=lakestat_db();
lakestat_webhook_ensure_table($pdo);

$limit=25;
$maxAttempts=5;

$stmt=$pdo->prepare(
    "SELECT * FROM `lakestat_webhook_queue`
     WHERE `status`='pending' AND `attempts` < :max_attempts
     ORDER BY `id` ASC LIMIT {$limit}"
);
$stmt->bindValue(':max_attempts',$maxAttempts,PDO::PARAM_INT);
$stmt->execute();
$jobs=$stmt->fetchAll(PDO::FETCH_ASSOC);

$processed=0;
$delivered=0;
$failed=0;

foreach($jobs as $job) {
    $processed++;

    $headers=json_decode((string)$job['headers_json'],true);
    if(!is_array($headers)) $headers=[];

    $result=lakestat_webhook_send(
        (string)$job['endpoint'],
        $headers,
        (string)$job['payload_json'],
        5
    );

    $attempts=(int)$job['attempts']+1;

    if($result['ok']) {
        $update=$pdo->prepare(
            "UPDATE `lakestat_webhook_queue`
             SET `status`='delivered',`attempts`=:attempts,
                 `http_code`=:http_code,`last_error`=NULL,
                 `last_attempt_at`=UTC_TIMESTAMP(),`delivered_at`=UTC_TIMESTAMP()
             WHERE `id`=:id"
        );
        $delivered++;
    } else {
        $status=$attempts >= $maxAttempts ? 'failed' : 'pending';
        $update=$pdo->prepare(
            "UPDATE `lakestat_webhook_queue`
             SET `status`=:status,`attempts`=:attempts,
                 `http_code`=:http_code,`last_error`=:last_error,
                 `last_attempt_at`=UTC_TIMESTAMP()
             WHERE `id`=:id"
        );
        $update->bindValue(':status',$status);
        $update->bindValue(':last_error',substr((string)$result['error'],0,4000));
        $failed++;
    }

    $update->bindValue(':attempts',$attempts,PDO::PARAM_INT);
    $update->bindValue(':http_code',(int)$result['http_code'],PDO::PARAM_INT);
    $update->bindValue(':id',(int)$job['id'],PDO::PARAM_INT);
    $update->execute();
}

if(PHP_SAPI!=='cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

if($processed > 0 || $failed > 0) {
    echo "SLIM webhook dispatcher: processed={$processed} delivered={$delivered} unsuccessful={$failed}\n";
}
