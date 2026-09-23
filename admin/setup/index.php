<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
lakestat_ensure_config_dir();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function csrf_check(): void { if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('Session validation failed. Reload the page and try again.'); }
$error=''; $message='';
$dbConfigured=is_file(lakestat_config_path('database.php'));
$systemConfigured=is_file(lakestat_config_path('system.php'));
try {
 if($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check(); $action=$_POST['action'] ?? '';
  if($action==='save_database') {
   $db=['host'=>trim($_POST['db_host'] ?? 'localhost'),'port'=>(int)($_POST['db_port'] ?? 3306),'database'=>trim($_POST['db_name'] ?? ''),'username'=>trim($_POST['db_user'] ?? ''),'password'=>(string)($_POST['db_password'] ?? ''),'charset'=>'utf8mb4'];
   if($db['host']==='' || $db['database']==='' || $db['username']==='') throw new RuntimeException('Host, database name, and username are required.');
   $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$db['host'],$db['port'],$db['database']);
   $pdo=new PDO($dsn,$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
   $probe='lakestat_setup_probe_'.bin2hex(random_bytes(4));
   $pdo->exec("CREATE TABLE `{$probe}` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `value` VARCHAR(20)) ENGINE=InnoDB");
   $pdo->exec("INSERT INTO `{$probe}` (`value`) VALUES ('ok')"); $pdo->query("SELECT * FROM `{$probe}`")->fetch(); $pdo->exec("DROP TABLE `{$probe}`");
   lakestat_write_php_config(lakestat_config_path('database.php'),$db); $dbConfigured=true; $message='Database connection saved successfully.';
  }
  if($action==='update_database') {
   $currentDb=lakestat_load_config('database.php'); $newPassword=(string)($_POST['db_password'] ?? '');
   $db=['host'=>trim((string)($_POST['db_host'] ?? 'localhost')),'port'=>max(1,(int)($_POST['db_port'] ?? 3306)),'database'=>trim((string)($_POST['db_name'] ?? '')),'username'=>trim((string)($_POST['db_user'] ?? '')),'password'=>$newPassword!==''?$newPassword:(string)($currentDb['password'] ?? ''),'charset'=>'utf8mb4'];
   if($db['host']==='' || $db['database']==='' || $db['username']==='') throw new RuntimeException('Host, database name, and username are required.');
   $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$db['host'],$db['port'],$db['database']);
   $pdo=new PDO($dsn,$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]); $pdo->query('SELECT 1')->fetchColumn();
   lakestat_write_php_config(lakestat_config_path('database.php'),$db); $dbConfigured=true; $message='Database connection tested and updated successfully.';
  }
  if($action==='save_system') {
   $system=['dashboard_name'=>trim($_POST['dashboard_name'] ?? 'Environmental Monitoring Dashboard'),'organization'=>trim($_POST['organization'] ?? ''),'timezone'=>slim_valid_timezone((string)($_POST['timezone'] ?? 'America/Chicago')),'refresh_seconds'=>max(15,(int)($_POST['refresh_seconds'] ?? 120)),'graph_hours'=>max(1,(int)($_POST['graph_hours'] ?? 72)),'require_https'=>isset($_POST['require_https']),'allow_get'=>isset($_POST['allow_get']),'allow_form_post'=>isset($_POST['allow_form_post']),'allow_json_post'=>true];
   lakestat_write_php_config(lakestat_config_path('system.php'),$system); $systemConfigured=true; $message='System defaults saved successfully.';
  }
 }
} catch(Throwable $e) { $error=$e->getMessage(); }
$currentDbConfig=$dbConfigured?lakestat_load_config('database.php'):[];
$systemConfig=$systemConfigured?slim_system_config():[];
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SLIM Setup</title><style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1050px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
h1,h2{margin-top:0}
label{display:block;margin:10px 0 5px;font-weight:600}
input,select{padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
input[type=text],input[type=password],input[type=number],input[type=url]{width:100%;max-width:520px}
.inline{display:flex;gap:12px;flex-wrap:wrap}.inline>div{flex:1;min-width:180px}
button{padding:9px 15px;border:0;border-radius:5px;cursor:pointer}
.primary{background:#222;color:#fff}.secondary{background:#e7e7e7}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.warn{background:#fff7dd;border:1px solid #e5cf86;padding:12px;margin-bottom:15px}
.danger-zone{margin-top:28px;padding:18px;border:2px solid #b42318;border-radius:8px;background:#fff7f6}
.danger-zone h3{color:#8f1d14;margin-top:0}
.delete-choice{font-weight:400;padding:10px;border:1px solid #ddd;border-radius:6px;background:#fff;margin:8px 0}
.delete-choice strong{color:#222}
.danger-button{background:#b42318;color:#fff;font-weight:700}
.danger-button:hover{background:#8f1d14}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}
table input,table select{width:100%;max-width:none}
.hint{color:#666;font-size:.92rem}.webhook-secret{font-family:monospace}
code,pre{background:#f2f2f2}
pre{padding:12px;overflow:auto}

.slim-version-footer{font-size:12px}
</style></head><body>
<?= slim_admin_session_bar() ?>
<div style="max-width:1100px;margin:12px auto 0;padding:0 20px;"><a href="../" style="display:inline-block;text-decoration:none;font-weight:600;">&larr; Admin Home</a></div>
<div style="max-width:1100px;margin:12px auto 0;padding:0 20px;">
<div style="background:#f0f2f4;border:1px solid #ccc;border-radius:5px;padding:10px 14px;font-size:.88rem;">
Configuration directory currently in use: <code><?= h(lakestat_home()) ?></code>
</div>
</div>
<?php if(slim_config_inside_webroot()): ?>
<div style="max-width:1100px;margin:12px auto 0;padding:0 20px;"><div class="warn">
<strong>Configuration is inside the web directory.</strong>
SLIM is reading and writing configuration in <code><?= h(lakestat_home()) ?></code>,
which is published by the web server and protected only by <code>config/.htaccess</code>.
That file is ignored by nginx and by Apache with <code>AllowOverride None</code>.
<p>To relocate it: create a directory outside the document root (for example
<code><?= h((string)(getenv('HOME') ?: '/home/youruser')) ?>/lakestat_config</code>), move the
current contents there, then point SLIM at it. There are two ways to do that -- use whichever
actually works on your host, and confirm by reloading this page and checking the path shown
above changed:</p>
<ol style="margin:6px 0 0 18px;padding:0;">
<li><strong>Preferred, works on every host:</strong> create a file named
<code>config/EXTERNAL_CONFIG_PATH</code> (plain text, no PHP) whose only content is the
absolute path, e.g. <code><?= h((string)(getenv('HOME') ?: '/home/youruser')) ?>/lakestat_config</code>.
SLIM reads this directly from disk, so it works the same under mod_php, php-fpm, and CGI.</li>
<li><strong>Environment variable:</strong> set <code>LAKESTAT_CONFIG_DIR</code> to the same path.
This depends on your host passing <code>SetEnv</code> through to PHP's <code>getenv()</code>,
which php-fpm and CGI hosting frequently do not do -- if the path above doesn't change after
setting this, your host is one of them; use the pointer file instead.</li>
</ol>
</div></div>
<?php endif; ?>
<div class="wrap"><div class="card"><h1>SLIM Setup</h1><p class="hint">Configure the database connection and system-wide defaults for this SLIM installation. Station-specific settings are managed separately in Station Manager.</p>
<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?><?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?></div>
<div class="card"><h2>Database Connection <?= slim_help_button('Database Connection','SQL credentials used internally by SLIM to connect to MySQL. Stations do not receive these credentials; they authenticate to the ingestion API with station-specific API keys.') ?></h2>
<p class="hint">SLIM tests the connection before saving it. After initial setup, the existing database password is never displayed; leave the password field blank to keep it unchanged.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="<?= $dbConfigured?'update_database':'save_database' ?>">
<label>MySQL host <?= slim_help_button('MySQL host','Hostname of the MySQL server. On many shared-hosting installations this is localhost.') ?></label><input type="text" name="db_host" value="<?= h((string)($currentDbConfig['host'] ?? 'localhost')) ?>" required>
<div class="inline"><div><label>Port</label><input type="number" name="db_port" value="<?= (int)($currentDbConfig['port'] ?? 3306) ?>" min="1" max="65535" required></div><div><label>Database name</label><input type="text" name="db_name" value="<?= h((string)($currentDbConfig['database'] ?? '')) ?>" required></div></div>
<label>Database username</label><input type="text" name="db_user" value="<?= h((string)($currentDbConfig['username'] ?? '')) ?>" autocomplete="username" required>
<label>Database password <?= slim_help_button('Database password',$dbConfigured?'Leave blank to retain the password currently stored in config/database.php.':'Enter the MySQL password used by SLIM.') ?></label><input type="password" name="db_password" value="" autocomplete="new-password" <?= $dbConfigured?'placeholder="Leave blank to keep current password"':'' ?>>
<p><button class="primary" type="submit">Test Connection &amp; Save</button></p></form></div>
<?php if($dbConfigured): ?>
<div class="card"><h2>System Defaults</h2><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save_system">
<label>System Name <?= slim_help_button('System Name','Overall name for the monitoring network or observing system managed by this SLIM installation.') ?></label><input type="text" name="dashboard_name" value="<?= h((string)($systemConfig['dashboard_name'] ?? 'Environmental Monitoring Network')) ?>">
<label>Organization <?= slim_help_button('Organization','Organization responsible for this SLIM installation or monitoring network.') ?></label><input type="text" name="organization" value="<?= h((string)($systemConfig['organization'] ?? '')) ?>">
<?php $currentTimezone=(string)($systemConfig['timezone'] ?? 'America/Chicago'); ?>
<label>Time zone <?= slim_help_button('Time zone','Controls how public dashboard timestamps and the live clock are displayed.') ?></label><select name="timezone" required><?php foreach(slim_timezone_identifiers() as $timezone): ?><option value="<?= h($timezone) ?>" <?= $timezone===$currentTimezone?'selected':'' ?>><?= h($timezone) ?></option><?php endforeach; ?></select>
<div class="inline"><div><label>Refresh interval (seconds)</label><input type="number" name="refresh_seconds" value="<?= (int)($systemConfig['refresh_seconds'] ?? 120) ?>" min="15"></div><div><label>Default graph period (hours)</label><input type="number" name="graph_hours" value="<?= (int)($systemConfig['graph_hours'] ?? 72) ?>" min="1"></div></div>
<label><input type="checkbox" name="allow_form_post" <?= !array_key_exists('allow_form_post',$systemConfig)||!empty($systemConfig['allow_form_post'])?'checked':'' ?>> Allow form POST</label>
<label><input type="checkbox" name="allow_get" <?= !empty($systemConfig['allow_get'])?'checked':'' ?>> Allow GET compatibility mode</label>
<label><input type="checkbox" name="require_https" <?= !array_key_exists('require_https',$systemConfig)||!empty($systemConfig['require_https'])?'checked':'' ?>> Require HTTPS</label>
<p><button class="primary" type="submit">Save System Defaults</button></p></form></div><?php endif; ?>
</div><div class="site-footer slim-version-footer"><?= h(slim_footer_text()) ?></div>
<?= slim_admin_help_assets() ?>
</body></html>
