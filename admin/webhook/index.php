<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';

function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }

$error='';
$rows=[];
try {
    $pdo=lakestat_db();
    lakestat_webhook_ensure_table($pdo);
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
<title>SLIM Webhook Deliveries</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}.wrap{max-width:1200px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px}.err{max-width:360px;word-break:break-word}
</style></head><body><div class="wrap">
<p><?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a> · <a href="../station/">Station Manager</a></p>
<div class="card"><h1>Webhook Deliveries</h1>
<p>Most recent 100 queued webhook deliveries.</p>
<?php if($error): ?><div class="error"><?= h($error) ?></div><?php elseif(!$rows): ?><p>No webhook deliveries have been queued yet.</p>
<?php else: ?><table><thead><tr><th>ID</th><th>Station</th><th>Status</th><th>Attempts</th><th>HTTP</th><th>Created</th><th>Last attempt</th><th>Error</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><?= (int)$r['id'] ?></td><td><?= h((string)$r['station']) ?></td><td><?= h((string)$r['status']) ?></td>
<td><?= (int)$r['attempts'] ?></td><td><?= $r['http_code']===null?'—':(int)$r['http_code'] ?></td>
<td><?= h((string)$r['created_at']) ?></td><td><?= h((string)($r['last_attempt_at'] ?? '—')) ?></td>
<td class="err"><?= h((string)($r['last_error'] ?? '')) ?></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div></div></body></html>