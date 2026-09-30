<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

slim_rate_limit('home');

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function home_theme_colors(array $appearance): array {
    $themes = [
        'lakestat_dark'=>[
            'background'=>'#0a1628',
            'background_mid'=>'#112240',
            'card'=>'#162a47',
            'card_hover'=>'#1e3a5f',
            'accent'=>'#00c2ff',
            'teal'=>'#0ddfb8',
            'text_primary'=>'#e8f0fe',
            'text_secondary'=>'#7a9bbf',
            'text_muted'=>'#4a6580'
        ],
        'lakestat_light'=>[
            'background'=>'#eef3f7',
            'background_mid'=>'#dde7ef',
            'card'=>'#ffffff',
            'card_hover'=>'#f7fbfe',
            'accent'=>'#006fa8',
            'teal'=>'#008f7a',
            'text_primary'=>'#17324a',
            'text_secondary'=>'#48677f',
            'text_muted'=>'#7890a3'
        ],
        'freshwater'=>[
            'background'=>'#062b36',
            'background_mid'=>'#0b3e4d',
            'card'=>'#105468',
            'card_hover'=>'#14657b',
            'accent'=>'#45d7e8',
            'teal'=>'#6be6bc',
            'text_primary'=>'#eefcff',
            'text_secondary'=>'#9dd4dc',
            'text_muted'=>'#6ea8b2'
        ],
        'neutral'=>[
            'background'=>'#202326',
            'background_mid'=>'#2a2e32',
            'card'=>'#34393e',
            'card_hover'=>'#3d4349',
            'accent'=>'#d4d8dc',
            'teal'=>'#9fd3c7',
            'text_primary'=>'#f4f5f6',
            'text_secondary'=>'#b7bec5',
            'text_muted'=>'#858d95'
        ]
    ];

    $theme=(string)($appearance['theme'] ?? 'lakestat_dark');

    if($theme==='custom') {
        return $appearance['custom_colors'];
    }

    return isset($themes[$theme]) ? $themes[$theme] : $themes['lakestat_dark'];
}

$appearance=lakestat_appearance_config();
$system=slim_system_config();
$site=lakestat_site_config();
$theme=home_theme_colors($appearance);

$stations=lakestat_list_stations();
$stationDetails=[];

foreach($stations as $code=>$summary) {
    try {
        $config=lakestat_station_config($code);

        // Publishing controls public discovery, not ingestion or administrator
        // preview. Stations remain unpublished until explicitly enabled.
        if(empty($config['published'])) {
            continue;
        }

        $stationDetails[]=[
            'code'=>$code,
            'name'=>$config['name'] ?? $summary['name'],
            'latitude'=>isset($config['latitude']) ? (float)$config['latitude'] : null,
            'longitude'=>isset($config['longitude']) ? (float)$config['longitude'] : null
        ];
    } catch(Throwable $e) {
        continue;
    }
}

$mappedStations=array_values(array_filter($stationDetails,function($station){
    return $station['latitude'] !== null && $station['longitude'] !== null;
}));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h((string)$site['site_title']) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

<?php if(!empty($site['show_station_map']) && $mappedStations): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<style>
:root{
    --bg:<?= h($theme['background']) ?>;
    --bg-mid:<?= h($theme['background_mid']) ?>;
    --card:<?= h($theme['card']) ?>;
    --card-hover:<?= h($theme['card_hover']) ?>;
    --accent:<?= h($theme['accent']) ?>;
    --teal:<?= h($theme['teal']) ?>;
    --text:<?= h($theme['text_primary']) ?>;
    --text-sec:<?= h($theme['text_secondary']) ?>;
    --text-muted:<?= h($theme['text_muted']) ?>;
    --border:rgba(0,194,255,.20);
}

*{box-sizing:border-box}

body{
    margin:0;
    min-height:100vh;
    background:var(--bg);
    color:var(--text);
    font-family:'Space Grotesk',system-ui,sans-serif;
}

.wrap{
    max-width:1380px;
    margin:0 auto;
    padding:30px 28px 60px;
}

header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:24px;
    flex-wrap:wrap;
    padding-bottom:24px;
    margin-bottom:30px;
    border-bottom:1px solid rgba(255,255,255,.08);
}

.brand{
    display:flex;
    gap:16px;
    align-items:center;
    flex-wrap:wrap;
}

.brand-logo{
    display:block;
    width:auto;
    object-fit:contain;
}

.org-name{
    font-size:20px;
    font-weight:600;
}

.site-title{
    margin:0;
    font-size:31px;
    font-weight:600;
    letter-spacing:-.4px;
}

.intro{
    max-width:980px;
    margin-bottom:28px;
    padding:22px 24px;
    border:1px solid rgba(255,255,255,.07);
    border-radius:14px;
    background:var(--card);
    color:var(--text-sec);
    font-size:16px;
    white-space:pre-line;
}

.links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}

.links a{
    color:var(--accent);
    text-decoration:none;
    border:1px solid var(--border);
    border-radius:20px;
    padding:7px 13px;
    font-size:13px;
}

.section-title{
    display:flex;
    align-items:center;
    gap:12px;
    margin:30px 0 16px;
    font-size:19px;
    font-weight:600;
}

.section-title::after{
    content:'';
    height:1px;
    flex:1;
    background:rgba(255,255,255,.08);
}

.map-card{
    padding:12px;
    background:var(--card);
    border:1px solid rgba(255,255,255,.07);
    border-radius:16px;
}

#stationMap{
    width:100%;
    height:500px;
    border-radius:10px;
    overflow:hidden;
}

.station-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:14px;
}

.station-card{
    display:block;
    padding:18px;
    background:var(--card);
    border:1px solid rgba(255,255,255,.07);
    border-radius:14px;
    text-decoration:none;
    color:var(--text);
    transition:.18s ease;
}

.station-card:hover{
    background:var(--card-hover);
    border-color:var(--border);
    transform:translateY(-1px);
}

.station-name{
    font-size:17px;
    font-weight:600;
}

.station-code{
    margin-top:3px;
    color:var(--accent);
    font-family:'IBM Plex Mono',monospace;
    font-size:12px;
}

.station-coords{
    margin-top:10px;
    color:var(--text-sec);
    font-family:'IBM Plex Mono',monospace;
    font-size:12px;
}

.station-link{
    margin-top:13px;
    color:var(--accent);
    font-size:13px;
}

.empty{
    padding:20px;
    color:var(--text-sec);
    background:var(--card);
    border-radius:14px;
}

footer{
    margin-top:38px;
    padding-top:18px;
    border-top:1px solid rgba(255,255,255,.08);
    color:var(--text-muted);
    font-size:12px;
}

@media(max-width:700px){
    .wrap{padding:20px 14px 45px}
    #stationMap{height:380px}
    .site-title{font-size:26px}
}

.slim-version-footer{font-size:12px}

/* v0.22.7: larger public-facing typography. */
body{font-size:18px}
.org-name{font-size:24px}
.site-title{font-size:37px}
.intro{font-size:19px}
.links a{font-size:16px}
.section-title{font-size:23px}
.station-name{font-size:20px}
.station-code,.station-coords{font-size:14px}
.station-link{font-size:16px}
footer,.slim-version-footer{font-size:14px}
@media(max-width:700px){
    body{font-size:17px}
    .site-title{font-size:31px}
}

.skip-link{position:fixed;left:12px;top:8px;z-index:100000;padding:10px 14px;border-radius:6px;background:#fff;color:#111;font-weight:700;text-decoration:none;clip-path:inset(50%);width:1px;height:1px;overflow:hidden;white-space:nowrap}
.skip-link:focus,.skip-link:focus-visible{clip-path:none;width:auto;height:auto;overflow:visible;white-space:normal;outline:3px solid currentColor;outline-offset:3px}
</style>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>

<div class="wrap">

<header>
    <div>
        <div class="brand">
        <?php
        $logoMode=(string)($appearance['logo_mode'] ?? 'title_only');
        $logoPath=(string)($appearance['logo_path'] ?? '');
        $orgName=(string)($system['organization'] ?? '');
        $logoHeight=max(24,min(120,(int)($appearance['logo_max_height'] ?? 52)));
        ?>

        <?php if($logoPath!=='' && ($logoMode==='logo_only' || $logoMode==='logo_and_name')): ?>
        <img
            class="brand-logo"
            src="<?= h(slim_public_asset_url($logoPath)) ?>"
            alt="<?= h($orgName !== '' ? $orgName : 'Organization logo') ?>"
            style="max-height:<?= (int)$logoHeight ?>px"
        >
        <?php endif; ?>

        <?php if($logoMode==='logo_and_name' && $orgName!==''): ?>
        <div class="org-name"><?= h($orgName) ?></div>
        <?php endif; ?>
        </div>

        <h1 class="site-title"><?= h((string)$site['site_title']) ?></h1>
    </div>
<nav class="links">
<a href="./">Home</a>
<?php if(!empty($site['show_about_page'])): ?><a href="about/"><?= h($site['about_nav_label'] ?: 'About') ?></a><?php endif; ?>
<?php if(!empty($site['show_contact_page'])): ?><a href="contact/"><?= h($site['contact_nav_label'] ?: 'Contact') ?></a><?php endif; ?>
</nav>
</header>

<main id="main-content" tabindex="-1">
<?php if(trim((string)$site['intro_text'])!==''): ?>
<div class="intro">
<?= h((string)$site['intro_text']) ?>
</div>
<?php endif; ?>

<?php if(!empty($site['show_station_map'])): ?>
<div class="section-title">Monitoring Locations</div>

<?php if($mappedStations): ?>
<div class="map-card">
<div id="stationMap"></div>
</div>
<?php else: ?>
<div class="empty">No published stations with latitude/longitude coordinates are currently available.</div>
<?php endif; ?>
<?php endif; ?>

<?php if(!empty($site['show_station_list'])): ?>
<div class="section-title">Stations</div>

<?php if($stationDetails): ?>
<div class="station-grid">
<?php foreach($stationDetails as $station): ?>
<a class="station-card" href="dashboard/?station=<?= urlencode($station['code']) ?>">
    <div class="station-name"><?= h((string)$station['name']) ?></div>
    <div class="station-code"><?= h((string)$station['code']) ?></div>

    <?php if(!empty($site['show_coordinates']) && $station['latitude']!==null && $station['longitude']!==null): ?>
    <div class="station-coords">
        <?= h(number_format($station['latitude'],4)) ?>,
        <?= h(number_format($station['longitude'],4)) ?>
    </div>
    <?php endif; ?>

    <div class="station-link">View dashboard →</div>
</a>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty">No stations are currently published.</div>
<?php endif; ?>
<?php endif; ?>

</main>

<footer class="slim-version-footer"><?= h(slim_footer_text()) ?></footer>

</div>

<?php if(!empty($site['show_station_map']) && $mappedStations): ?>
<script>
const stationData=<?= json_encode($mappedStations,JSON_UNESCAPED_SLASHES) ?>;

const map=L.map('stationMap',{
    scrollWheelZoom:false
});

L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    {
        maxZoom:19,
        attribution:'&copy; OpenStreetMap contributors'
    }
).addTo(map);

const bounds=[];

stationData.forEach(function(station){
    const lat=Number(station.latitude);
    const lon=Number(station.longitude);

    const marker=L.marker([lat,lon]).addTo(map);

    const dashboardUrl='dashboard/?station='+encodeURIComponent(station.code);

    marker.bindPopup(
        '<strong>'+escapeHtml(station.name)+'</strong><br>'+
        '<a href="'+dashboardUrl+'">View dashboard</a>'
    );

    bounds.push([lat,lon]);
});

if(bounds.length===1){
    map.setView(bounds[0],<?= (int)$site['map_zoom'] ?>);
}else{
    map.fitBounds(bounds,{padding:[35,35],maxZoom:<?= (int)$site['map_zoom'] ?>});
}

function escapeHtml(value){
    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}
</script>
<?php endif; ?>


<style>
.slim-public-admin-link{
    margin:24px 0 12px;
    text-align:center;
    font-size:11px;
    line-height:1.2;
    opacity:.55;
}
.slim-public-admin-link a{
    color:inherit;
    text-decoration:none;
}
.slim-public-admin-link a:hover,
.slim-public-admin-link a:focus{
    opacity:1;
    text-decoration:underline;
}
</style>

<div class="slim-public-admin-link">
    <a href="admin/">admin</a>
</div>

</body>
</html>
