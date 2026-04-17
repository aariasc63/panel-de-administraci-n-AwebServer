<?php
header('Content-Type: text/html; charset=UTF-8');

$version = "Android / AWebServer";
$server_name = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
$server_port = $_SERVER['SERVER_PORT'] ?? '8080';
$document_root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$php_version = phpversion();
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Web Server';
$server_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

function formatBytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getDiskStats(string $path): array
{
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);

    if ($total === false || $free === false || $total <= 0) {
        return [
            'total' => null,
            'free' => null,
            'used' => null,
            'used_percent' => null,
            'free_percent' => null,
        ];
    }

    $used = $total - $free;
    return [
        'total' => $total,
        'free' => $free,
        'used' => $used,
        'used_percent' => round(($used / $total) * 100, 1),
        'free_percent' => round(($free / $total) * 100, 1),
    ];
}

function parseMeminfo(): ?array
{
    $meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($meminfo === false) {
        return null;
    }

    $data = [];
    foreach ($meminfo as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', $line, $matches)) {
            $data[$matches[1]] = (int) $matches[2] * 1024;
        }
    }

    if (!isset($data['MemTotal'])) {
        return null;
    }

    $total = $data['MemTotal'];
    $available = $data['MemAvailable'] ?? (($data['MemFree'] ?? 0) + ($data['Buffers'] ?? 0) + ($data['Cached'] ?? 0));
    $used = max(0, $total - $available);

    return [
        'total' => $total,
        'available' => $available,
        'used' => $used,
        'used_percent' => $total > 0 ? round(($used / $total) * 100, 1) : null,
        'free_percent' => $total > 0 ? round(($available / $total) * 100, 1) : null,
    ];
}

function getCpuSnapshot(): ?array
{
    $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($line === false || empty($line)) {
        return null;
    }

    if (!preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line[0], $m)) {
        return null;
    }

    $values = array_map('intval', array_slice($m, 1));
    $idle = $values[3] + $values[4];
    $total = array_sum($values);

    return ['idle' => $idle, 'total' => $total, 'time' => time()];
}

function getCpuUsage(): array
{
    $snapshot = getCpuSnapshot();
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    $load1 = is_array($load) && isset($load[0]) ? round((float) $load[0], 2) : null;

    if ($snapshot === null) {
        return ['percent' => null, 'load1' => $load1];
    }

    $cacheFile = sys_get_temp_dir() . '/awebserver_cpu_stats.json';
    $previous = null;

    if (is_file($cacheFile)) {
        $json = @file_get_contents($cacheFile);
        $decoded = json_decode((string) $json, true);
        if (is_array($decoded) && isset($decoded['idle'], $decoded['total'])) {
            $previous = $decoded;
        }
    }

    @file_put_contents($cacheFile, json_encode($snapshot));

    if (!$previous) {
        return ['percent' => null, 'load1' => $load1];
    }

    $idleDiff = $snapshot['idle'] - $previous['idle'];
    $totalDiff = $snapshot['total'] - $previous['total'];

    if ($totalDiff <= 0) {
        return ['percent' => null, 'load1' => $load1];
    }

    $usage = 100 * (1 - ($idleDiff / $totalDiff));
    return [
        'percent' => round(max(0, min(100, $usage)), 1),
        'load1' => $load1,
    ];
}

function getResourceStats(): array
{
    $disk = getDiskStats($GLOBALS['document_root'] ?? __DIR__);
    $memory = parseMeminfo();
    $cpu = getCpuUsage();

    return [
        'disk' => [
            'used_text' => $disk['used'] !== null ? formatBytes((float) $disk['used']) : 'No disponible',
            'free_text' => $disk['free'] !== null ? formatBytes((float) $disk['free']) : 'No disponible',
            'total_text' => $disk['total'] !== null ? formatBytes((float) $disk['total']) : 'No disponible',
            'used_percent' => $disk['used_percent'],
            'free_percent' => $disk['free_percent'],
        ],
        'memory' => [
            'used_text' => $memory !== null ? formatBytes((float) $memory['used']) : 'No disponible',
            'free_text' => $memory !== null ? formatBytes((float) $memory['available']) : 'No disponible',
            'total_text' => $memory !== null ? formatBytes((float) $memory['total']) : 'No disponible',
            'used_percent' => $memory['used_percent'] ?? null,
            'free_percent' => $memory['free_percent'] ?? null,
        ],
        'cpu' => [
            'usage_percent' => $cpu['percent'],
            'usage_text' => $cpu['percent'] !== null ? $cpu['percent'] . '%' : 'Calculando...',
            'load_text' => $cpu['load1'] !== null ? (string) $cpu['load1'] : 'No disponible',
        ],
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'resources') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(getResourceStats(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$dirs = [];
$phpFiles = [];
$resourceStats = getResourceStats();

foreach (scandir('./') as $file) {
    if (is_dir($file) && !in_array($file, ['.', '..', 'css', 'images'], true)) {
        $dirs[] = $file;
    }

    if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php' && $file !== 'index.php') {
        $phpFiles[] = $file;
    }
}

sort($dirs);
sort($phpFiles);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AWebServer Android - Panel</title>
  <link rel="icon" href="./favicon.ico">
  <meta name="description" content="Panel principal estilo AWebServer Android">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      background: #0f1115;
      color: #e8eef5;
    }
    .topbar {
      background: linear-gradient(135deg, #0aa06e, #0b6e8a);
      padding: 18px 16px;
      box-shadow: 0 2px 12px rgba(0, 0, 0, .35);
    }
    .topbar h1 { margin: 0; font-size: 1.4rem; font-weight: 700; }
    .topbar .sub { margin-top: 4px; font-size: .92rem; opacity: .92; }
    .container {
      width: 100%;
      max-width: 1100px;
      margin: 0 auto;
      padding: 18px;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 16px;
    }
    .card {
      background: #171b22;
      border: 1px solid #26313d;
      border-radius: 16px;
      padding: 18px;
      box-shadow: 0 4px 18px rgba(0, 0, 0, .22);
    }
    .card h2 { margin: 0 0 14px 0; font-size: 1.05rem; color: #56d3ff; }
    .info-row { margin-bottom: 8px; line-height: 1.45; word-break: break-word; }
    .label { color: #8fb7c8; font-weight: 700; }
    .value { color: #ffffff; }
    .status {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(18, 179, 113, .16);
      color: #5ef0af;
      font-weight: 700;
      font-size: .9rem;
      border: 1px solid rgba(94, 240, 175, .24);
    }
    .links { display: grid; gap: 10px; }
    .link-btn {
      display: block;
      text-decoration: none;
      color: #fff;
      background: linear-gradient(135deg, #1e7bf6, #1452b8);
      padding: 12px 14px;
      border-radius: 12px;
      font-weight: 700;
      text-align: center;
      transition: transform .15s ease, opacity .15s ease;
    }
    .link-btn:hover { transform: translateY(-1px); opacity: .95; }
    .link-btn.green { background: linear-gradient(135deg, #14a86e, #0c7c58); }
    .link-btn.orange { background: linear-gradient(135deg, #d58919, #a96608); }
    .section-title { margin: 24px 0 14px; font-size: 1.15rem; color: #7ce4ff; }
    .list-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 12px;
    }
    .item {
      background: #171b22;
      border: 1px solid #26313d;
      border-radius: 14px;
      padding: 14px;
    }
    .item a { color: #d7f6ff; text-decoration: none; font-weight: 700; }
    .item small { display: block; margin-top: 6px; color: #8ba2b0; }
    .empty {
      color: #ff9c9c;
      background: #2a1717;
      border: 1px solid #5e2d2d;
      border-radius: 12px;
      padding: 14px;
    }
    .footer {
      text-align: center;
      color: #8ba2b0;
      padding: 24px 12px 34px;
      font-size: .92rem;
    }
    .badge {
      display: inline-block;
      padding: 4px 8px;
      font-size: .78rem;
      border-radius: 8px;
      background: #243242;
      color: #9ddcff;
      margin-left: 6px;
    }
    .resource-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
    }
    .metric {
      background: #111821;
      border: 1px solid #213142;
      border-radius: 14px;
      padding: 14px;
    }
    .metric-title {
      color: #9ddcff;
      font-weight: 700;
      margin-bottom: 10px;
      font-size: .96rem;
    }
    .metric-value {
      font-size: 1.45rem;
      font-weight: 700;
      color: #ffffff;
      margin-bottom: 8px;
    }
    .metric-sub {
      color: #8ba2b0;
      font-size: .9rem;
      margin-bottom: 10px;
      line-height: 1.4;
    }
    .progress {
      width: 100%;
      height: 10px;
      border-radius: 999px;
      background: #0a1118;
      border: 1px solid #1e2c39;
      overflow: hidden;
    }
    .progress-bar {
      height: 100%;
      width: 0;
      background: linear-gradient(135deg, #14a86e, #1e7bf6);
      transition: width .4s ease;
    }
    .updated-note {
      margin-top: 12px;
      color: #8ba2b0;
      font-size: .88rem;
    }
    @media (max-width: 640px) {
      .topbar h1 { font-size: 1.2rem; }
      .container { padding: 14px; }
      .card { padding: 15px; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="container" style="padding:0 18px;">
      <h1>📱 AWebServer Android <span class="badge">Panel Principal</span></h1>
      <div class="sub">Servidor web local · PHP activo · Acceso rápido a recursos</div>
    </div>
  </div>

  <div class="container">
    <div class="grid">
      <div class="card">
        <h2>Estado del servidor</h2>
        <div class="info-row"><span class="label">Estado:</span> <span class="status">En ejecución</span></div>
        <div class="info-row"><span class="label">Servidor:</span> <span class="value"><?php echo htmlspecialchars($server_software, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="info-row"><span class="label">PHP:</span> <span class="value"><?php echo htmlspecialchars($php_version, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="info-row"><span class="label">Host:</span> <span class="value"><?php echo htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="info-row"><span class="label">Puerto:</span> <span class="value"><?php echo htmlspecialchars($server_port, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="info-row"><span class="label">IP:</span> <span class="value"><?php echo htmlspecialchars($server_ip, ENT_QUOTES, 'UTF-8'); ?></span></div>
      </div>

      <div class="card">
        <h2>Información del entorno</h2>
        <div class="info-row"><span class="label">Plataforma:</span> <span class="value"><?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="info-row"><span class="label">Directorio raíz:</span> <span class="value"><?php echo htmlspecialchars($document_root, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="info-row"><span class="label">Hora del sistema:</span> <span class="value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
      </div>

      <div class="card">
        <h2>Accesos rápidos</h2>
        <div class="links">
          <a class="link-btn green" href="server_info.php" target="_blank">📊 Server Info</a>
          <a class="link-btn" href="info.php" target="_blank">⚙️ PHP Info</a>
          <a class="link-btn orange" href="mysqladmin" target="_blank">🗄️ phpMyAdmin</a>
          <a class="link-btn" href="ftp_admin.php" target="_blank">⚙️ FTP server</a>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <h2>Monitor de recursos en tiempo real</h2>
      <div class="resource-grid">
        <div class="metric">
          <div class="metric-title">Disco</div>
          <div class="metric-value" id="disk-used-percent"><?php echo $resourceStats['disk']['used_percent'] !== null ? $resourceStats['disk']['used_percent'] . '%' : 'No disponible'; ?></div>
          <div class="metric-sub" id="disk-text">Usado: <?php echo htmlspecialchars($resourceStats['disk']['used_text'], ENT_QUOTES, 'UTF-8'); ?> · Libre: <?php echo htmlspecialchars($resourceStats['disk']['free_text'], ENT_QUOTES, 'UTF-8'); ?> · Total: <?php echo htmlspecialchars($resourceStats['disk']['total_text'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="progress"><div class="progress-bar" id="disk-bar" style="width: <?php echo $resourceStats['disk']['used_percent'] ?? 0; ?>%"></div></div>
        </div>

        <div class="metric">
          <div class="metric-title">Memoria RAM</div>
          <div class="metric-value" id="memory-used-percent"><?php echo $resourceStats['memory']['used_percent'] !== null ? $resourceStats['memory']['used_percent'] . '%' : 'No disponible'; ?></div>
          <div class="metric-sub" id="memory-text">Usada: <?php echo htmlspecialchars($resourceStats['memory']['used_text'], ENT_QUOTES, 'UTF-8'); ?> · Libre: <?php echo htmlspecialchars($resourceStats['memory']['free_text'], ENT_QUOTES, 'UTF-8'); ?> · Total: <?php echo htmlspecialchars($resourceStats['memory']['total_text'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="progress"><div class="progress-bar" id="memory-bar" style="width: <?php echo $resourceStats['memory']['used_percent'] ?? 0; ?>%"></div></div>
        </div>

        <div class="metric">
          <div class="metric-title">Procesador</div>
          <div class="metric-value" id="cpu-usage"><?php echo htmlspecialchars($resourceStats['cpu']['usage_text'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="metric-sub" id="cpu-text">Carga promedio 1 min: <?php echo htmlspecialchars($resourceStats['cpu']['load_text'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="progress"><div class="progress-bar" id="cpu-bar" style="width: <?php echo $resourceStats['cpu']['usage_percent'] ?? 0; ?>%"></div></div>
        </div>
      </div>
      <div class="updated-note">Última actualización: <span id="updated-at"><?php echo htmlspecialchars($resourceStats['updated_at'], ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>

    <h2 class="section-title">📁 Subdirectorios disponibles</h2>

    <?php if (count($dirs) > 0): ?>
      <div class="list-grid">
        <?php foreach ($dirs as $index => $dir): ?>
          <div class="item">
            <a href="<?php echo rawurlencode($dir); ?>/" target="_blank"><?php echo ($index + 1) . '. ' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8'); ?></a>
            <small>Abrir carpeta en nueva pestaña</small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">No se encontraron subdirectorios en esta ruta.</div>
    <?php endif; ?>

    <h2 class="section-title">🐘 Archivos PHP detectados</h2>

    <?php if (count($phpFiles) > 0): ?>
      <div class="list-grid">
        <?php foreach ($phpFiles as $index => $phpFile): ?>
          <div class="item">
            <a href="<?php echo rawurlencode($phpFile); ?>" target="_blank"><?php echo ($index + 1) . '. ' . htmlspecialchars($phpFile, ENT_QUOTES, 'UTF-8'); ?></a>
            <small>Ejecutar archivo PHP</small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">No se encontraron archivos PHP adicionales.</div>
    <?php endif; ?>
  </div>

  <div class="footer">Copyright Aicasoft / AWebServer · PHP <?php echo htmlspecialchars($php_version, ENT_QUOTES, 'UTF-8'); ?></div>

  <script>
    async function updateResources() {
      try {
        const response = await fetch('index.php?ajax=resources&_=' + Date.now(), { cache: 'no-store' });
        if (!response.ok) {
          throw new Error('No se pudieron obtener los recursos');
        }

        const data = await response.json();

        const diskPercent = data.disk.used_percent ?? 0;
        document.getElementById('disk-used-percent').textContent = data.disk.used_percent !== null ? data.disk.used_percent + '%' : 'No disponible';
        document.getElementById('disk-text').textContent = `Usado: ${data.disk.used_text} · Libre: ${data.disk.free_text} · Total: ${data.disk.total_text}`;
        document.getElementById('disk-bar').style.width = diskPercent + '%';

        const memoryPercent = data.memory.used_percent ?? 0;
        document.getElementById('memory-used-percent').textContent = data.memory.used_percent !== null ? data.memory.used_percent + '%' : 'No disponible';
        document.getElementById('memory-text').textContent = `Usada: ${data.memory.used_text} · Libre: ${data.memory.free_text} · Total: ${data.memory.total_text}`;
        document.getElementById('memory-bar').style.width = memoryPercent + '%';

        const cpuPercent = data.cpu.usage_percent ?? 0;
        document.getElementById('cpu-usage').textContent = data.cpu.usage_text;
        document.getElementById('cpu-text').textContent = `Carga promedio 1 min: ${data.cpu.load_text}`;
        document.getElementById('cpu-bar').style.width = cpuPercent + '%';

        document.getElementById('updated-at').textContent = data.updated_at;
      } catch (error) {
        console.error(error);
      }
    }

    setInterval(updateResources, 5000);
  </script>
</body>
</html>
