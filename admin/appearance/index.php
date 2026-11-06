<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

if (empty($_SESSION['appearance_csrf'])) {
    $_SESSION['appearance_csrf'] = bin2hex(random_bytes(24));
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function valid_hex_color(string $value): string {
    $value = trim($value);

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
        throw new RuntimeException('Custom colors must use six-digit hex format, for example #0a1628.');
    }

    return strtolower($value);
}

function save_brand_logo(array $file): string {
    if (!isset($file['error'])) {
        throw new RuntimeException('No logo file reached PHP.');
    }

    if (is_array($file['error'])) {
        throw new RuntimeException('Unexpected multiple-file upload data.');
    }

    $error = (int)$file['error'];

    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed with PHP error code ' . $error . '.');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Logo must be 5 MB or smaller.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Logo did not pass PHP upload validation.');
    }

    $info = @getimagesize($file['tmp_name']);

    if ($info === false || empty($info['mime'])) {
        throw new RuntimeException('Uploaded logo is not a valid image.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$info['mime']])) {
        throw new RuntimeException('Logo must be JPEG, PNG, or WebP.');
    }

    $root = dirname(__DIR__, 2);
    $relativeDir = 'assets/branding';
    $absoluteDir = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'branding';

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create branding directory.');
    }

    $filename = 'logo_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$info['mime']];
    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to save uploaded logo.');
    }

    @chmod($absolutePath, 0644);

    return $relativeDir . '/' . $filename;
}

$error='';
$message='';
$config=lakestat_appearance_config();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['appearance_csrf'] ?? '', $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Session validation failed. Reload the page and try again.');
        }

        $theme=(string)($_POST['theme'] ?? 'lakestat_dark');
        $allowedThemes=['lakestat_dark','lakestat_light','freshwater','neutral','custom'];

        if (!in_array($theme,$allowedThemes,true)) {
            $theme='lakestat_dark';
        }

        $logoMode=(string)($_POST['logo_mode'] ?? 'title_only');
        $allowedModes=['title_only','logo_only','logo_and_name'];

        if (!in_array($logoMode,$allowedModes,true)) {
            $logoMode='title_only';
        }

        $newLogo = save_brand_logo($_FILES['logo_file'] ?? []);

        $customColors = [
            'background' => valid_hex_color((string)($_POST['background'] ?? '#0a1628')),
            'background_mid' => valid_hex_color((string)($_POST['background_mid'] ?? '#112240')),
            'card' => valid_hex_color((string)($_POST['card'] ?? '#162a47')),
            'card_hover' => valid_hex_color((string)($_POST['card_hover'] ?? '#1e3a5f')),
            'accent' => valid_hex_color((string)($_POST['accent'] ?? '#00c2ff')),
            'teal' => valid_hex_color((string)($_POST['teal'] ?? '#0ddfb8')),
            'text_primary' => valid_hex_color((string)($_POST['text_primary'] ?? '#e8f0fe')),
            'text_secondary' => valid_hex_color((string)($_POST['text_secondary'] ?? '#7a9bbf')),
            'text_muted' => valid_hex_color((string)($_POST['text_muted'] ?? '#4a6580'))
        ];

        $save = [
            'theme' => $theme,
            'logo_path' => $newLogo !== '' ? $newLogo : (string)($config['logo_path'] ?? ''),
            'logo_mode' => $logoMode,
            'logo_max_height' => max(24,min(120,(int)($_POST['logo_max_height'] ?? 52))),
            'custom_colors' => $customColors
        ];

        if (isset($_POST['remove_logo'])) {
            $save['logo_path']='';
        }

        lakestat_write_php_config(
            lakestat_config_path('appearance.php'),
            $save
        );

        $config=lakestat_appearance_config();
        $message='Appearance settings saved.';
    }

} catch(Throwable $e) {
    $error=$e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Appearance & Branding</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1050px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
label{display:block;margin:10px 0 5px;font-weight:600}
input[type=text],input[type=number],select{width:100%;max-width:520px;padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
input[type=color]{width:58px;height:38px;padding:2px;border:1px solid #bbb;border-radius:4px;background:#fff}
.inline{display:flex;gap:16px;flex-wrap:wrap}.inline>div{flex:1;min-width:220px}
button{padding:9px 15px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
.preview-logo{display:block;max-width:340px;max-height:90px;object-fit:contain;margin-top:10px;border:1px solid #ddd;padding:8px;background:#fafafa}
.color-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.color-row{display:flex;align-items:center;gap:10px}
</style>
</head>
<body>
<div class="wrap">

<?= slim_admin_session_bar() ?>
<div style="margin-bottom:12px;">
<a href="../">&larr; Admin Home</a>
</div>

<div class="card">
<h1>Appearance & Branding</h1>

<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['appearance_csrf']) ?>">

<label>Theme</label>
<select name="theme">
<option value="lakestat_dark" <?= $config['theme']==='lakestat_dark'?'selected':'' ?>>Dark</option>
<option value="lakestat_light" <?= $config['theme']==='lakestat_light'?'selected':'' ?>>Light</option>
<option value="freshwater" <?= $config['theme']==='freshwater'?'selected':'' ?>>Freshwater</option>
<option value="neutral" <?= $config['theme']==='neutral'?'selected':'' ?>>Neutral</option>
<option value="custom" <?= $config['theme']==='custom'?'selected':'' ?>>Custom</option>
</select>

<label>Header branding</label>
<p class="hint">If you choose Logo + organization name, The page uses the Organization set under Station Management → System defaults.</p>
<select name="logo_mode">
<option value="title_only" <?= $config['logo_mode']==='title_only'?'selected':'' ?>>Dashboard title only</option>
<option value="logo_only" <?= $config['logo_mode']==='logo_only'?'selected':'' ?>>Logo only</option>
<option value="logo_and_name" <?= $config['logo_mode']==='logo_and_name'?'selected':'' ?>>Logo + organization name</option>
</select>

<div class="inline">
<div>
<label>Upload logo</label>
<input type="file" name="logo_file" accept="image/jpeg,image/png,image/webp">
<p class="hint">JPEG, PNG, or WebP; maximum 5 MB.</p>
</div>
<div>
<label>Maximum logo height (px)</label>
<input type="number" name="logo_max_height" value="<?= (int)$config['logo_max_height'] ?>" min="24" max="120">
</div>
</div>

<?php if(!empty($config['logo_path'])): ?>
<p><strong>Current logo</strong></p>
<img class="preview-logo" src="<?= h(slim_public_asset_url((string)$config['logo_path'])) ?>" alt="Current organization logo">
<label><input type="checkbox" name="remove_logo"> Remove current logo</label>
<?php endif; ?>

<h2>Custom theme colors</h2>
<p class="hint">These are used only when Theme is set to Custom.</p>

<div class="color-grid">
<?php
$labels=[
    'background'=>'Page background',
    'background_mid'=>'Secondary background',
    'card'=>'Card background',
    'card_hover'=>'Card hover',
    'accent'=>'Accent',
    'teal'=>'Status / teal accent',
    'text_primary'=>'Primary text',
    'text_secondary'=>'Secondary text',
    'text_muted'=>'Muted text'
];
foreach($labels as $key=>$label):
?>
<div class="color-row">
<input type="color" name="<?= h($key) ?>" value="<?= h($config['custom_colors'][$key]) ?>">
<span><?= h($label) ?></span>
</div>
<?php endforeach; ?>
</div>

<p style="margin-top:22px;"><button type="submit">Save Appearance</button></p>
</form>
</div>
</div>
</body>
</html>
