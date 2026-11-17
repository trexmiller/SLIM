<?php
// Shared renderer for both public and administrator-only health dashboards.
// Entry-point wrappers set $slimDashboardMode before including this file.
$slimDashboardMode = ($slimDashboardMode ?? 'public') === 'health' ? 'health' : 'public';
$slimHealthDashboard = $slimDashboardMode === 'health';
$slimDashboardAssetPrefix = $slimHealthDashboard ? '../../' : '../';
$slimDashboardAllStationsHref = $slimHealthDashboard ? '../stations/' : '../';
$publicLimits=slim_public_limits();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function nval($v) {
    return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
}

function cardinal_direction($deg): string {
    $names = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    $deg = fmod(((float)$deg + 360.0), 360.0);
    $idx = (int)round($deg / 22.5) % 16;
    return $names[$idx];
}

function display_width_class(array $display): string {
    $width=(string)($display['width'] ?? 'full');
    return in_array($width,['full','half','third'],true) ? $width : 'full';
}


function lakestat_theme_colors(array $appearance): array {
    $themes = [
        'lakestat_dark' => [
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
        'lakestat_light' => [
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
        'freshwater' => [
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
        'neutral' => [
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
$site=lakestat_site_config();
$system=slim_system_config();
// Health dashboards are administrator-only and may request long, full-resolution ranges.
// Public dashboards use their own configurable date-range cap and point limit.
$maxPlotDays = $slimHealthDashboard
    ? max(1,min(3650,(int)($system['max_plot_days'] ?? 3650)))
    : max(1,min(3650,(int)($publicLimits['max_dashboard_days'] ?? 30)));

// The headquarters timezone is a fallback for legacy stations without a timezone.
$systemTimezone=(string)($system['timezone'] ?? 'UTC');
try { $displayTimezone=new DateTimeZone($systemTimezone); }
catch(Throwable $e) { $systemTimezone='UTC'; $displayTimezone=new DateTimeZone('UTC'); }
$stationTimezone=$systemTimezone;
$themeColors=lakestat_theme_colors($appearance);
$dateRangeError='';
$customDateRange=false;
$rangeStartLocal='';
$rangeEndLocal='';
$rangeStartUtc=null;
$rangeEndUtc=null;

$error='';
$stations=[];
$stationCode='';
$station=null;
$displayConfig=['displays'=>[]];

$lineData=[];
$gaugeData=[];
$profileData=[];
$mapData=[];
$polarData=[];
$directionalRecentData=[];

$latestTime=null;
$latestDisplayTime=null;
$stationOnline=false;
$onlineThresholdHours=3;

try {
    $stations = lakestat_list_stations();
    $adminPreview = $slimHealthDashboard
        || (function_exists('slim_optional_admin_authenticated') && slim_optional_admin_authenticated());

    if (isset($_GET['station'])) {
        $requestedCode = lakestat_station_code((string)$_GET['station']);
        $requestedStation = lakestat_station_config($requestedCode);

        if(!$slimHealthDashboard && empty($requestedStation['published']) && !$adminPreview) {
            http_response_code(404);
            $error='Dashboard not available.';
        } else {
            $stationCode=$requestedCode;
            $station=$requestedStation;
        }
    } elseif ($stations) {
        // A bare public /dashboard/ URL always resolves to the first published
        // station. Being signed in does not make an unpublished station the
        // accidental default; administrators can preview one by requesting it.
        foreach($stations as $candidateCode=>$candidateSummary) {
            $candidate=lakestat_station_config((string)$candidateCode);
            if($slimHealthDashboard || !empty($candidate['published'])) {
                $stationCode=(string)$candidateCode;
                $station=$candidate;
                break;
            }
        }
        if($stationCode==='' && !$slimHealthDashboard) {
            http_response_code(404);
            $error='Dashboard not available.';
        }
    }

    if ($stationCode !== '' && $station !== null) {
        $candidateTimezone=(string)($station['timezone'] ?? '');
        if($candidateTimezone!=='') {
            try {
                $stationTimezone=slim_valid_timezone($candidateTimezone);
                $displayTimezone=new DateTimeZone($stationTimezone);
            } catch(Throwable $e) {
                // Invalid legacy configuration falls back to headquarters timezone.
                $stationTimezone=$systemTimezone;
            }
        }
        // Convert local calendar boundaries only after selecting the station timezone.

if(isset($_GET['date_from']) || isset($_GET['date_to'])) {
    $rangeStartLocal=trim((string)($_GET['date_from'] ?? ''));
    $rangeEndLocal=trim((string)($_GET['date_to'] ?? ''));

    if($rangeStartLocal!=='' || $rangeEndLocal!=='') {
        try {
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$rangeStartLocal) ||
               !preg_match('/^\d{4}-\d{2}-\d{2}$/',$rangeEndLocal)) {
                throw new RuntimeException('Choose both a From and To date.');
            }

            $startLocal=DateTimeImmutable::createFromFormat('!Y-m-d',$rangeStartLocal,$displayTimezone);
            $endLocal=DateTimeImmutable::createFromFormat('!Y-m-d',$rangeEndLocal,$displayTimezone);

            if($startLocal===false || $endLocal===false) {
                throw new RuntimeException('One of the selected dates is invalid.');
            }
            if($endLocal<$startLocal) {
                throw new RuntimeException('The To date must be on or after the From date.');
            }

            $days=(int)$startLocal->diff($endLocal)->format('%a')+1;
            if($days>$maxPlotDays) {
                throw new RuntimeException('Dashboard date range is limited to a maximum of '.$maxPlotDays.' days.');
            }

            $rangeStartUtc=$startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $rangeEndUtc=$endLocal->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $customDateRange=true;
        } catch(Throwable $e) {
            $dateRangeError=$e->getMessage();
        }
    }
}

        $displayConfig = $slimHealthDashboard ? lakestat_health_display_config($stationCode) : lakestat_display_config($stationCode);

        $pdo = lakestat_db();
        $table = lakestat_safe_identifier((string)$station['table']);
        // Public and health dashboard requests must be read-only.
        // Legacy station backfills/index creation belong in administrative maintenance.

        // The date-range selector is based on observation time, not the time
        // the row happened to arrive in SLIM. This is especially important
        // for historical CSV imports, where received_at may be months later.
        if(!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
            $defaultObserved=$pdo->query(
                "SELECT MAX(`observed_at`) FROM `{$table}`"
            )->fetchColumn();

            if($defaultObserved!==false && $defaultObserved!==null && $defaultObserved!=='') {
                $defaultUtc=DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    (string)$defaultObserved,
                    new DateTimeZone('UTC')
                );
                if($defaultUtc!==false) {
                    $defaultLocal=$defaultUtc->setTimezone($displayTimezone);
                    $rangeEndLocal=$defaultLocal->format('Y-m-d');

                    $defaultHours=max(1,(int)($system['graph_hours'] ?? 72));
                    $rangeStartLocal=$defaultLocal
                        ->modify('-'.$defaultHours.' hours')
                        ->format('Y-m-d');
                }
            }
        }

        // Online/offline status is based on data ARRIVAL at SLIM, not
        // observation time. Historical imports may contain old observed_at
        // values even though they were received today.
        $latestStmt = $pdo->query(
            "SELECT MAX(`received_at`) AS event_time
             FROM `{$table}`
             WHERE `received_at` IS NOT NULL"
        );

        $latestRow = $latestStmt->fetch();
        if ($latestRow && !empty($latestRow['event_time'])) {
            $latestTime = $latestRow['event_time'];

            $latestUtc=DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string)$latestTime,
                new DateTimeZone('UTC')
            );

            if($latestUtc!==false) {
                $latestDisplayTime=$latestUtc
                    ->setTimezone($displayTimezone)
                    ->format('M j, Y g:i:s A T');

                $nowUtc=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                $stationOnline=(
                    $latestUtc <= $nowUtc->modify('+5 minutes') &&
                    $latestUtc >= $nowUtc->modify('-'.$onlineThresholdHours.' hours')
                );
            }
        }

        // Gauges and profiles all operate on recent observations. Fetch the largest
        // requested window once and share it across those displays instead of issuing
        // one ORDER BY/LIMIT query for every gauge/profile field.
        $recentFields=[];
        $recentMaxN=0;
        foreach(($displayConfig['displays'] ?? []) as $recentDisplay) {
            $recentType=(string)($recentDisplay['type'] ?? '');
            if($recentType==='gauge') {
                $field=(string)($recentDisplay['field'] ?? '');
                if($field!=='' && isset($station['fields'][$field])) {
                    $recentFields[$field]=lakestat_safe_identifier($field);
                    $recentMaxN=max($recentMaxN,max(1,(int)($recentDisplay['recent_n'] ?? 3)));
                }
            } elseif($recentType==='profile') {
                $recentMaxN=max($recentMaxN,max(1,(int)($recentDisplay['recent_n'] ?? 1)));
                foreach(($recentDisplay['points'] ?? []) as $point) {
                    $field=(string)($point['field'] ?? '');
                    if($field!=='' && isset($station['fields'][$field])) {
                        $recentFields[$field]=lakestat_safe_identifier($field);
                    }
                }
            }
        }
        $recentRows=[];
        if($recentMaxN>0 && $recentFields) {
            $recentSelect=array_map(static fn($f)=>"`{$f}`",array_values($recentFields));
            $recentSql="SELECT ".implode(', ',$recentSelect)."
                        FROM `{$table}`
                        ORDER BY `observed_at` DESC
                        LIMIT {$recentMaxN}";
            $recentRows=$pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($displayConfig['displays'] as $display) {
            $id = $display['id'] ?? '';
            $type = $display['type'] ?? '';

            if ($type === 'map') {
                $mapInfo=[
                    'latitude'=>$station['latitude'] ?? null,
                    'longitude'=>$station['longitude'] ?? null,
                    'source'=>'static'
                ];
                if(($display['location_mode'] ?? 'static')==='live') {
                    $latField=(string)($display['latitude_field'] ?? '');
                    $lonField=(string)($display['longitude_field'] ?? '');
                    if($latField!=='' && $lonField!=='' && isset($station['fields'][$latField],$station['fields'][$lonField])) {
                        $safeLat=lakestat_safe_identifier($latField);
                        $safeLon=lakestat_safe_identifier($lonField);
                        $gps=$pdo->query("SELECT `{$safeLat}` AS lat, `{$safeLon}` AS lon, `observed_at`
                                         FROM `{$table}` ORDER BY `observed_at` DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                        $lat=$gps['lat'] ?? null; $lon=$gps['lon'] ?? null;
                        $valid=is_numeric($lat) && is_numeric($lon) && (float)$lat!=0.0 && (float)$lon!=0.0;
                        if($valid) {
                            $mapInfo=['latitude'=>(float)$lat,'longitude'=>(float)$lon,'source'=>'live','observed_at'=>$gps['observed_at'] ?? null];
                        } elseif(empty($display['fallback_static'])) {
                            $mapInfo=['latitude'=>null,'longitude'=>null,'source'=>'none'];
                        } else {
                            $mapInfo['source']='fallback';
                        }
                    } elseif(empty($display['fallback_static'])) {
                        $mapInfo=['latitude'=>null,'longitude'=>null,'source'=>'none'];
                    } else {
                        $mapInfo['source']='fallback';
                    }
                }
                $mapData[$id]=$mapInfo;
                continue;
            }

            if ($type === 'line') {
                $seriesConfig = [];

                if (isset($display['series']) && is_array($display['series'])) {
                    foreach ($display['series'] as $series) {
                        $field = isset($series['field']) ? (string)$series['field'] : '';

                        if ($field === '' || !isset($station['fields'][$field])) {
                            continue;
                        }

                        $safeField = lakestat_safe_identifier($field);
                        $meta = $station['fields'][$field];

                        $seriesConfig[] = [
                            'field' => $safeField,
                            'label' => !empty($series['label'])
                                ? (string)$series['label']
                                : (!empty($meta['label']) ? $meta['label'] : $field),
                            'color' => !empty($series['color'])
                                ? (string)$series['color']
                                : '#3366cc',
                            'line_style' => (string)($series['line_style'] ?? 'solid'),
                            'stroke_width' => (float)($series['stroke_width'] ?? 1.5),
                            'show_points' => !empty($series['show_points']),
                            'point_color' => (string)($series['point_color'] ?? ($series['color'] ?? '#3366cc')),
                            'point_symbol' => (string)($series['point_symbol'] ?? 'circle'),
                            'point_size' => (float)($series['point_size'] ?? 3),
                            'axis' => (($series['axis'] ?? 'y')==='y2') ? 'y2' : 'y',
                            'units' => isset($meta['units']) ? $meta['units'] : ''
                        ];
                    }
                }

                if (!$seriesConfig) continue;

                $safeFields = array_map(function($s){
                    return $s['field'];
                }, $seriesConfig);

                // All time-series plots use the global period unless the viewer
                // selects a calendar date range.
                $hours = max(1,(int)($system['graph_hours'] ?? 72));
                if(!$slimHealthDashboard) {
                    $hours = min($hours,(int)$publicLimits['max_dashboard_hours']);
                }
                $maxRows = (int)$publicLimits['max_dashboard_rows'];
                $wasDownsampled = false;
                $sourceRowCount = 0;
                $sampleMinId = 0;
                $sampleMaxId = 0;

                $select = ['`observed_at`','`received_at`'];

                foreach ($safeFields as $f) {
                    $select[] = "`{$f}`";
                }

                $nonnullParts=[];

                foreach($safeFields as $f) {
                    $nonnullParts[]="`{$f}` IS NOT NULL";
                }

                if($customDateRange && $rangeStartUtc!==null && $rangeEndUtc!==null) {
                    $where = "`observed_at` >= :range_start
                              AND `observed_at` < :range_end
                              AND (".implode(' OR ',$nonnullParts).")";

                    if($slimHealthDashboard) {
                        // Administrator-only health dashboards intentionally return every
                        // observation in the requested interval without downsampling.
                        $sql = "SELECT ".implode(', ', $select)."
                                FROM `{$table}`
                                WHERE {$where}
                                ORDER BY `observed_at` ASC";
                        $stmt=$pdo->prepare($sql);
                        $stmt->execute([
                            ':range_start'=>$rangeStartUtc,
                            ':range_end'=>$rangeEndUtc
                        ]);
                        $rows=$stmt->fetchAll();
                        $sourceRowCount=count($rows);
                    } else {
                        $countSql="SELECT COUNT(*) AS n, MIN(`id`) AS min_id, MAX(`id`) AS max_id
                                   FROM `{$table}` WHERE {$where}";
                        $countStmt=$pdo->prepare($countSql);
                        $countStmt->execute([
                            ':range_start'=>$rangeStartUtc,
                            ':range_end'=>$rangeEndUtc
                        ]);
                        $countMeta=$countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $sourceRowCount=(int)($countMeta['n'] ?? 0);
                        $sampleMinId=(int)($countMeta['min_id'] ?? 0);
                        $sampleMaxId=(int)($countMeta['max_id'] ?? 0);

                        if($sourceRowCount <= $maxRows) {
                            $sql = "SELECT ".implode(', ', $select)."
                                    FROM `{$table}`
                                    WHERE {$where}
                                    ORDER BY `observed_at` ASC";
                            $stmt=$pdo->prepare($sql);
                            $stmt->execute([
                                ':range_start'=>$rangeStartUtc,
                                ':range_end'=>$rangeEndUtc
                            ]);
                            $rows=$stmt->fetchAll();
                        } else {
                            // Systematically select raw observations across the complete
                            // requested interval. Values are not averaged or aggregated.
                            $stride=max(1,(int)ceil($sourceRowCount/$maxRows));
                            // Visualization downsampling uses the monotonic primary key rather
                            // than a ROW_NUMBER() window sort. Gaps in IDs are acceptable here:
                            // this is display sampling, not statistical aggregation.
                            $sql = "SELECT ".implode(', ', $select)."
                                    FROM `{$table}`
                                    WHERE {$where}
                                      AND (MOD(`id` - {$sampleMinId}, {$stride}) = 0 OR `id` = {$sampleMaxId})
                                    ORDER BY `observed_at` ASC";
                            $stmt=$pdo->prepare($sql);
                            $stmt->execute([
                                ':range_start'=>$rangeStartUtc,
                                ':range_end'=>$rangeEndUtc
                            ]);
                            $rows=$stmt->fetchAll();
                            $wasDownsampled=true;
                        }
                    }

                } else {
                    $latestSeriesSql=
                        "SELECT MAX(`observed_at`)
                         FROM `{$table}`
                         WHERE ".implode(' OR ',$nonnullParts);

                    $latestSeriesTime=$pdo->query($latestSeriesSql)->fetchColumn();

                    if($latestSeriesTime!==false && $latestSeriesTime!==null && $latestSeriesTime!=='') {
                        $latestDt=DateTimeImmutable::createFromFormat(
                            'Y-m-d H:i:s',
                            (string)$latestSeriesTime,
                            new DateTimeZone('UTC')
                        );

                        if($latestDt===false) {
                            $latestDt=new DateTimeImmutable(
                                (string)$latestSeriesTime,
                                new DateTimeZone('UTC')
                            );
                        }

                        $cutoff=$latestDt
                            ->sub(new DateInterval('PT'.$hours.'H'))
                            ->format('Y-m-d H:i:s');

                        $where = "`observed_at` >= :cutoff
                                  AND `observed_at` <= :latest
                                  AND (".implode(' OR ',$nonnullParts).")";

                        if($slimHealthDashboard) {
                            $sql = "SELECT ".implode(', ', $select)."
                                    FROM `{$table}` WHERE {$where}
                                    ORDER BY `observed_at` ASC";
                            $stmt=$pdo->prepare($sql);
                            $stmt->execute([':cutoff'=>$cutoff,':latest'=>$latestSeriesTime]);
                            $rows=$stmt->fetchAll();
                            $sourceRowCount=count($rows);
                        } else {
                            $countSql="SELECT COUNT(*) AS n, MIN(`id`) AS min_id, MAX(`id`) AS max_id
                                       FROM `{$table}` WHERE {$where}";
                            $countStmt=$pdo->prepare($countSql);
                            $countStmt->execute([':cutoff'=>$cutoff,':latest'=>$latestSeriesTime]);
                            $countMeta=$countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                            $sourceRowCount=(int)($countMeta['n'] ?? 0);
                            $sampleMinId=(int)($countMeta['min_id'] ?? 0);
                            $sampleMaxId=(int)($countMeta['max_id'] ?? 0);

                            if($sourceRowCount <= $maxRows) {
                                $sql = "SELECT ".implode(', ', $select)."
                                        FROM `{$table}` WHERE {$where}
                                        ORDER BY `observed_at` ASC";
                                $stmt=$pdo->prepare($sql);
                                $stmt->execute([':cutoff'=>$cutoff,':latest'=>$latestSeriesTime]);
                                $rows=$stmt->fetchAll();
                            } else {
                                $stride=max(1,(int)ceil($sourceRowCount/$maxRows));
                                $sql = "SELECT ".implode(', ', $select)."
                                        FROM `{$table}`
                                        WHERE {$where}
                                          AND (MOD(`id` - {$sampleMinId}, {$stride}) = 0 OR `id` = {$sampleMaxId})
                                        ORDER BY `observed_at` ASC";
                                $stmt=$pdo->prepare($sql);
                                $stmt->execute([':cutoff'=>$cutoff,':latest'=>$latestSeriesTime]);
                                $rows=$stmt->fetchAll();
                                $wasDownsampled=true;
                            }
                        }
                    } else {
                        $rows=[];
                    }
                }

                $datasets = [];

                foreach ($seriesConfig as $series) {
                    $f = $series['field'];

                    $datasets[$f] = [
                        'field' => $f,
                        'label' => $series['label'],
                        'color' => $series['color'],
                        'line_style' => $series['line_style'],
                        'stroke_width' => $series['stroke_width'],
                        'show_points' => $series['show_points'],
                        'point_color' => $series['point_color'],
                        'point_symbol' => $series['point_symbol'],
                        'point_size' => $series['point_size'],
                        'axis' => $series['axis'],
                        'units' => $series['units'],
                        'points' => []
                    ];
                }

                foreach ($rows as $row) {
                    $ts = !empty($row['observed_at']) ? $row['observed_at'] : $row['received_at'];

                    $dt = DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        $ts,
                        new DateTimeZone('UTC')
                    );

                    if ($dt === false) continue;

                    $xMillis = $dt->getTimestamp() * 1000;

                    foreach ($safeFields as $f) {
                        $v = nval($row[$f]);

                        if ($v !== null) {
                            $datasets[$f]['points'][] = [
                                'x' => $xMillis,
                                'y' => $v
                            ];
                        }
                    }
                }

                $lineData[$id] = [
                    'datasets' => array_values($datasets),
                    'row_count' => count($rows),
                    'source_row_count' => $sourceRowCount,
                    'downsampled' => $wasDownsampled,
                    'y_label' => isset($display['y_label']) ? (string)$display['y_label'] : '',
                    'y2_label' => isset($display['y2_label']) ? (string)$display['y2_label'] : '',
                    'dygraph' => isset($display['dygraph']) && is_array($display['dygraph'])
                        ? $display['dygraph']
                        : []
                ];
            }

            if ($type === 'gauge') {
                $field = $display['field'] ?? '';

                if ($field === '' || !isset($station['fields'][$field])) {
                    continue;
                }

                $field = lakestat_safe_identifier($field);
                $recentN = max(1, (int)($display['recent_n'] ?? 3));

                $vals = [];
                // Match the previous semantics: take the most recent N non-null
                // values for this field from the shared recent-row cache.
                foreach ($recentRows as $row) {
                    $v = nval($row[$field] ?? null);
                    if ($v !== null) {
                        $vals[] = $v;
                        if(count($vals)>=$recentN) break;
                    }
                }

                $avg = $vals ? array_sum($vals) / count($vals) : null;
                $meta = $station['fields'][$field];

                $gaugeData[$id] = [
                    'value' => $avg,
                    'count' => count($vals),
                    'units' => !empty($display['units']) ? $display['units'] : ($meta['units'] ?? ''),
                    'label' => !empty($meta['label']) ? $meta['label'] : $field
                ];
            }

            if ($type === 'profile') {
                $points = is_array($display['points'] ?? null) ? $display['points'] : [];
                $recentN = max(1, (int)($display['recent_n'] ?? 1));

                $profilePoints = [];
                $valueUnits = '';

                foreach ($points as $point) {
                    $field = $point['field'] ?? '';
                    $position = isset($point['position']) ? nval($point['position']) : null;

                    if (
                        $field === '' ||
                        $position === null ||
                        !isset($station['fields'][$field])
                    ) {
                        continue;
                    }

                    $safeField = lakestat_safe_identifier($field);

                    $vals = [];
                    foreach ($recentRows as $row) {
                        $v = nval($row[$safeField] ?? null);
                        if ($v !== null) {
                            $vals[] = $v;
                            if(count($vals)>=$recentN) break;
                        }
                    }

                    if (!$vals) continue;

                    $avg = array_sum($vals) / count($vals);
                    $meta = $station['fields'][$field];

                    if ($valueUnits === '' && !empty($meta['units'])) {
                        $valueUnits = $meta['units'];
                    }

                    $profilePoints[] = [
                        'field' => $field,
                        'label' => !empty($meta['label']) ? $meta['label'] : $field,
                        'position' => $position,
                        'value' => $avg,
                        'count' => count($vals)
                    ];
                }

                usort($profilePoints, function($a,$b){
                    if ($a['position'] == $b['position']) return 0;
                    return ($a['position'] < $b['position']) ? -1 : 1;
                });

                // Color limits are independent of the current profile. Any blank
                // bound is derived from the full available dataset across all
                // configured profile variables.
                $configuredColorMin = isset($display['color_min']) && $display['color_min'] !== null
                    ? nval($display['color_min'])
                    : null;
                $configuredColorMax = isset($display['color_max']) && $display['color_max'] !== null
                    ? nval($display['color_max'])
                    : null;
                $dataColorMin = null;
                $dataColorMax = null;

                if ($configuredColorMin === null || $configuredColorMax === null) {
                    foreach ($points as $point) {
                        $rangeField = $point['field'] ?? '';
                        if ($rangeField === '' || !isset($station['fields'][$rangeField])) continue;
                        $safeRangeField = lakestat_safe_identifier($rangeField);
                        $rangeSql = "SELECT MIN(`{$safeRangeField}`) AS min_value,
                                            MAX(`{$safeRangeField}`) AS max_value
                                     FROM `{$table}`
                                     WHERE `{$safeRangeField}` IS NOT NULL";
                        $rangeRow = $pdo->query($rangeSql)->fetch();
                        if (!$rangeRow) continue;
                        $fieldMin = nval($rangeRow['min_value'] ?? null);
                        $fieldMax = nval($rangeRow['max_value'] ?? null);
                        if ($fieldMin !== null && ($dataColorMin === null || $fieldMin < $dataColorMin)) $dataColorMin = $fieldMin;
                        if ($fieldMax !== null && ($dataColorMax === null || $fieldMax > $dataColorMax)) $dataColorMax = $fieldMax;
                    }
                }

                $colorMin = $configuredColorMin !== null ? $configuredColorMin : $dataColorMin;
                $colorMax = $configuredColorMax !== null ? $configuredColorMax : $dataColorMax;

                // Safe fallback for an empty or constant dataset.
                if ($colorMin === null || $colorMax === null) {
                    $currentValues = array_column($profilePoints,'value');
                    if ($currentValues) {
                        if ($colorMin === null) $colorMin = min($currentValues);
                        if ($colorMax === null) $colorMax = max($currentValues);
                    }
                }
                if ($colorMin !== null && $colorMax !== null && $colorMin >= $colorMax) {
                    $colorMax = $colorMin + 1.0;
                }

                $coordinateLabel = !empty($display['coordinate_label'])
                    ? $display['coordinate_label']
                    : 'Position';

                $profileData[$id] = [
                    'points' => $profilePoints,
                    'value_units' => $valueUnits,
                    'coordinate_label' => $coordinateLabel,
                    'coordinate_units' => $display['coordinate_units'] ?? '',
                    'reverse_y' => strtolower(trim($coordinateLabel)) === 'depth',
                    'color_min' => $colorMin,
                    'color_max' => $colorMax
                ];
            }

            if ($type === 'polar' || $type === 'directional_recent') {
                $directionField = $display['direction_field'] ?? '';
                $magnitudeField = $display['magnitude_field'] ?? '';

                if (
                    $directionField === '' ||
                    $magnitudeField === '' ||
                    !isset($station['fields'][$directionField]) ||
                    !isset($station['fields'][$magnitudeField])
                ) {
                    continue;
                }

                $directionField = lakestat_safe_identifier($directionField);
                $magnitudeField = lakestat_safe_identifier($magnitudeField);

                $recentN = max(
                    1,
                    (int)($display['recent_n'] ?? ($type === 'polar' ? 30 : 10))
                );

                $sql = "SELECT `{$directionField}`, `{$magnitudeField}`,
                               `observed_at` AS event_time
                        FROM `{$table}`
                        WHERE `{$directionField}` IS NOT NULL
                          AND `{$magnitudeField}` IS NOT NULL
                          AND `observed_at` IS NOT NULL
                        ORDER BY `observed_at` DESC
                        LIMIT {$recentN}";

                $rows = $pdo->query($sql)->fetchAll();
                $points = [];

                foreach ($rows as $row) {
                    $direction = nval($row[$directionField]);
                    $magnitude = nval($row[$magnitudeField]);

                    if ($direction === null || $magnitude === null) continue;

                    $direction = fmod($direction, 360.0);
                    if ($direction < 0) $direction += 360.0;

                    $points[] = [
                        'direction' => $direction,
                        'magnitude' => max(0, $magnitude),
                        'time' => $row['event_time'] ?? ''
                    ];
                }

                $magMeta = $station['fields'][$magnitudeField];
                $dirMeta = $station['fields'][$directionField];

                $validTimes = [];
                foreach ($points as $point) {
                    if (!empty($point['time'])) $validTimes[] = $point['time'];
                }
                sort($validTimes);

                $common = [
                    'points' => $points, // newest first
                    'direction_label' => !empty($dirMeta['label']) ? $dirMeta['label'] : $directionField,
                    'magnitude_label' => !empty($magMeta['label']) ? $magMeta['label'] : $magnitudeField,
                    'magnitude_units' => !empty($display['magnitude_units'])
                        ? $display['magnitude_units']
                        : ($magMeta['units'] ?? ''),
                    'observed_start' => $validTimes[0] ?? '',
                    'observed_end' => !empty($validTimes) ? $validTimes[count($validTimes)-1] : '',
                    'observation_count' => count($points)
                ];

                if ($type === 'polar') {
                    $polarData[$id] = $common;
                } else {
                    $directionalRecentData[$id] = $common;
                }
            }
        }
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$headerInfo=is_array($displayConfig['header_info'] ?? null) ? $displayConfig['header_info'] : [];
$showStationCode=!array_key_exists('show_station_code',$headerInfo) || !empty($headerInfo['show_station_code']);
$showCoordinates=!array_key_exists('show_coordinates',$headerInfo) || !empty($headerInfo['show_coordinates']);
$showLastReceived=!array_key_exists('show_last_received',$headerInfo) || !empty($headerInfo['show_last_received']);
$showLocalTime=!array_key_exists('show_local_time',$headerInfo) || !empty($headerInfo['show_local_time']);

$gaugeDisplays = [];
$otherDisplays = [];

foreach ($displayConfig['displays'] as $display) {
    if (($display['type'] ?? '') === 'gauge') {
        $gaugeDisplays[] = $display;
    } else {
        $otherDisplays[] = $display;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $station ? h($station['name']).($slimHealthDashboard ? ' — Station Health' : '') : ($slimHealthDashboard ? 'Station Health Dashboard' : 'Dashboard') ?></title>

<?php $dashboardAssetBase = $slimHealthDashboard ? '../../assets' : '../assets'; ?>
<script src="<?= h($dashboardAssetBase) ?>/vendor/dygraphs/dygraph.min.js"></script>
<link rel="stylesheet" href="<?= h($dashboardAssetBase) ?>/vendor/dygraphs/dygraph.min.css">
<link rel="stylesheet" href="<?= h($dashboardAssetBase) ?>/vendor/leaflet/leaflet.css">
<script src="<?= h($dashboardAssetBase) ?>/vendor/leaflet/leaflet.js"></script>

<style>
:root {
    --navy:<?= h($themeColors['background']) ?>;
    --navy-mid:<?= h($themeColors['background_mid']) ?>;
    --navy-card:<?= h($themeColors['card']) ?>;
    --navy-light:<?= h($themeColors['card_hover']) ?>;
    --accent:<?= h($themeColors['accent']) ?>;
    --accent-dim:rgba(0,194,255,0.15);
    --teal:<?= h($themeColors['teal']) ?>;
    --amber:#f5a623;
    --purple:#a970ff;
    --text-prim:<?= h($themeColors['text_primary']) ?>;
    --text-sec:<?= h($themeColors['text_secondary']) ?>;
    --text-mute:<?= h($themeColors['text_muted']) ?>;
    --border:rgba(0,194,255,0.18);
    --border-dim:rgba(255,255,255,0.06);
    --radius:10px;
    --radius-lg:16px;
}

*,
*::before,
*::after {
    box-sizing:border-box;
}

html,body {
    margin:0;
    padding:0;
}


:where(a,button,input,select,textarea,[tabindex]):focus-visible {
    outline:3px solid var(--accent);
    outline-offset:3px;
}
.skip-link{position:fixed;left:12px;top:8px;z-index:100000;padding:10px 14px;border-radius:7px;background:var(--navy-card);color:var(--text-prim);font-weight:700;text-decoration:none;clip-path:inset(50%);width:1px;height:1px;overflow:hidden;white-space:nowrap}
.skip-link:focus,.skip-link:focus-visible{clip-path:none;width:auto;height:auto;overflow:visible;white-space:normal;outline:3px solid currentColor;outline-offset:3px}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}.pulse-dot{animation:none!important}}

body {
    background:var(--navy);
    color:var(--text-prim);
    font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
    font-size:15px;
    line-height:1.6;
    min-height:100vh;
}

.page-wrapper {
    max-width:1440px;
    margin:0 auto;
    padding:24px 28px 48px;
}

.site-header {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    flex-wrap:wrap;
    margin-bottom:28px;
    padding-bottom:22px;
    border-bottom:1px solid var(--border-dim);
}

.site-title {
    margin:0 0 4px;
    font-size:27px;
    font-weight:600;
    letter-spacing:-0.3px;
}

.site-title span {
    color:var(--accent);
}

.brand-row {
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
}

.brand-logo {
    display:block;
    width:auto;
    object-fit:contain;
}

.organization-name {
    font-size:20px;
    font-weight:600;
    letter-spacing:-0.2px;
}

.site-subtitle {
    color:var(--text-sec);
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:13px;
}

.local-time-row {
    display:flex;
    align-items:baseline;
    gap:8px;
    margin-top:8px;
    color:var(--text-sec);
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:12px;
}

.local-time-row strong {
    color:var(--text-prim);
    font-weight:500;
}

.timezone-name {
    color:var(--text-sec);
}

.refresh-badge {
    display:inline-flex;
    align-items:center;
    gap:7px;
    margin-top:10px;
    padding:5px 12px;
    border:1px solid var(--border);
    border-radius:20px;
    background:rgba(0,194,255,0.10);
    color:var(--accent);
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:13px;
}

.pulse-dot {
    width:7px;
    height:7px;
    border-radius:50%;
    background:var(--teal);
    animation:pulse 2s ease-in-out infinite;
}

.refresh-badge.offline {
    border-color:rgba(220,70,70,.55);
    background:rgba(220,70,70,.10);
    color:#e05b5b;
}

.refresh-badge.offline .pulse-dot {
    background:#e05b5b;
    animation:none;
    opacity:1;
    transform:none;
}

@keyframes pulse {
    0%,100% { opacity:1; transform:scale(1); }
    50% { opacity:.4; transform:scale(.7); }
}

.station-switcher {
    display:flex;
    align-items:center;
    gap:8px;
}

.station-select {
    background:var(--navy-card);
    color:var(--text-prim);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:8px 10px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
}

.station-go {
    min-height:38px;
    padding:7px 12px;
    border:1px solid var(--border);
    border-radius:7px;
    background:var(--navy-light);
    color:var(--accent);
    font:inherit;
    cursor:pointer;
}
.station-go:hover,.station-go:focus-visible { border-color:var(--accent); }

.links-row {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:24px;
}

.links-row a {
    display:inline-flex;
    align-items:center;
    padding:6px 14px;
    border:1px solid var(--border);
    border-radius:20px;
    color:var(--accent);
    text-decoration:none;
    font-size:13px;
}

.links-row a:hover {
    background:var(--accent-dim);
    border-color:var(--accent);
}


.public-nav {
    margin:-10px 0 20px;
}

.public-nav a {
    color:var(--accent);
    text-decoration:none;
    font-size:13px;
}

.public-nav a:hover {
    text-decoration:underline;
}


.text-display-body{
    padding:22px 24px;
}
.text-display-header{
    margin:0 0 8px;
    color:var(--text-prim);
    font-size:20px;
    font-weight:600;
    letter-spacing:-0.2px;
}
.text-display-copy{
    color:var(--text-sec);
    line-height:1.7;
}
.text-display-copy p{margin:.35em 0 .75em}
.text-display-copy ul,.text-display-copy ol{margin:.45em 0 .8em;padding-left:1.6em}
.text-display-copy a{color:var(--accent)}
.text-display-copy .slim-text-normal{font-size:20px}
.text-display-copy .slim-text-large{font-size:26px}
.text-display-copy .slim-text-xlarge{font-size:33px}



.date-range-panel.embedded{
    margin:0;
    padding:0;
    border:0;
    background:transparent;
}

.date-range-panel{margin:0 0 24px;padding:14px 16px;border:1px solid var(--border-dim);border-radius:var(--radius);background:var(--navy-card)}
.date-range-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.date-range-field{min-width:165px}
.date-range-field label{display:block;margin-bottom:4px;color:var(--text-sec);font-size:14px}
.date-range-field input[type="date"]{width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:7px;background:var(--navy);color:var(--text-prim);font:inherit}
.date-range-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.date-range-actions button,.date-reset{display:inline-flex;align-items:center;min-height:38px;padding:7px 12px;border:1px solid var(--border);border-radius:7px;background:var(--navy-light);color:var(--accent);text-decoration:none;font:inherit;cursor:pointer}
.date-range-actions button:hover,.date-reset:hover{border-color:var(--accent)}
.date-range-hint{margin-top:8px;color:var(--text-sec);font-size:13px}
.date-range-error{margin-top:8px;color:#e07070;font-size:13px}

.section-label {
    margin:28px 0 14px;
    color:var(--text-mute);
    font-size:13px;
    font-weight:600;
    letter-spacing:.10em;
    text-transform:uppercase;
}

.section-title {
    display:flex;
    align-items:center;
    gap:10px;
    margin:0 0 18px;
    font-size:18px;
    font-weight:600;
}

.section-title::after {
    content:'';
    height:1px;
    flex:1;
    background:var(--border-dim);
}

.error,
.notice {
    padding:12px 16px;
    border-radius:var(--radius);
    margin-bottom:18px;
}

.error {
    background:rgba(220,50,50,.10);
    border:1px solid rgba(220,50,50,.35);
    border-left:3px solid #dc3232;
    color:#e07070;
}

.notice {
    background:rgba(245,166,35,.08);
    border:1px solid rgba(245,166,35,.28);
    color:#d4a856;
}

.gauge-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
    gap:12px;
    margin-bottom:30px;
}

.gauge-card {
    min-height:190px;
    padding:14px;
    border:1px solid var(--border-dim);
    border-radius:var(--radius-lg);
    background:var(--navy-card);
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    transition:border-color .2s,background .2s;
}

.gauge-card:hover {
    border-color:var(--border);
    background:var(--navy-light);
}

.gauge-title {
    margin-bottom:8px;
    color:var(--text-sec);
    font-size:14px;
    text-transform:uppercase;
    letter-spacing:.06em;
}

.gauge-ring {
    --pct:0deg;
    width:125px;
    height:125px;
    border-radius:50%;
    background:conic-gradient(var(--gauge-color) var(--pct),var(--gauge-bg) var(--pct));
    display:flex;
    justify-content:center;
    align-items:center;
    position:relative;
}

.gauge-ring::before {
    content:'';
    width:91px;
    height:91px;
    background:var(--navy-card);
    border-radius:50%;
    position:absolute;
}

.gauge-content {
    z-index:1;
    position:relative;
    text-align:center;
}

.gauge-value {
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:22px;
    font-weight:500;
}

.gauge-units {
    color:#fff;
    font-size:15px;
    font-weight:500;
}

.gauge-note {
    margin-top:8px;
    text-align:center;
    white-space:normal;
    overflow-wrap:break-word;
    line-height:1.4;
    color:#fff;
    font-size:14px;
    font-weight:500;
}

.gauge-scale-labels {
    width:100%;
    display:flex;
    justify-content:space-between;
    color:#fff;
    font-size:14px;
    font-weight:500;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    margin-top:6px;
}

.gauge-semicircle-wrap {
    width:150px;
    height:86px;
    overflow:hidden;
    position:relative;
    margin-top:8px;
}

.gauge-semicircle {
    --pct:0deg;
    width:150px;
    height:150px;
    border-radius:50%;
    background:conic-gradient(
        from 270deg,
        var(--gauge-color) 0deg var(--pct),
        var(--gauge-bg) var(--pct) 180deg,
        transparent 180deg 360deg
    );
    position:absolute;
    top:0;
    left:0;
}

.gauge-semicircle::before {
    content:'';
    position:absolute;
    width:112px;
    height:112px;
    border-radius:50%;
    background:var(--navy-card);
    left:19px;
    top:19px;
}

.gauge-semicircle-value {
    position:absolute;
    left:0;
    right:0;
    bottom:1px;
    text-align:center;
    z-index:2;
}

.gauge-bar-wrap {
    width:100%;
    max-width:260px;
    margin-top:14px;
}

.gauge-bar-track {
    width:100%;
    height:18px;
    border-radius:999px;
    overflow:hidden;
    background:var(--gauge-bg);
    border:1px solid var(--border-dim);
}

.gauge-bar-fill {
    height:100%;
    width:var(--pct);
    background:var(--gauge-color);
    border-radius:999px;
}

.gauge-bar-value {
    margin-top:12px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:26px;
    font-weight:500;
    text-align:center;
}

.gauge-numeric-value {
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    color:var(--gauge-color);
    font-size:44px;
    line-height:1.05;
    font-weight:500;
    margin-top:18px;
}

.gauge-numeric-units {
    color:#fff;
    font-size:17px;
    font-weight:500;
    margin-top:5px;
}

.gauge-range-note {
    color:#fff;
    font-size:14px;
    font-weight:500;
    margin-top:10px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
}

.display-grid {
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:16px;
    margin-bottom:30px;
}

.display-card {
    border:1px solid var(--border-dim);
    border-radius:var(--radius-lg);
    background:var(--navy-card);
    overflow:hidden;
}

.display-card.full,.vector-card.full{grid-column:span 6}
.display-card.half,.vector-card.half{grid-column:span 3}
.display-card.third,.vector-card.third{grid-column:span 2}

.display-header {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    padding:14px 18px;
    border-bottom:1px solid var(--border-dim);
}

.display-title {
    font-size:16px;
    font-weight:600;
}

.slim-visually-hidden {
    position:absolute !important;
    width:1px !important;
    height:1px !important;
    padding:0 !important;
    margin:-1px !important;
    overflow:hidden !important;
    clip:rect(0,0,0,0) !important;
    white-space:nowrap !important;
    border:0 !important;
}

.display-note {
    margin-top:2px;
    color:var(--text-sec);
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:13px;
}

.display-body {
    padding:16px 18px 20px;
}

.chart-wrap {
    position:relative;
    height:330px;
    background:#fff;
    border-radius:10px;
    padding:8px 16px 14px 8px;
}

.dygraph-target,
.slim-polar-target {
    width:100%;
    height:100%;
}
.slim-polar-target svg {
    display:block;
    width:100%;
    height:100%;
    max-width:100%;
    max-height:100%;
}

.dygraph-label,
.dygraph-title,
.dygraph-legend {
    color:#111 !important;
}

.dygraph-axis-label {
    color:#111 !important;
    font-size:14px !important;
}

/* Axis titles should be more prominent than tick values. */
.dygraph-xlabel,
.dygraph-ylabel {
    color:#111 !important;
    font-size:18px !important;
    font-weight:600;
}

.dygraph-roller {
    color:#111 !important;
    background-color:#fff !important;
    border:1px solid #666 !important;
}

.dygraph-legend {
    background:rgba(255,255,255,.92) !important;
    border-radius:4px;
    padding:4px 6px;
}

.profile-body {
    display:grid;
    grid-template-columns:minmax(320px,1fr) 130px;
    gap:24px;
    align-items:center;
}

.profile-svg {
    width:100%;
    max-width:600px;
    height:auto;
    display:block;
    margin:0 auto;
}


.profile-legend-value {
    color:#fff;
    font-size:13px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    line-height:1.2;
    margin:5px 0;
}
.profile-legend-title {
    color:#fff;
    font-size:14px;
    font-weight:500;
    text-transform:uppercase;
    letter-spacing:.06em;
    margin-bottom:8px;
}

.profile-gradient {
    width:22px;
    height:220px;
    border-radius:6px;
    border:1px solid rgba(255,255,255,.15);
    background:linear-gradient(to bottom,#d73027 0%,#f46d43 20%,#fee08b 40%,#66c2a5 60%,#3288bd 80%,#313695 100%);
}

.profile-stats {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    padding:0 18px 14px;
}

.stat-chip {
    padding:7px 11px;
    border:1px solid var(--border-dim);
    border-radius:var(--radius);
    background:var(--navy-mid);
    min-width:92px;
    text-align:center;
}

.stat-chip span {
    display:block;
    color:var(--text-sec);
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.06em;
}

.stat-chip strong {
    display:block;
    margin-top:1px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:14px;
}

.vector-card {
    border:1px solid var(--border-dim);
    border-radius:var(--radius-lg);
    background:var(--navy-card);
    overflow:hidden;
}

.vector-current {
    text-align:right;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
}

.vector-current strong {
    display:block;
    font-size:20px;
    line-height:1.15;
}

.vector-current span {
    display:block;
    margin-top:3px;
    color:var(--text-sec);
    font-size:13px;
}

.direction-svg {
    display:block;
    width:100%;
    max-width:500px;
    height:auto;
    margin:0 auto;
}

.vector-footer {
    display:flex;
    justify-content:center;
    gap:18px;
    flex-wrap:wrap;
    padding:0 18px 16px;
    color:var(--text-sec);
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;
    font-size:13px;
}

.vector-key {
    display:inline-flex;
    align-items:center;
    gap:6px;
}

.vector-key-dot {
    width:8px;
    height:8px;
    border-radius:50%;
    background:var(--accent);
}

.vector-key-dot.faded {
    opacity:.3;
}


.map-display {
    width:100%;
    border-radius:10px;
    overflow:hidden;
    background:#e8edf2;
}

.station-image {
    display:block;
    width:100%;
    max-height:520px;
    object-fit:cover;
    border-radius:10px;
}

.image-caption {
    margin-top:10px;
    color:var(--text-sec);
    font-size:13px;
}

.debug {
    margin-top:8px;
    color:var(--text-mute);
    font-size:13px;
}


.site-footer{
    margin-top:36px;
    padding-top:16px;
    border-top:1px solid var(--border-dim);
    color:var(--text-mute);
    font-size:13px;
    text-align:center;
}

@media(max-width:900px) {
    .display-grid {
        grid-template-columns:1fr;
    }

    .display-card.full,.display-card.half,.display-card.third,
    .vector-card.full,.vector-card.half,.vector-card.third {
        grid-column:auto;
    }

    .profile-body {
        grid-template-columns:1fr;
    }

    .profile-legend {
        display:none;
    }
}

@media(max-width:620px) {
    .page-wrapper {
        padding:16px 14px 40px;
    }

    .gauge-grid {
        grid-template-columns:1fr 1fr;
    }
}

/* v0.22.7: public dashboards use a larger type scale. Health/admin dashboards
   intentionally retain their denser administrative typography. */
.station-page-title{margin:0 0 22px;color:var(--text-prim);font-size:32px;line-height:1.15;font-weight:700}
body.slim-public-dashboard{font-size:18px}
body.slim-public-dashboard .site-title{font-size:32px}
body.slim-public-dashboard .organization-name{font-size:24px}
body.slim-public-dashboard .site-subtitle{font-size:16px}
body.slim-public-dashboard .local-time-row,
body.slim-public-dashboard .refresh-badge{font-size:14px}
body.slim-public-dashboard .links-row a,
body.slim-public-dashboard .public-nav a{font-size:16px}
body.slim-public-dashboard .text-display-header{font-size:24px}
body.slim-public-dashboard .date-range-field label,
body.slim-public-dashboard .date-range-hint{font-size:14px}
body.slim-public-dashboard .date-range-error{font-size:16px}
body.slim-public-dashboard .section-label{font-size:13px}
body.slim-public-dashboard .section-title{font-size:22px}
body.slim-public-dashboard .display-note{font-size:14px}
body.slim-public-dashboard .slim-version-footer{font-size:14px}
@media(max-width:700px){
    body.slim-public-dashboard{font-size:17px}
}

</style>
</head>
<body class="<?= $slimHealthDashboard ? 'slim-health-dashboard' : 'slim-public-dashboard' ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>

<div class="page-wrapper">

<header class="site-header">
    <div>
        <?php
        $logoMode=(string)($appearance['logo_mode'] ?? 'title_only');
        $logoPath=(string)($appearance['logo_path'] ?? '');
        $organizationName=(string)($system['organization'] ?? '');
        $logoHeight=max(24,min(120,(int)($appearance['logo_max_height'] ?? 52)));
        ?>

        <div class="brand-row">
            <?php if($logoPath!=='' && ($logoMode==='logo_only' || $logoMode==='logo_and_name')): ?>
            <img
                class="brand-logo"
                src="<?= h(slim_public_asset_url($logoPath)) ?>"
                alt="<?= h($organizationName !== '' ? $organizationName : 'Organization logo') ?>"
                style="max-height:<?= (int)$logoHeight ?>px"
            >
            <?php endif; ?>

            <?php if($logoMode==='logo_and_name' && $organizationName!==''): ?>
            <div class="organization-name"><?= h($organizationName) ?></div>
            <?php elseif($logoMode==='title_only'): ?>
            <div class="site-title"><?= h($organizationName !== '' ? $organizationName : (string)($site['site_title'] ?? 'Monitoring Dashboard')) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($station): ?>
        <?php if($slimHealthDashboard || $showStationCode || ($showCoordinates && isset($station['latitude'],$station['longitude']))): ?>
        <div class="site-subtitle">
            <?php
            $subtitleParts=[];
            if($slimHealthDashboard) $subtitleParts[]='Private station health dashboard';
            if($showStationCode) $subtitleParts[]='Station: '.h((string)$station['code']);
            if($showCoordinates && isset($station['latitude'],$station['longitude'])) {
                $subtitleParts[]=h(number_format((float)$station['latitude'],4).', '.number_format((float)$station['longitude'],4));
            }
            echo implode(' &nbsp;|&nbsp; ',$subtitleParts);
            ?>
        </div>
        <?php endif; ?>

        <div class="refresh-badge<?= $stationOnline ? '' : ' offline' ?>">
            <span class="pulse-dot"></span>
            <?= $stationOnline ? 'ONLINE' : 'OFFLINE' ?>
            <?php if($showLastReceived): ?>
                <?php if ($latestDisplayTime): ?>
                &nbsp;&bull;&nbsp; Last received <?= h($latestDisplayTime) ?>
                <?php else: ?>
                &nbsp;&bull;&nbsp; No data received
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if($showLocalTime): ?>
        <div class="local-time-row">
            <span>Local time:</span>
            <strong id="slim-local-clock">--</strong>
            <span class="timezone-name"><?= h($stationTimezone) ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($stations): ?>
    <form method="get" class="station-switcher">
        <label class="slim-visually-hidden" for="slim-dashboard-station">Select a dashboard</label>
        <select class="station-select" id="slim-dashboard-station" name="station">
        <?php foreach ($stations as $code=>$info): ?>
            <option value="<?= h($code) ?>" <?= $stationCode===$code?'selected':'' ?>>
                <?= h($info['name']) ?> — <?= h($code) ?>
            </option>
        <?php endforeach; ?>
        </select>
        <button type="submit" class="station-go">Go</button>
    </form>
    <?php endif; ?>
</header>
<main id="main-content" tabindex="-1">

<div class="public-nav">
<a href="<?= h($slimDashboardAllStationsHref) ?>">&larr; All Stations</a>
</div>

<?php if ($station): ?>
<h1 class="station-page-title"><?= h((string)($station['name'] ?? $stationCode)) ?><?= $slimHealthDashboard ? ' — Station Health' : '' ?></h1>
<?php endif; ?>

<?php if ($error): ?>
<div class="error"><?= h($error) ?></div>

<?php elseif (!$station): ?>
<div class="notice">No configured station was found.</div>

<?php else: ?>

<?php
// Render every display in the exact order stored by Dashboard Configuration.
// Do not regroup by display type.
$orderedDisplays=$displayConfig['displays'];

$hasDateRangeDisplay=false;
foreach($orderedDisplays as $candidateDisplay) {
    if(($candidateDisplay['type'] ?? '')==='date_range') {
        $hasDateRangeDisplay=true;
        break;
    }
}

if(!$hasDateRangeDisplay) {
    array_unshift($orderedDisplays,[
        'id'=>'date_range_selector',
        'type'=>'date_range',
        'title'=>'Select Date Range',
        'width'=>'full'
    ]);
}
?>

<?php if($orderedDisplays): ?>
<div class="display-grid">

<?php foreach($orderedDisplays as $display):
$id=$display['id'] ?? '';
$type=$display['type'] ?? '';
?>

<?php if($type==='custom_text'): ?>
<div class="display-card text-display <?= h(display_width_class($display)) ?>">
    <div class="display-body text-display-body">
        <?php if(trim((string)($display['header_text'] ?? ''))!==''): ?>
        <div class="text-display-header"><?= h((string)$display['header_text']) ?></div>
        <?php endif; ?>

        <?php if(trim((string)($display['body_text'] ?? ''))!==''): ?>
        <div class="text-display-copy"><?php
            $customBody=(string)$display['body_text'];
            echo preg_match('/<[^>]+>/', $customBody) ? slim_sanitize_content_html($customBody) : nl2br(h($customBody));
        ?></div>
        <?php endif; ?>
    </div>
</div>

<?php elseif($type==='date_range'): ?>
<div class="display-card <?= h(display_width_class($display)) ?>">
    <div class="display-header">
        <div>
            <div class="display-title"><?= h($display['title'] ?? 'Select Date Range') ?></div>

        </div>
    </div>
    <div class="display-body">
        <div class="date-range-panel embedded">
            <form method="get" class="date-range-form">
                <input type="hidden" name="station" value="<?= h($stationCode) ?>">
                <div class="date-range-field">
                    <label for="date_from_<?= h($id) ?>">From</label>
                    <input type="date" id="date_from_<?= h($id) ?>" name="date_from" value="<?= h($rangeStartLocal) ?>">
                </div>
                <div class="date-range-field">
                    <label for="date_to_<?= h($id) ?>">To</label>
                    <input type="date" id="date_to_<?= h($id) ?>" name="date_to" value="<?= h($rangeEndLocal) ?>">
                </div>
                <div class="date-range-actions">
                    <button type="submit">Show Date Range</button>
                    <?php if(isset($_GET['date_from']) || isset($_GET['date_to'])): ?>
                    <a class="date-reset" href="?station=<?= urlencode($stationCode) ?>">
                        Reset to latest <?= (int)max(1,(int)($system['graph_hours'] ?? 72)) ?> hours
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            <div class="date-range-hint">
                <?php if($slimHealthDashboard): ?>
                Choose up to <?= (int)$maxPlotDays ?> calendar days. Health plots use all observations; large ranges may load slowly.
                <?php else: ?>
                Choose up to <?= (int)$maxPlotDays ?> calendar days. Large datasets are automatically downsampled across the selected period to limit the number of points displayed.
                <?php endif; ?>
                Calendar dates use <?= h($stationTimezone) ?>.
            </div>
            <?php if($dateRangeError!==''): ?>
            <div class="date-range-error"><?= h($dateRangeError) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif($type==='gauge' && isset($gaugeData[$id])): ?>
<?php
$g=$gaugeData[$id];
$min=(isset($display['min'])&&$display['min']!==null)?(float)$display['min']:0.0;
$max=(isset($display['max'])&&$display['max']!==null)?(float)$display['max']:100.0;
$value=$g['value'];
$pct=0;

if($value!==null && $max>$min){
    $pct=(($value-$min)/($max-$min))*100;
    $pct=max(0,min(100,$pct));
}

$deg=$pct*3.6;
$semiDeg=$pct*1.8;
$dec=max(0,min(6,(int)($display['decimals'] ?? 1)));
$style=(string)($display['gauge_style'] ?? 'ring');
if(!in_array($style,['ring','semicircle','bar','numeric'],true)) $style='ring';

$gaugeColor=(string)($display['gauge_color'] ?? '#37b7c3');
$gaugeBackgroundColor=(string)($display['gauge_background_color'] ?? '#23384a');
if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$gaugeColor)) $gaugeColor='#37b7c3';
if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$gaugeBackgroundColor)) $gaugeBackgroundColor='#23384a';

$formattedValue=$value===null?'—':number_format($value,$dec);
?>
<div class="gauge-card display-card <?= h(display_width_class($display)) ?>" style="--gauge-color:<?= h($gaugeColor) ?>;--gauge-bg:<?= h($gaugeBackgroundColor) ?>;">
    <div class="gauge-title"><?= h($display['title'] ?? $g['label']) ?></div>

    <?php if($style==='semicircle'): ?>
        <div class="gauge-semicircle-wrap">
            <div class="gauge-semicircle" style="--pct:<?= h((string)$semiDeg) ?>deg"></div>
            <div class="gauge-semicircle-value">
                <div class="gauge-value"><?= h($formattedValue) ?></div>
                <div class="gauge-units"><?= h($g['units']) ?></div>
            </div>
        </div>
        <div class="gauge-scale-labels"><span><?= h(number_format($min,$dec)) ?></span><span><?= h(number_format($max,$dec)) ?></span></div>

    <?php elseif($style==='bar'): ?>
        <div class="gauge-bar-wrap">
            <div class="gauge-bar-track">
                <div class="gauge-bar-fill" style="--pct:<?= h((string)$pct) ?>%"></div>
            </div>
            <div class="gauge-scale-labels"><span><?= h(number_format($min,$dec)) ?></span><span><?= h(number_format($max,$dec)) ?></span></div>
            <div class="gauge-bar-value"><?= h($formattedValue) ?> <span class="gauge-units"><?= h($g['units']) ?></span></div>
        </div>

    <?php elseif($style==='numeric'): ?>
        <div class="gauge-numeric-value"><?= h($formattedValue) ?></div>
        <div class="gauge-numeric-units"><?= h($g['units']) ?></div>
        <div class="gauge-range-note">Configured range: <?= h(number_format($min,$dec)) ?> – <?= h(number_format($max,$dec)) ?></div>

    <?php else: ?>
        <div class="gauge-ring" style="--pct:<?= h((string)$deg) ?>deg">
            <div class="gauge-content">
                <div class="gauge-value"><?= h($formattedValue) ?></div>
                <div class="gauge-units"><?= h($g['units']) ?></div>
            </div>
        </div>
        <div class="gauge-scale-labels"><span><?= h(number_format($min,$dec)) ?></span><span><?= h(number_format($max,$dec)) ?></span></div>
    <?php endif; ?>

    <div class="gauge-note">Avg. of <?= (int)$g['count'] ?> recent observations</div>
</div>

<?php elseif($type==='image'): ?>
<?php
$imagePath=(string)($display['image_path'] ?? '');
$imageVersion='';
if($imagePath!==''){
    $imageAbsolute=dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$imagePath);
    if(is_file($imageAbsolute)) $imageVersion='?v='.rawurlencode((string)filemtime($imageAbsolute));
}
?>
<div class="display-card <?= h(display_width_class($display)) ?>">
    <div class="display-body">
        <?php if($imagePath!==''): ?>
        <img
            class="station-image"
            src="<?= h($slimDashboardAssetPrefix) ?><?= h($imagePath) ?><?= h($imageVersion) ?>"
            alt="<?= h($display['alt_text'] ?? ($display['title'] ?? 'Station image')) ?>"
        >
        <?php if(!empty($display['caption'])): ?>
        <div class="image-caption"><?= h($display['caption']) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="notice">No image is configured.</div>
        <?php endif; ?>
    </div>
</div>

<?php elseif($type==='map'): ?>
<?php
$mapHeight=max(220,min(700,(int)($display['height'] ?? 360)));
$resolvedMap=$mapData[$id] ?? ['latitude'=>$station['latitude'] ?? null,'longitude'=>$station['longitude'] ?? null,'source'=>'static'];
$mapSourceLabel=$resolvedMap['source']==='live' ? 'Live GPS' : ($resolvedMap['source']==='fallback' ? 'Static station coordinates (fallback)' : 'Static station coordinates');
?>
<div class="display-card <?= h(display_width_class($display)) ?>">
    <div class="display-header">
        <div>
            <div class="display-note">
                <?php if($resolvedMap['latitude']!==null && $resolvedMap['longitude']!==null): ?>
                <?= h(number_format((float)$resolvedMap['latitude'],4)) ?>,
                <?= h(number_format((float)$resolvedMap['longitude'],4)) ?>
                — Location source: <?= h($mapSourceLabel) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="display-body">
        <div
            id="map-<?= h($id) ?>"
            class="map-display"
            style="height:<?= (int)$mapHeight ?>px"
            role="region"
            aria-label="<?= h('Map showing the location of '.($station['name'] ?? $station['code'])) ?>"
        ></div>
    </div>
</div>

<?php elseif($type==='profile' && isset($profileData[$id])): ?>
<?php
$source=$profileData[$id];
$pts=$source['points'];
$first=count($pts)?$pts[0]:null;
$last=count($pts)?$pts[count($pts)-1]:null;
$delta=($first&&$last)?$first['value']-$last['value']:null;
?>
<div class="display-card <?= h(display_width_class($display)) ?>">
    <div class="display-header">
        <div>
            <div class="display-title"><?= h($display['title'] ?? 'Current Profile') ?></div>
            <div class="display-note">Most recent profile from configured fields</div>
        </div>
    </div>

    <?php if($pts): ?>
    <div class="profile-stats">
        <?php if($first): ?>
        <div class="stat-chip">
            <span>First</span>
            <strong><?= h(number_format($first['value'],1)) ?> <?= h($source['value_units']) ?></strong>
        </div>
        <?php endif; ?>

        <?php if($last): ?>
        <div class="stat-chip">
            <span>Last</span>
            <strong><?= h(number_format($last['value'],1)) ?> <?= h($source['value_units']) ?></strong>
        </div>
        <?php endif; ?>

        <?php if($delta!==null): ?>
        <div class="stat-chip">
            <span>Δ</span>
            <strong><?= h(number_format($delta,1)) ?> <?= h($source['value_units']) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <div class="display-body profile-body">
        <div>
            <svg id="profile-svg-<?= h($id) ?>" class="profile-svg" viewBox="0 0 560 610"
                 role="img"
                 aria-labelledby="profile-title-<?= h($id) ?> profile-desc-<?= h($id) ?>">
                <title id="profile-title-<?= h($id) ?>"><?= h(($display['title'] ?? 'Current Profile').' chart') ?></title>
                <desc id="profile-desc-<?= h($id) ?>"><?php
                    $profileDesc='Profile containing '.count($pts).' observations';
                    if($first && $last) {
                        $profileDesc.=', from '.number_format((float)$first['value'],1).' '.$source['value_units']
                            .' at '.number_format((float)$first['position'],1).' '.$source['coordinate_units']
                            .' to '.number_format((float)$last['value'],1).' '.$source['value_units']
                            .' at '.number_format((float)$last['position'],1).' '.$source['coordinate_units'];
                    }
                    echo h($profileDesc.'.');
                ?></desc>
            </svg>
        </div>
        <div class="profile-legend">
            <div class="profile-legend-title"><?= h($source['value_units'] ?: 'Value') ?></div>
            <?php if($source['color_max'] !== null): ?><div class="profile-legend-value"><?= h(number_format((float)$source['color_max'],1)) ?></div><?php endif; ?>
            <div class="profile-gradient"></div>
            <?php if($source['color_min'] !== null): ?><div class="profile-legend-value"><?= h(number_format((float)$source['color_min'],1)) ?></div><?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="display-body notice">No valid recent observations were available for this profile.</div>
    <?php endif; ?>
</div>

<?php elseif($type==='directional_recent' && isset($directionalRecentData[$id])): ?>
<?php
$source=$directionalRecentData[$id];
$pts=$source['points'];
$current=count($pts)?$pts[0]:null;
?>
<div class="vector-card <?= h(display_width_class($display)) ?>">
    <div class="display-header">
        <div>
            <div class="display-title"><?= h($display['title'] ?? 'Current Direction') ?></div>
            <div class="display-note">
                <?php if($current && !empty($current['time'])): ?>
                Latest measurement: <?= h($current['time']) ?>
                <?php else: ?>
                Direction unavailable
                <?php endif; ?>
            </div>
        </div>

        <?php if($current): ?>
        <div class="vector-current">
            <strong>
                <?= h(number_format($current['magnitude'],1)) ?>
                <?= h($source['magnitude_units']) ?>
            </strong>
            <span>
                <?= h(cardinal_direction($current['direction'])) ?>
                <?= h(number_format($current['direction'],0)) ?>°
            </span>
        </div>
        <?php endif; ?>
    </div>

    <?php if($current): ?>
    <div class="display-body">
        <svg id="direction-svg-<?= h($id) ?>" class="direction-svg" viewBox="0 0 500 475"
             role="img"
             aria-labelledby="direction-title-<?= h($id) ?> direction-desc-<?= h($id) ?>">
            <title id="direction-title-<?= h($id) ?>"><?= h(($display['title'] ?? 'Current Direction').' chart') ?></title>
            <desc id="direction-desc-<?= h($id) ?>"><?= h(
                'Latest observation: '.number_format((float)$current['magnitude'],1).' '.$source['magnitude_units']
                .' toward '.cardinal_direction((float)$current['direction']).', '
                .number_format((float)$current['direction'],0).' degrees. Older observations are also shown with reduced emphasis.'
            ) ?></desc>
        </svg>
    </div>
    <div class="vector-footer">
        <span class="vector-key"><span class="vector-key-dot"></span>Latest bearing</span>
        <span class="vector-key"><span class="vector-key-dot faded"></span>Older observations fade</span>
    </div>
    <?php else: ?>
    <div class="display-body notice">Current directional data are unavailable.</div>
    <?php endif; ?>
</div>

<?php elseif($type==='line' && isset($lineData[$id])): ?>
<?php
$pointCount=0;
$accessibleSeries=[];
$allTimes=[];
foreach($lineData[$id]['datasets'] as $ds){
    $seriesPoints=$ds['points'] ?? [];
    $pointCount+=count($seriesPoints);
    $values=[];
    foreach($seriesPoints as $point){
        if(isset($point['x'])) $allTimes[]=(int)$point['x'];
        if(isset($point['y']) && is_numeric($point['y'])) $values[]=(float)$point['y'];
    }
    $accessibleSeries[]=[
        'label'=>(string)($ds['label'] ?? $ds['field'] ?? 'Series'),
        'units'=>(string)($ds['units'] ?? ''),
        'count'=>count($values),
        'min'=>$values ? min($values) : null,
        'max'=>$values ? max($values) : null,
        'latest'=>$values ? $values[count($values)-1] : null
    ];
}
$chartStart=$allTimes ? min($allTimes) : null;
$chartEnd=$allTimes ? max($allTimes) : null;
$chartTitle=(string)($display['title'] ?? 'Time Series');
$seriesNames=array_map(fn($s)=>$s['label'],$accessibleSeries);
$chartDesc='Time-series chart';
if($seriesNames) $chartDesc.=' showing '.implode(', ',$seriesNames);
if($chartStart!==null && $chartEnd!==null){
    $chartDesc.='. Displayed observations run from '
        .(new DateTimeImmutable('@'.(int)floor($chartStart/1000)))->setTimezone($displayTimezone)->format('M j, Y g:i A')
        .' through '
        .(new DateTimeImmutable('@'.(int)floor($chartEnd/1000)))->setTimezone($displayTimezone)->format('M j, Y g:i A');
}
$chartDesc.='. The horizontal axis represents observation date and time.';
if(!empty($lineData[$id]['y_label'])) $chartDesc.=' Primary vertical axis: '.$lineData[$id]['y_label'].'.';
if(!empty($lineData[$id]['y2_label'])) $chartDesc.=' Secondary vertical axis: '.$lineData[$id]['y2_label'].'.';
if(!empty($lineData[$id]['downsampled'])){
    $chartDesc.=' The visual plot is systematically sampled from '.number_format((int)$lineData[$id]['source_row_count']).' source observations; values are not averaged.';
}
?>
<div class="display-card <?= h(display_width_class($display)) ?>"
     role="region"
     aria-labelledby="line-title-<?= h($id) ?>"
     aria-describedby="line-desc-<?= h($id) ?>">
    <div class="display-header">
        <div>
            <div class="display-title" id="line-title-<?= h($id) ?>"><?= h($chartTitle) ?></div>
            <p id="line-desc-<?= h($id) ?>" class="slim-visually-hidden"><?= h($chartDesc) ?></p>
            <?php if($customDateRange || (!$slimHealthDashboard && !empty($lineData[$id]['downsampled'])) || $slimHealthDashboard): ?>
            <div class="display-note">
                <?php if($customDateRange): ?>
                    <?= h($rangeStartLocal) ?> through <?= h($rangeEndLocal) ?>
                <?php endif; ?>
                <?php if(!$slimHealthDashboard && !empty($lineData[$id]['downsampled'])): ?>
                    <?= $customDateRange ? '&nbsp;|&nbsp;' : '' ?><?= number_format((int)$lineData[$id]['source_row_count']) ?> observations systematically sampled to <?= number_format((int)$lineData[$id]['row_count']) ?> plotted points; values are not averaged
                <?php elseif($slimHealthDashboard): ?>
                    <?= $customDateRange ? '&nbsp;|&nbsp;' : '' ?>full-resolution administrative view
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="display-body">
        <?php if($pointCount>0): ?>
        <div class="chart-wrap" role="group" aria-labelledby="line-title-<?= h($id) ?>" aria-describedby="line-desc-<?= h($id) ?>">
            <div id="chart-<?= h($id) ?>" class="dygraph-target"></div>
        </div>

        <table class="slim-visually-hidden">
            <caption>Accessible summary of <?= h($chartTitle) ?></caption>
            <thead>
                <tr><th scope="col">Series</th><th scope="col">Units</th><th scope="col">Observations plotted</th><th scope="col">Minimum</th><th scope="col">Maximum</th><th scope="col">Latest plotted value</th></tr>
            </thead>
            <tbody>
            <?php foreach($accessibleSeries as $series): ?>
                <tr>
                    <th scope="row"><?= h($series['label']) ?></th>
                    <td><?= h($series['units']) ?></td>
                    <td><?= number_format((int)$series['count']) ?></td>
                    <td><?= $series['min']===null ? 'No value' : h(number_format((float)$series['min'],3)) ?></td>
                    <td><?= $series['max']===null ? 'No value' : h(number_format((float)$series['max'],3)) ?></td>
                    <td><?= $series['latest']===null ? 'No value' : h(number_format((float)$series['latest'],3)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="notice">No numeric observations were found in this time window.</div>
        <?php endif; ?>
    </div>
</div>

<?php elseif($type==='polar' && isset($polarData[$id])): ?>
<div class="display-card <?= h(display_width_class($display)) ?>">
    <div class="display-header">
        <div>
            <div class="display-title"><?= h($display['title'] ?? 'Polar Plot') ?></div>
        </div>
    </div>

    <div class="display-body">
        <?php if(count($polarData[$id]['points'])>0): ?>
        <div class="chart-wrap"><div id="polar-<?= h($id) ?>" class="slim-polar-target"></div></div>
        <?php
            $polarStart = $polarData[$id]['observed_start'] ?? '';
            $polarEnd = $polarData[$id]['observed_end'] ?? '';
            $polarCount = (int)($polarData[$id]['observation_count'] ?? count($polarData[$id]['points']));
            $formatObserved = static function($value) use ($displayTimezone) {
                if ($value === '') return '';
                try {
                    return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone($displayTimezone)->format('M j, Y g:i A T');
                } catch (Throwable $e) {
                    return (string)$value;
                }
            };
        ?>
        <?php if($polarStart !== '' && $polarEnd !== ''): ?>
        <div class="display-note" style="margin-top:10px;text-align:center;">
            <?= h($formatObserved($polarStart)) ?> – <?= h($formatObserved($polarEnd)) ?>
            · <?= h((string)$polarCount) ?> observation<?= $polarCount === 1 ? '' : 's' ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="notice">No valid paired observations were available.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>

</div>
<?php endif; ?>

<?php endif; // end error/station conditional ?>

<footer class="site-footer slim-version-footer"><?= h(slim_footer_text()) ?></footer>
</div>

<script>
const lineData = <?= json_encode($lineData,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const profileData = <?= json_encode($profileData,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const polarData = <?= json_encode($polarData,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const directionalData = <?= json_encode($directionalRecentData,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const displayConfig = <?= json_encode($displayConfig['displays'],JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function getDisplay(id) {
    for(let i=0;i<displayConfig.length;i++) {
        if(displayConfig[i].id===id)return displayConfig[i];
    }
    return null;
}


function slimStrokePattern(style){
    if(style==='dashed')return [8,5];
    if(style==='dotted')return [2,4];
    if(style==='dashdot')return [9,4,2,4];
    return null;
}

function slimPointDrawer(symbol,pointColor,size){
    return function(g,seriesName,ctx,cx,cy,dyColor,dyPointSize,index){
        const r=Number(size || dyPointSize || 3);
        const c=pointColor || dyColor || '#3366cc';

        ctx.save();
        ctx.fillStyle=c;
        ctx.strokeStyle=c;
        ctx.lineWidth=1.2;
        ctx.beginPath();

        if(symbol==='square'){
            ctx.rect(cx-r,cy-r,r*2,r*2);
        }else if(symbol==='triangle'){
            ctx.moveTo(cx,cy-r*1.25);
            ctx.lineTo(cx+r*1.15,cy+r);
            ctx.lineTo(cx-r*1.15,cy+r);
            ctx.closePath();
        }else if(symbol==='diamond'){
            ctx.moveTo(cx,cy-r*1.35);
            ctx.lineTo(cx+r*1.15,cy);
            ctx.lineTo(cx,cy+r*1.35);
            ctx.lineTo(cx-r*1.15,cy);
            ctx.closePath();
        }else{
            ctx.arc(cx,cy,r,0,Math.PI*2,false);
        }

        ctx.fill();
        ctx.stroke();
        ctx.restore();
    };
}

Object.keys(lineData).forEach(function(id){
    const target=document.getElementById('chart-'+id);
    if(!target)return;

    const display=getDisplay(id);
    const source=lineData[id];

    if(!source.datasets || source.datasets.length===0)return;

    const timeMap={};

    source.datasets.forEach(function(ds,seriesIndex){
        ds.points.forEach(function(point){
            const t=Number(point.x);

            if(!timeMap[t]){
                timeMap[t]=new Array(source.datasets.length+1).fill(null);
                timeMap[t][0]=new Date(t);
            }

            timeMap[t][seriesIndex+1]=Number(point.y);
        });
    });

    const rows=Object.keys(timeMap)
        .map(function(key){return timeMap[key];})
        .sort(function(a,b){return a[0]-b[0];});

    const labels=['Date'].concat(
        source.datasets.map(function(ds){
            return ds.label;
        })
    );

    const colors=source.datasets.map(function(ds){
        return ds.color || '#3366cc';
    });

    const dg=source.dygraph || {};
    const perSeries={};

    source.datasets.forEach(function(ds){
        const opts={
            strokeWidth:Number(ds.stroke_width !== undefined ? ds.stroke_width : 1.5),
            drawPoints:!!ds.show_points,
            pointSize:Number(ds.point_size !== undefined ? ds.point_size : 3),
            axis:ds.axis==='y2' ? 'y2' : 'y',
            drawPointCallback:slimPointDrawer(
                ds.point_symbol || 'circle',
                ds.point_color || ds.color || '#3366cc',
                Number(ds.point_size !== undefined ? ds.point_size : 3)
            ),
            drawHighlightPointCallback:slimPointDrawer(
                ds.point_symbol || 'circle',
                ds.point_color || ds.color || '#3366cc',
                Number(ds.point_size !== undefined ? ds.point_size + 2 : 5)
            )
        };

        const pattern=slimStrokePattern(ds.line_style || 'solid');
        if(pattern)opts.strokePattern=pattern;

        perSeries[ds.label]=opts;
    });

    const hasY2=source.datasets.some(function(ds){return ds.axis==='y2';});

    const chartTimezone=<?= json_encode($stationTimezone,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const chartDateTime=new Intl.DateTimeFormat(undefined,{timeZone:chartTimezone,year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit'});
    const chartDate=new Intl.DateTimeFormat(undefined,{timeZone:chartTimezone,month:'short',day:'numeric'});
    const chartTime=new Intl.DateTimeFormat(undefined,{timeZone:chartTimezone,hour:'2-digit',minute:'2-digit'});
    const chartParts=new Intl.DateTimeFormat('en-US',{timeZone:chartTimezone,hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
    const options={
        labels:labels,
        colors:colors,
        series:perSeries,
        rollPeriod:Number(dg.roll_period || 1),
        showRoller:dg.show_roller !== undefined ? !!dg.show_roller : true,
        showLabelsOnHighlight:dg.show_labels_on_highlight !== undefined
            ? !!dg.show_labels_on_highlight
            : true,
        labelsShowZeroValues:dg.labels_show_zero_values !== undefined
            ? !!dg.labels_show_zero_values
            : false,
        highlightCircleSize:Number(
            dg.highlight_circle_size !== undefined ? dg.highlight_circle_size : 8
        ),
        ylabel:source.y_label || '',
        y2label:hasY2 ? (source.y2_label || '') : '',
        rightGap:hasY2 ? 48 : 20,
        xLabelHeight:40,
        yLabelWidth:24,
        axisLabelFontSize:14,
        legend:(display && display.show_legend) ? 'always' : 'never',
        labelsSeparateLines:true,
        connectSeparatedPoints:true,
        gridLineColor:'rgba(0,0,0,0.10)',
        axisLineColor:'#555',
        axisLabelColor:'#111',
        titleFontColor:'#111',
        axes:{
            x:{
                valueFormatter:function(ms){
                    return chartDateTime.format(new Date(ms))+' ('+chartTimezone+')';
                },
                axisLabelFormatter:function(date){
                    const d=(date instanceof Date) ? date : new Date(date);

                    // Mark the change of calendar day on the time axis.
                    // Midnight receives a date label; other ticks remain time.
                    const parts=chartParts.formatToParts(d);
                    const hour=parts.find(p=>p.type==='hour');
                    const minute=parts.find(p=>p.type==='minute');
                    if(hour && minute && hour.value==='00' && minute.value==='00') {
                        return chartDate.format(d);
                    }
                    return chartTime.format(d);
                }
            },
            y:{
                axisLabelWidth:62,
                valueFormatter:function(v){
                    return Number(v).toFixed(2);
                }
            },
            y2:{
                axisLabelWidth:62,
                independentTicks:true,
                drawGrid:false,
                valueFormatter:function(v){
                    return Number(v).toFixed(2);
                }
            }
        },
        underlayCallback:function(canvas,area,g){
            canvas.fillStyle='#ffffff';
            canvas.fillRect(area.x,area.y,area.w,area.h);
        }
    };

    if(display && display.y_min!=null && display.y_max!=null){
        options.valueRange=[Number(display.y_min),Number(display.y_max)];
    } else if(display && display.y_min!=null){
        options.valueRange=[Number(display.y_min),null];
    } else if(display && display.y_max!=null){
        options.valueRange=[null,Number(display.y_max)];
    }

    const graph=new Dygraph(target,rows,options);

    // Dygraphs creates its rolling-average input dynamically. Give that form
    // control an explicit accessible label so it is identifiable to assistive
    // technology and accessibility checkers.
    const roller=target.querySelector('.dygraph-roller');
    if(roller){
        const rollerId='dygraph-roller-'+id;
        roller.id=rollerId;
        roller.setAttribute('aria-describedby','line-desc-'+id);
        const rollerLabel=document.createElement('label');
        rollerLabel.htmlFor=rollerId;
        rollerLabel.className='slim-visually-hidden';
        rollerLabel.textContent='Rolling average period for '+((display && display.title) ? display.title : 'time series')+' (observations)';
        target.parentNode.insertBefore(rollerLabel,target);
    }
});


const mapDisplays = displayConfig.filter(function(display){ return display.type==='map'; });
const stationMapInfo = <?= json_encode([
    'name' => $station ? ($station['name'] ?? $stationCode) : '',
    'maps' => $mapData
],JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

mapDisplays.forEach(function(display){
    const target=document.getElementById('map-'+display.id);
    const info=stationMapInfo.maps[display.id] || null;

    if(!target || !info || info.latitude===null || info.longitude===null){
        return;
    }

    const lat=Number(info.latitude);
    const lon=Number(info.longitude);
    const zoom=Number(display.zoom || 11);

    const map=L.map(target,{
        scrollWheelZoom:false
    }).setView([lat,lon],zoom);

    L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom:19,
            attribution:'&copy; OpenStreetMap contributors'
        }
    ).addTo(map);

    L.marker([lat,lon])
        .addTo(map)
        .bindPopup(stationMapInfo.name);
});

function svgEl(tag,attrs) {
    const NS='http://www.w3.org/2000/svg';
    const el=document.createElementNS(NS,tag);

    Object.keys(attrs||{}).forEach(function(key){
        el.setAttribute(key,attrs[key]);
    });

    return el;
}

function valueColor(v,min,max) {
    if(max<=min)return 'rgb(102,194,165)';

    const f=Math.max(0,Math.min(1,(v-min)/(max-min)));
    const stops=[
        [49,54,149],
        [50,136,189],
        [102,194,165],
        [254,224,139],
        [244,109,67],
        [215,48,39]
    ];

    const scaled=f*(stops.length-1);
    const i=Math.min(stops.length-2,Math.floor(scaled));
    const local=scaled-i;

    const rgb=stops[i].map(function(a,j){
        return Math.round(a+local*(stops[i+1][j]-a));
    });

    return 'rgb('+rgb.join(',')+')';
}

Object.keys(profileData).forEach(function(id){
    const svg=document.getElementById('profile-svg-'+id);
    if(!svg)return;

    const source=profileData[id];
    const points=source.points;
    if(!points.length)return;

    const values=points.map(p=>Number(p.value));
    const positions=points.map(p=>Number(p.position));

    const currentMin=Math.min.apply(null,values);
    const currentMax=Math.max.apply(null,values);
    const minVal=source.color_min===null || source.color_min===undefined ? currentMin : Number(source.color_min);
    const maxVal=source.color_max===null || source.color_max===undefined ? currentMax : Number(source.color_max);
    const minPos=Math.min.apply(null,positions);
    const maxPos=Math.max.apply(null,positions);

    const columnX=185;
    const columnY=45;
    const columnWidth=180;
    const columnHeight=500;

    function posToY(pos) {
        if(maxPos===minPos)return columnY+columnHeight/2;
        return columnY+((pos-minPos)/(maxPos-minPos))*columnHeight;
    }

    const defs=svgEl('defs',{});
    const gradient=svgEl('linearGradient',{
        id:'profile-gradient-'+id,
        x1:'0%',x2:'0%',y1:'0%',y2:'100%'
    });

    points.forEach(function(p){
        const offset=maxPos===minPos ? 0 : ((p.position-minPos)/(maxPos-minPos))*100;

        gradient.appendChild(svgEl('stop',{
            offset:offset+'%',
            'stop-color':valueColor(Number(p.value),minVal,maxVal)
        }));
    });

    defs.appendChild(gradient);
    svg.appendChild(defs);

    svg.appendChild(svgEl('line',{
        x1:columnX-35,y1:columnY,
        x2:columnX+columnWidth+35,y2:columnY,
        stroke:'#00c2ff','stroke-width':3
    }));

    const surface=svgEl('text',{
        x:columnX+columnWidth/2,
        y:27,
        'text-anchor':'middle',
        fill:'#7a9bbf',
        'font-size':13,
        'font-family':'system-ui'
    });
    surface.textContent=source.coordinate_label.toUpperCase();
    svg.appendChild(surface);

    svg.appendChild(svgEl('rect',{
        x:columnX,
        y:columnY,
        width:columnWidth,
        height:columnHeight,
        rx:6,
        fill:'url(#profile-gradient-'+id+')',
        stroke:'rgba(255,255,255,.28)',
        'stroke-width':1
    }));

    points.forEach(function(p){
        const y=posToY(Number(p.position));

        svg.appendChild(svgEl('line',{
            x1:columnX-12,y1:y,
            x2:columnX+columnWidth+12,y2:y,
            stroke:'rgba(255,255,255,.25)',
            'stroke-width':1
        }));

        svg.appendChild(svgEl('circle',{
            cx:columnX,cy:y,r:5,
            fill:'#fff',
            stroke:'#0a1628',
            'stroke-width':2
        }));

        const left=svgEl('text',{
            x:columnX-22,
            y:y+5,
            'text-anchor':'end',
            fill:'#7a9bbf',
            'font-size':13,
            'font-family':'monospace'
        });
        left.textContent=Number(p.position).toFixed(1)+' '+source.coordinate_units;
        svg.appendChild(left);

        const right=svgEl('text',{
            x:columnX+columnWidth+22,
            y:y+5,
            fill:'#e8f0fe',
            'font-size':14,
            'font-family':'monospace'
        });
        right.textContent=Number(p.value).toFixed(1)+' '+source.value_units;
        svg.appendChild(right);
    });
});

function compassPoint(cx,cy,radius,degrees) {
    const radians=(degrees-90)*Math.PI/180;

    return {
        x:cx+radius*Math.cos(radians),
        y:cy+radius*Math.sin(radians)
    };
}

function cardinal(deg) {
    const names=['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return names[Math.round((((deg%360)+360)%360)/22.5)%16];
}

Object.keys(directionalData).forEach(function(id){
    const svg=document.getElementById('direction-svg-'+id);
    if(!svg)return;

    const source=directionalData[id];
    const data=source.points;
    if(!data.length)return;

    const cx=250;
    const cy=194;
    const radius=132;
    const accent='#00c2ff';
    const ring='rgba(122,155,191,.42)';
    const text='#e8f0fe';
    const muted='#7a9bbf';

    [radius,radius*.68,radius*.36].forEach(function(r,i){
        svg.appendChild(svgEl('circle',{
            cx:cx,cy:cy,r:r,
            fill:i===0?'rgba(10,22,40,.28)':'none',
            stroke:ring,
            'stroke-width':i===0?1.5:1,
            'stroke-dasharray':i===0?'none':'4 6'
        }));
    });

    for(let deg=0;deg<360;deg+=30) {
        const outer=compassPoint(cx,cy,radius,deg);
        const inner=compassPoint(cx,cy,radius-(deg%90===0?13:7),deg);

        svg.appendChild(svgEl('line',{
            x1:inner.x,y1:inner.y,
            x2:outer.x,y2:outer.y,
            stroke:ring,
            'stroke-width':deg%90===0?2:1
        }));
    }

    [['N',0],['E',90],['S',180],['W',270]].forEach(function(item){
        const p=compassPoint(cx,cy,radius+25,item[1]);
        const t=svgEl('text',{
            x:p.x,y:p.y+6,
            'text-anchor':'middle',
            fill:text,
            'font-size':18,
            'font-weight':600,
            'font-family':'system-ui'
        });
        t.textContent=item[0];
        svg.appendChild(t);
    });

    const trail=data.slice().reverse();

    trail.forEach(function(point,reverseIndex){
        const originalIndex=data.length-1-reverseIndex;
        const ageFraction=data.length>1 ? originalIndex/(data.length-1) : 0;
        const opacity=.16+(1-ageFraction)*.55;
        const p=compassPoint(cx,cy,radius-19,Number(point.direction));

        svg.appendChild(svgEl('circle',{
            cx:p.x,cy:p.y,
            r:originalIndex===0?7:5,
            fill:accent,
            opacity:originalIndex===0?1:opacity,
            stroke:originalIndex===0?'#fff':'none',
            'stroke-width':originalIndex===0?2:0
        }));
    });

    const latest=data[0];
    const needleTip=compassPoint(cx,cy,radius-30,Number(latest.direction));
    const needleBack=compassPoint(cx,cy,23,Number(latest.direction)+180);

    svg.appendChild(svgEl('line',{
        x1:needleBack.x,y1:needleBack.y,
        x2:needleTip.x,y2:needleTip.y,
        stroke:accent,
        'stroke-width':6,
        'stroke-linecap':'round'
    }));

    const tip=compassPoint(cx,cy,radius-22,Number(latest.direction));
    const left=compassPoint(cx,cy,radius-42,Number(latest.direction)-5);
    const right=compassPoint(cx,cy,radius-42,Number(latest.direction)+5);

    svg.appendChild(svgEl('polygon',{
        points:tip.x+','+tip.y+' '+left.x+','+left.y+' '+right.x+','+right.y,
        fill:accent
    }));

    svg.appendChild(svgEl('circle',{
        cx:cx,cy:cy,r:9,
        fill:accent,
        stroke:'#fff',
        'stroke-width':2
    }));

    const primary=svgEl('text',{
        x:cx,y:cy+208,
        'text-anchor':'middle',
        fill:text,
        'font-size':22,
        'font-weight':600,
        'font-family':'monospace'
    });

    primary.textContent=cardinal(Number(latest.direction))+'  '+Math.round(Number(latest.direction))+'°';
    svg.appendChild(primary);

    const secondary=svgEl('text',{
        x:cx,y:cy+250,
        'text-anchor':'middle',
        fill:muted,
        'font-size':24,
        'font-family':'system-ui'
    });

    secondary.textContent=source.direction_label;
    svg.appendChild(secondary);
});

Object.keys(polarData).forEach(function(id){
 const target=document.getElementById('polar-'+id); if(!target)return;
 const source=polarData[id], display=getDisplay(id)||{};
 const raw=(source.points||[]).map(function(p){return {direction:Number(p.direction),magnitude:Number(p.magnitude)};})
  .filter(function(p){return Number.isFinite(p.direction)&&Number.isFinite(p.magnitude)&&p.magnitude>=0;});
 if(!raw.length)return;
 const n=Math.max(8,Math.min(36,Number(display.windrose_sectors||16))), w=360/n;
 let br=Array.isArray(display.windrose_breaks)?display.windrose_breaks.map(Number).filter(Number.isFinite).sort(function(a,b){return a-b;}):[];
 const maxMag=Math.max.apply(null,raw.map(function(p){return p.magnitude;}));
 if(br.length<2){const top=maxMag>0?maxMag:1;br=[0,top*.2,top*.4,top*.6,top*.8,top+Number.EPSILON];}
 else {if(br[0]>0)br.unshift(0);if(br[br.length-1]<=maxMag)br.push(maxMag+Number.EPSILON);}
 const counts=Array.from({length:br.length-1},function(){return Array(n).fill(0);});
 raw.forEach(function(p){const d=((p.direction%360)+360)%360,sec=Math.floor((d+w/2)/w)%n;let bin=br.length-2;
  for(let b=0;b<br.length-1;b++){if(p.magnitude>=br[b]&&p.magnitude<br[b+1]){bin=b;break;}} counts[bin][sec]++;});
 const pct=counts.map(function(row){return row.map(function(c){return c*100/raw.length;});}), totals=Array(n).fill(0);
 for(let i=0;i<n;i++)for(let b=0;b<pct.length;b++)totals[i]+=pct[b][i];
 const radialMax=Math.max(5,Math.ceil(Math.max.apply(null,totals.concat([1]))/5)*5),NS='http://www.w3.org/2000/svg';
 function el(name,a){const x=document.createElementNS(NS,name);Object.keys(a||{}).forEach(function(k){x.setAttribute(k,String(a[k]));});return x;}
 function pt(cx,cy,r,d){const a=(d-90)*Math.PI/180;return[cx+r*Math.cos(a),cy+r*Math.sin(a)];}
 function sectorPath(cx,cy,r0,r1,a0,a1){const p1=pt(cx,cy,r1,a0),p2=pt(cx,cy,r1,a1),p3=pt(cx,cy,r0,a1),p4=pt(cx,cy,r0,a0),lg=(a1-a0)>180?1:0;
  if(r0<=.01)return 'M '+cx+' '+cy+' L '+p1[0]+' '+p1[1]+' A '+r1+' '+r1+' 0 '+lg+' 1 '+p2[0]+' '+p2[1]+' Z';
  return 'M '+p1[0]+' '+p1[1]+' A '+r1+' '+r1+' 0 '+lg+' 1 '+p2[0]+' '+p2[1]+' L '+p3[0]+' '+p3[1]+' A '+r0+' '+r0+' 0 '+lg+' 0 '+p4[0]+' '+p4[1]+' Z';}
 function fmt(v){return Number(v).toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1});}
 function card(d){const c=['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];return c[Math.round((((d%360)+360)%360)/22.5)%16];}
 target.innerHTML='';
 const svg=el('svg',{viewBox:'0 0 760 470',preserveAspectRatio:'xMidYMid meet',role:'img','aria-labelledby':'polar-title-'+id+' polar-desc-'+id});
 let x=el('title',{id:'polar-title-'+id});x.textContent=display.title||'Polar plot';svg.appendChild(x);
 x=el('desc',{id:'polar-desc-'+id});x.textContent='Wind rose showing percentage of observations by direction and '+(source.magnitude_label||'magnitude')+'.';svg.appendChild(x);
 const cx=235,cy=225,R=175,text='#263238',muted='#455a64',grid='#90a4ae',st=getComputedStyle(document.documentElement);
 const colors=[st.getPropertyValue('--accent').trim()||'#1976d2',st.getPropertyValue('--teal').trim()||'#00897b',st.getPropertyValue('--amber').trim()||'#f5a623',st.getPropertyValue('--purple').trim()||'#7e57c2','#ef5350','#66bb6a','#8d6e63','#78909c'];
 for(let r=1;r<=4;r++){const rr=R*r/4;svg.appendChild(el('circle',{cx:cx,cy:cy,r:rr,fill:'none',stroke:grid,'stroke-opacity':.7}));const l=el('text',{x:cx+5,y:cy-rr+14,fill:muted,'font-size':22});l.textContent=Math.round(radialMax*r/4)+'%';svg.appendChild(l);}
 for(let i=0;i<n;i++){const q=pt(cx,cy,R,i*w);svg.appendChild(el('line',{x1:cx,y1:cy,x2:q[0],y2:q[1],stroke:grid,'stroke-opacity':.55}));}
 const gap=Math.min(1.2,w*.08);
 for(let i=0;i<n;i++){let cum=0;for(let b=0;b<pct.length;b++){const val=pct[b][i];if(val<=0)continue;const r0=R*cum/radialMax;cum+=val;const r1=Math.min(R,R*cum/radialMax);const q=el('path',{d:sectorPath(cx,cy,r0,r1,i*w-w/2+gap,i*w+w/2-gap),fill:colors[b%colors.length],stroke:'#fff','stroke-width':.7,tabindex:0});const tip=el('title',{});tip.textContent=card(i*w)+' · '+fmt(br[b])+'–'+fmt(br[b+1])+(source.magnitude_units?' '+source.magnitude_units:'')+' · '+fmt(val)+'%';q.appendChild(tip);svg.appendChild(q);}}
 const every=n>16?Math.ceil(n/16):1;for(let i=0;i<n;i+=every){const q=pt(cx,cy,R+34,i*w),l=el('text',{x:q[0],y:q[1]+4,'text-anchor':'middle',fill:text,'font-size':24,'font-weight':700});l.textContent=card(i*w);svg.appendChild(l);}
 const lx=505,ly=95,lt=el('text',{x:lx,y:ly-22,fill:text,'font-size':24,'font-weight':700});lt.textContent=source.magnitude_units?source.magnitude_label+' ('+source.magnitude_units+')':source.magnitude_label;svg.appendChild(lt);
 for(let b=0;b<br.length-1;b++){const y=ly+b*34;svg.appendChild(el('rect',{x:lx,y:y,width:22,height:22,rx:2,fill:colors[b%colors.length]}));const l=el('text',{x:lx+30,y:y+20,fill:text,'font-size':22});l.textContent=fmt(br[b])+'–'+fmt(br[b+1])+(source.magnitude_units?' '+source.magnitude_units:'');svg.appendChild(l);}
 target.appendChild(svg);
});
</script>


<script>
(function(){
    const clock=document.getElementById('slim-local-clock');
    if(!clock)return;

    const timeZone=<?= json_encode($stationTimezone,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

    const formatter=new Intl.DateTimeFormat(undefined,{
        timeZone:timeZone,
        year:'numeric',
        month:'short',
        day:'numeric',
        hour:'numeric',
        minute:'2-digit',
        second:'2-digit',
        hour12:true
    });

    function updateSlimClock(){
        clock.textContent=formatter.format(new Date());
    }

    updateSlimClock();
    setInterval(updateSlimClock,1000);

    const autoRefreshEnabled=<?= !empty($system['auto_refresh_enabled'])?'true':'false' ?>;
    const refreshSeconds=<?= max(15,(int)($system['refresh_seconds'] ?? 600)) ?>;
    if(autoRefreshEnabled){
        window.setTimeout(function(){ window.location.reload(); },refreshSeconds*1000);
    }
})();
</script>


</main>

<style>
.slim-public-admin-link{
    margin:24px 0 12px;
    text-align:center;
    font-size:13px;
    line-height:1.35;
    opacity:.65;
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
    <a href="<?= $slimHealthDashboard ? '../' : '../admin/' ?>">admin</a>
</div>

</body>
</html>