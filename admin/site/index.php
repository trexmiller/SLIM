<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/admin_session.php';

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(24));
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function slim_wysiwyg_editor(string $id,string $field,string $html): string {
    $idSafe=htmlspecialchars($id,ENT_QUOTES,'UTF-8');
    $fieldSafe=htmlspecialchars($field,ENT_QUOTES,'UTF-8');
    return '<div class="editor-toolbar" data-editor="'.$idSafe.'">'
        .'<button type="button" data-cmd="formatBlock" data-value="p">Paragraph</button>'
        .'<button type="button" data-cmd="formatBlock" data-value="h2">H2</button>'
        .'<button type="button" data-cmd="formatBlock" data-value="h3">H3</button>'
        .'<button type="button" data-cmd="bold"><strong>B</strong></button>'
        .'<button type="button" data-cmd="italic"><em>I</em></button>'
        .'<button type="button" data-cmd="insertUnorderedList">• List</button>'
        .'<button type="button" data-cmd="insertOrderedList">1. List</button>'
        .'<button type="button" data-action="link">Link</button>'
        .'<select class="image-size" aria-label="Image width" title="Choose image width"><option value="">Image size...</option><option value="third">1/3 page</option><option value="half">1/2 page</option><option value="full">Full page</option></select>'
        .'<button type="button" data-action="image">Insert image</button>'
        .'<button type="button" data-cmd="removeFormat">Clear format</button>'
        .'</div>'
        .'<div id="'.$idSafe.'" class="wysiwyg" contenteditable="true">'.$html.'</div>'
        .'<input type="hidden" name="'.$fieldSafe.'" id="'.$idSafe.'_input">'
        .'<input type="file" id="'.$idSafe.'_file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>';
}

$error='';
$message='';
$config=lakestat_site_config();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['site_csrf'] ?? '', $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Session validation failed. Reload the page and try again.');
        }

        $siteTitle=trim((string)($_POST['site_title'] ?? ''));
        if ($siteTitle === '') {
            throw new RuntimeException('Public site title is required.');
        }

        $websiteUrl=trim((string)($_POST['website_url'] ?? ''));
        if ($websiteUrl !== '' && filter_var($websiteUrl,FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Organization website must be a complete URL such as https://example.org.');
        }

        $contactEmail=trim((string)($_POST['contact_email'] ?? ''));
        $contactWebsite=trim((string)($_POST['contact_website'] ?? ''));
        if ($contactEmail !== '' && filter_var($contactEmail,FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Contact email address is not valid.');
        }
        if ($contactWebsite !== '' && filter_var($contactWebsite,FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Contact website must be a complete URL such as https://example.org.');
        }

        $save=[
            'site_title'=>$siteTitle,
            'intro_text'=>trim((string)($_POST['intro_text'] ?? '')),
            'website_url'=>$websiteUrl,
            'website_label'=>trim((string)($_POST['website_label'] ?? '')),
            'contact_email'=>$contactEmail,
            'show_about_page'=>isset($_POST['show_about_page']),
            'about_nav_label'=>trim((string)($_POST['about_nav_label'] ?? 'About')),
            'about_title'=>trim((string)($_POST['about_title'] ?? 'About')),
            'about_text'=>'',
            'about_html'=>slim_sanitize_content_html((string)($_POST['about_html'] ?? '')),
            'show_contact_page'=>isset($_POST['show_contact_page']),
            'contact_nav_label'=>trim((string)($_POST['contact_nav_label'] ?? 'Contact')),
            'contact_title'=>trim((string)($_POST['contact_title'] ?? 'Contact')),
            'contact_text'=>'',
            'contact_html'=>slim_sanitize_content_html((string)($_POST['contact_html'] ?? '')),
            'contact_organization'=>trim((string)($_POST['contact_organization'] ?? '')),
            'contact_person'=>trim((string)($_POST['contact_person'] ?? '')),
            'contact_phone'=>trim((string)($_POST['contact_phone'] ?? '')),
            'contact_address'=>trim((string)($_POST['contact_address'] ?? '')),
            'contact_website'=>$contactWebsite,
            'show_station_map'=>isset($_POST['show_station_map']),
            'show_station_list'=>isset($_POST['show_station_list']),
            'show_coordinates'=>isset($_POST['show_coordinates']),
            'map_zoom'=>max(1,min(19,(int)($_POST['map_zoom'] ?? 7))),
            'max_dashboard_hours'=>max(1,min(8760,(int)($_POST['max_dashboard_hours'] ?? 720))),
            'max_dashboard_days'=>max(1,min(3650,(int)($_POST['max_dashboard_days'] ?? 30))),
            'max_dashboard_rows'=>max(100,min(20000,(int)($_POST['max_dashboard_rows'] ?? 5000))),
            'rate_limit_enabled'=>isset($_POST['rate_limit_enabled']),
            'rate_limit_per_minute'=>max(5,min(600,(int)($_POST['rate_limit_per_minute'] ?? 60))),
            'export_max_days'=>max(1,min(3650,(int)($_POST['export_max_days'] ?? 180))),
            'export_max_rows_per_station'=>max(1000,min(1000000,(int)($_POST['export_max_rows_per_station'] ?? 100000))),
            'export_max_total_rows'=>max(1000,min(2000000,(int)($_POST['export_max_total_rows'] ?? 250000))),
            'export_max_stations'=>max(1,min(50,(int)($_POST['export_max_stations'] ?? 10))),
            'import_max_file_bytes'=>max(1048576,min(104857600,(int)($_POST['import_max_file_bytes'] ?? 52428800))),
            'import_max_rows'=>max(1000,min(1000000,(int)($_POST['import_max_rows'] ?? 100000))),
            'import_max_columns'=>max(5,min(200,(int)($_POST['import_max_columns'] ?? 60)))
        ];

        lakestat_write_php_config(
            lakestat_config_path('site.php'),
            $save
        );

        $config=lakestat_site_config();
        $message='Public site settings saved.';
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
<title>SLIM Site Configuration</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f7;margin:0;color:#222}
.wrap{max-width:1000px;margin:35px auto;padding:0 20px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px}
label{display:block;margin:10px 0 5px;font-weight:600}
input[type=text],input[type=email],input[type=url],input[type=number],textarea{
    width:100%;max-width:700px;padding:8px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box
}
textarea{min-height:180px;resize:vertical}
button{padding:9px 15px;border:0;border-radius:5px;background:#222;color:#fff;cursor:pointer}
.error{background:#fdeaea;border:1px solid #e4aaaa;padding:12px;margin-bottom:15px}
.ok{background:#eaf7ea;border:1px solid #a8d5a8;padding:12px;margin-bottom:15px}
.hint{color:#666;font-size:.92rem}
.inline{display:flex;gap:16px;flex-wrap:wrap}.inline>div{flex:1;min-width:220px}
.editor-toolbar{display:flex;gap:5px;flex-wrap:wrap;padding:8px;background:#f1f3f5;border:1px solid #bbb;border-bottom:0;border-radius:5px 5px 0 0}.editor-toolbar button{background:#fff;color:#222;border:1px solid #bbb;padding:6px 9px}.wysiwyg{min-height:260px;max-width:700px;padding:12px;border:1px solid #bbb;border-radius:0 0 5px 5px;background:#fff;line-height:1.6;overflow:auto}.wysiwyg img{height:auto;box-sizing:border-box;vertical-align:top;margin:.5%}.wysiwyg img.slim-img-third{width:32.3%}.wysiwyg img.slim-img-half{width:49%}.wysiwyg img.slim-img-full,.wysiwyg img:not([class*="slim-img-"]){width:99%}.editor-toolbar .image-size{width:auto;display:inline-block;padding:7px 8px;margin:0 4px}.wysiwyg:focus{outline:2px solid #8ab4f8;outline-offset:-2px}
</style>
</head>
<body>
<div class="wrap">

<?= slim_admin_session_bar() ?>
<div style="margin-bottom:12px;">
<a href="../">&larr; Admin Home</a>
</div>

<div class="card">
<h1>Public Site Configuration</h1>

<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>

<form method="post" id="site-config-form">
<input type="hidden" name="csrf" value="<?= h($_SESSION['site_csrf']) ?>">

<label>Public site title <?= slim_help_button('Public site title','Title shown on the public SLIM home page above the monitoring-network information.') ?></label>
<input type="text" name="site_title" value="<?= h((string)$config['site_title']) ?>" required>

<label>Introduction / site description <?= slim_help_button('Introduction / site description','Optional plain-text introduction shown on the public home page. Describe the network, purpose, participating organizations, or important context for the data.') ?></label>
<textarea name="intro_text" placeholder="Describe the monitoring program, purpose of the site, participating organizations, or how the data should be interpreted."><?= h((string)$config['intro_text']) ?></textarea>
<p class="hint">Plain text is used. Line breaks are preserved on the public page.</p>

<h2>Optional Public Pages</h2>
<p class="hint">Enable either page to show it as a public navigation link.</p>

<fieldset style="border:1px solid #ddd;border-radius:6px;padding:18px;margin:18px 0;">
<legend><strong>About page</strong></legend>
<label><input type="checkbox" name="show_about_page" <?= !empty($config['show_about_page'])?'checked':'' ?>> Show About page <?= slim_help_button('Show About page','Adds an optional About page and navigation link to the public site.') ?></label>
<div class="inline">
<div><label>Navigation label <?= slim_help_button('Navigation label','Text used for this optional page in public navigation.') ?></label><input type="text" name="about_nav_label" value="<?= h((string)($config['about_nav_label'] ?? 'About')) ?>"></div>
<div><label>Page title <?= slim_help_button('Page title','Heading displayed at the top of this optional public page.') ?></label><input type="text" name="about_title" value="<?= h((string)($config['about_title'] ?? 'About')) ?>"></div>
</div>
<label>About page content <?= slim_help_button('About page content','Use the constrained visual editor to format the About page. Images are uploaded to SLIM and inserted into the page. Only safe formatting, links, and images are retained when saved.') ?></label>
<?= slim_wysiwyg_editor('about_editor','about_html',(string)($config['about_html'] ?: nl2br(h((string)($config['about_text'] ?? ''))))) ?>
<div class="inline">
<div><label>Organization website (optional) <?= slim_help_button('Organization website','Optional complete URL for an organization or project website linked from the About page.') ?></label><input type="url" name="website_url" value="<?= h((string)$config['website_url']) ?>"></div>
<div><label>Website link label <?= slim_help_button('Website link label','Visible text used for the organization website link.') ?></label><input type="text" name="website_label" value="<?= h((string)$config['website_label']) ?>"></div>
</div>
</fieldset>

<fieldset style="border:1px solid #ddd;border-radius:6px;padding:18px;margin:18px 0;">
<legend><strong>Contact page</strong></legend>
<label><input type="checkbox" name="show_contact_page" <?= !empty($config['show_contact_page'])?'checked':'' ?>> Show Contact page <?= slim_help_button('Show Contact page','Adds an optional Contact page and navigation link to the public site.') ?></label>
<div class="inline">
<div><label>Navigation label <?= slim_help_button('Navigation label','Text used for this optional page in public navigation.') ?></label><input type="text" name="contact_nav_label" value="<?= h((string)($config['contact_nav_label'] ?? 'Contact')) ?>"></div>
<div><label>Page title <?= slim_help_button('Page title','Heading displayed at the top of this optional public page.') ?></label><input type="text" name="contact_title" value="<?= h((string)($config['contact_title'] ?? 'Contact')) ?>"></div>
</div>
<label>Contact page introduction <?= slim_help_button('Contact page introduction','Optional formatted introduction or contact instructions. Use the structured fields below for standard contact details.') ?></label>
<?= slim_wysiwyg_editor('contact_editor','contact_html',(string)($config['contact_html'] ?: nl2br(h((string)($config['contact_text'] ?? ''))))) ?>

<h3>Structured Contact Information</h3>
<div class="inline">
<div><label>Organization</label><input type="text" name="contact_organization" value="<?= h((string)($config['contact_organization'] ?? '')) ?>"></div>
<div><label>Contact person</label><input type="text" name="contact_person" value="<?= h((string)($config['contact_person'] ?? '')) ?>"></div>
</div>
<div class="inline">
<div><label>Public contact email</label><input type="email" name="contact_email" value="<?= h((string)$config['contact_email']) ?>"></div>
<div><label>Phone</label><input type="text" name="contact_phone" value="<?= h((string)($config['contact_phone'] ?? '')) ?>"></div>
</div>
<label>Mailing / street address</label>
<textarea name="contact_address" style="min-height:90px"><?= h((string)($config['contact_address'] ?? '')) ?></textarea>
<label>Contact or organization website</label>
<input type="url" name="contact_website" value="<?= h((string)($config['contact_website'] ?? '')) ?>" placeholder="https://example.org">
</fieldset>" placeholder="monitoring@example.org">

<h2>Station Overview</h2>

<label>
<input type="checkbox" name="show_station_map" <?= !empty($config['show_station_map'])?'checked':'' ?>>
Show all-stations map
 <?= slim_help_button('Show all-stations map','Displays a public map of configured stations using their saved latitude and longitude.') ?></label>

<label>
<input type="checkbox" name="show_station_list" <?= !empty($config['show_station_list'])?'checked':'' ?>>
Show station cards/list
 <?= slim_help_button('Show station cards/list','Displays public station cards that viewers can use to open individual station dashboards.') ?></label>

<label>
<input type="checkbox" name="show_coordinates" <?= !empty($config['show_coordinates'])?'checked':'' ?>>
Show coordinates on station cards
 <?= slim_help_button('Show coordinates','Displays saved latitude and longitude on public station cards.') ?></label>

<label>Initial map zoom <?= slim_help_button('Initial map zoom','Starting zoom level for the all-stations map. Larger values show a smaller geographic area in greater detail.') ?></label>
<input type="number" name="map_zoom" value="<?= (int)$config['map_zoom'] ?>" min="1" max="19">
<p class="hint">If there are multiple stations, SLIM automatically fits the map to all station markers.</p>


<h2>Public Dashboard Protection</h2>
<p class="hint">
These limits protect a shared-hosting account from unusually large public
queries or excessive repeated requests. They do not apply to ingestion,
the password-protected Admin area, or the CLI webhook dispatcher.
</p>

<div class="inline">
<div>
<label>Maximum dashboard time window (hours) <?= slim_help_button('Maximum dashboard time window','Server-side safety cap on public time-series history requests.') ?></label>
<input
    type="number"
    name="max_dashboard_hours"
    value="<?= h((string)($config['max_dashboard_hours'] ?? 720)) ?>"
    min="1"
    max="8760"
>
<p class="hint">Default 720 hours (30 days); hard maximum 8760 hours.</p>
</div>

<div>
<label>Maximum public date range (days) <?= slim_help_button('Maximum public date range','Maximum calendar range a visitor may request on a public dashboard. Longer permitted ranges are automatically downsampled when they exceed the public plotting-point limit.') ?></label>
<input
    type="number"
    name="max_dashboard_days"
    value="<?= h((string)($config['max_dashboard_days'] ?? 30)) ?>"
    min="1"
    max="3650"
>
<p class="hint">Default 30 days; administrator configurable up to 3650 days.</p>
</div>

<div>
<label>Maximum points per public time-series plot <?= slim_help_button('Maximum points per public time-series plot','If a public time-series range contains more observations than this limit, SLIM systematically selects raw observations across the complete interval. Values are not averaged or aggregated, and stored/exported data are unchanged.') ?></label>
<input
    type="number"
    name="max_dashboard_rows"
    value="<?= h((string)($config['max_dashboard_rows'] ?? 5000)) ?>"
    min="100"
    max="20000"
>
<p class="hint">Default 5000 points; hard maximum 20,000. Larger datasets are systematically subsampled across the complete requested interval.</p>
</div>
</div>

<label>
<input
    type="checkbox"
    name="rate_limit_enabled"
    <?= !array_key_exists('rate_limit_enabled',$config) || !empty($config['rate_limit_enabled']) ? 'checked' : '' ?>
>
Enable public request rate limiting
 <?= slim_help_button('Public request rate limiting','Limits repeated public requests from one IP address to reduce accidental or abusive load on the web server.') ?></label>

<label>Maximum public requests per IP per minute <?= slim_help_button('Maximum public request rate','Maximum number of rate-limited public requests allowed from one IP address during a one-minute window.') ?></label>
<input
    type="number"
    name="rate_limit_per_minute"
    value="<?= h((string)($config['rate_limit_per_minute'] ?? 60)) ?>"
    min="5"
    max="600"
>
<p class="hint">
Default 60 requests/minute. Requests above the limit receive HTTP 429 with
Retry-After: 60.
</p>


<h2>Admin Data Export Limits</h2>
<p class="hint">
These limits apply only to the protected Admin data-export tool. They are
enforced server-side to reduce the chance of a very large export overwhelming
shared hosting.
</p>

<div class="inline">
<div>
<label>Maximum export date range (days) <?= slim_help_button('Maximum export date range','Longest date range an administrator may request in one manual export.') ?></label>
<input
    type="number"
    name="export_max_days"
    value="<?= h((string)($config['export_max_days'] ?? 180)) ?>"
    min="1"
    max="3650"
>
</div>

<div>
<label>Maximum rows per station <?= slim_help_button('Maximum export rows per station','Maximum number of records that may be exported from one station in a single export.') ?></label>
<input
    type="number"
    name="export_max_rows_per_station"
    value="<?= h((string)($config['export_max_rows_per_station'] ?? 100000)) ?>"
    min="1000"
    max="1000000"
>
</div>
</div>

<div class="inline">
<div>
<label>Maximum total rows per export <?= slim_help_button('Maximum total export rows','Maximum combined number of records allowed across all selected stations in one export.') ?></label>
<input
    type="number"
    name="export_max_total_rows"
    value="<?= h((string)($config['export_max_total_rows'] ?? 250000)) ?>"
    min="1000"
    max="2000000"
>
</div>

<div>
<label>Maximum stations per export <?= slim_help_button('Maximum stations per export','Maximum number of stations that can be included in one multi-station export or backup.') ?></label>
<input
    type="number"
    name="export_max_stations"
    value="<?= h((string)($config['export_max_stations'] ?? 10)) ?>"
    min="1"
    max="50"
>
</div>
</div>

<p class="hint">
Recommended defaults: 180 days, 100,000 rows/station, 250,000 rows total,
10 stations/export.
</p>


<h2>Admin CSV Import Limits</h2>
<p class="hint">
These limits protect shared hosting during manual CSV imports. They apply only
to the password-protected Admin import tool.
</p>

<div class="inline">
<div>
<label>Maximum CSV file size (bytes) <?= slim_help_button('Maximum CSV import size','SLIM-side import limit. PHP upload_max_filesize and post_max_size must also be large enough.') ?></label>
<input
    type="number"
    name="import_max_file_bytes"
    value="<?= h((string)($config['import_max_file_bytes'] ?? 52428800)) ?>"
    min="1048576"
    max="104857600"
>
<p class="hint">Default 50 MB.</p>
</div>

<div>
<label>Maximum data rows per import <?= slim_help_button('Maximum import rows','Maximum number of CSV rows allowed in one manual import.') ?></label>
<input
    type="number"
    name="import_max_rows"
    value="<?= h((string)($config['import_max_rows'] ?? 100000)) ?>"
    min="1000"
    max="1000000"
>
</div>

<div>
<label>Maximum CSV columns <?= slim_help_button('Maximum CSV columns','Maximum number of columns accepted in one manual CSV import.') ?></label>
<input
    type="number"
    name="import_max_columns"
    value="<?= h((string)($config['import_max_columns'] ?? 60)) ?>"
    min="5"
    max="200"
>
</div>
</div>

<p style="margin-top:22px;">
<button type="submit">Save Public Site Settings</button>
</p>
</form>
</div>

<div class="card">
<h2>Preview</h2>
<p><a href="../../" target="_blank">Open public home page</a></p>
</div>

</div>
<script>
(function(){
 const csrf=<?= json_encode($_SESSION['site_csrf']) ?>;
 document.querySelectorAll('.editor-toolbar').forEach(toolbar=>{
   const id=toolbar.dataset.editor, editor=document.getElementById(id), file=document.getElementById(id+'_file');
   toolbar.addEventListener('mousedown',e=>{ if(!e.target.closest('select')) e.preventDefault(); });
   toolbar.addEventListener('click',async e=>{
     const btn=e.target.closest('button'); if(!btn) return;
     editor.focus();
     if(btn.dataset.cmd){ document.execCommand(btn.dataset.cmd,false,btn.dataset.value||null); return; }
     if(btn.dataset.action==='link'){
       const url=prompt('Enter a complete web address (https://...) or email link (mailto:...)');
       if(url) document.execCommand('createLink',false,url);
     }
     if(btn.dataset.action==='image'){
       const size=toolbar.querySelector('.image-size')?.value || '';
       if(!size){ alert('Choose an image size (1/3 page, 1/2 page, or Full page) before inserting an image.'); return; }
       file.dataset.imageSize=size; file.click();
     }
   });
   file.addEventListener('change',async()=>{
     if(!file.files[0]) return;
     const fd=new FormData(); fd.append('csrf',csrf); fd.append('image',file.files[0]);
     try{
       const res=await fetch('upload_image.php',{method:'POST',body:fd,credentials:'same-origin'});
       const json=await res.json();
       if(!res.ok || !json.location) throw new Error(json.error||'Upload failed.');
       editor.focus(); document.execCommand('insertImage',false,json.location);
       const images=editor.querySelectorAll('img'), inserted=images.length ? images[images.length-1] : null;
       if(inserted) inserted.className='slim-img-'+(file.dataset.imageSize || 'full');
     }catch(err){ alert(err.message); }
     file.value='';
   });
 });
 document.getElementById('site-config-form').addEventListener('submit',()=>{
   document.querySelectorAll('.wysiwyg').forEach(editor=>{
     document.getElementById(editor.id+'_input').value=editor.innerHTML;
   });
 });
})();
</script>

<?= slim_admin_help_assets() ?>
</body>
</html>
