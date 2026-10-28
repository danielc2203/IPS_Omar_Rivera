<?php
// admin/backup_manager.php — Gestor de copias de seguridad (v2)
// Crear (PHP puro, streaming, gzip), descargar, eliminar y restaurar.
require_once '../config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit();
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$backupDir = __DIR__ . '/backups/';
$logFile   = __DIR__ . '/backup_debug.log';

if (!is_dir($backupDir)) @mkdir($backupDir, 0750, true);
// Bloquear acceso web directo a los backups
if (!file_exists($backupDir . '.htaccess')) {
    @file_put_contents($backupDir . '.htaccess', "Require all denied\nDeny from all\n");
}
if (!file_exists($backupDir . 'index.html')) @file_put_contents($backupDir . 'index.html', '');

// ---------------------------------------------------------------- helpers
function bk_log($msg) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $msg . "\n", FILE_APPEND);
}

function bk_db() {
    global $pdo, $conn, $db;
    foreach ([$pdo ?? null, $conn ?? null, $db ?? null] as $c) {
        if ($c instanceof PDO) return $c;
    }
    $p = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $p;
}

// Conexión dedicada para lectura sin buffer (no satura memoria con tablas grandes)
function bk_db_stream() {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]
    );
}

function bk_csrf() {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['bk_csrf'])) $_SESSION['bk_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['bk_csrf'];
}

function bk_check_csrf() {
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(bk_csrf(), $t)) {
        throw new Exception('Token de seguridad inválido. Recargue la página.');
    }
}

function bk_safe_name($name) {
    $name = basename((string)$name);
    if (!preg_match('/^backup_[A-Za-z0-9_\-]+\.sql(\.gz)?$/', $name)) {
        throw new Exception('Nombre de archivo no válido.');
    }
    return $name;
}

function bk_audit($accion, $detalle) {
    // Registro en registro_actividades (logs.php) con la función del sistema
    if (function_exists('logActivity')) {
        try { logActivity($accion, 'system', $detalle); } catch (Throwable $e) {}
    }
}

function bk_human($bytes) {
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $u[$i];
}

// ------------------------------------------------------------ crear backup
function bk_create($prefix = 'backup_') {
    global $backupDir;

    $useGz    = function_exists('gzopen');
    $filename = $prefix . date('Y-m-d_H-i-s') . '.sql' . ($useGz ? '.gz' : '');
    $tmpPath  = $backupDir . $filename . '.part';
    $filePath = $backupDir . $filename;

    $h = $useGz ? gzopen($tmpPath, 'wb6') : fopen($tmpPath, 'wb');
    if (!$h) throw new Exception('No se pudo crear el archivo (permisos de escritura en backups/).');
    $w = $useGz ? 'gzwrite' : 'fwrite';

    try {
        $meta   = bk_db_stream();
        $stream = bk_db_stream();

        $w($h, "-- Backup Sistema de Consentimientos\n");
        $w($h, '-- Fecha: ' . date('Y-m-d H:i:s') . "\n");
        $w($h, '-- Base de datos: ' . DB_NAME . "\n\n");
        $w($h, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET UNIQUE_CHECKS=0;\n\n");

        $objs   = $meta->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];
        $views  = [];
        foreach ($objs as $o) {
            if (strtoupper($o[1]) === 'VIEW') $views[] = $o[0]; else $tables[] = $o[0];
        }

        $totalRows = 0;
        foreach ($tables as $table) {
            $qt = '`' . str_replace('`', '``', $table) . '`';

            $create = $meta->query("SHOW CREATE TABLE $qt")->fetch(PDO::FETCH_NUM);
            $w($h, "-- Tabla $table\nDROP TABLE IF EXISTS $qt;\n" . $create[1] . ";\n\n");

            // Columnas binarias → se exportan en hexadecimal
            $cols   = $meta->query("SHOW COLUMNS FROM $qt")->fetchAll(PDO::FETCH_ASSOC);
            $binary = [];
            $names  = [];
            foreach ($cols as $i => $c) {
                $names[] = '`' . str_replace('`', '``', $c['Field']) . '`';
                $binary[$i] = (bool)preg_match('/blob|binary|bit/i', $c['Type']);
            }
            $colList = '(' . implode(',', $names) . ')';

            $res   = $stream->query("SELECT * FROM $qt");
            $batch = [];
            $size  = 0;
            while ($row = $res->fetch(PDO::FETCH_NUM)) {
                $vals = [];
                foreach ($row as $i => $v) {
                    if ($v === null)        $vals[] = 'NULL';
                    elseif (!empty($binary[$i])) $vals[] = ($v === '' ? "''" : '0x' . bin2hex($v));
                    else                    $vals[] = $meta->quote((string)$v);
                }
                $tuple   = '(' . implode(',', $vals) . ')';
                $batch[] = $tuple;
                $size   += strlen($tuple);
                $totalRows++;
                // Lotes de ~1 MB o 200 filas para no exceder max_allowed_packet
                if ($size > 1000000 || count($batch) >= 200) {
                    $w($h, "INSERT INTO $qt $colList VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                    $size  = 0;
                }
            }
            $res->closeCursor();
            if ($batch) $w($h, "INSERT INTO $qt $colList VALUES\n" . implode(",\n", $batch) . ";\n");
            $w($h, "\n");
        }

        foreach ($views as $view) {
            $qv = '`' . str_replace('`', '``', $view) . '`';
            $c  = $meta->query("SHOW CREATE VIEW $qv")->fetch(PDO::FETCH_NUM);
            $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $c[1]);
            $w($h, "DROP VIEW IF EXISTS $qv;\n$sql;\n\n");
        }

        $w($h, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- FIN DEL BACKUP\n");
        $useGz ? gzclose($h) : fclose($h);
        $h = null;

        if (!@rename($tmpPath, $filePath)) throw new Exception('No se pudo finalizar el archivo de backup.');
        @chmod($filePath, 0640);

        bk_log("create OK $filename (" . count($tables) . " tablas, $totalRows filas, " . bk_human(filesize($filePath)) . ')');
        return ['file' => $filename, 'tables' => count($tables), 'rows' => $totalRows, 'size' => bk_human(filesize($filePath))];
    } catch (Throwable $e) {
        if ($h) { $useGz ? @gzclose($h) : @fclose($h); }
        @unlink($tmpPath);
        bk_log('create ERROR: ' . $e->getMessage());
        throw $e;
    }
}

// ------------------------------------------------------- restaurar backup
function bk_restore_file($path) {
    $isGz = substr($path, -3) === '.gz';
    $h = $isGz ? gzopen($path, 'rb') : fopen($path, 'rb');
    if (!$h) throw new Exception('No se pudo abrir el archivo de backup.');
    $read = $isGz ? 'gzgets' : 'fgets';
    $eof  = $isGz ? 'gzeof' : 'feof';

    $db = bk_db_stream();
    $db->exec('SET NAMES utf8mb4');
    $db->exec('SET FOREIGN_KEY_CHECKS=0');

    $stmt    = '';
    $inStr   = false;   // dentro de comillas
    $quote   = '';
    $count   = 0;

    while (!$eof($h)) {
        $line = $read($h);
        if ($line === false) break;

        // Saltar comentarios de línea fuera de strings
        if (!$inStr && $stmt === '') {
            $t = ltrim($line);
            if ($t === '' || strpos($t, '-- ') === 0 || $t === "--\n" || strpos($t, '#') === 0) continue;
        }

        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($inStr) {
                if ($ch === '\\') { $stmt .= $ch . ($line[$i + 1] ?? ''); $i++; continue; }
                if ($ch === $quote) {
                    if (($line[$i + 1] ?? '') === $quote) { $stmt .= $ch . $ch; $i++; continue; }
                    $inStr = false;
                }
                $stmt .= $ch;
            } else {
                if ($ch === "'" || $ch === '"' || $ch === '`') { $inStr = true; $quote = $ch; $stmt .= $ch; continue; }
                if ($ch === ';') {
                    $sql = trim($stmt);
                    if ($sql !== '') { $db->exec($sql); $count++; }
                    $stmt = '';
                    continue;
                }
                $stmt .= $ch;
            }
        }
    }
    $isGz ? gzclose($h) : fclose($h);
    if (trim($stmt) !== '') { $db->exec(trim($stmt)); $count++; }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    return $count;
}

// ------------------------------------------------------------- DESCARGA (GET)
if (isset($_GET['download'])) {
    try {
        $name = bk_safe_name($_GET['download']);
        $path = $backupDir . $name;
        if (!is_file($path)) throw new Exception('El archivo no existe.');
        while (ob_get_level()) ob_end_clean();
        bk_log("download $name");
        bk_audit('Backup Descargado', $name);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($path);
        exit();
    } catch (Throwable $e) {
        http_response_code(404);
        echo htmlspecialchars($e->getMessage());
        exit();
    }
}

// ----------------------------------------------------------- ACCIONES POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Captura errores fatales (memoria/tiempo) y responde JSON en lugar de "romperse"
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            bk_log('FATAL: ' . $e['message']);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e['message']]);
        }
    });

    try {
        bk_check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $r = bk_create();
            bk_audit('Backup Creado', $r['file']);
            echo json_encode(['success' => true, 'message' => "Backup creado: {$r['file']} ({$r['tables']} tablas, {$r['rows']} registros, {$r['size']})"]);
            exit();
        }

        if ($action === 'delete') {
            $name = bk_safe_name($_POST['file'] ?? '');
            if (!is_file($backupDir . $name) || !@unlink($backupDir . $name)) throw new Exception('No se pudo eliminar el archivo.');
            bk_log("delete $name");
            bk_audit('Backup Eliminado', $name);
            echo json_encode(['success' => true, 'message' => 'Backup eliminado.']);
            exit();
        }

        if ($action === 'restore') {
            if (($_POST['confirm'] ?? '') !== 'RESTAURAR') throw new Exception('Debe escribir RESTAURAR para confirmar.');

            // Origen: backup existente o archivo subido
            if (!empty($_FILES['upload']['tmp_name'])) {
                $up = $_FILES['upload'];
                if ($up['error'] !== UPLOAD_ERR_OK) throw new Exception('Error al subir el archivo (código ' . $up['error'] . ').');
                if (!preg_match('/\.sql(\.gz)?$/i', $up['name'])) throw new Exception('Solo se permiten archivos .sql o .sql.gz');
                $ext  = preg_match('/\.gz$/i', $up['name']) ? '.sql.gz' : '.sql';
                $name = 'backup_subido_' . date('Y-m-d_H-i-s') . $ext;
                if (!move_uploaded_file($up['tmp_name'], $backupDir . $name)) throw new Exception('No se pudo guardar el archivo subido.');
            } else {
                $name = bk_safe_name($_POST['file'] ?? '');
            }
            $src = $backupDir . $name;
            if (!is_file($src)) throw new Exception('El archivo de backup no existe.');

            // Validación mínima de contenido
            $isGz = substr($src, -3) === '.gz';
            $fh   = $isGz ? gzopen($src, 'rb') : fopen($src, 'rb');
            $head = $isGz ? gzread($fh, 65536) : fread($fh, 65536);
            $isGz ? gzclose($fh) : fclose($fh);
            if (!preg_match('/CREATE TABLE|INSERT INTO/i', (string)$head)) throw new Exception('El archivo no parece un backup SQL válido.');

            // Respaldo de seguridad automático antes de sobrescribir
            $pre = bk_create('backup_pre-restauracion_');
            bk_log("restore START $name (respaldo previo: {$pre['file']})");

            $n = bk_restore_file($src);

            bk_log("restore OK $name ($n sentencias)");
            bk_audit('Backup Restaurado', "$name (respaldo previo: {$pre['file']})");
            echo json_encode(['success' => true, 'message' => "Base de datos restaurada desde $name ($n sentencias). Respaldo previo guardado como {$pre['file']}."]);
            exit();
        }

        throw new Exception('Acción no válida.');
    } catch (Throwable $e) {
        bk_log('ERROR (' . ($_POST['action'] ?? '-') . '): ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// ------------------------------------------------------------------ VISTA
$csrf  = bk_csrf();
$files = [];
foreach (glob($backupDir . 'backup_*.sql*') ?: [] as $f) {
    if (substr($f, -5) === '.part') continue;
    $files[] = ['name' => basename($f), 'time' => filemtime($f), 'size' => filesize($f)];
}
usort($files, fn($a, $b) => $b['time'] <=> $a['time']);
$maxUpload = ini_get('upload_max_filesize');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backups | Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#f8f9fa; }
        .tiny { font-size:.8rem; }
        .row-warn td { background:#fff3cd !important; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary mb-4">
    <div class="container-fluid">
        <span class="navbar-brand">Administración</span>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
    </div>
</nav>

<div class="container">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="bi bi-database"></i> Copias de Seguridad</h2>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="bi bi-upload"></i> Restaurar desde archivo
            </button>
            <button class="btn btn-success" id="btnCreate" onclick="createBackup()">
                <i class="bi bi-cloud-arrow-down"></i> Generar Respaldo Ahora
            </button>
        </div>
    </div>

    <div id="alertBox"></div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Archivo</th><th>Fecha</th><th>Tamaño</th><th class="text-end">Acciones</th></tr>
                </thead>
                <tbody>
                <?php if (!$files): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No hay copias de seguridad todavía.</td></tr>
                <?php endif; ?>
                <?php foreach ($files as $f):
                    $bad = $f['size'] < 1024; ?>
                    <tr class="<?= $bad ? 'row-warn' : '' ?>">
                        <td class="fw-semibold">
                            <?= htmlspecialchars($f['name']) ?>
                            <?php if ($bad): ?><span class="badge bg-warning text-dark ms-1" title="Archivo demasiado pequeño, probablemente incompleto">Incompleto</span><?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y H:i:s', $f['time']) ?></td>
                        <td><?= bk_human($f['size']) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-success" title="Descargar" href="?download=<?= urlencode($f['name']) ?>"><i class="bi bi-download"></i></a>
                            <?php if (!$bad): ?>
                            <button class="btn btn-sm btn-warning" title="Restaurar" onclick="openRestore('<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>')"><i class="bi bi-arrow-counterclockwise"></i></button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger" title="Eliminar" onclick="deleteBackup('<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>')"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-info mt-4 tiny">
        <i class="bi bi-info-circle"></i> <strong>Recomendación:</strong> descargue una copia al menos una vez por semana y guárdela fuera del servidor (disco externo o nube institucional).
        Antes de cada restauración el sistema genera automáticamente un respaldo de seguridad (<code>backup_pre-restauracion_…</code>).
    </div>
</div>

<!-- Modal restaurar backup existente -->
<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Restaurar base de datos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p>Se reemplazarán <strong>todos los datos actuales</strong> por los del archivo:</p>
            <p class="fw-bold" id="restoreName"></p>
            <p class="tiny text-muted">Antes de restaurar se creará un respaldo automático del estado actual.</p>
            <label class="form-label">Escriba <strong>RESTAURAR</strong> para confirmar:</label>
            <input type="text" class="form-control" id="restoreConfirm" autocomplete="off">
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-danger" id="btnRestore" onclick="doRestore()">Restaurar</button>
        </div>
    </div></div>
</div>

<!-- Modal restaurar desde archivo -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-upload"></i> Restaurar desde archivo</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="file" class="form-control mb-2" id="uploadFile" accept=".sql,.gz">
            <p class="tiny text-muted">Archivos .sql o .sql.gz (máx. <?= htmlspecialchars($maxUpload) ?>). Se reemplazarán todos los datos actuales; antes se crea un respaldo automático.</p>
            <label class="form-label">Escriba <strong>RESTAURAR</strong> para confirmar:</label>
            <input type="text" class="form-control" id="uploadConfirm" autocomplete="off">
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-danger" id="btnUpload" onclick="doUploadRestore()">Subir y restaurar</button>
        </div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($csrf) ?>;
let restoreFile = null;

function showAlert(type, msg) {
    const box = document.getElementById('alertBox');
    box.innerHTML = '';
    const div = document.createElement('div');
    div.className = 'alert alert-' + type + ' alert-dismissible fade show';
    div.textContent = msg;
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn-close'; btn.setAttribute('data-bs-dismiss', 'alert');
    div.appendChild(btn);
    box.appendChild(div);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function post(fd) {
    fd.append('csrf', CSRF);
    const r = await fetch('backup_manager.php', { method: 'POST', body: fd });
    const txt = await r.text();
    try { return JSON.parse(txt); }
    catch (e) { return { success: false, message: 'Respuesta inesperada del servidor: ' + txt.substring(0, 300) }; }
}

function busy(btn, on, label) {
    if (on) { btn.dataset.html = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + label; }
    else { btn.disabled = false; btn.innerHTML = btn.dataset.html; }
}

async function createBackup() {
    const btn = document.getElementById('btnCreate');
    busy(btn, true, 'Generando...');
    const fd = new FormData(); fd.append('action', 'create');
    const res = await post(fd);
    busy(btn, false);
    showAlert(res.success ? 'success' : 'danger', res.message);
    if (res.success) setTimeout(() => location.reload(), 1500);
}

async function deleteBackup(name) {
    if (!window.confirm('¿Eliminar el backup ' + name + '? Esta acción no se puede deshacer.')) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('file', name);
    const res = await post(fd);
    showAlert(res.success ? 'success' : 'danger', res.message);
    if (res.success) setTimeout(() => location.reload(), 1000);
}

function openRestore(name) {
    restoreFile = name;
    document.getElementById('restoreName').textContent = name;
    document.getElementById('restoreConfirm').value = '';
    new bootstrap.Modal(document.getElementById('restoreModal')).show();
}

async function doRestore() {
    if (document.getElementById('restoreConfirm').value.trim() !== 'RESTAURAR') { alert('Escriba RESTAURAR para confirmar.'); return; }
    const btn = document.getElementById('btnRestore');
    busy(btn, true, 'Restaurando...');
    const fd = new FormData(); fd.append('action', 'restore'); fd.append('file', restoreFile); fd.append('confirm', 'RESTAURAR');
    const res = await post(fd);
    busy(btn, false);
    bootstrap.Modal.getInstance(document.getElementById('restoreModal')).hide();
    showAlert(res.success ? 'success' : 'danger', res.message);
    if (res.success) setTimeout(() => location.reload(), 2500);
}

async function doUploadRestore() {
    const f = document.getElementById('uploadFile').files[0];
    if (!f) { alert('Seleccione un archivo.'); return; }
    if (document.getElementById('uploadConfirm').value.trim() !== 'RESTAURAR') { alert('Escriba RESTAURAR para confirmar.'); return; }
    const btn = document.getElementById('btnUpload');
    busy(btn, true, 'Subiendo y restaurando...');
    const fd = new FormData(); fd.append('action', 'restore'); fd.append('upload', f); fd.append('confirm', 'RESTAURAR');
    const res = await post(fd);
    busy(btn, false);
    bootstrap.Modal.getInstance(document.getElementById('uploadModal')).hide();
    showAlert(res.success ? 'success' : 'danger', res.message);
    if (res.success) setTimeout(() => location.reload(), 2500);
}
</script>
</body>
</html>
