<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }

$error='';
$rows=[];
$system=slim_system_config();
$displayTimezoneName=slim_valid_timezone((string)($system['timezone'] ?? 'UTC'));
function webhook_display_time(mixed $value): string {
    global $displayTimezoneName;
    if($value===null || trim((string)$value)==='') return '—';
    // Database connections use UTC. Convert only for display using the configured
    // IANA timezone so daylight-saving transitions are handled automatically.
    try {
        $utc=new DateTimeZone('UTC');
        $display=new DateTimeZone($displayTimezoneName);
        return (new DateTimeImmutable((string)$value,$utc))->setTimezone($display)->format('Y-m-d g:i:s A');
    } catch(Throwable) {
        return (string)$value;
    }
}
try {
    $pdo=lakestat_db();
    lakestat_webhook_ensure_table($pdo);
    if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        if(!slim_admin_auth_token_valid()) throw new RuntimeException('Invalid security token.');
        $action=(string)($_POST['action'] ?? '');
        if($action==='retry') {
            $id=(int)($_POST['job_id'] ?? 0);
            if($id<=0) throw new RuntimeException('Invalid job ID.');
            $stmt=$pdo->prepare("UPDATE `lakestat_webhook_queue` SET `status`='pending',`attempts`=0,`last_error`=NULL WHERE `id`=:id AND `status`='failed'");
            $stmt->execute([':id'=>$id]);
            // Manual retry is queued for the next cron run, never sent in an admin request.
        } elseif($action==='close_pending') {
            $pdo->exec("UPDATE `lakestat_webhook_queue` SET `status`='failed', `last_error`='Pending job closed by administrator', `last_attempt_at`=UTC_TIMESTAMP() WHERE `status`='pending'");
        } else { throw new RuntimeException('Invalid action.'); }
    }
    lakestat_webhook_prune($pdo);
    $scheduler=null;
    if(lakestat_table_exists($pdo,'slim_scheduler_health')) {
        $scheduler=$pdo->query('SELECT * FROM `slim_scheduler_health` WHERE `id`=1')->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $pendingCount=(int)$pdo->query("SELECT COUNT(*) FROM `lakestat_webhook_queue` WHERE `status`='pending'")->fetchColumn();
    $rows=$pdo->query(
        "SELECT `id`,`station`,`status`,`attempts`,`http_code`,`last_error`,
                `created_at`,`last_attempt_at`,`delivered_at`
         FROM `lakestat_webhook_queue`
         ORDER BY `id` DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { $error=$e->getMessage(); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Webhook Deliveries</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}.wrap{max-width:1200px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px}.err{max-width:360px;word-break:break-word}
</style></head><body><div class="wrap">
<p><?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a> · <a href="../station/">Station Manager</a></p>
<div class="card"><h1>Webhook Deliveries</h1>
<p>Most recent 100 webhook delivery records. Times shown in <?= h($displayTimezoneName) ?>.</p>
<p>Webhook delivery is asynchronous: the single installation-wide cron task sends queued observations. Ingestion never contacts webhook receivers. One automatic attempt per delivery; failed deliveries may be requeued manually.</p>
<p><a href="scheduler.php"><strong>Configure and verify the webhook scheduler</strong></a></p>
<p>Pending deliveries: <strong><?= (int)($pendingCount ?? 0) ?></strong>. Last scheduler run (UTC): <strong><?= h((string)($scheduler['last_run_at'] ?? 'Not yet detected')) ?></strong>.</p>
<p><strong>Upgrade caution:</strong> Any pending records from earlier SLIM versions will also be dispatched when cron starts. Review the pending queue before enabling the scheduler.</p>
<form method="post"><input type="hidden" name="auth_csrf" value="<?= h(slim_admin_auth_token()) ?>"><input type="hidden" name="action" value="close_pending"><button type="submit" onclick="return confirm('Mark ALL pending webhook jobs as failed without sending? Use only to discard old or unwanted jobs.')">Discard all pending jobs</button></form>
<p>Delivered records are retained for 7 days; failed records for 30 days. Failed deliveries can be queued for one manual retry (processed by cron).</p>
<?php if($error): ?><div class="error"><?= h($error) ?></div><?php elseif(!$rows): ?><p>No webhook deliveries have been queued yet.</p>
<?php else: ?><table><thead><tr><th>ID</th><th>Station</th><th>Status</th><th>Attempts</th><th>HTTP</th><th>Created</th><th>Last attempt</th><th>Error</th><th>Action</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><?= (int)$r['id'] ?></td><td><?= h((string)$r['station']) ?></td><td><?= h((string)$r['status']) ?></td>
<td><?= (int)$r['attempts'] ?></td><td><?= $r['http_code']===null?'—':(int)$r['http_code'] ?></td>
<td><?= h(webhook_display_time($r['created_at'])) ?></td><td><?= h(webhook_display_time($r['last_attempt_at'])) ?></td>
<td class="err"><?= h((string)($r['last_error'] ?? '')) ?></td>
<td><?php if($r['status']==='failed'): ?><form method="post"><input type="hidden" name="auth_csrf" value="<?= h(slim_admin_auth_token()) ?>"><input type="hidden" name="action" value="retry"><input type="hidden" name="job_id" value="<?= (int)$r['id'] ?>"><button type="submit">Queue retry</button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div></div></body></html>