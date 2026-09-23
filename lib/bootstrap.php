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
      . "script-src 'self' 'unsafe-inline' https://code.highcharts.com https://cdnjs.cloudflare.com https://unpkg.com; "
      . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://unpkg.com https://fonts.googleapis.com; "
      . "font-src 'self' data: https://fonts.gstatic.com; "
      . "img-src 'self' data: https:; "
      . "connect-src 'self'; "
      . "object-src 'none'; base-uri 'self'; form-action 'self'; "
      . "frame-ancestors 'self'"
    );

    $slimHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if ($slimHttps) {
        header('Strict-Transport-Security: max-age=31536000');
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
            throw new RuntimeException(
                'config/EXTERNAL_CONFIG_PATH points to "' . $pointed . '", '
                . 'which does not exist or is not a directory. Fix the path '
                . 'in that file, or remove it to use the default location.'
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

function lakestat_write_php_config(string $path, array $data): void {
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create configuration directory.');
    }

    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
         . var_export($data, true)
         . ";\n";

    $tmp = $path . '.tmp';

    if (file_put_contents($tmp, $php, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary configuration file.');
    }

    $check = require $tmp;

    if (!is_array($check)) {
        @unlink($tmp);
        throw new RuntimeException('Generated configuration failed validation.');
    }

    if (is_file($path)) {
        @copy($path, $path . '.bak');
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to activate configuration file.');
    }

    @chmod($path, 0640);

    // PHP OPcache can continue serving the previously compiled version of a
    // configuration file for a short time after it has been replaced. That
    // makes newly added/edited displays or station settings appear stale until
    // several page refreshes have occurred. Clear filesystem metadata and
    // explicitly invalidate this config path whenever possible.
    clearstatcache(true, $path);

    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
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

    return new PDO(
        $dsn,
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
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

        fputcsv($fh, array_values($row));
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

    $firstLine='';
    $fh=@fopen($path,'rb');
    if($fh!==false) {
        $firstLine=trim((string)fgets($fh));
        fclose($fh);
    }

    // VERSION.txt uses "SLIM v0.xx.xx"; footers need only "v0.xx.xx".
    if(preg_match('/\b(v[0-9]+(?:\.[0-9]+)+)\b/i',$firstLine,$m)) {
        return $version=$m[1];
    }
    return $version='';
}

function slim_footer_text(bool $powered=true): string {
    $version=slim_version();
    return 'Powered by SLIM'.($version!=='' ? ' · '.$version : '');
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
        '/admin/appearance/index.php','/admin/site/index.php','/admin/index.php',
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

    // Constrained public-page HTML: formatting, links, and images only.
    // Scriptable/embedded content is never allowed.
    $allowedTags='<p><br><h2><h3><h4><strong><b><em><i><u><ul><ol><li><blockquote><a><img>';
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
        if($tag==='img' && $el->hasAttribute('src')) {
            $url=trim($el->getAttribute('src'));
            if(!preg_match('#^(https?://|/|\\.\\.?/)#i',$url)) $el->removeAttribute('src');
            $class=trim($el->getAttribute('class'));
            if(!in_array($class,['slim-img-third','slim-img-half','slim-img-full'],true)) {
                $el->removeAttribute('class');
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
function lakestat_apply_derived_variables(array $station, array $values): array {
    $rules=is_array($station['derived_variables'] ?? null)
        ? $station['derived_variables']
        : [];

    if(!$rules) return $values;

    foreach($rules as $targetKey=>$rule) {
        if(!is_array($rule)) continue;

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
        'timeout'=>5
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

function lakestat_webhook_payload(array $station, array $data, ?string $observedAt=null): array {
    $config=lakestat_webhook_config($station);
    $selected=$config['fields'];
    $payload=[
        'station'=>(string)$station['code'],
        'timestamp'=>$data['timestamp'] ?? ($observedAt !== null ? gmdate('c',strtotime($observedAt.' UTC')) : null)
    ];

    foreach($station['fields'] as $key=>$field) {
        $jsonKey=(string)($field['json_key'] ?? $key);

        if($selected && !in_array($jsonKey,$selected,true)) continue;
        if(array_key_exists($jsonKey,$data)) $payload[$jsonKey]=$data[$jsonKey];
    }

    return $payload;
}

function lakestat_webhook_enqueue(PDO $pdo, array $station, array $payload): void {
    $config=lakestat_webhook_config($station);
    if(empty($config['enabled']) || trim((string)$config['url'])==='') return;

    lakestat_webhook_ensure_table($pdo);

    $stmt=$pdo->prepare(
        "INSERT INTO `lakestat_webhook_queue`
        (`station`,`endpoint`,`headers_json`,`payload_json`)
        VALUES (:station,:endpoint,:headers,:payload)"
    );

    $stmt->execute([
        ':station'=>(string)$station['code'],
        ':endpoint'=>(string)$config['url'],
        ':headers'=>json_encode($config['headers'],JSON_UNESCAPED_SLASHES),
        ':payload'=>json_encode($payload,JSON_UNESCAPED_SLASHES)
    ]);
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

    $ch=curl_init($url);
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
    $error=curl_error($ch);
    curl_close($ch);

    return [
        'ok'=>$body!==false && $http>=200 && $http<300,
        'http_code'=>$http,
        'error'=>$body===false ? $error : (($http>=200 && $http<300) ? '' : substr((string)$body,0,1000))
    ];
}


function slim_public_limits(): array {
    $site = function_exists('lakestat_site_config') ? lakestat_site_config() : [];

    return [
        'max_dashboard_hours' => max(
            1,
            min(8760, (int)($site['max_dashboard_hours'] ?? 720))
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

// A fixed-length placeholder used when no real API key exists to compare
// against (unknown station). Comparing against this rather than skipping
// the comparison keeps response timing similar for "unknown station" and
// "known station, wrong key", so neither can be distinguished by an
// observer measuring response latency.
const SLIM_INGEST_DUMMY_KEY = '0000000000000000000000000000000000000000000000';

function slim_ingest_throttle_dir(): ?string {
    $dir = lakestat_config_path('runtime' . DIRECTORY_SEPARATOR . 'ingest_auth');

    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }

    return $dir;
}

function slim_ingest_auth_locked(): int {
    $limits = slim_ingest_limits();
    $dir = slim_ingest_throttle_dir();

    if ($dir === null) {
        return 0;
    }

    $key = hash('sha256', slim_client_ip());
    $path = $dir . DIRECTORY_SEPARATOR . $key . '.json';

    if (!is_file($path)) {
        return 0;
    }

    $state = json_decode((string)@file_get_contents($path), true);

    if (!is_array($state)) {
        return 0;
    }

    $elapsed = time() - (int)($state['first'] ?? 0);

    if ($elapsed > $limits['auth_lockout_window']) {
        return 0;
    }

    if ((int)($state['failures'] ?? 0) < $limits['auth_max_failures']) {
        return 0;
    }

    return $limits['auth_lockout_window'] - $elapsed;
}

function slim_ingest_record_auth_failure(): void {
    $limits = slim_ingest_limits();
    $dir = slim_ingest_throttle_dir();

    if ($dir === null) {
        return;
    }

    $key = hash('sha256', slim_client_ip());
    $path = $dir . DIRECTORY_SEPARATOR . $key . '.json';

    $state = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;

    if (!is_array($state) || (time() - (int)($state['first'] ?? 0)) > $limits['auth_lockout_window']) {
        $state = ['first' => time(), 'failures' => 0];
    }

    $state['failures'] = (int)$state['failures'] + 1;

    @file_put_contents($path, json_encode($state), LOCK_EX);
    @chmod($path, 0640);
}

function slim_ingest_clear_auth_failures(): void {
    $dir = slim_ingest_throttle_dir();

    if ($dir === null) {
        return;
    }

    $path = $dir . DIRECTORY_SEPARATOR . hash('sha256', slim_client_ip()) . '.json';

    if (is_file($path)) {
        @unlink($path);
    }
}

function slim_client_ip(): string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
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
    $table = lakestat_safe_identifier((string)$station['table']);

    $sql = "SELECT COUNT(*) AS n
            FROM `{$table}`
            WHERE COALESCE(`observed_at`,`received_at`) >= :start
              AND COALESCE(`observed_at`,`received_at`) < :end";

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
        'refresh_seconds' => 120,
        'graph_hours' => 72,
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
    return DateTimeZone::listIdentifiers();
}

function slim_valid_timezone(string $timezone): string {
    $timezone=trim($timezone);

    if(!in_array($timezone,DateTimeZone::listIdentifiers(),true)) {
        throw new RuntimeException('Please select a valid time zone.');
    }

    return $timezone;
}



function slim_help_button(string $title,string $text): string {
    $t=htmlspecialchars($title,ENT_QUOTES,'UTF-8');
    $x=htmlspecialchars($text,ENT_QUOTES,'UTF-8');
    return '<button type="button" class="slim-help-button" data-help-title="'.$t.'" data-help-text="'.$x.'" aria-label="Help: '.$t.'">?</button>';
}

function slim_admin_help_assets(): string {
    return <<<'HTML'
<style>
.slim-help-button{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;margin-left:6px;padding:0;border:1px solid #888;border-radius:50%;background:#fff;color:#333;font:700 13px/1 Arial,sans-serif;vertical-align:middle;cursor:pointer}
.slim-help-button:hover,.slim-help-button:focus{border-color:#222;background:#f2f2f2}
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
 document.addEventListener('click',function(e){const btn=e.target.closest('.slim-help-button');if(btn){openHelp(btn);return;}if(e.target===modal||e.target===close)closeHelp();});
 document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)closeHelp();});
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
