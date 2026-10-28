<?php
// admin/migration_queue.php
require_once '../config.php';

// Allow running from CLI or Browser (Authenticated)
if (php_sapi_name() !== 'cli') {
    if (!isLoggedIn() || !isAdmin()) {
        die("Acceso denegado");
    }
}

$db = getDBConnection();

echo "<h2>Migración de Colas (Queue)</h2>";

// 1. Add 'called_at' to patient_exams
$table = 'patient_exams';
$col = 'called_at';

$res = $db->query("SHOW COLUMNS FROM $table LIKE '$col'");
if ($res->num_rows === 0) {
    $sql = "ALTER TABLE $table ADD COLUMN $col DATETIME NULL COMMENT 'Momento del llamado'";
    if ($db->query($sql)) {
        echo "<p>✅ Columna '$col' agregada a tabla '$table'.</p>";
    } else {
        echo "<p>❌ Error agregando columna: " . $db->error . "</p>";
    }
} else {
    echo "<p>ℹ️ La columna '$col' ya existe en '$table'.</p>";
}

echo "<p>Migración Finalizada.</p>";
echo '<a href="dashboard.php">Volver al Dashboard</a>';
?>
