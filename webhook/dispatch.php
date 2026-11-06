<?php
declare(strict_types=1);
// Single installation-wide scheduled task. NEVER accessible via HTTP.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/lib/bootstrap.php';
try {
    $pdo=lakestat_db();
    // Serialize executions across all cron invocations for this database.
    $lockName='slim_webhook_dispatch_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,30);
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,0)');
    $stmt->execute([$lockName]);
    if((int)$stmt->fetchColumn()!==1) { fwrite(STDOUT,"SLIM dispatcher already running.\n"); exit(0); }
    try {
        lakestat_webhook_ensure_table($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `slim_scheduler_health` (
            `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            `last_run_at` DATETIME NOT NULL,
            `last_processed` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_delivered` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_failed` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        lakestat_webhook_prune($pdo);
        // Up to 50 jobs/run with a 45s soft time budget checked between
        // requests. One final HTTP timeout may extend runtime; GET_LOCK prevents
        // overlapping dispatchers.
        $result=lakestat_webhook_process_pending($pdo,50,1);
        $health=$pdo->prepare('INSERT INTO `slim_scheduler_health`
            (`id`,`last_run_at`,`last_processed`,`last_delivered`,`last_failed`)
            VALUES (1,UTC_TIMESTAMP(),?,?,?) ON DUPLICATE KEY UPDATE
            `last_run_at`=VALUES(`last_run_at`),`last_processed`=VALUES(`last_processed`),
            `last_delivered`=VALUES(`last_delivered`),`last_failed`=VALUES(`last_failed`)');
        $health->execute([$result['processed'],$result['delivered'],$result['unsuccessful']]);
        fwrite(STDOUT,'SLIM dispatcher: '.json_encode($result)."\n");
    } finally {
        $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
} catch(Throwable $e) {
    error_log('SLIM cron dispatcher failure: '.get_class($e).': '.$e->getMessage());
    fwrite(STDERR,"SLIM dispatcher failed; see PHP error log.\n");
    exit(1);
}
