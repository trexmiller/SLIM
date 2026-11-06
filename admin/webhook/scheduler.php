<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';
function h(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
$script=realpath(dirname(__DIR__,2).'/webhook/dispatch.php');
$command='[PHP CLI executable] '.escapeshellarg($script ?: dirname(__DIR__,2).'/webhook/dispatch.php');
$health=null;$pending=null;$error='';
try {
    $pdo=lakestat_db();
    if(lakestat_table_exists($pdo,'slim_scheduler_health'))
        $health=$pdo->query('SELECT * FROM `slim_scheduler_health` WHERE `id`=1')->fetch(PDO::FETCH_ASSOC) ?: null;
    if(lakestat_table_exists($pdo,'lakestat_webhook_queue'))
        $pending=(int)$pdo->query("SELECT COUNT(*) FROM `lakestat_webhook_queue` WHERE `status`='pending'")->fetchColumn();
} catch(Throwable $e) { $error='Unable to read scheduler status.'; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhook Scheduler Setup</title><style>
body{font:16px Arial,sans-serif;background:#f5f6f7;color:#222;margin:0}.wrap{max-width:950px;margin:35px auto;padding:25px;background:white;border:1px solid #ddd;border-radius:8px;line-height:1.55}code,pre{background:#f1f3f5;padding:6px;overflow-wrap:anywhere}pre{padding:15px;white-space:pre-wrap}.warn{background:#fff4d5;padding:12px}li{margin-bottom:10px}
</style></head><body><div class="wrap"><p><?= slim_admin_session_bar() ?><a href="./">&larr; Webhook Deliveries</a></p><h1>Webhook Scheduler Setup</h1>
<p>One scheduled PHP task processes outbound webhooks for <strong>all stations</strong>. Data ingestion is independent of this task. If cron is absent or stops running, observations continue to be stored, but webhook jobs accumulate.</p>
<ol><li>In cPanel open <strong>Cron Jobs</strong> and add one job. Set the schedule to <strong>Once Per Minute</strong> (minute <code>*</code>, hour <code>*</code>, day <code>*</code>, month <code>*</code>, weekday <code>*</code>), if your host permits.</li>
<li>Identify the <strong>PHP CLI</strong> executable appropriate to your hosting account. Your hosting provider or cPanel documentation can confirm the path; it is not necessarily the same as PHP used for web requests.</li>
<li>Enter this command, replacing <code>[PHP CLI executable]</code> with the correct executable path:</li></ol>
<pre><?= h($command) ?></pre>
<p>Example only: <code>/usr/local/bin/php <?= h(escapeshellarg($script ?: 'webhook/dispatch.php')) ?></code>. Do not assume this PHP executable exists on your host. The dispatcher is CLI-only and cannot be run through a browser.</p>
<p>After saving the cron job, wait approximately two minutes and reload this page to check the heartbeat. The task records its run even when no webhooks are pending.</p>
<?php if($error): ?><p class="warn"><?= h($error) ?></p><?php endif; ?>
<p><strong>Last completed cron run (UTC):</strong> <?= h((string)($health['last_run_at'] ?? 'Not yet detected')) ?></p>
<p><strong>Last run:</strong> <?= (int)($health['last_processed'] ?? 0) ?> processed, <?= (int)($health['last_delivered'] ?? 0) ?> delivered, <?= (int)($health['last_failed'] ?? 0) ?> failed.</p>
<p><strong>Pending webhook jobs:</strong> <?= $pending===null?'Unavailable':(int)$pending ?></p>
<p class="warn"><strong>Upgrade note:</strong> Review pending webhook records from previous releases before enabling cron. Old pending records will be eligible for delivery. The Webhook Deliveries page can discard them if needed.</p>
<p>Delivery uses one attempt per queued job. Slow or unavailable destinations cannot delay a station's ingestion response. If a destination accepted data but timed out before acknowledging it, a manual retry could duplicate delivery.</p>
</div></body></html>
