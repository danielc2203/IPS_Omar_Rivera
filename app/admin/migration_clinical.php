<?php
// admin/migration_clinical.php
require_once '../config.php';

if (php_sapi_name() !== 'cli' && (!isLoggedIn() || !isAdmin())) {
    die("Acceso denegado");
}

$db = getDBConnection();

echo "<h2>Migración Módulo Clínico</h2>";

// Add columns to patient_exams
$columns = [
    "results_data" => "TEXT NULL COMMENT 'JSON con resultados estructurados'",
    "diagnosis" => "TEXT NULL COMMENT 'Diagnóstico médico'",
    "aptitude" => "ENUM('apto', 'no_apto', 'apto_restriccion', 'aplazado') NULL COMMENT 'Concepto de aptitud'",
    "recommendations" => "TEXT NULL COMMENT 'Recomendaciones médicas'"
];

foreach ($columns as $col => $def) {
    try {
        $sql = "ALTER TABLE patient_exams ADD COLUMN $col $def";
        if ($db->query($sql)) {
            echo "<p style='color:green'>Columna <b>$col</b> agregada con éxito.</p>";
        } else {
            // Check if error is "Duplicate column"
            if ($db->errno == 1060) {
                 echo "<p style='color:orange'>La columna <b>$col</b> ya existe.</p>";
            } else {
                 echo "<p style='color:red'>Error agregando <b>$col</b>: " . $db->error . "</p>";
            }
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>Excepción: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>Migración Completada.</h3>";
echo "<a href='dashboard.php'>Volver al Dashboard</a>";
?>
