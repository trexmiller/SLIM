<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';
lakestat_ensure_config_dir();

if(empty($_SESSION['db_csrf'])) $_SESSION['db_csrf']=bin2hex(random_bytes(24));

function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }

function current_db_config(): array {
    $path=lakestat_config_path('database.php');
    $defaults=['host'=>'localhost','port'=>3306,'database'=>'','username'=>'','password'=>'','charset'=>'utf8mb4'];
    if(!is_file($path)) return $defaults;
    $cfg=require $path;
    return is_array($cfg) ? array_merge($defaults,$cfg) : $defaults;
}

function test_db_config(array $db): void {
    if($db['host']===''||$db['database']===''||$db['username']==='') {
        throw new RuntimeException('Host, database name, and username are required.');
    }
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$db['host'],$db['port'],$db['database'],$db['charset']);
    $pdo=new PDO($dsn,$db['username'],$db['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    $pdo->query('SELECT 1')->fetchColumn();
}

$error=''; $message=''; $existing=current_db_config();

try {
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($_SESSION['db_csrf'] ?? '',$_POST['csrf'] ?? '')) {
            throw new RuntimeException('Session validation failed. Reload and try again.');
        }
        $newPassword=(string)($_POST['db_password'] ?? '');
        $db=[
            'host'=>trim((string)($_POST['db_host'] ?? 'localhost')),
            'port'=>max(1,min(65535,(int)($_POST['db_port'] ?? 3306))),
            'database'=>trim((string)($_POST['db_name'] ?? '')),
            'username'=>trim((string)($_POST['db_user'] ?? '')),
            'password'=>$newPassword!=='' ? $newPassword : (string)$existing['password'],
            'charset'=>'utf8mb4'
        ];
        test_db_config($db);
        lakestat_write_php_config(lakestat_config_path('database.php'),$db);
        $existing=$db;
        $message='Database connection tested successfully and settings were saved.';
    }
} catch(Throwable $e) {
    $error=$e->getMessage();
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Database Settings</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}.wrap{max-width:900px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
label{display:block;margin:12px 0 5px;font-weight:600}input{width:100%;max-width:560px;padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
.inline{display:flex;gap:16px;flex-wrap:wrap}.inline>div{flex:1;min-width:220px}
button{padding:10px 18px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
</style></head><body><div class="wrap">
<p><?= slim_admin_session_bar() ?>
<a href="../">&larr; Admin Home</a></p>
<div class="card"><h1>Database Settings</h1>
<p class="hint">Submitted credentials are tested before saving them.</p>
<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['db_csrf']) ?>">
<div class="inline">
<div><label>Database host <?= slim_help_button('Database host','Hostname of the MySQL server. On many shared hosts this is localhost.') ?></label><input type="text" name="db_host" value="<?= h((string)$existing['host']) ?>" required></div>
<div><label>Port <?= slim_help_button('Database port','TCP port used by MySQL. The standard port is 3306 unless your host specifies otherwise.') ?></label><input type="number" name="db_port" value="<?= (int)$existing['port'] ?>" required></div>
</div>
<label>Database name <?= slim_help_button('Database name','Name of the MySQL database containing SLIM station tables.') ?></label><input type="text" name="db_name" value="<?= h((string)$existing['database']) ?>" required>
<label>Database username <?= slim_help_button('Database username','MySQL account SLIM uses to connect to the database.') ?></label><input type="text" name="db_user" value="<?= h((string)$existing['username']) ?>" required>
<label>Database password <?= slim_help_button('Database password','Leave blank to keep the saved password. SLIM tests new credentials before saving them.') ?></label><input type="password" name="db_password" value="" autocomplete="new-password">
<p class="hint">Leave blank to keep the currently saved password.</p>
<p><button type="submit">Test Connection &amp; Save</button></p>
</form></div>
<div class="card"><h2>Safety behavior</h2><p>The existing password is never displayed. If the connection test fails, the current <code>config/database.php</code> remains unchanged.</p></div>
</div><?= slim_admin_help_assets() ?>
</body></html>