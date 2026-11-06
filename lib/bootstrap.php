<?php
declare(strict_types=1);

// Baseline response headers. Sent once, for web requests only, before any
// page produces output. These do not restrict inline CSS/JS, which SLIM uses
// throughout, but do close off MIME sniffing, framing, and referrer leakage.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header(
        "Content-Security-Policy: default-src 'self'; "
      . "script-src 'self' 'unsafe-inline'; "
      . "style-src 'self' 'unsafe-inline'; "
      . "font-src 'self' data:; "
      . "img-src 'self' data: https://*.tile.openstreetmap.org; "
      . "connect-src 'self'; "
      . "object-src 'none'; base-uri 'self'; form-action 'self'; "
      . "frame-ancestors 'self'"
    );

    $slimHttps = slim_request_is_https();

    if ($slimHttps) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Only HTTP(S) URLs are suitable for public-facing links. */
function slim_is_http_url(string $url): bool {
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
}

/** Prevent spreadsheet software from interpreting untrusted TEXT as a formula. */
function slim_csv_safe_value($value, bool $isText=true) {
    if (!$isText || !is_string($value)) return $value;
    // Leading whitespace/control characters can hide a formula prefix.
    if (preg_match('/^[\x00-\x20\x7f]*[=+\-@]/', $value)) return "'" . $value;
    return $value;
}

/** Public DNS answers only. Re-resolve for each connection and pin the selected IP. */
function slim_outbound_https_target(string $url): array {
    if (!slim_is_http_url($url) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('Destination must be a valid HTTPS URL.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !empty($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new RuntimeException('Destination URL contains unsupported components.');
    }
    $host = (string)($parts['host'] ?? '');
    $port = (int)($parts['port'] ?? 443);
    if ($host === '' || $port !== 443 || strlen($host) > 253) {
        throw new RuntimeException('Destination must use HTTPS on port 443.');
    }
    $host = trim($host, '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        if (!preg_match('/^(?=.{1,253}$)[a-z0-9.-]+$/i', $host)) {
            throw new RuntimeException('Invalid destination hostname.');
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) $records = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) $ips[] = $record['ip'];
            if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
        }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) throw new RuntimeException('Destination hostname did not resolve.');
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Destination resolves to a non-public address.');
        }
        // IPv4-mapped IPv6 addresses must not bypass IPv4 range validation.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            preg_match('/^::ffff:(.+)$/i', $ip, $m)) {
            if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Destination resolves to a non-public address.');
            }
        }
    }
    $ip = $ips[0];
    return [$host, $port, $ip];
}

/** Enforce HTTPS-only transport and pin DNS resolution against rebinding. */
function slim_curl_secure_destination($ch, string $url): void {
    [$host, $port, $ip] = slim_outbound_https_target($url);
    $pinned = strpos($ip, ':') !== false ? '['.$ip.']' : $ip;
    curl_setopt($ch, CURLOPT_RESOLVE, [$host.':'.$port.':'.$pinned]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
    } else {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    }
}

/**
 * Resolves in this order:
 *   1. LAKESTAT_CONFIG_DIR environment variable, if PHP actually receives it.
 *      Many shared hosts (php-fpm, CGI) do not pass Apache's SetEnv through
 *      to getenv(), so this cannot be relied on alone.
 *   2. A pointer file, config/EXTERNAL_CONFIG_PATH, containing a single
 *      absolute path on its first line. This works identically under every
 *      PHP SAPI because it is read directly from disk rather than through
 *      the environment, and is the recommended way to relocate config on
 *      hosts where SetEnv does not reach PHP.
 *   3. The in-webroot config/ directory, but only if it already contains a
 *      database.php -- i.e. an existing install, not a fresh one.
 *   4. $HOME/lakestat_config, if it exists.
 *
 * If none of these resolve, this throws rather than silently falling back to
 * the web root. A silent fallback previously caused SLIM to treat a broken
 * relocation as a brand-new install and quietly recreate credentials inside
 * the document root -- exactly the outcome relocation is meant to prevent.
 */
function lakestat_home(): string {
    $explicit = getenv('LAKESTAT_CONFIG_DIR');

    if ($explicit && is_dir($explicit)) {
        return rtrim($explicit, DIRECTORY_SEPARATOR);
    }

    $local   = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config';
    $pointer = $local . DIRECTORY_SEPARATOR . 'EXTERNAL_CONFIG_PATH';

    if (is_file($pointer)) {
        $pointed = trim((string)@file_get_contents($pointer));

        if ($pointed !== '') {
            if (is_dir($pointed)) {
                return rtrim($pointed, DIRECTORY_SEPARATOR);
            }

            // A pointer file exists but names a path that isn't there. This
            // almost always means a typo or an unmounted/misconfigured
            // path -- silently using the web root instead would recreate
            // the exact problem this mechanism exists to prevent.
            $placeholder = (strpos($pointed, 'USERNAME') !== false);

            if ($placeholder) {
                throw new RuntimeException(
                    'SLIM private configuration is not configured yet. Create a directory outside '
                    . 'public_html (for example /home/youraccount/slim_config), then edit '
                    . 'config/EXTERNAL_CONFIG_PATH and replace /home/USERNAME/slim_config with '
                    . 'the absolute path to that directory. Reload this page when finished.'
                );
            }

            throw new RuntimeException(
                'config/EXTERNAL_CONFIG_PATH points to "' . $pointed . '", '
                . 'which does not exist or is not a directory. Create that directory or correct '
                . 'the absolute path in config/EXTERNAL_CONFIG_PATH.'
            );
        }
    }

    if (is_dir($local) && is_file($local . DIRECTORY_SEPARATOR . 'database.php')) {
        return $local;
    }

    $home = getenv('HOME');

    if ($home && is_dir($home)) {
        $external = rtrim($home, DIRECTORY_SEPARATOR)
                  . DIRECTORY_SEPARATOR
                  . 'lakestat_config';

        if (is_dir($external)) {
            return $external;
        }
    }

    // Nothing resolved. This is a genuinely fresh install with no relocation
    // configured, so falling back to the web root here is correct -- setup
    // will warn about it. What must NOT happen is falling back here after a
    // relocation was attempted and silently failed; the checks above ensure
    // an attempted relocation either succeeds or throws.
    return $local;
}

function lakestat_config_path(string $file): string {
    return lakestat_home() . DIRECTORY_SEPARATOR . $file;
}

function lakestat_load_config(string $file): array {
    $path = lakestat_config_path($file);

    if (!is_file($path)) {
        throw new RuntimeException('Configuration file not found: ' . $file);
    }

    $config = require $path;

    if (!is_array($config)) {
        throw new RuntimeException('Invalid configuration file: ' . $file);
    }

    return $config;
}

function slim_private_write_file(string $path, string $contents, int $mode = 0600): void {
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Configuration directory is not writable.');
    }
    $tmp = tempnam($dir, '.slim-secure-');
    if ($tmp === false) throw new RuntimeException('Unable to create private temporary file.');
    try {
        // tempnam creates a private 0600 file. Set permissions before any content is written.
        if (!chmod($tmp, $mode)) throw new RuntimeException('Unable to secure temporary file.');
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write private file.');
        }
        if (!rename($tmp, $path)) throw new RuntimeException('Unable to activate private file.');
        @chmod($path, $mode);
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

function lakestat_write_php_config(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create configuration directory.');
    }
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
    // Administrator credentials require owner-only permissions, including backups.
    $mode = basename($path) === 'auth.php' ? 0600 : 0640;
    if (is_file($path)) {
        $old = file_get_contents($path);
        if ($old === false) throw new RuntimeException('Unable to read existing configuration for backup.');
        slim_private_write_file($path . '.bak', $old, $mode);
    }
    slim_private_write_file($path, $php, $mode);
    clearstatcache(true, $path);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
}

function slim_private_session_path(): string {
    $base = lakestat_home() . DIRECTORY_SEPARATOR . 'runtime';
    $path = $base . DIRECTORY_SEPARATOR . 'sessions';
    foreach ([$base, $path] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create private session directory.');
        }
        if (!chmod($dir, 0700) || !is_writable($dir)) {
            throw new RuntimeException('Unable to secure private session directory.');
        }
    }
    return $path;
}

function slim_configure_private_sessions(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (ini_set('session.save_path', slim_private_session_path()) === false) {
        throw new RuntimeException('Unable to configure private session storage.');
    }
}

function lakestat_ensure_config_dir(): string {
    $dir = lakestat_home();

    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create LakeStat configuration directory.');
    }

    foreach (['stations','displays','backups'] as $sub) {
        $path = $dir . DIRECTORY_SEPARATOR . $sub;

        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create configuration subdirectory: ' . $sub);
        }
    }

    foreach (['public','health'] as $displaySub) {
        $path=$dir.DIRECTORY_SEPARATOR.'displays'.DIRECTORY_SEPARATOR.$displaySub;
        if (!is_dir($path) && !mkdir($path,0750,true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create display configuration subdirectory: '.$displaySub);
        }
    }

    return $dir;
}

function lakestat_db(): PDO {
    $db = lakestat_load_config('database.php');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        (int)$db['port'],
        $db['database'],
        isset($db['charset']) ? $db['charset'] : 'utf8mb4'
    );

    $pdo = new PDO(
        $dsn,
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Keep database TIMESTAMP values deterministic regardless of the hosting
    // server's local timezone. Presentation layers convert UTC to the configured
    // SLIM IANA timezone (for example America/Chicago, including DST).
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function lakestat_station_code(string $value): string {
    $value = strtoupper(trim($value));

    if (!preg_match('/^[A-Z0-9_-]{2,32}$/', $value)) {
        throw new InvalidArgumentException(
            'Station code must be 2–32 characters using letters, numbers, hyphens, or underscores.'
        );
    }

    return $value;
}


function lakestat_optimize_station_table(PDO $pdo, array $station): void {
    $table=lakestat_safe_identifier((string)($station['table'] ?? ''));
    if($table==='') return;

    // Backfill legacy rows before all timestamp queries switch to observed_at directly.
    $pdo->exec("UPDATE `{$table}` SET `observed_at`=`received_at` WHERE `observed_at` IS NULL");

    $indexes=[];
    foreach($pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $column=(string)($row['Column_name'] ?? '');
        if($column!=='') $indexes[$column]=true;
    }
    if(empty($indexes['observed_at'])) {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `idx_observed_at` (`observed_at`)");
    }
    if(empty($indexes['received_at'])) {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `idx_received_at` (`received_at`)");
    }
}

function lakestat_safe_identifier(string $value): string {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $value)) {
        throw new InvalidArgumentException(
            'Names must begin with a letter and contain only letters, numbers, and underscores.'
        );
    }

    return $value;
}

function lakestat_station_config(string $code): array {
    $code = lakestat_station_code($code);

    $path = lakestat_config_path(
        'stations' . DIRECTORY_SEPARATOR . $code . '.php'
    );

    if (!is_file($path)) {
        throw new RuntimeException('Unknown station.');
    }

    $config = require $path;

    if (!is_array($config)) {
        throw new RuntimeException('Invalid station configuration.');
    }

    // Convert legacy plaintext keys in place without rotating deployed devices.
    // Migration is atomic and never writes a plaintext backup.
    if (isset($config['api_key']) && is_string($config['api_key']) && $config['api_key'] !== '') {
        $config['api_key_hash'] = slim_api_key_digest($config['api_key']);
        unset($config['api_key']);
        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        slim_private_write_file($path, $php, 0600);
    }
    // Old station backups may contain credentials; do not retain them.
    if (is_file($path . '.bak')) {
        $backup = @include $path . '.bak';
        if (is_array($backup) && array_key_exists('api_key', $backup)) {
            unset($backup['api_key']);
            $backup['api_key_hash'] = $config['api_key_hash'] ?? '';
            $backupPhp = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($backup, true) . ";\n";
            slim_private_write_file($path . '.bak', $backupPhp, 0600);
        }
    }
    return $config;
}

function lakestat_display_config_path(string $stationCode, string $kind='public'): string {
    $stationCode=lakestat_station_code($stationCode);
    if(!in_array($kind,['public','health'],true)) {
        throw new InvalidArgumentException('Unknown display configuration type.');
    }
    return lakestat_config_path(
        'displays'.DIRECTORY_SEPARATOR.$kind.DIRECTORY_SEPARATOR.$stationCode.'.php'
    );
}

function lakestat_display_config_for(string $stationCode, string $kind='public'): array {
    $stationCode=lakestat_station_code($stationCode);
    $path=lakestat_display_config_path($stationCode,$kind);


    if(!is_file($path)) {
        return ['station'=>$stationCode,'displays'=>[]];
    }

    $config=require $path;
    if(!is_array($config)) {
        throw new RuntimeException('Invalid '.$kind.' display configuration.');
    }
    if(!isset($config['displays']) || !is_array($config['displays'])) {
        $config['displays']=[];
    }
    return $config;
}

function lakestat_display_config(string $stationCode): array {
    return lakestat_display_config_for($stationCode,'public');
}

function lakestat_health_display_config(string $stationCode): array {
    return lakestat_display_config_for($stationCode,'health');
}


function lakestat_list_stations(): array {
    $dir = lakestat_config_path('stations');

    if (!is_dir($dir)) {
        return [];
    }

    $stations = [];

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
        $config = require $file;

        if (!is_array($config) || empty($config['code'])) {
            continue;
        }

        $stations[$config['code']] = [
            'code' => $config['code'],
            'name' => isset($config['name']) ? $config['name'] : $config['code']
        ];
    }

    ksort($stations);

    return $stations;
}

function lakestat_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name"
    );

    $stmt->execute([
        ':table_name' => $table
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function lakestat_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name"
    );

    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function slim_api_key_digest(string $key): string {
    return hash('sha256', $key);
}

function slim_api_key_matches(array $station, string $submitted): bool {
    $stored = (string)($station['api_key_hash'] ?? '');
    $valid = preg_match('/^[a-f0-9]{64}$/D', $stored) === 1;
    $expected = $valid ? $stored : str_repeat('0', 64);
    $matches = hash_equals($expected, slim_api_key_digest($submitted));
    return $valid && $matches;
}

function lakestat_random_api_key(): string {
    return bin2hex(random_bytes(24));
}

function lakestat_backup_station(PDO $pdo, array $station): array {
    $table = lakestat_safe_identifier((string)$station['table']);
    $code = lakestat_station_code((string)$station['code']);

    $baseDir = lakestat_config_path(
        'backups' . DIRECTORY_SEPARATOR . $code
    );

    if (
        !is_dir($baseDir)
        && !mkdir($baseDir, 0750, true)
        && !is_dir($baseDir)
    ) {
        throw new RuntimeException('Unable to create backup directory.');
    }

    $stamp = gmdate('Y-m-d_His');

    $csvPath = $baseDir
             . DIRECTORY_SEPARATOR
             . $code . '_' . $stamp . '.csv';

    $configPath = $baseDir
                . DIRECTORY_SEPARATOR
                . $code . '_' . $stamp . '_config.php';

    $fh = fopen($csvPath, 'w');

    if ($fh === false) {
        throw new RuntimeException('Unable to create CSV backup.');
    }

    $stmt = $pdo->query("SELECT * FROM `{$table}`");

    $headerWritten = false;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$headerWritten) {
            fputcsv($fh, array_keys($row));
            $headerWritten = true;
        }

        $safeRow = [];
        foreach ($row as $value) {
            $safeRow[] = slim_csv_safe_value($value, is_string($value) && !is_numeric($value));
        }
        fputcsv($fh, $safeRow);
    }

    if (!$headerWritten) {
        $cols = $pdo
            ->query("DESCRIBE `{$table}`")
            ->fetchAll(PDO::FETCH_ASSOC);

        $names = [];

        foreach ($cols as $col) {
            $names[] = $col['Field'];
        }

        fputcsv($fh, $names);
    }

    fclose($fh);

    $configPhp = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
               . var_export($station, true)
               . ";\n";

    if (
        file_put_contents(
            $configPath,
            $configPhp,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'CSV backup succeeded, but configuration backup failed.'
        );
    }

    @chmod($csvPath, 0640);
    @chmod($configPath, 0640);

    return [
        'csv' => $csvPath,
        'config' => $configPath
    ];
}


function lakestat_default_appearance(): array {
    return [
        'theme' => 'lakestat_dark',
        'organization_name' => '',
        'logo_path' => '',
        'logo_mode' => 'title_only',
        'logo_max_height' => 52,
        'custom_colors' => [
            'background' => '#0a1628',
            'background_mid' => '#112240',
            'card' => '#162a47',
            'card_hover' => '#1e3a5f',
            'accent' => '#00c2ff',
            'teal' => '#0ddfb8',
            'text_primary' => '#e8f0fe',
            'text_secondary' => '#7a9bbf',
            'text_muted' => '#4a6580'
        ]
    ];
}

function slim_theme_colors(array $appearance): array {
    $themes=[
        'lakestat_dark'=>[
            'background'=>'#0a1628','background_mid'=>'#112240',
            'card'=>'#162a47','card_hover'=>'#1e3a5f',
            'accent'=>'#00c2ff','teal'=>'#0ddfb8',
            'text_primary'=>'#e8f0fe','text_secondary'=>'#7a9bbf',
            'text_muted'=>'#4a6580'
        ],
        'lakestat_light'=>[
            'background'=>'#eef3f7','background_mid'=>'#dde7ef',
            'card'=>'#ffffff','card_hover'=>'#f7fbfe',
            'accent'=>'#006fa8','teal'=>'#008f7a',
            'text_primary'=>'#17324a','text_secondary'=>'#48677f',
            'text_muted'=>'#7890a3'
        ],
        'freshwater'=>[
            'background'=>'#062b36','background_mid'=>'#0b3e4d',
            'card'=>'#105468','card_hover'=>'#14657b',
            'accent'=>'#45d7e8','teal'=>'#6be6bc',
            'text_primary'=>'#eefcff','text_secondary'=>'#9dd4dc',
            'text_muted'=>'#6ea8b2'
        ],
        'neutral'=>[
            'background'=>'#202326','background_mid'=>'#2a2e32',
            'card'=>'#34393e','card_hover'=>'#3d4349',
            'accent'=>'#d4d8dc','teal'=>'#9fd3c7',
            'text_primary'=>'#f4f5f6','text_secondary'=>'#b7bec5',
            'text_muted'=>'#858d95'
        ]
    ];

    $theme=(string)($appearance['theme'] ?? 'lakestat_dark');
    if($theme==='custom') {
        $defaults=$themes['lakestat_dark'];
        $custom=is_array($appearance['custom_colors'] ?? null)
            ? $appearance['custom_colors'] : [];
        return array_merge($defaults,$custom);
    }
    return $themes[$theme] ?? $themes['lakestat_dark'];
}

function lakestat_appearance_config(): array {
    $defaults = lakestat_default_appearance();
    $path = lakestat_config_path('appearance.php');

    if (!is_file($path)) {
        return $defaults;
    }

    $config = require $path;

    if (!is_array($config)) {
        return $defaults;
    }

    $merged = array_merge($defaults, $config);

    $merged['custom_colors'] = array_merge(
        $defaults['custom_colors'],
        isset($config['custom_colors']) && is_array($config['custom_colors'])
            ? $config['custom_colors']
            : []
    );

    return $merged;
}


function lakestat_default_site_config(): array {
    return [
        'site_title' => 'Environmental Monitoring Network',
        'intro_text' => '',
        'website_url' => '',
        'website_label' => '',
        'contact_email' => '',
        'show_about_page' => false,
        'about_nav_label' => 'About',
        'about_title' => 'About',
        'about_text' => '',
        'about_html' => '',
        'show_contact_page' => false,
        'contact_nav_label' => 'Contact',
        'contact_title' => 'Contact',
        'contact_text' => '',
        'contact_html' => '',
        'contact_organization' => '',
        'contact_person' => '',
        'contact_phone' => '',
        'contact_address' => '',
        'contact_website' => '',
        'show_station_map' => true,
        'show_station_list' => true,
        'show_coordinates' => true,
        'map_zoom' => 7,
        'max_dashboard_hours' => 720,
        'max_dashboard_days' => 30,
        'max_dashboard_rows' => 5000,
        'rate_limit_enabled' => true,
        'rate_limit_per_minute' => 60,
        'export_max_days' => 180,
        'export_max_rows_per_station' => 100000,
        'export_max_total_rows' => 250000,
        'export_max_stations' => 10,
        'import_max_file_bytes' => 52428800,
        'import_max_rows' => 100000,
        'import_max_columns' => 60
    ];
}

function slim_version(): string {
    static $version=null;
    if($version!==null) return $version;
    $path=dirname(__DIR__).DIRECTORY_SEPARATOR.'VERSION.txt';
    if(!is_file($path)) return $version='';
    $line=trim((string)@file_get_contents($path));
    if(preg_match('/(?:^|\s)v?([0-9]+(?:\.[0-9]+)+)(?:\s|$)/i',$line,$m)) return $version=$m[1];
    return $version='';
}

function slim_footer_text(bool $powered=true): string {
    $version=slim_version();
    return 'SLIM'.($version!=='' ? ' '.$version : '');
}

function slim_public_asset_url(string $path): string {
    $path=trim(str_replace('\\','/',$path));
    if($path==='') return '';

    // Already absolute/root-relative URLs can be used as supplied.
    if(preg_match('#^(https?://|/)#i',$path)) return $path;

    // Appearance configuration stores assets relative to the SLIM root
    // (for example assets/branding/logo_x.png). Determine the installation
    // base from the current request so the same URL works from every page.
    $scriptName=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $markers=[
        '/admin/appearance/index.php','/admin/site/index.php','/admin/health/index.php','/admin/index.php',
        '/dashboard/index.php','/about/index.php','/contact/index.php',
        '/index.php'
    ];
    $base='';
    foreach($markers as $marker) {
        $pos=strrpos($scriptName,$marker);
        if($pos!==false) {
            $base=substr($scriptName,0,$pos);
            break;
        }
    }

    return rtrim($base,'/').'/'.ltrim($path,'/');
}

function slim_sanitize_content_html(string $html): string {
    $html=trim($html);
    if($html==='') return '';

    // Recover WYSIWYG markup that may have been stored as escaped text by an
    // earlier editor revision. It is decoded only before passing through the
    // same strict sanitizer below.
    if (
        strpos($html,'&lt;') !== false &&
        preg_match('/&lt;\/?(?:span|b|strong|i|em|u|p|br|ul|ol|li|sub|sup|a)\b/i',$html)
    ) {
        $html=html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8');
    }

    // Constrained public-page HTML: formatting, links, and images only.
    // Scriptable/embedded content is never allowed.
    $allowedTags='<p><br><h2><h3><h4><strong><b><em><i><u><ul><ol><li><blockquote><a><img><span><sub><sup>';
    $html=strip_tags($html,$allowedTags);

    if(!class_exists('DOMDocument')) {
        // Safe fallback: keep formatting but remove all attributes, which also
        // removes href/src. DOM is normally available on PHP web hosts.
        return preg_replace('/<([a-z0-9]+)\\s+[^>]*>/i','<$1>',$html) ?? '';
    }

    $dom=new DOMDocument('1.0','UTF-8');
    $previous=libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="slim-content-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root=$dom->getElementById('slim-content-root');
    if(!$root) return '';

    $elements=[];
    foreach($root->getElementsByTagName('*') as $el) $elements[]=$el;
    foreach($elements as $el) {
        $tag=strtolower($el->tagName);
        $allowed=[];
        if($tag==='a') $allowed=['href','title'];
        if($tag==='img') $allowed=['src','alt','title','class'];
        if($tag==='span') $allowed=['class'];

        $remove=[];
        foreach($el->attributes ?? [] as $attr) {
            if(!in_array(strtolower($attr->name),$allowed,true)) $remove[]=$attr->name;
        }
        foreach($remove as $name) $el->removeAttribute($name);

        if($tag==='a' && $el->hasAttribute('href')) {
            $url=trim($el->getAttribute('href'));
            if(!preg_match('#^(https?://|mailto:|/|\\.\\.?/)#i',$url)) $el->removeAttribute('href');
            else { $el->setAttribute('rel','noopener'); }
        }
        if($tag==='span' && $el->hasAttribute('class')) {
            $class=trim($el->getAttribute('class'));
            if(!in_array($class,['slim-text-normal','slim-text-large','slim-text-xlarge'],true)) {
                $el->removeAttribute('class');
            }
        }
        if($tag==='img' && $el->hasAttribute('src')) {
            $url=trim($el->getAttribute('src'));
            if(!preg_match('#^(https?://|/|\\.\\.?/)#i',$url)) $el->removeAttribute('src');
            $class=trim($el->getAttribute('class'));
            if(!in_array($class,['slim-img-third','slim-img-half','slim-img-full'],true)) {
                $el->removeAttribute('class');
            }
        }
    }

    // Collapse legacy chains such as Large > Extra large > Large when the
    // outer size span contains only another size span. The innermost explicit
    // choice is the effective formatting and is retained.
    $sizeClasses=['slim-text-normal','slim-text-large','slim-text-xlarge'];
    $changed=true;
    while($changed) {
        $changed=false;
        $spans=[];
        foreach($root->getElementsByTagName('span') as $span) $spans[]=$span;
        foreach($spans as $span) {
            $class=$span->getAttribute('class');
            if(!in_array($class,$sizeClasses,true)) continue;

            $meaningful=[];
            foreach($span->childNodes as $child) {
                if($child->nodeType===XML_TEXT_NODE && trim($child->nodeValue)==='') continue;
                $meaningful[]=$child;
            }
            if(count($meaningful)!==1 || $meaningful[0]->nodeType!==XML_ELEMENT_NODE) continue;

            $child=$meaningful[0];
            if(strtolower($child->nodeName)!=='span') continue;
            if(!in_array($child->getAttribute('class'),$sizeClasses,true)) continue;

            $parent=$span->parentNode;
            if($parent) {
                $parent->replaceChild($child,$span);
                $changed=true;
                break;
            }
        }
    }

    $out='';
    foreach($root->childNodes as $child) $out.=$dom->saveHTML($child);
    return trim($out);
}

function lakestat_site_config(): array {
    $defaults = lakestat_default_site_config();
    $path = lakestat_config_path('site.php');

    if (!is_file($path)) {
        return $defaults;
    }

    $config = require $path;

    if (!is_array($config)) {
        return $defaults;
    }

    return array_merge($defaults, $config);
}


/** Normalize one sensor value using the same rules for API and CSV imports. */
function slim_observation_normalize_field(array $field, mixed $value): array {
    $type=(string)($field['type'] ?? 'number');
    if($value===null || $value==='') $value=null;
    elseif($type==='number') {
        if(!is_numeric($value)) throw new InvalidArgumentException('Expected numeric measurement.');
        $value=(float)$value;
    } elseif($type==='integer') {
        if(filter_var($value,FILTER_VALIDATE_INT)===false) throw new InvalidArgumentException('Expected integer measurement.');
        $value=(int)$value;
    } else $value=substr((string)$value,0,255);
    $qc=is_array($field['qc'] ?? null) ? $field['qc'] : [];
    if(empty($qc['enabled']) || $type==='text' || !empty($field['derived'])) return ['value'=>$value];
    $raw=$value;
    $status=$value===null ? 'missing' : 'valid';
    if($value!==null) {
        foreach(($qc['diagnostic_codes'] ?? []) as $diag) {
            if(is_array($diag) && array_key_exists('value',$diag) && (float)$value===(float)$diag['value']) {
                $status='diagnostic'; $value=null; break;
            }
        }
        if($status==='valid') {
            $min=isset($qc['min']) ? (float)$qc['min'] : null;
            $max=isset($qc['max']) ? (float)$qc['max'] : null;
            if(($min!==null && $value<$min) || ($max!==null && $value>$max)) {
                $status='out_of_range'; $value=null;
            }
        }
    }
    return ['value'=>$value,'raw'=>$raw,'qc'=>$status];
}

function lakestat_webhook_ensure_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lakestat_webhook_queue` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `station` VARCHAR(32) NOT NULL,
            `endpoint` VARCHAR(2048) NOT NULL,
            `headers_json` TEXT NULL,
            `payload_json` MEDIUMTEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `http_code` INT NULL,
            `last_error` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_attempt_at` DATETIME NULL,
            `delivered_at` DATETIME NULL,
            INDEX `idx_webhook_status_created` (`status`,`created_at`),
            INDEX `idx_webhook_station` (`station`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}


/**
 * Calculate configured derived numeric variables from already-normalized
 * station values. Input and output arrays are keyed by MySQL column name.
 *
 * A derived variable starts with one numeric source variable and may contain
 * an ordered list of safe arithmetic operations. Each operation can use a
 * numeric constant or another configured numeric station variable. This
 * supports both unit/calibration transformations and simple multivariable
 * calculations without evaluating arbitrary PHP expressions.
 *
 * If any required input is missing/non-numeric, or a calculation attempts
 * division by zero, the derived result is stored as NULL.
 */
function lakestat_apply_derived_variables(array $station, array $values, array $timestamps=[]): array {
    $rules=is_array($station['derived_variables'] ?? null)
        ? $station['derived_variables']
        : [];

    if(!$rules) return $values;

    foreach($rules as $targetKey=>$rule) {
        if(!is_array($rule)) continue;

        // Timestamp-derived variables use SLIM's canonical UTC timestamps rather
        // than a measurement field. They are stored as ordinary station columns
        // so exports, dashboards, and webhooks can use them without special cases.
        if(($rule['kind'] ?? '')==='timestamp') {
            $targetColumn=lakestat_safe_identifier(
                (string)($station['fields'][$targetKey]['column'] ?? $targetKey)
            );
            $source=(string)($rule['source'] ?? 'observed_at');
            $format=(string)($rule['format'] ?? 'epoch_seconds');
            $raw=$timestamps[$source] ?? null;
            if($raw===null || $raw==='') {
                $values[$targetColumn]=null;
                continue;
            }
            try {
                $dt=new DateTimeImmutable((string)$raw,new DateTimeZone('UTC'));
                $dt=$dt->setTimezone(new DateTimeZone('UTC'));
                if($format==='epoch_seconds') {
                    $values[$targetColumn]=$dt->getTimestamp();
                } else {
                    $values[$targetColumn]=null;
                }
            } catch(Throwable $e) {
                $values[$targetColumn]=null;
            }
            continue;
        }

        $sourceKey=(string)($rule['source'] ?? '');
        if($sourceKey==='' || !isset($station['fields'][$sourceKey])) continue;
        if(!isset($station['fields'][$targetKey])) continue;

        $sourceColumn=lakestat_safe_identifier(
            (string)($station['fields'][$sourceKey]['column'] ?? $sourceKey)
        );
        $targetColumn=lakestat_safe_identifier(
            (string)($station['fields'][$targetKey]['column'] ?? $targetKey)
        );

        if(!array_key_exists($sourceColumn,$values)) {
            $values[$targetColumn]=null;
            continue;
        }

        $sourceValue=$values[$sourceColumn];
        if($sourceValue===null || $sourceValue==='' || !is_numeric($sourceValue)) {
            $values[$targetColumn]=null;
            continue;
        }

        $result=(float)$sourceValue;

        if(!isset($rule['steps']) || !is_array($rule['steps'])) continue;

        foreach($rule['steps'] as $step) {
            if(!is_array($step)) {
                $result=NAN;
                break;
            }

            $operation=(string)($step['operation'] ?? '');
            $operandType=(string)($step['operand_type'] ?? 'constant');
            $operand=null;

            if($operandType==='variable') {
                $operandKey=(string)($step['variable'] ?? '');
                if($operandKey==='' || !isset($station['fields'][$operandKey])) {
                    $result=NAN;
                    break;
                }
                $operandColumn=lakestat_safe_identifier(
                    (string)($station['fields'][$operandKey]['column'] ?? $operandKey)
                );
                if(!array_key_exists($operandColumn,$values)
                    || $values[$operandColumn]===null
                    || $values[$operandColumn]===''
                    || !is_numeric($values[$operandColumn])) {
                    $result=NAN;
                    break;
                }
                $operand=(float)$values[$operandColumn];
            } elseif($operandType==='constant') {
                if(!array_key_exists('value',$step) || !is_numeric($step['value'])) {
                    $result=NAN;
                    break;
                }
                $operand=(float)$step['value'];
            } else {
                $result=NAN;
                break;
            }

            switch($operation) {
                case 'multiply':
                    $result*=$operand;
                    break;
                case 'divide':
                    if($operand==0.0) $result=NAN;
                    else $result/=$operand;
                    break;
                case 'add':
                    $result+=$operand;
                    break;
                case 'subtract':
                    $result-=$operand;
                    break;
                default:
                    $result=NAN;
                    break;
            }

            if(!is_finite($result)) break;
        }

        $values[$targetColumn]=is_finite($result) ? $result : null;
    }

    return $values;
}

function lakestat_webhook_config(array $station): array {
    $defaults = [
        'enabled'=>false,
        'url'=>'',
        'headers'=>[],
        'fields'=>[],
        'payload_mode'=>'default',
        'json_template'=>'',
        'timeout'=>15
    ];

    $config = isset($station['webhook']) && is_array($station['webhook'])
        ? $station['webhook'] : [];

    return array_merge($defaults,$config);
}

function lakestat_webhook_headers(array $names, array $values): array {
    $headers=[];

    foreach($names as $i=>$rawName) {
        $name=trim((string)$rawName);
        $value=trim((string)($values[$i] ?? ''));

        if($name==='' && $value==='') continue;

        if(!preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/',$name)) {
            throw new RuntimeException('Webhook header names contain invalid characters.');
        }

        if(stripos($name,'Content-Length')===0 || strcasecmp($name,'Host')===0) {
            throw new RuntimeException('Host and Content-Length webhook headers are managed by LakeStat.');
        }

        if(preg_match('/[\r\n]/',$value)) {
            throw new RuntimeException('Webhook header values cannot contain line breaks.');
        }

        $headers[]=['name'=>$name,'value'=>$value];
    }

    return $headers;
}

function lakestat_webhook_template_context(array $station, array $data, ?string $observedAt=null): array {
    $context=[
        'station'=>(string)$station['code'],
        'timestamp'=>$observedAt !== null ? gmdate('c',strtotime($observedAt.' UTC')) : null
    ];

    foreach($station['fields'] as $key=>$field) {
        $jsonKey=(string)($field['json_key'] ?? $key);
        $column=(string)($field['column'] ?? $key);
        if(array_key_exists($jsonKey,$data)) {
            $context[$jsonKey]=$data[$jsonKey];
        } elseif(array_key_exists($column,$data)) {
            $context[$jsonKey]=$data[$column];
        } else {
            $context[$jsonKey]=null;
        }
    }

    return $context;
}

function lakestat_webhook_render_template(string $template, array $context): mixed {
    $template=trim($template);
    if($template==='') throw new RuntimeException('Custom webhook JSON template cannot be empty.');

    if(preg_match_all('/{{\s*([A-Za-z0-9_.-]+)\s*}}/',$template,$matches)) {
        foreach(array_unique($matches[1]) as $name) {
            if(!array_key_exists($name,$context)) {
                throw new RuntimeException('Unknown webhook template placeholder: {{'.$name.'}}.');
            }
        }
    }

    $rendered=preg_replace_callback(
        '/{{\s*([A-Za-z0-9_.-]+)\s*}}/',
        static function(array $match) use ($context): string {
            $json=json_encode($context[$match[1]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if($json===false) throw new RuntimeException('A webhook value could not be encoded as JSON.');
            return $json;
        },
        $template
    );

    if($rendered===null) throw new RuntimeException('Custom webhook JSON template could not be rendered.');
    if(str_contains($rendered,'{{')) {
        throw new RuntimeException('Custom webhook JSON contains an invalid or unresolved placeholder.');
    }

    try {
        return json_decode($rendered,true,512,JSON_THROW_ON_ERROR);
    } catch(JsonException $e) {
        throw new RuntimeException('Custom webhook JSON is invalid after placeholder substitution: '.$e->getMessage());
    }
}

function lakestat_webhook_payload(array $station, array $data, ?string $observedAt=null): mixed {
    $config=lakestat_webhook_config($station);
    $context=lakestat_webhook_template_context($station,$data,$observedAt);

    if(($config['payload_mode'] ?? 'default')==='custom') {
        return lakestat_webhook_render_template((string)($config['json_template'] ?? ''),$context);
    }

    $selected=$config['fields'];
    $payload=[
        'station'=>$context['station'],
        'timestamp'=>$data['timestamp'] ?? $context['timestamp']
    ];

    foreach($station['fields'] as $key=>$field) {
        $jsonKey=(string)($field['json_key'] ?? $key);

        if($selected) {
            if(!in_array($jsonKey,$selected,true)) continue;
        } elseif(!empty($field['webhook_default_exclude'])) {
            continue;
        }
        if(array_key_exists($jsonKey,$data)) $payload[$jsonKey]=$data[$jsonKey];
    }

    return $payload;
}

// Bounded queue policy. Expired pending jobs are marked failed (not retried).
const SLIM_WEBHOOK_MAX_PENDING = 1000;
const SLIM_WEBHOOK_PENDING_MAX_DAYS = 7;

function lakestat_webhook_expire_pending(PDO $pdo): void {
    $pdo->exec("UPDATE `lakestat_webhook_queue` SET `status`='failed',
        `last_error`='Delivery expired: pending longer than 7 days',
        `last_attempt_at`=UTC_TIMESTAMP()
        WHERE `status`='pending' AND `created_at` < UTC_TIMESTAMP() - INTERVAL 7 DAY");
}

function lakestat_webhook_enqueue(PDO $pdo, array $station, mixed $payload): ?int {
    $config=lakestat_webhook_config($station);
    if(empty($config['enabled']) || trim((string)$config['url'])==='') return null;

    // Ingestion must never grow the pending queue without bound, including
    // when the scheduler is missing. Caller isolates any thrown exception.
    lakestat_webhook_expire_pending($pdo);
    $pending=(int)$pdo->query("SELECT COUNT(*) FROM `lakestat_webhook_queue` WHERE `status`='pending'")->fetchColumn();
    if ($pending >= SLIM_WEBHOOK_MAX_PENDING) {
        error_log('SLIM webhook queue full: dropping new delivery for station '.(string)($station['code'] ?? 'unknown'));
        return null;
    }
    $stmt=$pdo->prepare(
        "INSERT INTO `lakestat_webhook_queue`
        (`station`,`endpoint`,`headers_json`,`payload_json`)
        VALUES (:station,:endpoint,:headers,:payload)"
    );

    $stmt->execute([
        ':station'=>(string)$station['code'],
        ':endpoint'=>(string)$config['url'],
        ':headers'=>null, // Credentials are read from current station config at delivery.
        ':payload'=>json_encode($payload,JSON_UNESCAPED_SLASHES)
    ]);

    return (int)$pdo->lastInsertId();
}

function lakestat_webhook_process_job(PDO $pdo, int $jobId, int $maxAttempts=2, int $timeout=5): array {
    $maxAttempts=max(1,min(20,$maxAttempts));
    $stmt=$pdo->prepare("SELECT * FROM `lakestat_webhook_queue` WHERE `id`=:id AND `status`='pending' AND `attempts` < :max_attempts LIMIT 1");
    $stmt->bindValue(':id',$jobId,PDO::PARAM_INT);
    $stmt->bindValue(':max_attempts',$maxAttempts,PDO::PARAM_INT);
    $stmt->execute();
    $job=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$job) return ['processed'=>0,'delivered'=>0,'unsuccessful'=>0];

    $attempts=(int)$job['attempts']+1;
    try {
        $station=lakestat_station_config((string)$job['station']);
        $config=lakestat_webhook_config($station);
        if(empty($config['enabled']) || trim((string)$config['url'])==='') {
            throw new RuntimeException('Webhook is disabled or has no destination.');
        }
        $headers=is_array($config['headers']) ? $config['headers'] : [];
        $configuredTimeout=max(1,min(10,(int)($config['timeout'] ?? 10)));
        $result=lakestat_webhook_send((string)$config['url'],$headers,(string)$job['payload_json'],$configuredTimeout);
    } catch(Throwable $e) {
        $result=['ok'=>false,'http_code'=>0,'error'=>'Webhook sender exception: '.$e->getMessage()];
    }

    if($result['ok']) {
        $update=$pdo->prepare("UPDATE `lakestat_webhook_queue` SET `status`='delivered',`attempts`=:attempts,`http_code`=:http_code,`last_error`=NULL,`last_attempt_at`=NOW(),`delivered_at`=NOW() WHERE `id`=:id");
        $delivered=1; $unsuccessful=0;
    } else {
        $status=$attempts >= $maxAttempts ? 'failed' : 'pending';
        $update=$pdo->prepare("UPDATE `lakestat_webhook_queue` SET `status`=:status,`attempts`=:attempts,`http_code`=:http_code,`last_error`=:last_error,`last_attempt_at`=NOW() WHERE `id`=:id");
        $update->bindValue(':status',$status);
        $update->bindValue(':last_error',substr((string)($result['error'] ?? ''),0,4000));
        $delivered=0; $unsuccessful=1;
    }
    $update->bindValue(':attempts',$attempts,PDO::PARAM_INT);
    $update->bindValue(':http_code',(int)($result['http_code'] ?? 0),PDO::PARAM_INT);
    $update->bindValue(':id',$jobId,PDO::PARAM_INT);
    $update->execute();
    return ['processed'=>1,'delivered'=>$delivered,'unsuccessful'=>$unsuccessful];
}

function lakestat_webhook_process_pending(PDO $pdo, int $limit=4, int $maxAttempts=1): array {
    // Called only from CLI dispatcher. One automatic attempt per queued delivery.
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->query("SELECT `id` FROM `lakestat_webhook_queue` WHERE `status`='pending' AND `attempts`=0 ORDER BY `id` ASC LIMIT ".(int)$limit);
    $total=['processed'=>0,'delivered'=>0,'unsuccessful'=>0];
    $deadline=microtime(true)+45.0;
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if(microtime(true)+10.0>=$deadline) break;
        $result=lakestat_webhook_process_job($pdo,(int)$id,1);
        foreach($total as $key=>$value) $total[$key]+=(int)$result[$key];
    }
    return $total;
}

function lakestat_webhook_prune(PDO $pdo): void {
    // Remove historic copies of webhook authorization headers from old queue rows.
    $pdo->exec("UPDATE `lakestat_webhook_queue` SET `headers_json`=NULL WHERE `headers_json` IS NOT NULL");
    lakestat_webhook_expire_pending($pdo);
    // Remove old terminal rows, including expired pending deliveries.
    $pdo->exec("DELETE FROM `lakestat_webhook_queue` WHERE (`status`='delivered' AND `delivered_at` < UTC_TIMESTAMP() - INTERVAL 7 DAY) OR (`status`='failed' AND `last_attempt_at` < UTC_TIMESTAMP() - INTERVAL 7 DAY)");
}

function lakestat_webhook_send(string $url, array $headers, string $json, int $timeout=5): array {
    if(!function_exists('curl_init')) {
        return ['ok'=>false,'http_code'=>0,'error'=>'PHP cURL extension is required.'];
    }

    $outHeaders=['Content-Type: application/json'];

    foreach($headers as $header) {
        if(!is_array($header)) continue;
        $name=trim((string)($header['name'] ?? ''));
        $value=(string)($header['value'] ?? '');
        if($name==='') continue;

        if(strcasecmp($name,'Content-Type')===0) {
            foreach($outHeaders as $i=>$existing) {
                if(stripos($existing,'Content-Type:')===0) unset($outHeaders[$i]);
            }
        }
        $outHeaders[]=$name.': '.$value;
    }

    try { slim_outbound_https_target($url); } catch (Throwable $e) {
        return ['ok'=>false,'http_code'=>0,'error'=>'Unsafe or unreachable webhook destination.'];
    }
    $ch=curl_init($url);
    slim_curl_secure_destination($ch, $url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_HTTPHEADER=>array_values($outHeaders),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>max(1,min(30,$timeout)),
        CURLOPT_CONNECTTIMEOUT=>max(1,min(10,$timeout)),
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2
    ]);

    $body=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $curlErrno=curl_errno($ch);
    $curlError=curl_error($ch);
    $elapsed=(float)curl_getinfo($ch,CURLINFO_TOTAL_TIME);
    $connect=(float)curl_getinfo($ch,CURLINFO_CONNECT_TIME);
    $appconnect=(float)curl_getinfo($ch,CURLINFO_APPCONNECT_TIME);
    $starttransfer=(float)curl_getinfo($ch,CURLINFO_STARTTRANSFER_TIME);
    curl_close($ch);

    // Only record a generic category and timing metadata; never log request
    // headers, credentials, URLs, response bodies or raw cURL error strings.
    $diagnostic='cURL '.(int)$curlErrno.' (';
    switch ($curlErrno) {
        case 28: $diagnostic.='timeout'; break;
        case 6: $diagnostic.='DNS resolution failed'; break;
        case 7: $diagnostic.='connection failed'; break;
        case 35: $diagnostic.='TLS handshake failed'; break;
        case 60: $diagnostic.='TLS certificate verification failed'; break;
        case 52: $diagnostic.='empty server response'; break;
        case 56: $diagnostic.='receive failure'; break;
        default: $diagnostic.='transport error'; break;
    }
    $diagnostic.=')';
    $diagnostic.=sprintf('; elapsed %.2fs, connect %.2fs, TLS %.2fs, first byte %.2fs',
        $elapsed,$connect,$appconnect,$starttransfer);
    return [
        'ok'=>$body!==false && $http>=200 && $http<300,
        'http_code'=>$http,
        'error'=>$body===false ? $diagnostic : (($http>=200 && $http<300) ? '' : 'Webhook returned HTTP '.(int)$http)
    ];
}


function slim_public_limits(): array {
    $site = function_exists('lakestat_site_config') ? lakestat_site_config() : [];

    return [
        'max_dashboard_hours' => max(
            1,
            min(8760, (int)($site['max_dashboard_hours'] ?? 720))
        ),
        'max_dashboard_days' => max(
            1,
            min(3650, (int)($site['max_dashboard_days'] ?? 30))
        ),
        'max_dashboard_rows' => max(
            100,
            min(20000, (int)($site['max_dashboard_rows'] ?? 5000))
        ),
        'rate_limit_enabled' => array_key_exists('rate_limit_enabled',$site)
            ? (bool)$site['rate_limit_enabled']
            : true,
        'rate_limit_per_minute' => max(
            5,
            min(600, (int)($site['rate_limit_per_minute'] ?? 60))
        )
    ];
}

function slim_ingest_limits(): array {
    $system = function_exists('slim_system_config') ? slim_system_config() : [];

    return [
        'rate_limit_enabled' => array_key_exists('ingest_rate_limit_enabled', $system)
            ? (bool)$system['ingest_rate_limit_enabled']
            : true,
        // Per-IP requests/minute across all stations. Generous relative to
        // any single station's real posting interval, since one IP may
        // front several stations (a shared cellular gateway, for example).
        'rate_limit_per_minute' => max(
            5,
            min(1200, (int)($system['ingest_rate_limit_per_minute'] ?? 120))
        ),
        // Failed-authentication throttling: distinct from the throughput
        // limit above. This targets API-key guessing specifically, where a
        // burst of *invalid* attempts against one IP is far more suspicious
        // than a burst of valid ones.
        'auth_max_failures' => max(
            3,
            min(100, (int)($system['ingest_auth_max_failures'] ?? 20))
        ),
        'auth_lockout_window' => max(
            60,
            min(3600, (int)($system['ingest_auth_lockout_window'] ?? 600))
        )
    ];
}

function slim_ip_in_cidr(string $ip,string $network): bool {
    $ip=trim($ip);
    $network=trim($network);
    if($ip==='' || $network==='') return false;

    if(strpos($network,'/')===false) {
        return filter_var($network,FILTER_VALIDATE_IP)!==false
            && inet_pton($ip)!==false
            && hash_equals(inet_pton($network),inet_pton($ip));
    }

    [$base,$prefixText]=array_pad(explode('/',$network,2),2,'');
    if(filter_var($base,FILTER_VALIDATE_IP)===false || !ctype_digit($prefixText)) return false;

    $ipBin=@inet_pton($ip);
    $baseBin=@inet_pton($base);
    if($ipBin===false || $baseBin===false || strlen($ipBin)!==strlen($baseBin)) return false;

    $prefix=(int)$prefixText;
    $bits=strlen($ipBin)*8;
    if($prefix<0 || $prefix>$bits) return false;

    $whole=intdiv($prefix,8);
    $remainder=$prefix%8;
    if($whole>0 && substr($ipBin,0,$whole)!==substr($baseBin,0,$whole)) return false;
    if($remainder===0) return true;

    $mask=(0xFF << (8-$remainder)) & 0xFF;
    return (ord($ipBin[$whole]) & $mask)===(ord($baseBin[$whole]) & $mask);
}

function slim_trusted_proxy_ranges(): array {
    try { $system=function_exists('slim_system_config') ? slim_system_config() : []; }
    catch(Throwable $e) { $system=[]; }
    $configured=$system['trusted_proxies'] ?? [];
    if(is_string($configured)) {
        $configured=preg_split('/[\s,]+/',$configured,-1,PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if(!is_array($configured)) return [];

    $out=[];
    foreach($configured as $range) {
        $range=trim((string)$range);
        if($range!=='') $out[]=$range;
    }
    return $out;
}

function slim_ip_is_trusted_proxy(string $ip): bool {
    if(filter_var($ip,FILTER_VALIDATE_IP)===false) return false;
    foreach(slim_trusted_proxy_ranges() as $range) {
        if(slim_ip_in_cidr($ip,$range)) return true;
    }
    return false;
}

/**
 * Trust forwarded HTTPS only when the immediate connection comes from a
 * configured trusted reverse proxy. Direct TLS always takes precedence.
 */
function slim_request_is_https(): bool {
    $https=strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    if($https!=='' && $https!=='off' && $https!=='0') return true;

    $remote=trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if(!slim_ip_is_trusted_proxy($remote)) return false;

    // Only accept the single protocol asserted by the immediate trusted peer;
    // comma-separated chains or unexpected values are not authoritative.
    return strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')))==='https';
}

function slim_client_ip(): string {
    $remote=trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if(filter_var($remote,FILTER_VALIDATE_IP)===false) return $remote!=='' ? $remote : 'unknown';

    // Never trust a forwarding header from an ordinary client. X-Forwarded-For is
    // considered only when the web server's direct peer is explicitly configured
    // as a trusted proxy/load balancer.
    if(!slim_ip_is_trusted_proxy($remote)) return $remote;

    $forwarded=trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if($forwarded==='') return $remote;

    $chain=[];
    foreach(explode(',',$forwarded) as $candidate) {
        $candidate=trim($candidate);
        if(filter_var($candidate,FILTER_VALIDATE_IP)!==false) $chain[]=$candidate;
    }
    if(!$chain) return $remote;

    // Walk from the server outward through the proxy chain. Trusted intermediary
    // addresses are skipped; the first untrusted address is the effective client.
    // This prevents a client-supplied leftmost XFF value from overriding a value
    // appended by a trusted reverse proxy.
    $chain[]=$remote;
    for($i=count($chain)-1;$i>=0;$i--) {
        if(!slim_ip_is_trusted_proxy($chain[$i])) return $chain[$i];
    }

    // If every address in the chain was explicitly trusted, retain the leftmost
    // forwarded address rather than collapsing all traffic back to REMOTE_ADDR.
    return $chain[0];
}

function slim_rate_limit(string $scope='public', ?int $overrideLimit=null, ?bool $overrideEnabled=null): void {
    $limits=slim_public_limits();

    $enabled = $overrideEnabled ?? !empty($limits['rate_limit_enabled']);

    if(!$enabled) {
        return;
    }

    $limit=$overrideLimit ?? (int)$limits['rate_limit_per_minute'];
    $bucket=gmdate('YmdHi');
    $ip=slim_client_ip();

    $dir=lakestat_config_path(
        'runtime'.DIRECTORY_SEPARATOR.'rate_limit'
    );

    if(!is_dir($dir) && !@mkdir($dir,0750,true) && !is_dir($dir)) {
        // Fail open if the shared host does not allow counter-file creation.
        return;
    }

    $filename=hash('sha256',$scope.'|'.$ip.'|'.$bucket).'.count';
    $path=$dir.DIRECTORY_SEPARATOR.$filename;

    $fh=@fopen($path,'c+');
    if($fh===false) {
        return;
    }

    $count=0;

    if(flock($fh,LOCK_EX)) {
        rewind($fh);
        $raw=stream_get_contents($fh);
        $count=is_numeric(trim((string)$raw)) ? (int)trim((string)$raw) : 0;
        $count++;

        ftruncate($fh,0);
        rewind($fh);
        fwrite($fh,(string)$count);
        fflush($fh);
        flock($fh,LOCK_UN);
    }

    fclose($fh);
    @chmod($path,0640);

    if($count>$limit) {
        if(!headers_sent()) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            header('Retry-After: 60');
        }

        echo "Too many requests. Please wait a minute and try again.\n";
        exit;
    }

    // Clean old minute buckets occasionally instead of scanning every request.
    if(random_int(1,100)===1) {
        $cutoff=time()-7200;

        foreach(glob($dir.DIRECTORY_SEPARATOR.'*.count') as $old) {
            $mtime=@filemtime($old);
            if($mtime!==false && $mtime<$cutoff) {
                @unlink($old);
            }
        }
    }
}



function slim_export_limits(): array {
    $site = function_exists('lakestat_site_config')
        ? lakestat_site_config()
        : [];

    return [
        'max_days' => max(1, min(3650, (int)($site['export_max_days'] ?? 180))),
        'max_rows_per_station' => max(1000, min(1000000, (int)($site['export_max_rows_per_station'] ?? 100000))),
        'max_total_rows' => max(1000, min(2000000, (int)($site['export_max_total_rows'] ?? 250000))),
        'max_stations' => max(1, min(50, (int)($site['export_max_stations'] ?? 10)))
    ];
}

function slim_export_station_row_count(PDO $pdo, array $station, string $startUtc, string $endUtc): int {
    lakestat_optimize_station_table($pdo,$station);
    $table = lakestat_safe_identifier((string)$station['table']);

    $sql = "SELECT COUNT(*) AS n
            FROM `{$table}`
            WHERE `observed_at` >= :start
              AND `observed_at` < :end";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $startUtc,
        ':end' => $endUtc
    ]);

    return (int)$stmt->fetchColumn();
}

function slim_export_station_columns(PDO $pdo, array $station): array {
    $table = lakestat_safe_identifier((string)$station['table']);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['Field'])) {
            $columns[] = (string)$row['Field'];
        }
    }

    return $columns;
}



function slim_import_limits(): array {
    $site = function_exists('lakestat_site_config')
        ? lakestat_site_config()
        : [];

    return [
        'max_file_bytes' => max(
            1024 * 1024,
            min(100 * 1024 * 1024, (int)($site['import_max_file_bytes'] ?? 50 * 1024 * 1024))
        ),
        'max_rows' => max(
            1000,
            min(1000000, (int)($site['import_max_rows'] ?? 100000))
        ),
        'max_columns' => max(
            5,
            min(200, (int)($site['import_max_columns'] ?? 60))
        )
    ];
}

function slim_import_safe_field_name(string $value): string {
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9_]+/', '_', $value);
    $value = trim((string)$value, '_');

    if ($value === '') {
        return '';
    }

    if (preg_match('/^[0-9]/', $value)) {
        $value = 'f_' . $value;
    }

    return substr($value, 0, 48);
}

function slim_import_scan_csv(string $path, int $maxRows, int $maxColumns, int $previewRows=20): array {
    $fh=fopen($path,'r');
    if($fh===false) throw new RuntimeException('Unable to open uploaded CSV.');
    $header=fgetcsv($fh);
    if($header===false){fclose($fh);throw new RuntimeException('CSV file is empty.');}
    $header=array_map(function($v){return trim((string)$v);},$header);
    if(count($header)>$maxColumns){fclose($fh);throw new RuntimeException('CSV contains '.count($header).' columns; current maximum is '.$maxColumns.'.');}
    $preview=[];$rowCount=0;
    while(($row=fgetcsv($fh))!==false){
        $rowCount++;
        if($rowCount>$maxRows){fclose($fh);throw new RuntimeException('CSV contains more than the current maximum of '.$maxRows.' data rows.');}
        if(count($preview)<$previewRows){$row=array_pad($row,count($header),null);$preview[]=array_slice($row,0,count($header));}
    }
    fclose($fh);
    return ['header'=>$header,'preview_rows'=>$preview,'row_count'=>$rowCount];
}

function slim_import_stream_rows(string $path, array $expectedHeader): Generator {
    $fh=fopen($path,'r');
    if($fh===false) throw new RuntimeException('Unable to reopen uploaded CSV.');
    $header=fgetcsv($fh);
    if($header===false){fclose($fh);throw new RuntimeException('CSV file is empty.');}
    $header=array_map(function($v){return trim((string)$v);},$header);
    if($header!==$expectedHeader){fclose($fh);throw new RuntimeException('CSV header changed between preview and import.');}
    while(($row=fgetcsv($fh))!==false){$row=array_pad($row,count($header),null);yield array_slice($row,0,count($header));}
    fclose($fh);
}

function slim_import_detect_type(array $values): string {
    $numeric = 0;
    $nonEmpty = 0;

    foreach ($values as $value) {
        $value = trim((string)$value);

        if ($value === '') continue;

        $nonEmpty++;

        if (is_numeric($value)) {
            $numeric++;
        }
    }

    if ($nonEmpty > 0 && $numeric === $nonEmpty) {
        return 'number';
    }

    return 'text';
}

function slim_import_parse_timestamp(string $value): ?string {
    $value = trim($value);

    if ($value === '') return null;

    $ts = strtotime($value);

    if ($ts === false) return null;

    return gmdate('Y-m-d H:i:s', $ts);
}


function slim_system_config(): array {
    $defaults = [
        'dashboard_name' => 'Environmental Monitoring Dashboard',
        'organization' => '',
        'timezone' => 'America/Chicago',
        'refresh_seconds' => 600,
        'auto_refresh_enabled' => true,
        'graph_hours' => 72,
        'max_plot_days' => 30,
        'require_https' => true,
        'allow_get' => false,
        'allow_form_post' => true,
        'allow_json_post' => true
    ];

    $path = lakestat_config_path('system.php');

    if (!is_file($path)) {
        return $defaults;
    }

    $config = require $path;

    if (!is_array($config)) {
        return $defaults;
    }

    return array_merge($defaults,$config);
}

function slim_timezone_identifiers(): array {
    return array_values(array_unique(array_merge(['UTC'],DateTimeZone::listIdentifiers())));
}

function slim_valid_timezone(string $timezone): string {
    $timezone=trim($timezone);

    if($timezone!=='UTC' && !in_array($timezone,DateTimeZone::listIdentifiers(),true)) {
        throw new RuntimeException('Please select a valid time zone.');
    }

    return $timezone;
}



function slim_help_button(string $title,string $text): string {
    $t=htmlspecialchars($title,ENT_QUOTES,'UTF-8');
    $x=htmlspecialchars($text,ENT_QUOTES,'UTF-8');
    return '<button type="button" class="slim-help-button" data-help-title="'.$t.'" data-help-text="'.$x.'" title="'.$x.'" aria-label="Help: '.$t.'">?</button>';
}

function slim_admin_help_assets(): string {
    return <<<'HTML'
<style>
.slim-help-button{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;margin-left:6px;padding:0;border:1px solid #888;border-radius:50%;background:#fff;color:#333;font:700 13px/1 Arial,sans-serif;vertical-align:middle;cursor:pointer}
.slim-help-button:hover,.slim-help-button:focus{border-color:#222;background:#f2f2f2}.slim-help-button:focus-visible,.slim-help-close:focus-visible{outline:3px solid #1b66c9;outline-offset:2px}
.slim-help-modal[hidden]{display:none!important}
.slim-help-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.48)}
.slim-help-dialog{width:min(560px,100%);max-height:80vh;overflow:auto;background:#fff;color:#222;border-radius:10px;box-shadow:0 18px 60px rgba(0,0,0,.28);padding:22px 24px}
.slim-help-dialog-header{display:flex;justify-content:space-between;gap:18px;margin-bottom:12px}
.slim-help-dialog h2{margin:0;font-size:20px}
.slim-help-close{border:0;background:transparent;font-size:26px;line-height:1;cursor:pointer;color:#555}
.slim-help-body{white-space:pre-line;line-height:1.55;color:#333}
</style>
<div id="slim-help-modal" class="slim-help-modal" hidden aria-hidden="true">
  <div class="slim-help-dialog" role="dialog" aria-modal="true" aria-labelledby="slim-help-title">
    <div class="slim-help-dialog-header">
      <h2 id="slim-help-title">Help</h2>
      <button type="button" class="slim-help-close" aria-label="Close help">&times;</button>
    </div>
    <div id="slim-help-body" class="slim-help-body"></div>
  </div>
</div>
<script>
(function(){
 const modal=document.getElementById('slim-help-modal'); if(!modal)return;
 const title=document.getElementById('slim-help-title'), body=document.getElementById('slim-help-body'), close=modal.querySelector('.slim-help-close');
 let last=null;
 function openHelp(btn){last=btn;title.textContent=btn.dataset.helpTitle||'Help';body.textContent=btn.dataset.helpText||'';modal.hidden=false;modal.setAttribute('aria-hidden','false');close.focus();}
 function closeHelp(){modal.hidden=true;modal.setAttribute('aria-hidden','true');if(last)last.focus();}
 document.querySelectorAll('.slim-help-button').forEach(function(btn){
   btn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();openHelp(btn);});
 });
 close.addEventListener('click',function(){closeHelp();});
 modal.addEventListener('click',function(e){if(e.target===modal)closeHelp();});
 document.addEventListener('keydown',function(e){if(modal.hidden)return;if(e.key==='Escape'){closeHelp();return;}if(e.key==='Tab'){const f=[...modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex=\"-1\"])')].filter(el=>!el.disabled);if(!f.length)return;const first=f[0],lastEl=f[f.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();lastEl.focus();}else if(!e.shiftKey&&document.activeElement===lastEl){e.preventDefault();first.focus();}}});
})();

// Keep the Admin PHP session active while an Admin page remains open.
// The endpoint is resolved from the current /admin/ path so SLIM can live in
// any subdirectory on shared hosting.
(function(){
    const marker='/admin/';
    const pos=window.location.pathname.indexOf(marker);
    if(pos<0)return;

    const url=window.location.pathname.slice(0,pos+marker.length)+'keepalive.php';

    function slimAdminKeepalive(){
        fetch(url,{
            method:'GET',
            credentials:'same-origin',
            cache:'no-store',
            headers:{'X-SLIM-Keepalive':'1'}
        }).catch(function(){});
    }

    // Ten minutes is comfortably below typical shared-host PHP session cleanup.
    window.setInterval(slimAdminKeepalive,10*60*1000);
})();
</script>
HTML;
}
