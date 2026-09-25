<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
slim_rate_limit('about');
function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
$site=lakestat_site_config();
$appearance=lakestat_appearance_config();
$themeColors=slim_theme_colors($appearance);
if(empty($site['show_about_page'])){http_response_code(404);echo "Page not available.";exit;}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h((string)$site['about_title']) ?></title>
<style>
:root{
 --bg:<?= h($themeColors['background']) ?>;
 --bg-mid:<?= h($themeColors['background_mid']) ?>;
 --card:<?= h($themeColors['card']) ?>;
 --accent:<?= h($themeColors['accent']) ?>;
 --text:<?= h($themeColors['text_primary']) ?>;
 --text-secondary:<?= h($themeColors['text_secondary']) ?>;
 --text-muted:<?= h($themeColors['text_muted']) ?>;
}
</style>
<link rel="stylesheet" href="../assets/css/public-content.css"></head><body><div class="wrap">
<?php
$logoMode=(string)($appearance['logo_mode'] ?? 'title_only');
$logoPath=(string)($appearance['logo_path'] ?? '');
$logoHeight=max(24,min(120,(int)($appearance['logo_max_height'] ?? 52)));
$orgName=trim((string)($appearance['organization_name'] ?? ($site['organization_name'] ?? '')));
?>
<?php if($logoPath!=='' && ($logoMode==='logo_only' || $logoMode==='logo_and_name')): ?>
<div class="brand">
<img class="brand-logo" src="<?= h(slim_public_asset_url($logoPath)) ?>" alt="<?= h($orgName!==''?$orgName:'Organization logo') ?>" style="max-height:<?= (int)$logoHeight ?>px">
<?php if($logoMode==='logo_and_name' && $orgName!==''): ?><span class="brand-name"><?= h($orgName) ?></span><?php endif; ?>
</div>
<?php endif; ?>
<nav><a href="../">Home</a><?php if(!empty($site['show_about_page'])): ?> <a href="../about/"><?= h($site['about_nav_label'] ?: 'About') ?></a><?php endif; ?><?php if(!empty($site['show_contact_page'])): ?> <a href="../contact/"><?= h($site['contact_nav_label'] ?: 'Contact') ?></a><?php endif; ?></nav>
<div class="card"><h1><?= h((string)$site['about_title']) ?></h1><div class="text rich"><?= $site['about_html']!=='' ? slim_sanitize_content_html((string)$site['about_html']) : nl2br(h((string)$site['about_text'])) ?></div><?php if($site['website_url']!==''): ?><p><a href="<?= h($site['website_url']) ?>" target="_blank" rel="noopener"><?= h($site['website_label'] ?: 'Organization website') ?></a></p><?php endif; ?></div>
<p class="slim-version-footer"><?= h(slim_footer_text()) ?></p>
</div>

<div class="slim-public-admin-link">
    <a href="../admin/">admin</a>
</div>

</body></html>