<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/admin_session.php';
require_once dirname(__DIR__,2).'/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('POST required.');
    $expectedCsrf=(string)($_SESSION['site_csrf'] ?? '');
    $providedCsrf=(string)($_POST['csrf'] ?? '');
    if($expectedCsrf==='' || $providedCsrf==='' || !hash_equals($expectedCsrf,$providedCsrf)) {
        throw new RuntimeException('Session validation failed. Reload the page and try again.');
    }
    if(!isset($_FILES['image']) || !is_array($_FILES['image'])) throw new RuntimeException('No image was uploaded.');
    $f=$_FILES['image'];
    if(($f['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed.');
    if((int)$f['size']>5*1024*1024) throw new RuntimeException('Image must be 5 MB or smaller.');
    $fi=new finfo(FILEINFO_MIME_TYPE); $mime=$fi->file((string)$f['tmp_name']);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if(!isset($types[$mime])) throw new RuntimeException('Only JPEG, PNG, GIF, and WebP images are allowed.');
    $dir=dirname(__DIR__,2).'/assets/site';
    if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Unable to create site asset directory.');
    $name='page_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(6)).'.'.$types[$mime];
    if(!move_uploaded_file((string)$f['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Unable to store uploaded image.');
    @chmod($dir.'/'.$name,0644);
    // Return a root-relative URL. A path such as ../../assets/site/... is
    // interpreted relative to /admin/site/ while editing, but after it is
    // stored in HTML and rendered from /about/ or /contact/ it points to the
    // wrong location. Derive the SLIM installation base from this script URL.
    $scriptName=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminMarker='/admin/site/upload_image.php';
    $markerPos=strrpos($scriptName,$adminMarker);
    $baseUrl=$markerPos===false ? '' : substr($scriptName,0,$markerPos);
    $location=rtrim($baseUrl,'/').'/assets/site/'.rawurlencode($name);

    echo json_encode(['location'=>$location],JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
