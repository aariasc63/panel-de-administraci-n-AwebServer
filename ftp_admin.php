<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN DE ACCESO A LA PÁGINA
|--------------------------------------------------------------------------
*/
const ADMIN_USER = 'root';
const ADMIN_PASS = 'root';

/*
|--------------------------------------------------------------------------
| FUNCIONES AUXILIARES
|--------------------------------------------------------------------------
*/
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash($msg, $type = 'info')
{
    $_SESSION['flash'] = [
        'msg' => (string)$msg,
        'type' => (string)$type
    ];
}

function get_flash()
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function normalize_path($path)
{
    $path = trim((string)$path);

    if ($path === '') {
        return '/';
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);

    if ($path === '') {
        return '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return $path;
}

function parent_path($path)
{
    $path = normalize_path($path);

    if ($path === '/' || $path === '.') {
        return '/';
    }

    $parent = dirname($path);
    $parent = str_replace('\\', '/', $parent);

    return ($parent === '.' || $parent === '\\') ? '/' : $parent;
}



function sanitize_rename_name($name)
{
    $name = trim((string)$name);
    $name = str_replace('\\', '/', $name);
    $name = basename($name);

    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }

    return $name;
}

function normalize_ftp_host($host)
{
    $host = trim((string)$host);
    $host = preg_replace('#^ftps?://#i', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    return trim($host);
}

function ftp_is_supported()
{
    return function_exists('ftp_connect');
}

function ftp_ssl_is_supported()
{
    return function_exists('ftp_ssl_connect');
}

function ftp_is_dir($conn, $path)
{
    $current = @ftp_pwd($conn);

    if ($current === false) {
        return false;
    }

    $result = @ftp_chdir($conn, $path);
    if ($result) {
        @ftp_chdir($conn, $current);
        return true;
    }

    return false;
}

function ftp_list_detailed($conn, $path)
{
    $items = [];
    $raw = @ftp_rawlist($conn, $path);

    // Intentar parsear rawlist normal
    if (is_array($raw) && count($raw) > 0) {
        foreach ($raw as $line) {
            $chunks = preg_split('/\s+/', $line, 9);

            if (count($chunks) < 9) {
                continue;
            }

            $name = $chunks[8];
            if ($name === '.' || $name === '..') {
                continue;
            }

            $typeChar = substr($chunks[0], 0, 1);
            $isDir = ($typeChar === 'd');

            $base = rtrim($path, '/');
            if ($base === '') {
                $base = '/';
            }

            $fullPath = ($base === '/' ? '/' . $name : $base . '/' . $name);

            $items[] = [
                'name'   => $name,
                'size'   => $chunks[4] ?? '',
                'date'   => ($chunks[5] ?? '') . ' ' . ($chunks[6] ?? '') . ' ' . ($chunks[7] ?? ''),
                'is_dir' => $isDir,
                'perm'   => $chunks[0] ?? '',
                'path'   => $fullPath
            ];
        }
    }

    // Fallback si rawlist no devolvió nada útil
    if (count($items) === 0) {
        $names = @ftp_nlist($conn, $path);

        if (is_array($names)) {
            foreach ($names as $entry) {
                $name = basename($entry);

                if ($name === '.' || $name === '..') {
                    continue;
                }

                $fullPath = normalize_path($entry);
                $isDir = ftp_is_dir($conn, $fullPath);
                $size = $isDir ? '' : @ftp_size($conn, $fullPath);

                $items[] = [
                    'name'   => $name,
                    'size'   => ($size > -1 ? $size : ''),
                    'date'   => '',
                    'is_dir' => $isDir,
                    'perm'   => '',
                    'path'   => $fullPath
                ];
            }
        }
    }

    usort($items, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return $items;
}

function ftp_delete_recursive($conn, $path)
{
    if (!ftp_is_dir($conn, $path)) {
        return @ftp_delete($conn, $path);
    }

    $items = ftp_list_detailed($conn, $path);

    foreach ($items as $item) {
        if ($item['is_dir']) {
            if (!ftp_delete_recursive($conn, $item['path'])) {
                return false;
            }
        } else {
            if (!@ftp_delete($conn, $item['path'])) {
                return false;
            }
        }
    }

    return @ftp_rmdir($conn, $path);
}

function is_editable_file($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $editable = [
        'php',
        'phtml',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'inc',
        'txt',
        'text',
        'log',
        'md',
        'readme',
        'html',
        'htm',
        'css',
        'js',
        'mjs',
        'ts',
        'json',
        'xml',
        'svg',
        'ini',
        'conf',
        'config',
        'env',
        'py',
        'java',
        'c',
        'cpp',
        'h',
        'hpp',
        'cs',
        'rb',
        'pl',
        'sh',
        'bash',
        'bat',
        'cmd',
        'sql',
        'yml',
        'yaml',
        'csv',
        'asp',
        'aspx',
        'jsp'
    ];

    return in_array($ext, $editable, true);
}

function get_file_language_class($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $map = [
        'php'   => 'lang-php',
        'phtml' => 'lang-php',
        'inc'   => 'lang-php',
        'html'  => 'lang-html',
        'htm'   => 'lang-html',
        'css'   => 'lang-css',
        'js'    => 'lang-js',
        'json'  => 'lang-json',
        'xml'   => 'lang-xml',
        'py'    => 'lang-py',
        'java'  => 'lang-java',
        'sql'   => 'lang-sql',
        'txt'   => 'lang-text',
        'md'    => 'lang-text',
        'log'   => 'lang-text',
        'sh'    => 'lang-bash',
        'bat'   => 'lang-bat',
        'cmd'   => 'lang-bat',
        'yml'   => 'lang-yaml',
        'yaml'  => 'lang-yaml'
    ];

    return $map[$ext] ?? 'lang-text';
}

function ftp_read_file_text($conn, $remoteFile, &$error = '')
{
    $error = '';
    $temp = fopen('php://temp', 'w+');

    if (!$temp) {
        $error = 'No se pudo abrir memoria temporal.';
        return false;
    }

    $ok = @ftp_fget($conn, $temp, $remoteFile, FTP_BINARY);

    if (!$ok) {
        fclose($temp);
        $error = 'No se pudo leer el archivo remoto.';
        return false;
    }

    rewind($temp);
    $content = stream_get_contents($temp);
    fclose($temp);

    if ($content === false) {
        $error = 'No se pudo obtener el contenido del archivo.';
        return false;
    }

    return $content;
}

function ftp_write_file_text($conn, $remoteFile, $content, &$error = '')
{
    $error = '';
    $temp = fopen('php://temp', 'w+');

    if (!$temp) {
        $error = 'No se pudo abrir memoria temporal para guardar.';
        return false;
    }

    fwrite($temp, $content);
    rewind($temp);

    $ok = @ftp_fput($conn, $remoteFile, $temp, FTP_BINARY);
    fclose($temp);

    if (!$ok) {
        $error = 'No se pudo guardar el archivo remoto.';
        return false;
    }

    return true;
}


function sanitize_zip_filename($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        $name = 'archivos_comprimidos';
    }

    $name = preg_replace('/[^a-zA-Z0-9_\-\.]+/', '_', $name);
    $name = trim($name, '._-');

    if ($name === '') {
        $name = 'archivos_comprimidos';
    }

    if (!preg_match('/\.zip$/i', $name)) {
        $name .= '.zip';
    }

    return $name;
}

function ftp_read_file_binary($conn, $remoteFile, &$error = '')
{
    $error = '';
    $temp = fopen('php://temp', 'w+b');

    if (!$temp) {
        $error = 'No se pudo abrir memoria temporal.';
        return false;
    }

    $ok = @ftp_fget($conn, $temp, $remoteFile, FTP_BINARY);

    if (!$ok) {
        fclose($temp);
        $error = 'No se pudo leer el archivo remoto.';
        return false;
    }

    rewind($temp);
    $content = stream_get_contents($temp);
    fclose($temp);

    if ($content === false) {
        $error = 'No se pudo obtener el contenido binario del archivo.';
        return false;
    }

    return $content;
}

function ftp_output_download($conn, $remoteFile)
{
    $error = '';
    $content = ftp_read_file_binary($conn, $remoteFile, $error);

    if ($content === false) {
        return $error ?: 'No se pudo descargar el archivo.';
    }

    $filename = basename($remoteFile);
    if ($filename === '') {
        $filename = 'archivo.bin';
    }

    if (ob_get_length()) {
        @ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: public');
    echo $content;
    exit;
}

function ftp_add_path_to_zip($conn, $remotePath, ZipArchive $zip, $zipPrefix = '')
{
    $name = basename($remotePath);
    if ($name === '' || $name === '.' || $name === '..') {
        return;
    }

    $entryPath = ltrim(($zipPrefix === '' ? $name : $zipPrefix . '/' . $name), '/');

    if (ftp_is_dir($conn, $remotePath)) {
        $zip->addEmptyDir($entryPath);
        $items = ftp_list_detailed($conn, $remotePath);

        foreach ($items as $item) {
            ftp_add_path_to_zip($conn, $item['path'], $zip, $entryPath);
        }
        return;
    }

    $error = '';
    $content = ftp_read_file_binary($conn, $remotePath, $error);
    if ($content === false) {
        throw new RuntimeException($error ?: ('No se pudo agregar al ZIP: ' . $remotePath));
    }

    if (!$zip->addFromString($entryPath, $content)) {
        throw new RuntimeException('No se pudo escribir en el ZIP: ' . $entryPath);
    }
}


function is_zip_file($filename)
{
    return (bool)preg_match('/\.zip$/i', (string)$filename);
}

function ftp_write_file_binary($conn, $remoteFile, $content, &$error = '')
{
    $error = '';
    $temp = fopen('php://temp', 'w+b');

    if (!$temp) {
        $error = 'No se pudo abrir memoria temporal para escribir binario.';
        return false;
    }

    fwrite($temp, $content);
    rewind($temp);

    $ok = @ftp_fput($conn, $remoteFile, $temp, FTP_BINARY);
    fclose($temp);

    if (!$ok) {
        $error = 'No se pudo escribir el archivo remoto.';
        return false;
    }

    return true;
}

function ftp_extract_zip_remote($conn, $remoteZipPath, $targetDir, &$error = '')
{
    $error = '';

    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive no está disponible en este servidor PHP.';
        return false;
    }

    $zipData = ftp_read_file_binary($conn, $remoteZipPath, $error);
    if ($zipData === false) {
        return false;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'ftp_unzip_');
    if ($tmpZip === false) {
        $error = 'No se pudo crear archivo temporal para el ZIP.';
        return false;
    }

    file_put_contents($tmpZip, $zipData);

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        $error = 'No se pudo abrir el ZIP.';
        return false;
    }

    $baseTarget = rtrim(normalize_path($targetDir), '/');
    if ($baseTarget === '') {
        $baseTarget = '/';
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat || !isset($stat['name'])) {
            continue;
        }

        $entryName = str_replace('\\', '/', $stat['name']);
        $entryName = ltrim($entryName, '/');

        if ($entryName === '' || strpos($entryName, '../') !== false) {
            continue;
        }

        $remoteEntryPath = normalize_path($baseTarget . '/' . $entryName);
        $isDir = substr($entryName, -1) === '/';

        if ($isDir) {
            $parts = explode('/', trim($entryName, '/'));
            $pathTemp = $baseTarget;
            foreach ($parts as $dirPart) {
                if ($dirPart === '') {
                    continue;
                }
                $pathTemp .= '/' . $dirPart;
                @ftp_mkdir($conn, $pathTemp);
            }
            continue;
        }

        $dirName = dirname($entryName);
        if ($dirName !== '.' && $dirName !== '') {
            $parts = explode('/', $dirName);
            $pathTemp = $baseTarget;
            foreach ($parts as $dirPart) {
                if ($dirPart === '') {
                    continue;
                }
                $pathTemp .= '/' . $dirPart;
                @ftp_mkdir($conn, $pathTemp);
            }
        }

        $stream = $zip->getStream($stat['name']);
        if (!$stream) {
            $zip->close();
            @unlink($tmpZip);
            $error = 'No se pudo leer la entrada del ZIP: ' . $stat['name'];
            return false;
        }

        $content = stream_get_contents($stream);
        fclose($stream);

        if ($content === false) {
            $zip->close();
            @unlink($tmpZip);
            $error = 'No se pudo extraer la entrada del ZIP: ' . $stat['name'];
            return false;
        }

        $writeError = '';
        if (!ftp_write_file_binary($conn, $remoteEntryPath, $content, $writeError)) {
            $zip->close();
            @unlink($tmpZip);
            $error = $writeError ?: ('No se pudo escribir: ' . $remoteEntryPath);
            return false;
        }
    }

    $zip->close();
    @unlink($tmpZip);
    return true;
}


function detect_text_encoding($content)
{
    if ($content === '' || $content === null) {
        return 'UTF-8';
    }

    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        return 'UTF-8';
    }

    if (preg_match('//u', $content)) {
        return 'UTF-8';
    }

    if (function_exists('mb_detect_encoding')) {
        $enc = mb_detect_encoding(
            $content,
            ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15', 'ASCII'],
            true
        );

        if ($enc !== false) {
            return $enc;
        }
    }

    return 'Windows-1252';
}

function convert_to_utf8_for_editor($content, &$detectedEncoding = 'UTF-8')
{
    $detectedEncoding = detect_text_encoding($content);

    if (strtoupper($detectedEncoding) === 'UTF-8') {
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            return substr($content, 3);
        }
        return $content;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
        if ($converted !== false) {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv($detectedEncoding, 'UTF-8//IGNORE', $content);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $content;
}

function convert_from_utf8_for_save($content, $targetEncoding)
{
    $targetEncoding = trim((string)$targetEncoding);

    if ($targetEncoding === '' || strtoupper($targetEncoding) === 'UTF-8') {
        return $content;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($content, $targetEncoding, 'UTF-8');
        if ($converted !== false) {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', $targetEncoding . '//IGNORE', $content);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $content;
}

function ftp_open_connection($host, $port, $user, $pass, $useSsl, &$errorMessage = '')
{
    $errorMessage = '';

    if (!ftp_is_supported()) {
        $errorMessage = 'La extensión FTP de PHP no está disponible en este servidor.';
        return false;
    }

    $conn = false;
    $lastPhpWarning = '';

    set_error_handler(function ($severity, $message) use (&$lastPhpWarning) {
        $lastPhpWarning = $message;
        return true;
    });

    try {
        if ($useSsl) {
            if (!ftp_ssl_is_supported()) {
                restore_error_handler();
                $errorMessage = 'FTPS/SSL no está disponible en este servidor PHP.';
                return false;
            }
            $conn = ftp_ssl_connect($host, $port, 20);
        } else {
            $conn = ftp_connect($host, $port, 20);
        }

        if (!$conn) {
            restore_error_handler();
            $errorMessage = 'No se pudo conectar a ' . $host . ':' . $port .
                ($lastPhpWarning ? ' | Detalle: ' . $lastPhpWarning : '');
            return false;
        }

        if (!ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            restore_error_handler();
            $errorMessage = 'No se pudo autenticar con las credenciales FTP.' .
                ($lastPhpWarning ? ' | Detalle: ' . $lastPhpWarning : '');
            return false;
        }

        @ftp_pasv($conn, true);
        restore_error_handler();
        return $conn;
    } catch (Throwable $e) {
        restore_error_handler();

        if ($conn) {
            @ftp_close($conn);
        }

        $errorMessage = 'Excepción FTP: ' . $e->getMessage();
        return false;
    }
}

/*
|--------------------------------------------------------------------------
| LOGIN INTERNO DE LA PÁGINA
|--------------------------------------------------------------------------
*/
if (isset($_POST['admin_login'])) {
    $user = $_POST['admin_user'] ?? '';
    $pass = $_POST['admin_pass'] ?? '';

    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['admin_ok'] = true;
        flash('Acceso autorizado.', 'success');
    } else {
        flash('Usuario o contraseña de administración incorrectos.', 'error');
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_GET['logout_admin'])) {
    session_unset();
    session_destroy();
    session_start();
    flash('Sesión cerrada.', 'info');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$adminOk = !empty($_SESSION['admin_ok']);

/*
|--------------------------------------------------------------------------
| LOGIN / LOGOUT FTP
|--------------------------------------------------------------------------
*/
if ($adminOk && isset($_POST['ftp_connect'])) {
    $host     = normalize_ftp_host($_POST['ftp_host'] ?? '');
    $port     = (int)($_POST['ftp_port'] ?? 2221);
    $user     = trim($_POST['ftp_user'] ?? '');
    $pass     = (string)($_POST['ftp_pass'] ?? '');
    $rootPath = normalize_path($_POST['ftp_path'] ?? '/');
    $useSsl   = !empty($_POST['ftp_ssl']);

    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        flash('Debes indicar host, puerto, usuario y contraseña FTP.', 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $connectError = '';
    $conn = ftp_open_connection($host, $port, $user, $pass, $useSsl, $connectError);

    if (!$conn) {
        flash($connectError, 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!ftp_is_dir($conn, $rootPath)) {
        @ftp_close($conn);
        flash('La ruta inicial no existe o no es accesible: ' . $rootPath, 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    @ftp_close($conn);

    $_SESSION['ftp_connected'] = true;
    $_SESSION['ftp_host'] = $host;
    $_SESSION['ftp_port'] = $port;
    $_SESSION['ftp_user'] = $user;
    $_SESSION['ftp_pass'] = $pass;
    $_SESSION['ftp_ssl']  = $useSsl ? 1 : 0;
    $_SESSION['ftp_path'] = $rootPath;

    flash('Conexión FTP establecida correctamente.', 'success');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($adminOk && isset($_GET['ftp_logout'])) {
    unset(
        $_SESSION['ftp_connected'],
        $_SESSION['ftp_host'],
        $_SESSION['ftp_port'],
        $_SESSION['ftp_user'],
        $_SESSION['ftp_pass'],
        $_SESSION['ftp_ssl'],
        $_SESSION['ftp_path'],
        $_SESSION['edit_encoding'],
        $_SESSION['edit_file']
    );

    flash('Sesión FTP cerrada.', 'info');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/*
|--------------------------------------------------------------------------
| OPERACIONES FTP
|--------------------------------------------------------------------------
*/
$listing = [];
$currentPath = '/';
$editingFile = null;
$editingContent = '';
$editingLanguage = 'lang-text';
$editingEncoding = 'UTF-8';

if ($adminOk && !empty($_SESSION['ftp_connected'])) {
    $host = $_SESSION['ftp_host'];
    $port = (int)$_SESSION['ftp_port'];
    $user = $_SESSION['ftp_user'];
    $pass = $_SESSION['ftp_pass'];
    $useSsl = !empty($_SESSION['ftp_ssl']);
    $currentPath = normalize_path($_GET['path'] ?? $_SESSION['ftp_path'] ?? '/');

    $connectError = '';
    $ftp = ftp_open_connection($host, $port, $user, $pass, $useSsl, $connectError);

    if (!$ftp) {
        flash('Se perdió la conexión FTP. ' . $connectError, 'error');
        unset($_SESSION['ftp_connected']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!ftp_is_dir($ftp, $currentPath)) {
        $currentPath = $_SESSION['ftp_path'] ?? '/';
    }

    $_SESSION['ftp_path'] = $currentPath;

    if (isset($_POST['save_file'])) {
        $savePath = normalize_path($_POST['edit_file_path'] ?? '');
        $saveName = basename($savePath);
        $newContent = (string)($_POST['file_content'] ?? '');

        if ($savePath === '' || !is_editable_file($saveName)) {
            flash('Archivo inválido para guardar.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $originalEncoding = $_SESSION['edit_encoding'] ?? 'UTF-8';
        $lastEditFile = $_SESSION['edit_file'] ?? '';

        if ($lastEditFile !== $savePath) {
            $originalEncoding = 'UTF-8';
        }

        $newContentToSave = convert_from_utf8_for_save($newContent, $originalEncoding);

        $writeError = '';
        $ok = ftp_write_file_text($ftp, $savePath, $newContentToSave, $writeError);

        if ($ok) {
            flash('Archivo guardado correctamente.', 'success');
        } else {
            flash('No se pudo guardar el archivo. ' . $writeError, 'error');
        }

        @ftp_close($ftp);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode(parent_path($savePath)) . '&edit=' . urlencode($savePath));
        exit;
    }

    if (isset($_POST['create_dir'])) {
        $newDir = trim($_POST['new_dir_name'] ?? '');

        if ($newDir === '') {
            flash('Indica el nombre de la carpeta.', 'error');
        } else {
            $base = rtrim($currentPath, '/');
            if ($base === '') {
                $base = '/';
            }

            $newPath = ($base === '/' ? '/' . $newDir : $base . '/' . $newDir);

            if (@ftp_mkdir($ftp, $newPath)) {
                flash('Carpeta creada correctamente.', 'success');
            } else {
                flash('No se pudo crear la carpeta.', 'error');
            }
        }

        @ftp_close($ftp);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
        exit;
    }

    if (isset($_POST['upload_file'])) {
        if (!isset($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
            flash('No se recibió un archivo válido.', 'error');
        } else {
            $tmp  = $_FILES['upload']['tmp_name'];
            $name = basename($_FILES['upload']['name']);

            $base = rtrim($currentPath, '/');
            if ($base === '') {
                $base = '/';
            }

            $dest = ($base === '/' ? '/' . $name : $base . '/' . $name);

            if (@ftp_put($ftp, $dest, $tmp, FTP_BINARY)) {
                flash('Archivo subido correctamente.', 'success');
            } else {
                flash('No se pudo subir el archivo al servidor FTP.', 'error');
            }
        }

        @ftp_close($ftp);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
        exit;
    }

    if (isset($_GET['download'])) {
        $downloadPath = normalize_path($_GET['download']);

        if (ftp_is_dir($ftp, $downloadPath)) {
            flash('Solo se pueden descargar archivos individuales desde este botón.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        ftp_output_download($ftp, $downloadPath);
    }

    if (isset($_GET['unzip'])) {
        $zipPath = normalize_path($_GET['unzip']);

        if (ftp_is_dir($ftp, $zipPath)) {
            flash('La opción descomprimir solo aplica a archivos ZIP.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        if (!is_zip_file(basename($zipPath))) {
            flash('El archivo seleccionado no es un ZIP válido.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $extractTarget = parent_path($zipPath);
        $unzipError = '';

        $ok = ftp_extract_zip_remote($ftp, $zipPath, $extractTarget, $unzipError);

        flash(
            $ok ? ('ZIP descomprimido correctamente en: ' . $extractTarget) : ('No se pudo descomprimir el ZIP. ' . $unzipError),
            $ok ? 'success' : 'error'
        );

        @ftp_close($ftp);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($extractTarget));
        exit;
    }

    if (isset($_POST['create_zip_selected'])) {
        $selectedItems = $_POST['selected_items'] ?? [];
        $zipName = sanitize_zip_filename($_POST['zip_name'] ?? 'archivos_comprimidos.zip');

        if (!class_exists('ZipArchive')) {
            flash('ZipArchive no está disponible en este servidor PHP.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        if (!is_array($selectedItems) || count($selectedItems) === 0) {
            flash('Selecciona al menos un archivo o carpeta para comprimir.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'ftpzip_');
        if ($tmpZip === false) {
            flash('No se pudo crear un archivo temporal para el ZIP.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            flash('No se pudo inicializar el archivo ZIP.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        try {
            foreach ($selectedItems as $selectedPath) {
                $selectedPath = normalize_path($selectedPath);
                ftp_add_path_to_zip($ftp, $selectedPath, $zip);
            }

            $zip->close();
            @ftp_close($ftp);

            if (ob_get_length()) {
                @ob_end_clean();
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"; filename*=UTF-8\'\'' . rawurlencode($zipName));
            header('Content-Length: ' . filesize($tmpZip));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: public');
            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        } catch (Throwable $e) {
            $zip->close();
            @unlink($tmpZip);
            flash('No se pudo crear el ZIP. ' . $e->getMessage(), 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }
    }


    if (isset($_POST['rename_item'])) {
        $oldPath = normalize_path($_POST['rename_old_path'] ?? '');
        $newName = sanitize_rename_name($_POST['rename_new_name'] ?? '');

        if ($oldPath === '' || $newName === '') {
            flash('Debes indicar un nombre válido para renombrar.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $newPath = rtrim(parent_path($oldPath), '/') . '/' . $newName;
        $newPath = normalize_path($newPath);

        if ($newPath === $oldPath) {
            flash('El nuevo nombre es igual al actual.', 'info');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $ok = @ftp_rename($ftp, $oldPath, $newPath);

        flash(
            $ok ? 'Elemento renombrado correctamente.' : 'No se pudo renombrar el elemento.',
            $ok ? 'success' : 'error'
        );

        @ftp_close($ftp);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
        exit;
    }

    if (isset($_GET['delete'])) {
        $deletePath = normalize_path($_GET['delete']);

        $ok = ftp_delete_recursive($ftp, $deletePath);

        flash(
            $ok ? 'Elemento eliminado correctamente.' : 'No se pudo eliminar el elemento.',
            $ok ? 'success' : 'error'
        );

        @ftp_close($ftp);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
        exit;
    }

    if (isset($_GET['edit'])) {
        $editPath = normalize_path($_GET['edit']);
        $editName = basename($editPath);

        if (!is_editable_file($editName)) {
            flash('Ese tipo de archivo no está permitido para edición.', 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $readError = '';
        $content = ftp_read_file_text($ftp, $editPath, $readError);

        if ($content === false) {
            flash('No se pudo abrir el archivo para edición. ' . $readError, 'error');
            @ftp_close($ftp);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($currentPath));
            exit;
        }

        $editingFile = $editPath;
        $editingContent = convert_to_utf8_for_editor($content, $editingEncoding);
        $editingLanguage = get_file_language_class($editName);

        $_SESSION['edit_encoding'] = $editingEncoding;
        $_SESSION['edit_file'] = $editPath;
    }

    $listing = ftp_list_detailed($ftp, $currentPath);
    @ftp_close($ftp);
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrador FTP PRO</title>
    <style>
        :root {
            --bg: #0a0e14;
            --bg2: #0f1621;
            --panel: #141d29;
            --panel2: #1b2635;
            --line: #2a3a4f;
            --text: #edf4ff;
            --muted: #9db1c8;
            --cyan: #49c9ff;
            --green: #27d17f;
            --green2: #14945b;
            --red: #ff6a6a;
            --red2: #a43232;
            --orange: #ffb14a;
            --orange2: #bc6f10;
            --blue: #2d8cff;
            --blue2: #1858b9;
            --gray: #79879a;
            --gray2: #4d5969;
            --gold: #ffd166;
            --shadow: 0 12px 30px rgba(0, 0, 0, .28);
            --radius: 18px;
        }

        * {
            box-sizing: border-box
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background:
                radial-gradient(circle at top left, rgba(73, 201, 255, .10), transparent 28%),
                radial-gradient(circle at top right, rgba(39, 209, 127, .08), transparent 22%),
                linear-gradient(180deg, #081018 0%, #0d121a 100%);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            min-height: 100%;
        }

        body {
            padding-bottom: 32px;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: linear-gradient(135deg, #0f9b77 0%, #0b6f91 55%, #1456b6 100%);
            border-bottom: 1px solid rgba(255, 255, 255, .10);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
        }

        .topbar-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: linear-gradient(135deg, rgba(255, 255, 255, .16), rgba(255, 255, 255, .06));
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .12);
        }

        .brand-text h1 {
            margin: 0;
            font-size: 1.4rem;
            letter-spacing: .2px;
        }

        .brand-text p {
            margin: 4px 0 0;
            font-size: .92rem;
            color: rgba(255, 255, 255, .85);
        }

        .wrap {
            max-width: 1280px;
            margin: 24px auto 0;
            padding: 0 18px;
        }

        .card {
            background: linear-gradient(180deg, rgba(23, 33, 47, .94), rgba(17, 24, 35, .96));
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 18px;
            overflow: hidden;
        }

        .card h2 {
            margin: 0 0 16px;
            font-size: 1.08rem;
            color: var(--cyan);
            letter-spacing: .2px;
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .grid-2 {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }

        .grid-3 {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }

        label {
            display: block;
            margin: 0 0 7px;
            color: #b9d1e8;
            font-weight: bold;
            font-size: .95rem;
        }

        input[type="text"],
        input[type="password"],
        input[type="number"],
        input[type="file"],
        textarea {
            width: 100%;
            border: 1px solid #31445b;
            background: #0d141d;
            color: var(--text);
            border-radius: 12px;
            padding: 12px 13px;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease, transform .12s ease;
        }

        input[type="text"]:focus,
        input[type="password"]:focus,
        input[type="number"]:focus,
        input[type="file"]:focus,
        textarea:focus {
            border-color: #4aaeff;
            box-shadow: 0 0 0 3px rgba(74, 174, 255, .14);
        }

        input[type="checkbox"] {
            transform: scale(1.12);
            margin-right: 8px;
        }

        .checkbox-row {
            display: flex;
            align-items: flex-end;
            min-height: 100%;
            padding-bottom: 12px;
        }

        .checkbox-row label {
            margin: 0;
            display: flex;
            align-items: center;
            color: #c0d7ea;
            font-weight: bold;
        }

        .btnbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            appearance: none;
            border: 0;
            border-radius: 12px;
            padding: 11px 15px;
            color: #fff;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform .12s ease, filter .2s ease, box-shadow .2s ease;
            box-shadow: 0 8px 18px rgba(0, 0, 0, .18);
        }

        .btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-blue {
            background: linear-gradient(135deg, var(--blue), var(--blue2));
        }

        .btn-green {
            background: linear-gradient(135deg, var(--green), var(--green2));
        }

        .btn-red {
            background: linear-gradient(135deg, var(--red), var(--red2));
        }

        .btn-orange {
            background: linear-gradient(135deg, var(--orange), var(--orange2));
            color: #1b1200;
        }

        .btn-gray {
            background: linear-gradient(135deg, var(--gray), var(--gray2));
        }

        .flash {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-weight: bold;
            box-shadow: var(--shadow);
        }

        .flash.success {
            background: linear-gradient(180deg, rgba(23, 61, 41, .92), rgba(17, 45, 31, .96));
            color: #98f1c1;
            border: 1px solid #2f7755;
        }

        .flash.error {
            background: linear-gradient(180deg, rgba(69, 28, 28, .92), rgba(49, 19, 19, .96));
            color: #ffb0b0;
            border: 1px solid #8a3a3a;
        }

        .flash.info {
            background: linear-gradient(180deg, rgba(26, 45, 64, .92), rgba(19, 33, 47, .96));
            color: #9edcff;
            border: 1px solid #376589;
        }

        .meta {
            display: grid;
            gap: 8px;
        }

        .meta-item {
            color: var(--muted);
            font-size: .95rem;
        }

        .meta-item strong {
            color: #dce9f8;
        }

        .path {
            color: var(--gold);
            font-family: Consolas, monospace;
            word-break: break-all;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid #27364a;
            border-radius: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
            background: rgba(255, 255, 255, .02);
        }

        thead th {
            position: sticky;
            top: 0;
            background: #162130;
            color: #b7cee4;
            z-index: 1;
        }

        th,
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #243345;
            text-align: left;
            vertical-align: top;
            font-size: .95rem;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, .025);
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: bold;
            font-size: .88rem;
            background: #122030;
            border: 1px solid #263d58;
            white-space: nowrap;
        }

        .type-dir {
            color: #ffe08a;
        }

        .type-file {
            color: #9fd9ff;
        }

        .file-name a,
        a.link {
            color: #8edbff;
            text-decoration: none;
        }

        .file-name a:hover,
        a.link:hover {
            text-decoration: underline;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty {
            color: #ffb5b5;
            padding: 8px 0 2px;
        }

        .editor-shell {
            border: 1px solid #2a3d53;
            border-radius: 16px;
            overflow: hidden;
            background: #0d141c;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .02);
        }

        .editor-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            background: linear-gradient(180deg, #182333, #132030);
            border-bottom: 1px solid #2b3d52;
            flex-wrap: wrap;
        }

        .editor-title {
            font-weight: bold;
            color: #dce9f8;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .editor-title small {
            color: #95abc0;
            font-weight: normal;
        }

        .editor-info {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #0e1823;
            border: 1px solid #284059;
            color: #9fdcff;
            font-size: .84rem;
            font-weight: bold;
        }

        .editor-area {
            width: 100%;
            min-height: 520px;
            resize: vertical;
            border: 0;
            border-radius: 0;
            background: #0b1118;
            color: #ecf4ff;
            font-family: Consolas, "Courier New", monospace;
            font-size: 14px;
            line-height: 1.55;
            tab-size: 4;
            padding: 18px;
        }

        .editor-area.lang-php {
            color: #e8f1ff;
        }

        .editor-area.lang-html {
            color: #f9f2e9;
        }

        .editor-area.lang-css {
            color: #e7f5ff;
        }

        .editor-area.lang-js {
            color: #fff6d9;
        }

        .editor-area.lang-json {
            color: #d8fff2;
        }

        .editor-area.lang-xml {
            color: #ffe7da;
        }

        .editor-area.lang-py {
            color: #eefadf;
        }

        .editor-area.lang-java {
            color: #fce5d8;
        }

        .editor-area.lang-sql {
            color: #ddeaff;
        }

        .editor-area.lang-yaml {
            color: #e7fff8;
        }

        .editor-area.lang-bash {
            color: #e5ffe5;
        }

        .editor-area.lang-bat {
            color: #fff0e0;
        }

        .editor-area.lang-text {
            color: #eef4ff;
        }

        .editor-footer {
            padding: 12px 14px;
            border-top: 1px solid #27384d;
            background: #101923;
            color: #98aec4;
            font-size: .9rem;
        }

        .login-box {
            max-width: 500px;
            margin: 24px auto 0;
        }

        .muted {
            color: var(--muted);
        }

        .right {
            text-align: right;
        }

        .danger-note {
            background: rgba(255, 106, 106, .08);
            border: 1px solid rgba(255, 106, 106, .28);
            color: #ffc1c1;
            border-radius: 14px;
            padding: 12px 14px;
            margin-top: 12px;
            font-size: .93rem;
        }

        .hint {
            color: #98afc4;
            font-size: .92rem;
            margin-top: 6px;
        }

        @media (max-width: 768px) {
            .topbar-inner {
                flex-direction: column;
                align-items: flex-start;
            }

            .editor-area {
                min-height: 380px;
                font-size: 13px;
            }

            .btn {
                width: 100%;
            }

            .btnbar .btn {
                width: auto;
            }
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-icon">📁</div>
                <div class="brand-text">
                    <h1>Administrador FTP PRO</h1>
                    <p>Explorador, carga, edición y gestión de archivos por FTP</p>
                </div>
            </div>
        </div>
    </div>

    <div class="wrap">

        <?php if ($flash): ?>
            <div class="flash <?php echo h($flash['type']); ?>">
                <?php echo h($flash['msg']); ?>
            </div>
        <?php endif; ?>

        <?php if (!$adminOk): ?>
            <div class="login-box card">
                <h2>Acceso a la página</h2>
                <form method="post">
                    <label>Usuario administrador</label>
                    <input type="text" name="admin_user" value="root" required>

                    <label>Contraseña</label>
                    <input type="password" name="root" required>

                    <div class="btnbar">
                        <button class="btn btn-green" type="submit" name="admin_login" value="1">Entrar</button>
                    </div>
                </form>
            </div>
        <?php else: ?>

            <div class="card">
                <div class="btnbar">
                    <a class="btn btn-gray" href="?logout_admin=1">Cerrar sesión de la página</a>
                    <?php if (!empty($_SESSION['ftp_connected'])): ?>
                        <a class="btn btn-red" href="?ftp_logout=1">Cerrar sesión FTP</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($_SESSION['ftp_connected'])): ?>
                <div class="card">
                    <h2>Conexión FTP</h2>
                    <form method="post">
                        <div class="grid grid-2">
                            <div>
                                <label>Host FTP</label>
                                <input type="text" name="ftp_host" placeholder="mi_sitio.ddns.net" required>
                                <div class="hint">Solo host o dominio. No pongas ftp:// ni http://</div>
                            </div>
                            <div>
                                <label>Puerto</label>
                                <input type="number" name="ftp_port" value="21" required>
                                <div class="hint">Usa el puerto real de tu servidor FTP</div>
                            </div>
                        </div>

                        <div class="grid grid-2">
                            <div>
                                <label>Usuario FTP</label>
                                <input type="text" name="ftp_user" required>
                            </div>
                            <div>
                                <label>Contraseña FTP</label>
                                <input type="password" name="ftp_pass" required>
                            </div>
                        </div>

                        <div class="grid grid-2">
                            <div>
                                <label>Ruta inicial</label>
                                <input type="text" name="ftp_path" value="/" required>
                            </div>
                            <div class="checkbox-row">
                                <label>
                                    <input type="checkbox" name="ftp_ssl" value="1">
                                    Usar FTPS/SSL si está disponible
                                </label>
                            </div>
                        </div>

                        <div class="btnbar">
                            <button class="btn btn-green" type="submit" name="ftp_connect" value="1">Conectar FTP</button>
                        </div>
                    </form>

                    <div class="danger-note">
                        Este panel requiere que el servidor PHP tenga habilitada la extensión FTP.
                        Si FileZilla conecta pero este panel no, revisa que <strong>ftp_connect()</strong> exista en tu PHP.
                    </div>
                </div>
            <?php else: ?>

                <div class="card">
                    <h2>Sesión FTP activa</h2>
                    <div class="meta">
                        <div class="meta-item"><strong>Host:</strong> <?php echo h($_SESSION['ftp_host']); ?></div>
                        <div class="meta-item"><strong>Puerto:</strong> <?php echo h((string)$_SESSION['ftp_port']); ?></div>
                        <div class="meta-item"><strong>Usuario:</strong> <?php echo h($_SESSION['ftp_user']); ?></div>
                        <div class="meta-item"><strong>Ruta actual:</strong> <span
                                class="path"><?php echo h($currentPath); ?></span></div>
                    </div>

                    <div class="btnbar" style="margin-top:14px;">
                        <a class="btn btn-gray" href="?path=<?php echo urlencode(parent_path($currentPath)); ?>">⬆ Subir
                            nivel</a>
                        <a class="btn btn-blue" href="?path=<?php echo urlencode($currentPath); ?>">⟳ Recargar</a>
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="card">
                        <h2>Subir archivo</h2>
                        <form method="post" enctype="multipart/form-data" action="?path=<?php echo urlencode($currentPath); ?>">
                            <label>Seleccionar archivo</label>
                            <input type="file" name="upload" required>

                            <div class="btnbar">
                                <button class="btn btn-green" type="submit" name="upload_file" value="1">Subir archivo</button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <h2>Crear carpeta</h2>
                        <form method="post" action="?path=<?php echo urlencode($currentPath); ?>">
                            <label>Nombre de nueva carpeta</label>
                            <input type="text" name="new_dir_name" required>

                            <div class="btnbar">
                                <button class="btn btn-orange" type="submit" name="create_dir" value="1">Crear carpeta</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($editingFile !== null): ?>
                    <div class="card">
                        <h2>Editor integrado</h2>

                        <form method="post"
                            action="?path=<?php echo urlencode(parent_path($editingFile)); ?>&edit=<?php echo urlencode($editingFile); ?>">
                            <input type="hidden" name="edit_file_path" value="<?php echo h($editingFile); ?>">

                            <div class="editor-shell">
                                <div class="editor-toolbar">
                                    <div class="editor-title">
                                        <span><?php echo h(basename($editingFile)); ?></span>
                                        <small class="path"><?php echo h($editingFile); ?></small>
                                    </div>

                                    <div class="editor-info">
                                        <span class="pill">Editable</span>
                                        <span
                                            class="pill"><?php echo h(strtoupper(pathinfo($editingFile, PATHINFO_EXTENSION) ?: 'TXT')); ?></span>
                                        <span class="pill"><?php echo h($editingEncoding); ?></span>
                                    </div>
                                </div>

                                <textarea name="file_content" class="editor-area <?php echo h($editingLanguage); ?>"
                                    spellcheck="false"><?php echo h($editingContent); ?></textarea>

                                <div class="editor-footer">
                                    Editor de texto plano integrado. Úsalo para PHP, HTML, CSS, JS, TXT, SQL, JSON, XML, Python,
                                    Java y otros archivos editables.
                                </div>
                            </div>

                            <div class="btnbar" style="margin-top:14px;">
                                <button class="btn btn-green" type="submit" name="save_file" value="1">Guardar cambios</button>
                                <a class="btn btn-gray" href="?path=<?php echo urlencode(parent_path($editingFile)); ?>">Cerrar
                                    editor</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <h2>Contenido del directorio</h2>

                    <div class="meta-item" style="margin-bottom:14px;">
                        <strong>Ruta del directorio:</strong>
                        <span class="path"><?php echo h($currentPath); ?></span>
                    </div>

                    <?php if (count($listing) === 0): ?>
                        <div class="empty">No hay elementos en esta ruta.</div>
                    <?php else: ?>
                        <form method="post" action="?path=<?php echo urlencode($currentPath); ?>">
                            <div class="btnbar" style="margin-bottom:14px;align-items:center;">
                                <input type="text" name="zip_name" value="archivos_comprimidos.zip" placeholder="Nombre del ZIP"
                                    style="max-width:260px;">
                                <button class="btn btn-orange" type="submit" name="create_zip_selected" value="1">Comprimir
                                    selección ZIP</button>
                            </div>

                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width:42px;text-align:center;">
                                                <input type="checkbox" id="select_all_items" title="Seleccionar todo">
                                            </th>
                                            <th>Tipo</th>
                                            <th>Nombre</th>
                                            <th>Tamaño</th>
                                            <th>Fecha</th>
                                            <th>Permisos</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($listing as $item): ?>
                                            <tr>
                                                <td style="text-align:center;">
                                                    <input type="checkbox" class="select_item_checkbox" name="selected_items[]"
                                                        value="<?php echo h($item['path']); ?>">
                                                </td>
                                                <td>
                                                    <?php if ($item['is_dir']): ?>
                                                        <span class="type-badge type-dir">📁 Carpeta</span>
                                                    <?php else: ?>
                                                        <span class="type-badge type-file">📄 Archivo</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="file-name">
                                                    <?php if ($item['is_dir']): ?>
                                                        <a href="?path=<?php echo urlencode($item['path']); ?>">
                                                            <?php echo h($item['name']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php echo h($item['name']); ?>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo h($item['size']); ?></td>
                                                <td><?php echo h($item['date']); ?></td>
                                                <td><?php echo h($item['perm']); ?></td>
                                                <td>
                                                    <div class="actions">
                                                        <?php if (!$item['is_dir'] && is_editable_file($item['name'])): ?>
                                                            <a class="btn btn-blue"
                                                                href="?path=<?php echo urlencode($currentPath); ?>&edit=<?php echo urlencode($item['path']); ?>">
                                                                Editar
                                                            </a>
                                                        <?php endif; ?>

                                                        <?php if (!$item['is_dir']): ?>
                                                            <a class="btn btn-green"
                                                                href="?path=<?php echo urlencode($currentPath); ?>&download=<?php echo urlencode($item['path']); ?>">
                                                                Descargar
                                                            </a>

                                                            <?php if (is_zip_file($item['name'])): ?>
                                                                <a class="btn btn-orange"
                                                                    href="?path=<?php echo urlencode($currentPath); ?>&unzip=<?php echo urlencode($item['path']); ?>"
                                                                    onclick="return confirm('¿Descomprimir el archivo ZIP: <?php echo h($item['name']); ?> ?');">
                                                                    Descomprimir
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <a class="btn btn-gray" href="#"
                                                            onclick="var nuevo=prompt('Nuevo nombre para <?php echo h($item['name']); ?>:', '<?php echo h($item['name']); ?>'); if(nuevo!==null){nuevo=nuevo.trim(); if(nuevo!==''){ var f=document.getElementById('rename_form'); f.rename_old_path.value='<?php echo h($item['path']); ?>'; f.rename_new_name.value=nuevo; f.submit(); } } return false;">
                                                            Renombrar
                                                        </a>

                                                        <a class="btn btn-red"
                                                            href="?path=<?php echo urlencode($currentPath); ?>&delete=<?php echo urlencode($item['path']); ?>"
                                                            onclick="return confirm('¿Eliminar <?php echo $item['is_dir'] ? 'la carpeta' : 'el archivo'; ?>: <?php echo h($item['name']); ?> ?');">
                                                            Eliminar
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
        (function() {
            var master = document.getElementById('select_all_items');
            if (!master) return;

            function getItems() {
                return Array.prototype.slice.call(document.querySelectorAll('.select_item_checkbox'));
            }

            master.addEventListener('change', function() {
                getItems().forEach(function(cb) {
                    cb.checked = master.checked;
                });
            });

            getItems().forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var items = getItems();
                    master.checked = items.length > 0 && items.every(function(item) {
                        return item.checked;
                    });
                });
            });
        })();
    </script>
</body>

</html <form method="post" id="rename_form" action="?path=<?php echo urlencode($currentPath); ?>" style="display:none;">
<input type="hidden" name="rename_item" value="1">
<input type="hidden" name="rename_old_path" value="">
<input type="hidden" name="rename_new_name" value="">
</form>