<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['account_csrf'])) {
    $_SESSION['account_csrf'] = bin2hex(random_bytes(24));
}

function account_csrf_check(): void {
    if (!hash_equals((string)($_SESSION['account_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Session validation failed. Reload the page and try again.');
    }
}

$currentUser = (string)slim_admin_current_user();
$error   = '';
$message = '';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        account_csrf_check();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            $users = slim_admin_users();
            $hash  = (string)($users[$currentUser]['hash'] ?? '');

            if (!password_verify($current, $hash)) {
                usleep(400000);
                throw new RuntimeException('Your current password is incorrect.');
            }

            if (!hash_equals($new, $confirm)) {
                throw new RuntimeException('The two new passwords do not match.');
            }

            if (hash_equals($current, $new)) {
                throw new RuntimeException('The new password must differ from the current one.');
            }

            slim_admin_set_password($currentUser, $new);

            // Changing a credential invalidates the old session identifier.
            session_regenerate_id(true);

            $message = 'Password updated.';
        }

        if ($action === 'add_user') {
            $newUser = (string)($_POST['new_username'] ?? '');
            $newPass = (string)($_POST['new_user_password'] ?? '');
            $confirm = (string)($_POST['new_user_confirm'] ?? '');

            if (!hash_equals($newPass, $confirm)) {
                throw new RuntimeException('The two passwords do not match.');
            }

            slim_admin_create_user($newUser, $newPass);
            $message = 'Administrator account created.';
        }

        if ($action === 'delete_user') {
            $target = (string)($_POST['target_user'] ?? '');

            if ($target === $currentUser) {
                throw new RuntimeException('You cannot remove the account you are signed in with.');
            }

            slim_admin_delete_user($target);
            $message = 'Administrator account removed.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$users = slim_admin_users();
$token = h((string)$_SESSION['account_csrf']);
$minLength = SLIM_ADMIN_MIN_PASSWORD;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SLIM Administrator Accounts</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:900px;margin:25px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
h1,h2{margin-top:0}
label{display:block;margin:12px 0 5px;font-weight:600}
input[type=text],input[type=password]{width:100%;max-width:420px;padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
button{padding:9px 15px;border:0;border-radius:5px;cursor:pointer}
.primary{background:#222;color:#fff}
.danger{background:#b42318;color:#fff}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;border-radius:4px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;border-radius:4px;margin-bottom:15px}
.warn{background:#fff7dd;border:1px solid #e5cf86;padding:12px;border-radius:4px;margin-bottom:15px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:9px;border-bottom:1px solid #ddd;text-align:left}
.hint{color:#666;font-size:.92rem}
</style>
</head>
<body>
<?= slim_admin_session_bar() ?>
<div style="max-width:940px;margin:6px auto 0;padding:0 20px;">
  <a href="../" style="display:inline-block;text-decoration:none;font-weight:600;">&larr; Admin Home</a>
</div>
<div class="wrap">

<div class="card">
<h1>Administrator Accounts</h1>
<p class="hint">
SLIM authenticates administrators against bcrypt password hashes stored in the
configuration directory. Web-server access control such as cPanel Directory
Privacy may be layered on top of this but is no longer required for basic
protection.
</p>
<?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
<?php if (slim_config_inside_webroot()): ?>
<div class="warn">
Configuration (including administrator password hashes, database credentials,
station API keys, and backups) is stored inside the published web directory.
This relies on <code>config/.htaccess</code> being honoured. Moving the
configuration directory outside the document root is safer.
</div>
<?php endif; ?>
</div>

<div class="card">
<h2>Change My Password</h2>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $token ?>">
<input type="hidden" name="action" value="change_password">
<label>Current password</label>
<input type="password" name="current_password" required autocomplete="current-password">
<label>New password</label>
<input type="password" name="new_password" required minlength="<?= (int)$minLength ?>" autocomplete="new-password">
<label>Confirm new password</label>
<input type="password" name="confirm_password" required minlength="<?= (int)$minLength ?>" autocomplete="new-password">
<p class="hint">Minimum <?= (int)$minLength ?> characters.</p>
<p><button class="primary" type="submit">Update Password</button></p>
</form>
</div>

<div class="card">
<h2>Administrators</h2>
<table>
<tr><th>Username</th><th>Created (UTC)</th><th>Password last changed</th><th></th></tr>
<?php foreach ($users as $name => $meta): ?>
<tr>
  <td><strong><?= h((string)$name) ?></strong><?= $name === $currentUser ? ' <span class="hint">(you)</span>' : '' ?></td>
  <td><?= h((string)($meta['created_at'] ?? '—')) ?></td>
  <td><?= h((string)($meta['password_changed'] ?? '—')) ?></td>
  <td>
  <?php if ($name !== $currentUser && count($users) > 1): ?>
    <form method="post" style="margin:0"
          onsubmit="return confirm('Remove administrator <?= h((string)$name) ?>?');">
      <input type="hidden" name="csrf" value="<?= $token ?>">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="target_user" value="<?= h((string)$name) ?>">
      <button class="danger" type="submit">Remove</button>
    </form>
  <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Add Administrator</h2>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $token ?>">
<input type="hidden" name="action" value="add_user">
<label>Username</label>
<input type="text" name="new_username" required pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32">
<label>Password</label>
<input type="password" name="new_user_password" required minlength="<?= (int)$minLength ?>" autocomplete="new-password">
<label>Confirm password</label>
<input type="password" name="new_user_confirm" required minlength="<?= (int)$minLength ?>" autocomplete="new-password">
<p class="hint">Named accounts make configuration changes attributable to a person.</p>
<p><button class="primary" type="submit">Create Account</button></p>
</form>
</div>

</div>
<div class="site-footer slim-version-footer" style="text-align:center;color:#888;font-size:12px;padding-bottom:20px;">
<?= h(slim_footer_text()) ?>
</div>
</body>
</html>
