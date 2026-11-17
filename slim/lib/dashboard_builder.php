<?php
require_once __DIR__ . '/page_frame.php';
require_once __DIR__ . '/dashboard_backups.php';
// Shared dashboard editor for public and health dashboards.
$slimDashboardMode = ($slimDashboardMode ?? 'public') === 'health' ? 'health' : 'public';
$slimHealthDashboard = $slimDashboardMode === 'health';
$slimDashboardKind = $slimHealthDashboard ? 'health' : 'public';
$slimDashboardFlashKey = $slimHealthDashboard ? 'health_dashboard_flash' : 'dashboard_flash';
$slimDashboardSetupTitle = $slimHealthDashboard ? 'SLIM Station Health Dashboard Setup' : 'SLIM Dashboard Setup';
$slimDashboardDescription = $slimHealthDashboard ? 'station health dashboard' : 'public dashboard';
$slimDashboardViewLabel = $slimHealthDashboard ? 'View Health Dashboard' : 'View Dashboard';
$slimDashboardViewBase = $slimHealthDashboard ? './?station=' : '../../dashboard/?station=';

if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(24));
}

function slim_dashboard_save(string $code,string $kind,array $config): void {
    lakestat_write_php_config(lakestat_display_config_path($code,$kind),$config);
    try { slim_dashboard_backup_save($code,$kind,$config); }
    catch(Throwable $e) { error_log('SLIM dashboard backup failed: '.$e->getMessage()); $_SESSION['dashboard_backup_warning']='Dashboard saved, but automatic JSON backup failed. Check private directory permissions.'; }
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['dashboard_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        throw new RuntimeException('Session validation failed. Reload the page and try again.');
    }
}

function clean_float_or_null($value) {
    if ($value === '' || $value === null) return null;
    if (!is_numeric($value)) throw new RuntimeException('A numeric display option contains an invalid value.');
    return (float)$value;
}

function require_field(array $station, string $field, string $label): string {
    $field = trim($field);
    if ($field === '' || !isset($station['fields'][$field])) {
        throw new RuntimeException($label . ' must reference a configured station field.');
    }
    return $field;
}

function normalize_field_list(array $station, $fields): array {
    if (!is_array($fields)) $fields = [];
    $out = [];

    foreach ($fields as $field) {
        $field = trim((string)$field);
        if ($field !== '' && isset($station['fields'][$field]) && !in_array($field,$out,true)) {
            $out[] = $field;
        }
    }

    if (!$out) throw new RuntimeException('Select at least one configured field.');
    return $out;
}


function upload_error_message(int $code): string {
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'The image exceeds the server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'The image exceeds the form upload size limit.',
        UPLOAD_ERR_PARTIAL    => 'The image was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No image file was received.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image to temporary storage.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
    ];

    return isset($messages[$code])
        ? $messages[$code]
        : 'Unknown upload error code ' . $code . '.';
}


function maybe_replace_station_image(string $stationCode, array $file, string $existingPath): string {
    if (!isset($file['error']) || is_array($file['error'])) {
        return $existingPath;
    }

    if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    return save_station_image($stationCode, $file);
}

function save_station_image(string $stationCode, array $file): string {
    if (!isset($file['error'])) {
        $fileUploads = ini_get('file_uploads');
        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'not reported';
        $receivedKeys = !empty($_FILES) ? implode(', ', array_keys($_FILES)) : 'none';

        throw new RuntimeException(
            'No image upload reached PHP. ' .
            'file_uploads=' . ($fileUploads === false ? 'unknown' : $fileUploads) .
            '; upload_max_filesize=' . ($uploadMax === false ? 'unknown' : $uploadMax) .
            '; post_max_size=' . ($postMax === false ? 'unknown' : $postMax) .
            '; request Content-Type=' . $contentType .
            '; files received=' . $receivedKeys . '.'
        );
    }

    if (is_array($file['error'])) {
        throw new RuntimeException('Unexpected multiple-file upload data was received.');
    }

    $uploadError = (int)$file['error'];

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($uploadError));
    }

    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
        throw new RuntimeException('PHP reported a successful upload but no temporary file was provided.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The uploaded image did not pass PHP upload validation.');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be 5 MB or smaller.');
    }

    $tmp = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Image must be JPEG, PNG, or WebP.');
    }

    $stationCode = lakestat_station_code($stationCode);

    $root = dirname(__DIR__);
    $relativeDir = 'assets/stations/' . $stationCode;
    $absoluteDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create station image directory.');
    }

    $filename = 'dashboard_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Unable to save uploaded image.');
    }

    @chmod($absolutePath, 0644);

    return $relativeDir . '/' . $filename;
}

$error=''; $message='';

if (!empty($_SESSION[$slimDashboardFlashKey])) {
    $message=(string)$_SESSION[$slimDashboardFlashKey];
    unset($_SESSION[$slimDashboardFlashKey]);
}
$stations = lakestat_list_stations();
$selectedCode='';

if (isset($_GET['station'])) $selectedCode = lakestat_station_code((string)$_GET['station']);
elseif (isset($_POST['station'])) $selectedCode = lakestat_station_code((string)$_POST['station']);
elseif ($stations) { $keys=array_keys($stations); $selectedCode=$keys[0]; }

$station=null;
$displayConfig=['station'=>$selectedCode,'displays'=>[]];

try {
    if ($selectedCode !== '') {
        $station=lakestat_station_config($selectedCode);
        $displayConfig=($slimHealthDashboard ? lakestat_health_display_config($selectedCode) : lakestat_display_config($selectedCode));

        // Ensure each dashboard configuration includes the date-range selector.
        $hasDateRangeDisplay=false;

        foreach(($displayConfig['displays'] ?? []) as $existingDisplay) {
            if(($existingDisplay['type'] ?? '')==='date_range') {
                $hasDateRangeDisplay=true;
                break;
            }
        }

        if(!$hasDateRangeDisplay) {
            $displayConfig['displays'][]=[
                'id'=>'date_range_selector',
                'type'=>'date_range',
                'title'=>'Select Date Range',
                'order'=>count($displayConfig['displays'])+1,
                'width'=>'full'
            ];

            slim_dashboard_save($selectedCode,$slimDashboardKind,$displayConfig);
        }
    }

    if($station && ($_GET['export_dashboard']??'')==='1') {
        $json=json_encode(['format'=>'slim-dashboard','format_version'=>1,'kind'=>$slimDashboardKind,'station'=>$selectedCode,'saved_at'=>gmdate('c'),'configuration'=>$displayConfig],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="slim_'.$slimDashboardKind.'_'. $selectedCode.'_dashboard.json"');
        header('X-Content-Type-Options: nosniff');
        echo $json;exit;
    }

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();
        if (!$station) throw new RuntimeException('Select a station first.');

        $action=$_POST['action'] ?? '';

        if ($action==='save_dashboard_info') {
            $displayConfig['header_info']=[
                'show_station_code'=>isset($_POST['show_station_code']),
                'show_coordinates'=>isset($_POST['show_coordinates']),
                'show_last_received'=>isset($_POST['show_last_received']),
                'show_local_time'=>isset($_POST['show_local_time'])
            ];
            slim_dashboard_save($selectedCode,$slimDashboardKind,$displayConfig);
            $_SESSION[$slimDashboardFlashKey]='Dashboard information settings saved.';
            header('Location: ?station=' . urlencode($selectedCode));
            exit;
        }

        if($action==='import_dashboard' || $action==='restore_dashboard') {
            if($action==='import_dashboard') {
                $file=$_FILES['dashboard_json'] ?? null;
                if(!is_array($file) || ($file['error']??1)!==UPLOAD_ERR_OK || ($file['size']??0)>1048576 || !is_uploaded_file((string)($file['tmp_name']??''))) throw new RuntimeException('Upload a valid dashboard JSON file (maximum 1 MB).');
                $raw=file_get_contents($file['tmp_name']);
            } else {
                $version=(string)($_POST['backup_version']??'current');
                $path=slim_dashboard_backup_path($selectedCode,$slimDashboardKind,$version);
                if(!is_file($path)) throw new RuntimeException('The selected saved dashboard version is unavailable.');
                $raw=file_get_contents($path);
            }
            if(!is_string($raw)) throw new RuntimeException('Cannot read dashboard configuration.');
            $restored=slim_dashboard_backup_parse($raw,$slimDashboardKind);
            $restored['station']=$selectedCode;
            foreach($restored['displays'] as &$entry) {
                if(!is_array($entry) || !is_string($entry['type']??null) || !is_string($entry['id']??null)) throw new RuntimeException('Invalid display in backup.');
                if(($entry['type']??'')==='image') $entry['image_path']='';
            }
            unset($entry);
            slim_dashboard_save($selectedCode,$slimDashboardKind,$restored);
            $_SESSION[$slimDashboardFlashKey]='Dashboard configuration restored. Review field references and images.';
            header('Location: ?station='.rawurlencode($selectedCode));exit;
        }

        if ($action==='copy_dashboard') {
            $sourceCode=lakestat_station_code((string)($_POST['source_station'] ?? ''));

            if($sourceCode===$selectedCode) {
                throw new RuntimeException('Choose a different source station.');
            }

            // Requiring a valid station configuration prevents arbitrary file
            // access and keeps dashboard copies limited to known SLIM stations.
            lakestat_station_config($sourceCode);
            $sourceConfig=($slimHealthDashboard ? lakestat_health_display_config($sourceCode) : lakestat_display_config($sourceCode));

            if(empty($sourceConfig['displays'])) {
                throw new RuntimeException('The selected source station does not have a '.$slimDashboardDescription.' configuration to copy.');
            }

            $copiedConfig=$sourceConfig;
            $copiedConfig['station']=$selectedCode;

            // Uploaded dashboard images belong to the source station's asset
            // directory. Preserve the image display itself, but require a
            // destination-specific image after copying.
            foreach($copiedConfig['displays'] as &$copiedDisplay) {
                if(($copiedDisplay['type'] ?? '')==='image') {
                    $copiedDisplay['image_path']='';
                }
            }
            unset($copiedDisplay);

            slim_dashboard_save($selectedCode,$slimDashboardKind,$copiedConfig);

            $_SESSION[$slimDashboardFlashKey]=
                'Copied the '.$slimDashboardDescription.' configuration from '.$sourceCode.
                '. Review the copied displays and update variables or other station-specific settings as needed.';

            header('Location: ?station='.rawurlencode($selectedCode));
            exit;
        }

        if ($action==='add_display' || $action==='update_display') {
            $isEdit = ($action==='update_display');
            $existingDisplay = null;
            $existingIndex = null;

            if ($isEdit) {
                $targetId = (string)($_POST['display_id'] ?? '');

                foreach ($displayConfig['displays'] as $i=>$candidate) {
                    if (($candidate['id'] ?? '') === $targetId) {
                        $existingDisplay = $candidate;
                        $existingIndex = $i;
                        break;
                    }
                }

                if ($existingDisplay === null || $existingIndex === null) {
                    throw new RuntimeException('The display to edit could not be found.');
                }
            }

            $type=trim((string)($_POST['display_type'] ?? ($existingDisplay['type'] ?? '')));
            $title=trim((string)($_POST['title'] ?? ''));

            if ($title==='') throw new RuntimeException('Display title is required.');

            $width=(string)($_POST['display_width'] ?? 'full');
            if(!in_array($width,['full','half','third'],true))$width='full';

            $display=[
                'id'=>$isEdit ? (string)$existingDisplay['id'] : bin2hex(random_bytes(6)),
                'type'=>$type,
                'title'=>$title,
                'order'=>$isEdit ? (int)($existingDisplay['order'] ?? ($existingIndex+1)) : count($displayConfig['displays'])+1,
                'width'=>$width
            ];

            if ($type==='date_range') {
                if(!$isEdit) {
                    foreach($displayConfig['displays'] as $candidate) {
                        if(($candidate['type'] ?? '')==='date_range') {
                            throw new RuntimeException('This dashboard already has a Date Range Selector.');
                        }
                    }
                }

            } elseif ($type==='custom_text') {
                $display['header_text']=trim((string)($_POST['custom_header_text'] ?? ''));
                $display['body_text']=slim_sanitize_content_html((string)($_POST['custom_body_text'] ?? ''));

                if($display['header_text']==='' && $display['body_text']==='') {
                    throw new RuntimeException('Add header text, body text, or both.');
                }

            } elseif ($type==='line') {
                $lineFields = $_POST['line_series_field'] ?? [];
                $lineLabels = $_POST['line_series_label'] ?? [];
                $lineColors = $_POST['line_series_color'] ?? [];
                $lineStyles = $_POST['line_series_style'] ?? [];
                $lineWidths = $_POST['line_series_width'] ?? [];
                $linePointModes = $_POST['line_series_points'] ?? [];
                $linePointColors = $_POST['line_series_point_color'] ?? [];
                $linePointSymbols = $_POST['line_series_point_symbol'] ?? [];
                $linePointSizes = $_POST['line_series_point_size'] ?? [];
                $lineAxes = $_POST['line_series_axis'] ?? [];

                $series = [];

                foreach ($lineFields as $i=>$field) {
                    $field = trim((string)$field);
                    if ($field === '') continue;

                    $field = require_field($station,$field,'Line series field');

                    $label = trim((string)($lineLabels[$i] ?? ''));
                    if ($label === '') {
                        $meta = $station['fields'][$field];
                        $label = !empty($meta['label']) ? $meta['label'] : $field;
                    }

                    $color = trim((string)($lineColors[$i] ?? ''));
                    if ($color === '') $color = '#3366cc';

                    $style=(string)($lineStyles[$i] ?? 'solid');
                    if(!in_array($style,['solid','dashed','dotted','dashdot'],true)) {
                        $style='solid';
                    }

                    $strokeWidth=(float)($lineWidths[$i] ?? 1.5);
                    $strokeWidth=max(0.1,min(10,$strokeWidth));

                    $showPoints=(($linePointModes[$i] ?? 'hide')==='show');

                    $pointColor=trim((string)($linePointColors[$i] ?? ''));
                    if($pointColor==='')$pointColor=$color;

                    $pointSymbol=(string)($linePointSymbols[$i] ?? 'circle');
                    if(!in_array($pointSymbol,['circle','square','triangle','diamond'],true)) {
                        $pointSymbol='circle';
                    }

                    $pointSize=(float)($linePointSizes[$i] ?? 3);
                    $pointSize=max(1,min(15,$pointSize));

                    $axis=(string)($lineAxes[$i] ?? 'y');
                    if(!in_array($axis,['y','y2'],true))$axis='y';

                    $series[] = [
                        'field' => $field,
                        'label' => $label,
                        'color' => $color,
                        'line_style' => $style,
                        'stroke_width' => $strokeWidth,
                        'show_points' => $showPoints,
                        'point_color' => $pointColor,
                        'point_symbol' => $pointSymbol,
                        'point_size' => $pointSize,
                        'axis' => $axis
                    ];
                }

                if (!$series) {
                    throw new RuntimeException('Add at least one line series.');
                }

                $display['series'] = $series;


                $display['y_label'] = trim((string)($_POST['line_y_label'] ?? ''));
                $display['y2_label'] = trim((string)($_POST['line_y2_label'] ?? ''));
                $display['y_min'] = clean_float_or_null($_POST['line_y_min'] ?? '');
                $display['y_max'] = clean_float_or_null($_POST['line_y_max'] ?? '');
                $display['show_legend'] = isset($_POST['line_show_legend']);

                $display['dygraph'] = [
                    'roll_period' => max(1,(int)($_POST['line_roll_period'] ?? 1)),
                    'show_roller' => isset($_POST['line_show_roller']),
                    'show_labels_on_highlight' => isset($_POST['line_show_highlight_labels']),
                    'labels_show_zero_values' => isset($_POST['line_show_zero_values']),
                    'highlight_circle_size' => max(0,(int)($_POST['line_highlight_circle_size'] ?? 8))
                ];

            } elseif ($type==='gauge') {
                $display['field']=require_field($station,(string)($_POST['gauge_field'] ?? ''),'Gauge field');
                $display['recent_n']=max(1,(int)($_POST['gauge_recent_n'] ?? 3));
                $display['min']=clean_float_or_null($_POST['gauge_min'] ?? '');
                $display['max']=clean_float_or_null($_POST['gauge_max'] ?? '');
                $display['units']=trim((string)($_POST['gauge_units'] ?? ''));
                $display['decimals']=max(0,min(6,(int)($_POST['gauge_decimals'] ?? 1)));

                $gaugeStyle=(string)($_POST['gauge_style'] ?? 'ring');
                $allowedGaugeStyles=['ring','semicircle','bar','numeric'];
                $display['gauge_style']=in_array($gaugeStyle,$allowedGaugeStyles,true)
                    ? $gaugeStyle : 'ring';

                $gaugeColor=(string)($_POST['gauge_color'] ?? '#37b7c3');
                $gaugeBackgroundColor=(string)($_POST['gauge_background_color'] ?? '#23384a');
                $display['gauge_color']=preg_match('/^#[0-9A-Fa-f]{6}$/',$gaugeColor)
                    ? $gaugeColor : '#37b7c3';
                $display['gauge_background_color']=preg_match('/^#[0-9A-Fa-f]{6}$/',$gaugeBackgroundColor)
                    ? $gaugeBackgroundColor : '#23384a';

            } elseif ($type==='polar') {
                $display['direction_field']=require_field($station,(string)($_POST['polar_direction'] ?? ''),'Direction field');
                $display['magnitude_field']=require_field($station,(string)($_POST['polar_magnitude'] ?? ''),'Magnitude field');
                $display['windrose_sectors']=max(8,min(36,(int)($_POST['windrose_sectors'] ?? 16)));

                $breakText=trim((string)($_POST['windrose_breaks'] ?? ''));
                $breaks=[];

                if($breakText!==''){
                    foreach(preg_split('/[\s,;]+/',$breakText) as $piece){
                        if($piece==='' || !is_numeric($piece))continue;
                        $breaks[]=(float)$piece;
                    }

                    $breaks=array_values(array_unique($breaks));
                    sort($breaks,SORT_NUMERIC);
                }

                $display['windrose_breaks']=$breaks;
                $display['recent_n']=max(1,(int)($_POST['polar_recent_n'] ?? 30));
                $display['direction_units']='degrees';
                $display['magnitude_units']=trim((string)($_POST['polar_units'] ?? ''));

            } elseif ($type==='profile') {
                $fields=$_POST['profile_field'] ?? [];
                $positions=$_POST['profile_position'] ?? [];
                $points=[];

                foreach ($fields as $i=>$field) {
                    $field=trim((string)$field);
                    $position=isset($positions[$i]) ? trim((string)$positions[$i]) : '';

                    if ($field==='' && $position==='') continue;

                    $field=require_field($station,$field,'Profile field');

                    if ($position==='' || !is_numeric($position)) {
                        throw new RuntimeException('Each profile field requires a numeric position.');
                    }

                    $points[]=['field'=>$field,'position'=>(float)$position];
                }

                if (!$points) throw new RuntimeException('Add at least one profile point.');

                usort($points,function($a,$b){
                    if($a['position']==$b['position'])return 0;
                    return ($a['position']<$b['position'])?-1:1;
                });

                $display['coordinate_label']=trim((string)($_POST['profile_coordinate_label'] ?? 'Depth'));
                $display['coordinate_units']=trim((string)($_POST['profile_coordinate_units'] ?? 'm'));
                $display['recent_n']=max(1,(int)($_POST['profile_recent_n'] ?? 1));

                $profileColorMin=trim((string)($_POST['profile_color_min'] ?? ''));
                $profileColorMax=trim((string)($_POST['profile_color_max'] ?? ''));

                if($profileColorMin!=='' && !is_numeric($profileColorMin)) {
                    throw new RuntimeException('Profile color scale minimum must be numeric or left blank.');
                }
                if($profileColorMax!=='' && !is_numeric($profileColorMax)) {
                    throw new RuntimeException('Profile color scale maximum must be numeric or left blank.');
                }

                $display['color_min']=$profileColorMin==='' ? null : (float)$profileColorMin;
                $display['color_max']=$profileColorMax==='' ? null : (float)$profileColorMax;

                if($display['color_min']!==null && $display['color_max']!==null && $display['color_min'] >= $display['color_max']) {
                    throw new RuntimeException('Profile color scale maximum must be greater than the minimum.');
                }

                $display['points']=$points;

            } elseif ($type==='directional_recent') {
                $display['direction_field']=require_field($station,(string)($_POST['recent_direction'] ?? ''),'Direction field');
                $display['magnitude_field']=require_field($station,(string)($_POST['recent_magnitude'] ?? ''),'Magnitude field');
                $display['recent_n']=max(2,(int)($_POST['recent_n'] ?? 10));
                $display['magnitude_units']=trim((string)($_POST['recent_units'] ?? ''));

            } elseif ($type==='map') {
                if (!isset($station['latitude'],$station['longitude'])) {
                    throw new RuntimeException('This station does not have latitude/longitude coordinates.');
                }

                $locationMode=(string)($_POST['map_location_mode'] ?? 'static');
                $display['location_mode']=$locationMode==='live' ? 'live' : 'static';
                if($display['location_mode']==='live') {
                    $display['latitude_field']=require_field($station,(string)($_POST['map_latitude_field'] ?? ''),'Latitude variable');
                    $display['longitude_field']=require_field($station,(string)($_POST['map_longitude_field'] ?? ''),'Longitude variable');
                    $display['fallback_static']=isset($_POST['map_fallback_static']);
                }
                $display['zoom']=max(1,min(19,(int)($_POST['map_zoom'] ?? 11)));
                $display['height']=max(220,min(700,(int)($_POST['map_height'] ?? 360)));

            } elseif ($type==='image') {
                $existingImagePath = $isEdit ? (string)($existingDisplay['image_path'] ?? '') : '';

                $display['image_path']=$isEdit
                    ? maybe_replace_station_image($selectedCode,$_FILES['image_file'] ?? [],$existingImagePath)
                    : save_station_image($selectedCode,$_FILES['image_file'] ?? []);

                $display['caption']=trim((string)($_POST['image_caption'] ?? ''));
                $display['alt_text']=trim((string)($_POST['image_alt_text'] ?? ''));

                if ($display['alt_text']==='') {
                    $display['alt_text']=$title;
                }

            } else {
                throw new RuntimeException('Unknown display type.');
            }

            if ($isEdit) {
                $displayConfig['displays'][$existingIndex]=$display;
                $_SESSION[$slimDashboardFlashKey]='Display updated successfully.';
            } else {
                $displayConfig['displays'][]=$display;
                $_SESSION[$slimDashboardFlashKey]='Display added successfully.';
            }

            slim_dashboard_save($selectedCode,$slimDashboardKind,$displayConfig);

            header('Location: ?station=' . urlencode($selectedCode));
            exit;
        }

        if ($action==='delete_display') {
            $deleteId=(string)($_POST['display_id'] ?? '');
            $new=[];

            foreach($displayConfig['displays'] as $display) {
                if(($display['id'] ?? '')!==$deleteId)$new[]=$display;
            }

            foreach($new as $i=>&$display)$display['order']=$i+1;
            unset($display);

            $displayConfig['displays']=$new;

            slim_dashboard_save($selectedCode,$slimDashboardKind,$displayConfig);

            $message='Display deleted.';
        }

        if ($action==='move_display') {
            $displayId=(string)($_POST['display_id'] ?? '');
            $direction=(string)($_POST['direction'] ?? '');
            $index=null;

            foreach($displayConfig['displays'] as $i=>$display) {
                if(($display['id'] ?? '')===$displayId){$index=$i;break;}
            }

            if($index!==null) {
                $target=$direction==='position' ? (int)($_POST['target_position']??0)-1 : ($direction==='up' ? $index-1 : $index+1);

                if($target>=0 && $target<count($displayConfig['displays'])) {
                    $tmp=$displayConfig['displays'][$index];
                    if($direction==='position') { array_splice($displayConfig['displays'],$index,1); array_splice($displayConfig['displays'],$target,0,[$tmp]); }
                    else { $displayConfig['displays'][$index]=$displayConfig['displays'][$target]; $displayConfig['displays'][$target]=$tmp; }
                }

                foreach($displayConfig['displays'] as $i=>&$display)$display['order']=$i+1;
                unset($display);

                slim_dashboard_save($selectedCode,$slimDashboardKind,$displayConfig);

                $message='Display order updated.';
            }
        }
    }

} catch(Throwable $e) {
    $error=$e->getMessage();
}


$editId = '';
$editDisplay = null;

if (isset($_GET['edit'])) {
    $editId = (string)$_GET['edit'];
}

if ($editId !== '' && isset($displayConfig['displays']) && is_array($displayConfig['displays'])) {
    foreach ($displayConfig['displays'] as $candidate) {
        if (($candidate['id'] ?? '') === $editId) {
            $editDisplay = $candidate;
            break;
        }
    }
}

$fieldOptions=[];

if($station && !empty($station['fields'])) {
    foreach($station['fields'] as $key=>$field) {
        $label=!empty($field['label']) ? $field['label'] : $key;
        $units=!empty($field['units']) ? ' ('.$field['units'].')' : '';
        $fieldOptions[$key]=$label.$units;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($slimDashboardSetupTitle) ?></title>
<link rel="stylesheet" href="<?= h($slimHealthDashboard ? '../../assets/css/admin-dashboard.css' : '../../assets/css/admin-dashboard.css') ?>">

</head>
<body>
<?= slim_admin_session_bar() ?>
<div style="max-width:1100px;margin:12px auto 0;padding:0 20px;">
<a href="../" style="display:inline-block;text-decoration:none;font-weight:600;">&larr; Admin Home</a>
</div>

<div class="wrap">

<div class="card">
<h1><?= h($slimDashboardSetupTitle) ?></h1>
<p>
<a href="../station/">Station Setup</a> ·
<a id="viewDashboardLink" href="<?= h($slimDashboardViewBase) ?><?= urlencode($selectedCode) ?>"><?= h($slimDashboardViewLabel) ?></a>
</p>

<?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>

<form method="get" id="dashboardStationForm">
<label>Station <?= slim_help_button('Station','Select the monitoring station whose '.($slimHealthDashboard ? 'private station health' : 'public').' dashboard you want to configure. Each station has its own independent set and order of displays.') ?></label>
<select id="dashboardStationSelect" name="station" onchange="this.form.submit()">
<?php foreach($stations as $code=>$info): ?>
<option value="<?= h($code) ?>" <?= $selectedCode===$code?'selected':'' ?>>
<?= h($info['name']) ?> — <?= h($code) ?>
</option>
<?php endforeach; ?>
</select>
</form>
</div>

<?php if($station): ?>
<?php
$headerInfo=is_array($displayConfig['header_info'] ?? null) ? $displayConfig['header_info'] : [];
$showStationCode=!array_key_exists('show_station_code',$headerInfo) || !empty($headerInfo['show_station_code']);
$showCoordinates=!array_key_exists('show_coordinates',$headerInfo) || !empty($headerInfo['show_coordinates']);
$showLastReceived=!array_key_exists('show_last_received',$headerInfo) || !empty($headerInfo['show_last_received']);
$showLocalTime=!array_key_exists('show_local_time',$headerInfo) || !empty($headerInfo['show_local_time']);
?>
<div class="card">
<h2>Dashboard Information</h2>
<p class="hint">Choose which station information appears at the top of this <?= $slimHealthDashboard ? 'private station health' : 'public' ?> dashboard. Station Name remains the page heading.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="action" value="save_dashboard_info">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<label><input type="checkbox" name="show_station_code" value="1" <?= $showStationCode?'checked':'' ?>> Show station code</label>
<label><input type="checkbox" name="show_coordinates" value="1" <?= $showCoordinates?'checked':'' ?>> Show station coordinates</label>
<label><input type="checkbox" name="show_last_received" value="1" <?= $showLastReceived?'checked':'' ?>> Show last received time</label>
<label><input type="checkbox" name="show_local_time" value="1" <?= $showLocalTime?'checked':'' ?>> Show local time</label>
<p><button class="primary" type="submit">Save Dashboard Information</button></p>
</form>
</div>
<?php endif; ?>

<?php if($station): ?>
<div class="card">
<h2>Dashboard Configuration Backup</h2>
<p class="hint">SLIM automatically keeps the current and previous saved JSON versions in the private configuration directory. Export a portable copy or restore a saved version here. Restoring replaces the active dashboard.</p>
<p><a class="button secondary" href="?station=<?= urlencode($selectedCode) ?>&amp;export_dashboard=1">Export Dashboard JSON</a></p>
<form method="post" onsubmit="return confirm('Replace the current dashboard with this saved version?');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="restore_dashboard">
<label>Saved version</label><select name="backup_version"><option value="current">Current</option><option value="previous">Previous</option></select>
<p><button class="secondary" type="submit">Restore Saved Dashboard</button></p>
</form>
<form method="post" enctype="multipart/form-data" onsubmit="return confirm('Replace the current dashboard with the uploaded JSON configuration?');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="import_dashboard">
<label>Import Dashboard JSON</label><input type="file" name="dashboard_json" accept=".json,application/json" required>
<p><button class="secondary" type="submit">Import Dashboard</button></p>
</form>
</div>
<?php endif; ?>

<?php if($station && count($stations)>1): ?>
<div class="card">
<h2>Copy Dashboard Configuration</h2>
<p class="hint">
Copy the complete <?= h($slimDashboardDescription) ?> configuration from another station, then edit the copied displays for this station.
This replaces the current <?= h($slimDashboardDescription) ?> configuration for <?= h($selectedCode) ?>. The copy is independent; later changes to the source station will not affect this dashboard.
</p>

<form method="post" onsubmit="return confirm('Replace this station\'s current <?= h($slimDashboardDescription) ?> configuration with a copy from the selected station?');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="copy_dashboard">

<label>Copy from station <?= slim_help_button('Copy dashboard configuration','Copies all displays, layout, plot settings, colors, labels, and variable references from another station. After copying, review the dashboard because source variables may not exist or may have different meanings at the destination station.') ?></label>
<select name="source_station" required>
<option value="">Choose source station...</option>
<?php foreach($stations as $copyCode=>$copyInfo): ?>
<?php if($copyCode!==$selectedCode): ?>
<option value="<?= h($copyCode) ?>"><?= h($copyInfo['name']) ?> — <?= h($copyCode) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>

<p><button class="secondary" type="submit">Copy Dashboard Configuration</button></p>
</form>
</div>
<?php endif; ?>

<?php if($station): ?>
<div class="card">
<h2>Current displays</h2>

<?php if(!$displayConfig['displays']): ?><p>No displays configured yet.</p><?php endif; ?>

<?php foreach($displayConfig['displays'] as $display): ?>
<div class="display-item">
<strong><?= (int)($display['order'] ?? 0) ?>. <?= h($display['title'] ?? '') ?></strong>
<div>
<?php
$displayTypeLabel=(string)($display['type'] ?? '');
if($displayTypeLabel==='date_range')$displayTypeLabel='Date Range Selector';
elseif($displayTypeLabel==='custom_text')$displayTypeLabel='Custom Text';
?>
<?= h($displayTypeLabel) ?>
· <?= h(ucfirst($display['width'] ?? 'full')) ?> width
</div>

<div class="display-actions">
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="move_display">
<input type="hidden" name="display_id" value="<?= h($display['id'] ?? '') ?>">
<input type="hidden" name="direction" value="up">
<button class="secondary" type="submit">↑</button>
</form>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="move_display">
<input type="hidden" name="display_id" value="<?= h($display['id'] ?? '') ?>">
<input type="hidden" name="direction" value="down">
<button class="secondary" type="submit">↓</button>
</form>

<form method="post" style="display:inline-flex;align-items:center;gap:5px;">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="move_display">
<input type="hidden" name="display_id" value="<?= h($display['id'] ?? '') ?>">
<input type="hidden" name="direction" value="position">
<label>Position <select name="target_position" style="width:auto;" aria-label="Move display to position">
<?php for($pos=1;$pos<=count($displayConfig['displays']);$pos++): ?>
<option value="<?= $pos ?>" <?= $pos===(int)($display['order']??0)?'selected':'' ?>><?= $pos ?></option>
<?php endfor; ?>
</select></label><button class="secondary" type="submit">Move</button>
</form>

<form method="get">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="edit" value="<?= h($display['id'] ?? '') ?>">
<button class="secondary" type="submit">Edit</button>
</form>

<form method="post" onsubmit="return confirm('Delete this display definition?');">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="delete_display">
<input type="hidden" name="display_id" value="<?= h($display['id'] ?? '') ?>">
<button class="danger" type="submit">Delete</button>
</form>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="card">
<h2><?= $editDisplay ? 'Edit Display' : 'Add Display' ?></h2>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
<input type="hidden" name="station" value="<?= h($selectedCode) ?>">
<input type="hidden" name="action" value="<?= $editDisplay ? 'update_display' : 'add_display' ?>">
<?php if($editDisplay): ?>
<input type="hidden" name="display_id" value="<?= h($editDisplay['id'] ?? '') ?>">
<?php endif; ?>

<label>Display type <?= slim_help_button('Display type','Choose the type of dashboard element to create: time series, gauge, wind rose, map, image, custom text, date selector, and others.') ?></label>
<?php if($editDisplay): ?>
<input type="hidden" name="display_type" value="<?= h($editDisplay['type'] ?? '') ?>">
<select id="displayType" disabled>
<option value="<?= h($editDisplay['type'] ?? '') ?>" selected>
<?php
$editTypeLabel=(string)($editDisplay['type'] ?? '');
if($editTypeLabel==='date_range')$editTypeLabel='Date Range Selector';
elseif($editTypeLabel==='custom_text')$editTypeLabel='Custom Text';
?>
<?= h($editTypeLabel) ?>
</option>
</select>
<p class="hint">Display type cannot be changed while editing. Create a new display to use a different type.</p>
<?php else: ?>
<select name="display_type" id="displayType" onchange="showPanel()" required>
<option value="">Choose...</option>
<option value="date_range">Date Range Selector</option>
<option value="custom_text">Custom Text</option>
<option value="line">Time-series line plot</option>
<option value="gauge">Recent-value gauge</option>
<option value="polar">Polar plot</option>
<option value="profile">Spatial / profile plot</option>
<option value="directional_recent">Recent directional observations</option>
<option value="map">Station map</option>
<option value="image">Image / photo</option>
</select>
<?php endif; ?>

<label>Display title <?= slim_help_button('Display title','A name used to identify and manage this display within SLIM. The title is also shown above certain display types on the '.($slimHealthDashboard ? 'private station health' : 'public').' dashboard.') ?></label>
<input type="text" name="title" value="<?= h($editDisplay['title'] ?? '') ?>" required>

<label>Display width <?= slim_help_button('Display width','Controls horizontal space on larger screens. Displays automatically stack on small screens.') ?></label>
<select name="display_width">
<?php $currentWidth=$editDisplay['width'] ?? 'full'; ?>
<option value="full" <?= $currentWidth==='full'?'selected':'' ?>>Full width</option>
<option value="half" <?= $currentWidth==='half'?'selected':'' ?>>Half width</option>
<option value="third" <?= $currentWidth==='third'?'selected':'' ?>>One-third width</option>
</select>
<p class="hint">On small screens, displays automatically stack vertically.</p>

<div id="panel-date_range" class="display-panel">
<h3>Date Range Selector</h3>
<p class="hint">
Adds the public From / To calendar controls at this position in the dashboard.
The selected range applies to all time-series plots and is limited by the maximum time-series range configured in System Setup.
</p>
</div>

<div id="panel-custom_text" class="display-panel">
<h3>Custom Text</h3>
<p class="hint">
Use this display for explanatory text, section introductions, notes, instructions,
or other information for dashboard viewers. Use the formatting toolbar to emphasize text without writing HTML.
</p>

<label>Header text <?= slim_help_button('Custom text header','Optional heading shown inside a Custom Text display.') ?></label>
<input type="text" name="custom_header_text"
       value="<?= h((string)($editDisplay['header_text'] ?? '')) ?>"
       placeholder="Water Temperature">

<label>Body text <?= slim_help_button('Custom text body','Format dashboard text with controlled sizes, bold, italic, underline, lists, links, subscript, and superscript. SLIM sanitizes the saved formatting for safety.') ?></label>
<div class="slim-wysiwyg-toolbar" role="toolbar" aria-label="Custom text formatting">
    <select id="customTextSize" aria-label="Text size" title="Text size">
        <option value="normal">Normal</option>
        <option value="large">Large</option>
        <option value="xlarge">Extra large</option>
    </select>
    <button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
    <button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
    <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
    <button type="button" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>
    <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
    <button type="button" data-cmd="subscript" title="Subscript">X<sub>2</sub></button>
    <button type="button" data-cmd="superscript" title="Superscript">X<sup>2</sup></button>
    <button type="button" id="customTextLink" title="Add link">Link</button>
    <button type="button" data-cmd="undo" title="Undo">Undo</button>
    <button type="button" data-cmd="redo" title="Redo">Redo</button>
</div>
<div id="customTextEditor" class="slim-wysiwyg-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Add explanatory text for dashboard viewers..."><?php
$customBody=(string)($editDisplay['body_text'] ?? '');
if($customBody!=='') {
    echo preg_match('/<[^>]+>/', $customBody) ? slim_sanitize_content_html($customBody) : nl2br(h($customBody));
}
?></div>
<textarea id="customBodyText" name="custom_body_text" hidden></textarea>
<p class="hint">Text sizes are predefined by SLIM so dashboard formatting remains consistent and responsive.</p>
</div>

<div id="panel-line" class="display-panel">
<h3>Time-series line plot</h3>

<label>Y-axis label <?= slim_help_button('Y-axis label','Optional vertical-axis label for a time-series plot, such as Water Temperature (°C).') ?></label>
<input type="text" name="line_y_label" value="<?= h((string)($editDisplay['y_label'] ?? '')) ?>" placeholder="Water Temperature (°C)">

<label>Right Y-axis label <?= slim_help_button('Right Y-axis label','Optional label for the secondary Y2 axis when one or more series are assigned to Right (Y2).') ?></label>
<input type="text" name="line_y2_label" value="<?= h((string)($editDisplay['y2_label'] ?? '')) ?>" placeholder="Optional secondary-axis label">

<div class="inline">
<div>
<label>Y minimum <?= slim_help_button('Y minimum','Optional fixed lower limit for the left Y-axis. Leave blank to let the graph scale automatically to the displayed data.') ?></label>
<input type="number" step="any" name="line_y_min" value="<?= h((string)($editDisplay['y_min'] ?? '')) ?>">
</div>

<div>
<label>Y maximum <?= slim_help_button('Y maximum','Optional fixed upper limit for the left Y-axis. Leave blank to let the graph scale automatically to the displayed data.') ?></label>
<input type="number" step="any" name="line_y_max" value="<?= h((string)($editDisplay['y_max'] ?? '')) ?>">
</div>
</div>

<h4>Series</h4>
<p class="hint">Each series can have its own line style, line width, point appearance, and Y-axis assignment.</p>

<div class="line-series-scroll">
<table id="lineSeriesTable">
<thead>
<tr>
<th>Variable <?= slim_help_button('Series variable','Station variable plotted as this time-series line.') ?></th>
<th>Line label <?= slim_help_button('Line label','Label shown for this series in the graph legend. This can differ from the station-level variable label.') ?></th>
<th>Line color <?= slim_help_button('Line color','Color used to draw this series line.') ?></th>
<th>Style <?= slim_help_button('Line style','Choose a solid, dashed, dotted, or dash-dot line pattern.') ?></th>
<th>Width <?= slim_help_button('Line width','Thickness of this series line. Larger values draw a heavier line.') ?></th>
<th>Points <?= slim_help_button('Persistent points','Choose whether individual observation symbols remain visible along the line. Highlight points can still appear when hovering.') ?></th>
<th>Point color <?= slim_help_button('Point color','Color of the observation symbols. It may be different from the line color.') ?></th>
<th>Symbol <?= slim_help_button('Point symbol','Shape used for observation points when persistent points are shown or a point is highlighted.') ?></th>
<th>Point size <?= slim_help_button('Point size','Size of the observation symbol in pixels.') ?></th>
<th>Axis <?= slim_help_button('Y-axis assignment','Plot this series on the primary left Y1 axis or the secondary right Y2 axis. Use Y2 when variables have substantially different units or scales.') ?></th>
<th></th>
</tr>
</thead>
<tbody>
<tr>
<td>
<select name="line_series_field[]">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?>
<option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option>
<?php endforeach; ?>
</select>
</td>
<td><input type="text" name="line_series_label[]" placeholder="Surface"></td>
<td><input type="color" name="line_series_color[]" value="#3366cc"></td>
<td>
<select name="line_series_style[]">
<option value="solid">Solid</option>
<option value="dashed">Dashed</option>
<option value="dotted">Dotted</option>
<option value="dashdot">Dash-dot</option>
</select>
</td>
<td><input type="number" step="0.1" min="0.1" max="10" name="line_series_width[]" value="1.5"></td>
<td>
<select name="line_series_points[]">
<option value="hide">Hide</option>
<option value="show">Show</option>
</select>
</td>
<td><input type="color" name="line_series_point_color[]" value="#3366cc"></td>
<td>
<select name="line_series_point_symbol[]">
<option value="circle">Circle</option>
<option value="square">Square</option>
<option value="triangle">Triangle</option>
<option value="diamond">Diamond</option>
</select>
</td>
<td><input type="number" step="0.5" min="1" max="15" name="line_series_point_size[]" value="3"></td>
<td>
<select name="line_series_axis[]">
<option value="y">Left (Y1)</option>
<option value="y2">Right (Y2)</option>
</select>
</td>
<td><button class="secondary" type="button" onclick="removeLineSeriesRow(this)">Remove</button></td>
</tr>
</tbody>
</table>
</div>

<p><button class="secondary" type="button" onclick="addLineSeriesRow()">+ Add Series</button></p>

<label><input type="checkbox" name="line_show_legend" checked> Show legend <?= slim_help_button('Show legend','Displays the series names and current highlighted values above the time-series graph.') ?></label>

<details style="margin-top:14px;">
<summary><strong>Advanced Dygraphs options</strong></summary>

<div class="inline" style="margin-top:12px;">
<div>
<label>Rolling period <?= slim_help_button('Rolling period','Number of adjacent observations averaged by the Dygraphs rolling-average control. A value of 1 means no smoothing.') ?></label>
<input type="number" name="line_roll_period" value="1" min="1">
</div>

<div>
<label>Highlight circle size <?= slim_help_button('Highlight circle size','Size of the point marker displayed when a viewer hovers over or selects an observation. Set to 0 to suppress the highlight circle.') ?></label>
<input type="number" name="line_highlight_circle_size" value="8" min="0">
</div>

</div>

<label><input type="checkbox" name="line_show_roller" checked> Show rolling-average control <?= slim_help_button('Rolling-average control','Shows Dygraphs\' interactive smoothing control so viewers can change the rolling period on the '.($slimHealthDashboard ? 'private station health' : 'public').' dashboard.') ?></label>
<label><input type="checkbox" name="line_show_highlight_labels" checked> Show labels on highlight <?= slim_help_button('Highlight labels','Shows the observation time and series values when the viewer highlights a point on the graph.') ?></label>
<label><input type="checkbox" name="line_show_zero_values"> Show zero values in labels <?= slim_help_button('Zero values in labels','When enabled, values equal to zero are included in highlighted-value labels rather than being omitted.') ?></label>
</details>
</div>

<div id="panel-gauge" class="display-panel">
<h3>Recent-value gauge</h3>
<p class="hint">Summarizes one variable as a current value using the newest observation or an average of several recent observations.</p>

<label>Variable <?= slim_help_button('Gauge variable','Station variable summarized by this recent-value gauge.') ?></label>
<select name="gauge_field">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?>
<option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option>
<?php endforeach; ?>
</select>

<label>Gauge style <?= slim_help_button('Gauge style','Choose how the recent value is displayed. Circular ring shows progress around a full circle; semicircle resembles an instrument dial; horizontal bar provides a compact scale; numeric card emphasizes the value without a graphical scale.') ?></label>
<select name="gauge_style">
<?php $gaugeStyle=(string)($editDisplay['gauge_style'] ?? 'ring'); ?>
<option value="ring" <?= $gaugeStyle==='ring'?'selected':'' ?>>Circular ring</option>
<option value="semicircle" <?= $gaugeStyle==='semicircle'?'selected':'' ?>>Semicircle dial</option>
<option value="bar" <?= $gaugeStyle==='bar'?'selected':'' ?>>Horizontal bar</option>
<option value="numeric" <?= $gaugeStyle==='numeric'?'selected':'' ?>>Large numeric value</option>
</select>

<div class="inline">
<div>
<label>Gauge color <?= slim_help_button('Gauge color','Color used for the active portion of the circular, semicircle, or horizontal gauge. For the large numeric style, this controls the displayed numeric value.') ?></label>
<input type="color" name="gauge_color" value="<?= h((string)($editDisplay['gauge_color'] ?? '#37b7c3')) ?>">
</div>
<div>
<label>Gauge background color <?= slim_help_button('Gauge background color','Color used for the unfilled portion of circular, semicircle, and horizontal gauges. This setting has little visual effect on the large numeric style.') ?></label>
<input type="color" name="gauge_background_color" value="<?= h((string)($editDisplay['gauge_background_color'] ?? '#23384a')) ?>">
</div>
</div>

<div class="inline">
<div><label>Average most recent N <?= slim_help_button('Gauge averaging','Number of the newest observations averaged to produce the displayed gauge value. Use 1 to show only the latest observation.') ?></label><input type="number" name="gauge_recent_n" value="<?= h((string)($editDisplay['recent_n'] ?? 3)) ?>" min="1"></div>
<div><label>Minimum <?= slim_help_button('Gauge minimum','Lower end of the gauge scale. Choose a meaningful minimum for this variable.') ?></label><input type="number" step="any" name="gauge_min" value="<?= h((string)($editDisplay['min'] ?? '')) ?>"></div>
<div><label>Maximum <?= slim_help_button('Gauge maximum','Upper end of the gauge scale. Choose a meaningful maximum for this variable.') ?></label><input type="number" step="any" name="gauge_max" value="<?= h((string)($editDisplay['max'] ?? '')) ?>"></div>
</div>

<div class="inline">
<div><label>Units <?= slim_help_button('Gauge units','Units printed with the gauge value, for example °C, mg/L, m/s, or µg/L.') ?></label><input type="text" name="gauge_units" value="<?= h((string)($editDisplay['units'] ?? '')) ?>"></div>
<div><label>Decimal places <?= slim_help_button('Decimal places','Number of digits displayed after the decimal point in the gauge value.') ?></label><input type="number" name="gauge_decimals" value="<?= h((string)($editDisplay['decimals'] ?? 1)) ?>" min="0" max="6"></div>
</div>
</div>

<div id="panel-polar" class="display-panel">
<h3>Polar plot</h3>

<div class="inline">
<div>
<label>Direction variable <?= slim_help_button('Direction variable','Variable containing direction in degrees. This can be wind direction, wave direction, current direction, or another directional measurement.') ?></label>
<select name="polar_direction">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</div>

<div>
<label>Magnitude variable <?= slim_help_button('Magnitude variable','Variable paired with direction, such as wind speed, wave height, current speed, or another magnitude measurement.') ?></label>
<select name="polar_magnitude">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</div>
</div>

<div class="inline">
<div>
<label>Recent observations <?= slim_help_button('Recent observations','Number of the most recent paired direction and magnitude observations used to build the directional frequency rose.') ?></label>
<input type="number" name="polar_recent_n" value="<?= h((string)($editDisplay['recent_n'] ?? 30)) ?>" min="1">
</div>
<div>
<label>Magnitude units <?= slim_help_button('Magnitude units','Units displayed for the magnitude classes, for example m/s for wind speed or m for wave height.') ?></label>
<input type="text" name="polar_units" value="<?= h((string)($editDisplay['magnitude_units'] ?? '')) ?>">
</div>
</div>

<div class="inline">
<div>
<label>Direction sectors <?= slim_help_button('Direction sectors','Number of directional bins used by the directional frequency rose. Sixteen sectors gives 22.5° bins. This can be used for wind, waves, currents, or any other direction and magnitude pair.') ?></label>
<select name="windrose_sectors">
<?php $sectorCount=(int)($editDisplay['windrose_sectors'] ?? 16); ?>
<option value="8" <?= $sectorCount===8?'selected':'' ?>>8 sectors (45°)</option>
<option value="16" <?= $sectorCount===16?'selected':'' ?>>16 sectors (22.5°)</option>
<option value="24" <?= $sectorCount===24?'selected':'' ?>>24 sectors (15°)</option>
<option value="36" <?= $sectorCount===36?'selected':'' ?>>36 sectors (10°)</option>
</select>
</div>

<div>
<label>Magnitude bin breaks <?= slim_help_button('Magnitude bin breaks','Optional boundaries for stacked magnitude classes. For wind these could be speed classes; for waves they could be wave-height classes. Leave blank for automatic bins.') ?></label>
<input
    type="text"
    name="windrose_breaks"
    value="<?= h(implode(', ',(array)($editDisplay['windrose_breaks'] ?? []))) ?>"
    placeholder="0, 2, 4, 6, 8, 10"
>
<p class="hint">
Optional. Enter comma-separated class boundaries. Leave blank for automatic
five-bin scaling from the displayed data.
</p>
</div>
</div>
</div>


<div id="panel-profile" class="display-panel">
<h3>Spatial / profile plot</h3>
<p class="hint">Build a profile from separate station variables measured at known spatial positions, such as temperatures from sensors at several depths.</p>

<div class="inline">
<div><label>Coordinate label <?= slim_help_button('Profile coordinate','Name of the spatial coordinate represented by the positions below, such as Depth, Height, Distance, or Transect position.') ?></label><input type="text" name="profile_coordinate_label" value="<?= h((string)($editDisplay['coordinate_label'] ?? 'Depth')) ?>"></div>
<div><label>Coordinate units <?= slim_help_button('Coordinate units','Units used for the profile positions, such as m, cm, or km.') ?></label><input type="text" name="profile_coordinate_units" value="<?= h((string)($editDisplay['coordinate_units'] ?? 'm')) ?>"></div>
<div><label>Average most recent N <?= slim_help_button('Profile averaging','Number of recent observations averaged for each profile variable. Use 1 for the latest observation only.') ?></label><input type="number" name="profile_recent_n" value="<?= h((string)($editDisplay['recent_n'] ?? 1)) ?>" min="1"></div>
</div>

<div class="inline">
<div><label>Color scale minimum <?= slim_help_button('Color scale minimum','Optional fixed lower value for the profile color scale. Leave blank to use the minimum value found across the full available dataset for the configured profile variables.') ?></label><input type="number" step="any" name="profile_color_min" value="<?= h(isset($editDisplay['color_min']) && $editDisplay['color_min'] !== null ? (string)$editDisplay['color_min'] : '') ?>" placeholder="Automatic"></div>
<div><label>Color scale maximum <?= slim_help_button('Color scale maximum','Optional fixed upper value for the profile color scale. Leave blank to use the maximum value found across the full available dataset for the configured profile variables.') ?></label><input type="number" step="any" name="profile_color_max" value="<?= h(isset($editDisplay['color_max']) && $editDisplay['color_max'] !== null ? (string)$editDisplay['color_max'] : '') ?>" placeholder="Automatic"></div>
</div>
<p class="hint">These values control the color scale only. Blank limits are determined from all available observations for the variables included in this profile, so colors remain comparable over time.</p>

<table id="profileTable">
<thead><tr>
<th>Variable <?= slim_help_button('Profile variable','Station variable represented at this spatial position.') ?></th>
<th>Position <?= slim_help_button('Profile position','Numerical coordinate for this variable, such as sensor depth in meters. Use a consistent coordinate system for all rows.') ?></th>
<th></th>
</tr></thead>
<tbody>
<tr>
<td>
<select name="profile_field[]">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</td>
<td><input type="number" step="any" name="profile_position[]"></td>
<td><button class="secondary" type="button" onclick="removeProfileRow(this)">Remove</button></td>
</tr>
</tbody>
</table>

<p><button class="secondary" type="button" onclick="addProfileRow()">+ Add Position</button></p>
</div>

<div id="panel-directional_recent" class="display-panel">
<h3>Recent directional observations</h3>
<p class="hint">Shows the newest paired direction and magnitude observations individually rather than grouping them into frequency bins.</p>

<div class="inline">
<div>
<label>Direction variable <?= slim_help_button('Direction variable','Variable containing direction in degrees for each recent observation, such as wind, wave, or current direction.') ?></label>
<select name="recent_direction">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</div>

<div>
<label>Magnitude variable <?= slim_help_button('Magnitude variable','Variable paired with direction, such as wind speed, wave height, or current speed.') ?></label>
<select name="recent_magnitude">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>"><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</div>
</div>

<div class="inline">
<div><label>Recent observations <?= slim_help_button('Recent observations','Number of newest paired direction and magnitude observations shown in this display.') ?></label><input type="number" name="recent_n" value="<?= h((string)($editDisplay['recent_n'] ?? 10)) ?>" min="2"></div>
<div><label>Magnitude units <?= slim_help_button('Magnitude units','Units shown with the magnitude values, for example m/s, m, cm/s, or another appropriate unit.') ?></label><input type="text" name="recent_units" value="<?= h((string)($editDisplay['magnitude_units'] ?? '')) ?>"></div>
</div>
</div>


<div id="panel-map" class="display-panel">
<h3>Station map</h3>
<p class="hint">Choose the saved station location or the most recent latitude/longitude reported in station data.</p>

<label>Location source <?= slim_help_button('Location source','Static uses the coordinates saved for the station. Live GPS uses latitude and longitude variables from the newest observation.') ?></label>
<select name="map_location_mode" id="map_location_mode">
<option value="static" <?= (($editDisplay['location_mode'] ?? 'static')==='static')?'selected':'' ?>>Static station coordinates</option>
<option value="live" <?= (($editDisplay['location_mode'] ?? 'static')==='live')?'selected':'' ?>>Live GPS coordinates from station data</option>
</select>

<div class="inline" style="margin-top:12px">
<div>
<label>Latitude variable <?= slim_help_button('Latitude variable','Station variable containing latitude in decimal degrees.') ?></label>
<select name="map_latitude_field">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>" <?= (($editDisplay['latitude_field'] ?? '')===$key)?'selected':'' ?>><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</div>
<div>
<label>Longitude variable <?= slim_help_button('Longitude variable','Station variable containing longitude in decimal degrees.') ?></label>
<select name="map_longitude_field">
<option value="">Choose...</option>
<?php foreach($fieldOptions as $key=>$label): ?><option value="<?= h($key) ?>" <?= (($editDisplay['longitude_field'] ?? '')===$key)?'selected':'' ?>><?= h($label) ?> — <?= h($key) ?></option><?php endforeach; ?>
</select>
</div>
</div>
<p><label><input type="checkbox" name="map_fallback_static" value="1" <?= !array_key_exists('fallback_static',$editDisplay ?? []) || !empty($editDisplay['fallback_static'])?'checked':'' ?>>
Fall back to static station coordinates when live latitude or longitude is zero or unavailable</label></p>
<p class="hint">SLIM uses the newest observation only; it does not search backward for an older valid GPS fix.</p>

<div class="inline">
<div>
<label>Map zoom <?= slim_help_button('Map zoom','Initial map zoom level. Larger numbers show a closer view.') ?></label>
<input type="number" name="map_zoom" value="<?= h((string)($editDisplay['zoom'] ?? 11)) ?>" min="1" max="19">
</div>
<div>
<label>Map height (pixels) <?= slim_help_button('Map height','Vertical size of the map display in pixels.') ?></label>
<input type="number" name="map_height" value="<?= h((string)($editDisplay['height'] ?? 360)) ?>" min="220" max="700">
</div>
</div>
</div>

<div id="panel-image" class="display-panel">
<h3>Image / photo</h3>

<label>Image file <?= slim_help_button('Image file','Upload a JPEG, PNG, or WebP image to display on this station dashboard. Images are stored by SLIM and are limited to 5 MB.') ?></label>
<input type="file" name="image_file" accept="image/jpeg,image/png,image/webp">
<?php if($editDisplay && ($editDisplay['type'] ?? '')==='image' && empty($editDisplay['image_path'])): ?>
<p class="hint" style="font-weight:700;">
This image display was copied from another station. Upload an image for this station before saving it.
</p>
<?php endif; ?>
<p class="hint">
JPEG, PNG, or WebP; SLIM limit 5 MB.
Server limits:
upload_max_filesize = <?= h((string)ini_get('upload_max_filesize')) ?>,
post_max_size = <?= h((string)ini_get('post_max_size')) ?>,
file_uploads = <?= h((string)ini_get('file_uploads')) ?>.
</p>

<label>Caption (optional) <?= slim_help_button('Image caption','Optional visible text displayed with the image to explain what viewers are seeing.') ?></label>
<input type="text" name="image_caption" value="<?= h((string)($editDisplay['caption'] ?? '')) ?>" placeholder="Panther Buoy deployed at station">

<label>Alternative text <?= slim_help_button('Alternative text','Short description of the image for screen readers and for cases where the image cannot be displayed. Describe the meaningful content of the image.') ?></label>
<input type="text" name="image_alt_text" value="<?= h((string)($editDisplay['alt_text'] ?? '')) ?>" placeholder="Photo of environmental monitoring buoy">
</div>

<p style="margin-top:20px">
<button class="primary" type="submit"><?= $editDisplay ? 'Save Changes' : 'Add Display' ?></button>
<?php if($editDisplay): ?>
<a href="?station=<?= urlencode($selectedCode) ?>" style="margin-left:12px;">Cancel</a>
<?php endif; ?>
</p>
</form>
</div>
<?php endif; ?>

</div>

<style>
.slim-wysiwyg-toolbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0 0;padding:8px;border:1px solid #ccd4dc;border-bottom:0;border-radius:6px 6px 0 0;background:#f6f8fa}
.slim-wysiwyg-toolbar button,.slim-wysiwyg-toolbar select{width:auto;margin:0;padding:6px 9px;min-height:34px}
.slim-wysiwyg-editor{min-height:150px;padding:10px 12px;border:1px solid #ccd4dc;border-radius:0 0 6px 6px;background:#fff;line-height:1.55;overflow:auto}
.slim-wysiwyg-editor:empty:before{content:attr(data-placeholder);color:#7b8794}
.slim-wysiwyg-editor .slim-text-normal{font-size:16px}
.slim-wysiwyg-editor .slim-text-large{font-size:21px}
.slim-wysiwyg-editor .slim-text-xlarge{font-size:26px}
</style>
<script>

const editDisplay = <?= json_encode($editDisplay, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function hydrateEditDisplay(){
    if(!editDisplay)return;

    var type=editDisplay.type || '';
    var displayType=document.getElementById('displayType');

    if(displayType){
        if(!displayType.disabled)displayType.value=type;
    }

    showPanel();

    function setSelect(name,value){
        if(value===undefined || value===null)return;
        var el=document.querySelector('[name="'+name+'"]');
        if(el)el.value=String(value);
    }

    if(type==='gauge'){
        setSelect('gauge_field',editDisplay.field);
    }

    if(type==='polar'){
        setSelect('polar_direction',editDisplay.direction_field);
        setSelect('polar_magnitude',editDisplay.magnitude_field);
    }

    if(type==='directional_recent'){
        setSelect('recent_direction',editDisplay.direction_field);
        setSelect('recent_magnitude',editDisplay.magnitude_field);
    }

    if(type==='line'){
        var tbody=document.querySelector('#lineSeriesTable tbody');

        if(tbody){
            var firstRow=tbody.querySelector('tr');
            var series=Array.isArray(editDisplay.series) ? editDisplay.series : [];

            if(series.length===0 && Array.isArray(editDisplay.fields)){
                series=editDisplay.fields.map(function(field){
                    return {
                        field:field,
                        label:field,
                        color:'#3366cc',
                        line_style:'solid',
                        stroke_width:1.5,
                        show_points:false,
                        point_color:'#3366cc',
                        point_symbol:'circle',
                        point_size:3,
                        axis:'y'
                    };
                });
            }

            while(tbody.children.length>1){
                tbody.removeChild(tbody.lastElementChild);
            }

            series.forEach(function(s,index){
                if(index>0)addLineSeriesRow();
                var row=tbody.children[index];
                if(!row)return;

                var field=row.querySelector('[name="line_series_field[]"]');
                var label=row.querySelector('[name="line_series_label[]"]');
                var color=row.querySelector('[name="line_series_color[]"]');
                var style=row.querySelector('[name="line_series_style[]"]');
                var width=row.querySelector('[name="line_series_width[]"]');
                var points=row.querySelector('[name="line_series_points[]"]');
                var pointColor=row.querySelector('[name="line_series_point_color[]"]');
                var symbol=row.querySelector('[name="line_series_point_symbol[]"]');
                var pointSize=row.querySelector('[name="line_series_point_size[]"]');
                var axis=row.querySelector('[name="line_series_axis[]"]');

                if(field)field.value=s.field || '';
                if(label)label.value=s.label || '';
                if(color)color.value=s.color || '#3366cc';
                if(style)style.value=s.line_style || 'solid';
                if(width)width.value=(s.stroke_width!==undefined?s.stroke_width:1.5);
                if(points)points.value=s.show_points ? 'show' : 'hide';
                if(pointColor)pointColor.value=s.point_color || s.color || '#3366cc';
                if(symbol)symbol.value=s.point_symbol || 'circle';
                if(pointSize)pointSize.value=(s.point_size!==undefined?s.point_size:3);
                if(axis)axis.value=s.axis || 'y';
            });
        }
    }

    if(type==='profile'){
        var tbody=document.querySelector('#profileTable tbody');

        if(tbody){
            var firstRow=tbody.querySelector('tr');
            var points=Array.isArray(editDisplay.points) ? editDisplay.points : [];

            while(tbody.children.length>1){
                tbody.removeChild(tbody.lastElementChild);
            }

            if(firstRow){
                var firstSelect=firstRow.querySelector('select');
                var firstInput=firstRow.querySelector('input');
                if(firstSelect)firstSelect.selectedIndex=0;
                if(firstInput)firstInput.value='';
            }

            points.forEach(function(p,index){
                if(index>0)addProfileRow();
                var row=tbody.children[index];
                if(!row)return;
                var sel=row.querySelector('select');
                var inp=row.querySelector('input');
                if(sel)sel.value=p.field || '';
                if(inp)inp.value=(p.position!==undefined?p.position:'');
            });
        }
    }
}

function initCustomTextEditor(){
    var editor=document.getElementById('customTextEditor');
    var hidden=document.getElementById('customBodyText');
    if(!editor || !hidden)return;

    function sync(){ hidden.value=editor.innerHTML; }
    document.querySelectorAll('.slim-wysiwyg-toolbar [data-cmd]').forEach(function(button){
        button.addEventListener('mousedown',function(e){e.preventDefault();});
        button.addEventListener('click',function(){
            editor.focus();
            document.execCommand(button.dataset.cmd,false,null);
            sync();
        });
    });

    var savedSizeRange=null;
    function rememberSizeRange(){
        var selection=window.getSelection();
        if(!selection || selection.rangeCount===0 || selection.isCollapsed)return;
        var candidate=selection.getRangeAt(0);
        if(editor.contains(candidate.commonAncestorContainer))savedSizeRange=candidate.cloneRange();
    }
    editor.addEventListener('mouseup',rememberSizeRange);
    editor.addEventListener('keyup',rememberSizeRange);

    var size=document.getElementById('customTextSize');
    if(size){
        size.addEventListener('mousedown',rememberSizeRange);
        size.addEventListener('change',function(){
        var selection=window.getSelection();
        var range=savedSizeRange ? savedSizeRange.cloneRange() :
            (selection && selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null);
        if(!range || range.collapsed || !editor.contains(range.commonAncestorContainer))return;
        editor.focus();
        selection=window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        /* Size choices are mutually exclusive. First isolate the selected
           content, then remove any SLIM size wrappers already present so
           repeated Large/Extra large changes cannot compound. */
        var fragment=range.extractContents();
        var holder=document.createElement('div');
        holder.appendChild(fragment);

        holder.querySelectorAll('.slim-text-normal,.slim-text-large,.slim-text-xlarge').forEach(function(node){
            var parent=node.parentNode;
            while(node.firstChild)parent.insertBefore(node.firstChild,node);
            parent.removeChild(node);
        });

        /* Always apply an explicit size span, including Normal. This is
           important when the selection is inside an older Large/Extra large
           ancestor: removing the inner size alone would simply expose the
           ancestor's larger font size again. Fixed pixel sizes ensure the
           selected choice wins even if legacy nested markup surrounds it. */
        var span=document.createElement('span');
        span.className='slim-text-'+size.value;
        while(holder.firstChild)span.appendChild(holder.firstChild);
        range.insertNode(span);
        selection.removeAllRanges();
        var after=document.createRange();
        after.selectNodeContents(span);
        selection.addRange(after);
        savedSizeRange=null;
        sync();
        });
    }

    var link=document.getElementById('customTextLink');
    if(link){
        link.addEventListener('mousedown',function(e){e.preventDefault();});
        link.addEventListener('click',function(){
            editor.focus();
            var url=window.prompt('Enter link URL (https://...)');
            if(url)document.execCommand('createLink',false,url);
            sync();
        });
    }
    editor.addEventListener('input',sync);
    var form=editor.closest('form');
    if(form)form.addEventListener('submit',sync);
    sync();
}

document.addEventListener('DOMContentLoaded',function(){
    if(editDisplay){
        hydrateEditDisplay();
    } else {
        showPanel();
    }
    initCustomTextEditor();
});


function showPanel(){
    var displayType=document.getElementById('displayType');
    var type=displayType ? displayType.value : '';

    document.querySelectorAll('.display-panel').forEach(function(panel){
        panel.classList.remove('active');
        panel.querySelectorAll('input,select,textarea,button').forEach(function(control){
            control.disabled=true;
        });
    });

    if(type){
        var panel=document.getElementById('panel-'+type);
        if(panel){
            panel.classList.add('active');
            panel.querySelectorAll('input,select,textarea,button').forEach(function(control){
                control.disabled=false;
            });
        }
    }
}

function addProfileRow(){
    var tbody=document.querySelector('#profileTable tbody');
    var clone=tbody.querySelector('tr').cloneNode(true);
    clone.querySelector('select').selectedIndex=0;
    clone.querySelector('input').value='';
    tbody.appendChild(clone);
}

function removeProfileRow(button){
    var rows=document.querySelectorAll('#profileTable tbody tr');
    if(rows.length>1)button.closest('tr').remove();
}

function addLineSeriesRow(){
    var tbody=document.querySelector('#lineSeriesTable tbody');
    var first=tbody.querySelector('tr');
    var clone=first.cloneNode(true);

    clone.querySelectorAll('select').forEach(function(sel){sel.selectedIndex=0;});

    var field=clone.querySelector('[name="line_series_field[]"]');
    if(field)field.value='';

    var label=clone.querySelector('[name="line_series_label[]"]');
    if(label)label.value='';

    var lineColor=clone.querySelector('[name="line_series_color[]"]');
    if(lineColor)lineColor.value='#3366cc';

    var width=clone.querySelector('[name="line_series_width[]"]');
    if(width)width.value='1.5';

    var points=clone.querySelector('[name="line_series_points[]"]');
    if(points)points.value='hide';

    var pointColor=clone.querySelector('[name="line_series_point_color[]"]');
    if(pointColor)pointColor.value='#3366cc';

    var pointSize=clone.querySelector('[name="line_series_point_size[]"]');
    if(pointSize)pointSize.value='3';

    var axis=clone.querySelector('[name="line_series_axis[]"]');
    if(axis)axis.value='y';

    tbody.appendChild(clone);
}

function removeLineSeriesRow(button){
    var rows=document.querySelectorAll('#lineSeriesTable tbody tr');
    if(rows.length>1){
        button.closest('tr').remove();
    }
}

</script>
<?= slim_admin_help_assets() ?>
</body>
</html>
