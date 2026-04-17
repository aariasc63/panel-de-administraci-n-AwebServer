<?php
header('Content-Type: text/html; charset=UTF-8');

function formatBytes($bytes)
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    $i = min($i, count($sizes) - 1);

    return round($bytes / pow(1024, $i), 2) . ' ' . $sizes[$i];
}

$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
$serverAddr     = $_SERVER['SERVER_ADDR'] ?? 'N/A';
$serverName     = $_SERVER['SERVER_NAME'] ?? 'N/A';
$documentRoot   = $_SERVER['DOCUMENT_ROOT'] ?? 'N/A';
$phpVersion     = phpversion();
$systemInfo     = php_uname();
$memoryLimit    = ini_get('memory_limit');
$maxExecTime    = ini_get('max_execution_time');
$uploadMax      = ini_get('upload_max_filesize');
$postMax        = ini_get('post_max_size');
$loadedExt      = get_loaded_extensions();
sort($loadedExt);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Info</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #eee;
            padding: 20px;
            margin: 0;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background: #1e1e1e;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.25);
        }

        h1,
        h2 {
            color: #00ff88;
            margin-top: 0;
        }

        p,
        li {
            line-height: 1.6;
        }

        a {
            color: #00c3ff;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .mono {
            font-family: Consolas, monospace;
            color: #ffd166;
        }

        ul {
            padding-left: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
        }

        .ext-box {
            max-height: 250px;
            overflow-y: auto;
            background: #151515;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #333;
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="card">
            <h1>📊 Información del Servidor</h1>
            <p><strong>Servidor:</strong> <span
                    class="mono"><?= htmlspecialchars($serverSoftware, ENT_QUOTES, 'UTF-8') ?></span></p>
            <p><strong>PHP:</strong> <span class="mono"><?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?></span>
            </p>
            <p><strong>Sistema:</strong> <span
                    class="mono"><?= htmlspecialchars($systemInfo, ENT_QUOTES, 'UTF-8') ?></span></p>
            <p><strong>IP:</strong> <span class="mono"><?= htmlspecialchars($serverAddr, ENT_QUOTES, 'UTF-8') ?></span>
            </p>
            <p><strong>Host:</strong> <span
                    class="mono"><?= htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8') ?></span></p>
            <p><strong>Document Root:</strong> <span
                    class="mono"><?= htmlspecialchars($documentRoot, ENT_QUOTES, 'UTF-8') ?></span></p>
            <p><strong>Hora:</strong> <span class="mono"><?= date('Y-m-d H:i:s') ?></span></p>
        </div>

        <div class="grid">
            <div class="card">
                <h2>💾 Recursos PHP</h2>
                <p><strong>Memoria límite:</strong> <span
                        class="mono"><?= htmlspecialchars($memoryLimit, ENT_QUOTES, 'UTF-8') ?></span></p>
                <p><strong>Tiempo máximo:</strong> <span
                        class="mono"><?= htmlspecialchars($maxExecTime, ENT_QUOTES, 'UTF-8') ?> s</span></p>
                <p><strong>Upload máximo:</strong> <span
                        class="mono"><?= htmlspecialchars($uploadMax, ENT_QUOTES, 'UTF-8') ?></span></p>
                <p><strong>POST máximo:</strong> <span
                        class="mono"><?= htmlspecialchars($postMax, ENT_QUOTES, 'UTF-8') ?></span></p>
            </div>

            <div class="card">
                <h2>🧩 Extensiones PHP</h2>
                <div class="ext-box">
                    <?= htmlspecialchars(implode(', ', $loadedExt), ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>🔗 Enlaces útiles</h2>
            <ul>
                <li><a href="info.php" target="_blank">PHP Info completo</a></li>
                <li><a href="./" target="_blank">Inicio del sitio</a></li>
                <li><a href="mysqladmin" target="_blank">phpMyAdmin</a></li>
            </ul>
        </div>

    </div>
</body>

</html>