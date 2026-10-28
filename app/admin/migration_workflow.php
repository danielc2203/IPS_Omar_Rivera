<?php
// public_html/admin/migration_workflow.php
require_once '../config.php';

if (php_sapi_name() !== 'cli' && (!isLoggedIn() || (!isAdmin() && !isDesarrollador()))) {
    die("Acceso restringido");
}

$db = getDBConnection();

echo "<h2>Migración Phase 7: Flujo de Trabajo y Especialistas</h2>";

// 1. Update `patient_exams`
echo "<h3>1. Actualizando Tabla `patient_exams`</h3>";
try {
    $res = $db->query("SHOW COLUMNS FROM patient_exams LIKE 'status'");
    if ($res->num_rows == 0) {
        $sql = "ALTER TABLE patient_exams 
                ADD COLUMN status ENUM('pending', 'completed') DEFAULT 'pending',
                ADD COLUMN completed_at TIMESTAMP NULL,
                ADD COLUMN completed_by INT NULL";
        if ($db->query($sql)) {
            echo "<div style='color:green'>Columnas `status`, `completed_at`, `completed_by` agregadas.</div>";
        } else {
            throw new Exception($db->error);
        }
    } else {
        echo "<div style='color:orange'>Columnas de estado ya existen.</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red'>Error: " . $e->getMessage() . "</div>";
}

// 2. Update `usuarios` table for specialist permissions
echo "<h3>2. Actualizando Tabla `usuarios` (Permisos de Especialista)</h3>";
try {
    $res = $db->query("SHOW COLUMNS FROM usuarios LIKE 'allowed_exam_ids'");
    if ($res->num_rows == 0) {
        $sql = "ALTER TABLE usuarios ADD COLUMN allowed_exam_ids TEXT NULL COMMENT 'JSON array of exam IDs'";
        if ($db->query($sql)) {
            echo "<div style='color:green'>Columna `allowed_exam_ids` agregada a usuarios.</div>";
        } else {
            throw new Exception($db->error);
        }
    } else {
        echo "<div style='color:orange'>Columna `allowed_exam_ids` ya existe.</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red'>Error: " . $e->getMessage() . "</div>";
}

// Ensure 'profesional' role is handled in code (ENUM usually not strict in PHP logic if VARCHAR, but let's check DB)
// Checking 'usuarios' table schema briefly
$res = $db->query("DESCRIBE usuarios rol");
$row = $res->fetch_assoc();
if (strpos($row['Type'], 'enum') !== false && strpos($row['Type'], 'profesional') === false) {
    // Need to alter ENUM
    $newType = str_replace(")", ",'profesional')", $row['Type']);
    $db->query("ALTER TABLE usuarios MODIFY COLUMN rol $newType");
    echo "<div>Rol 'profesional' agregado a ENUM.</div>";
}

echo "<hr><p>Proceso finalizado.</p>";
