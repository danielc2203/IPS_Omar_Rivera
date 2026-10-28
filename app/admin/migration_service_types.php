<?php
// public_html/admin/migration_service_types.php
require_once '../config.php';

if (php_sapi_name() !== 'cli' && (!isLoggedIn() || (!isAdmin() && !isDesarrollador()))) {
    die("Acceso restringido");
}

$db = getDBConnection();

echo "<h2>Migración Phase 6: Contexto del Servicio y Exámenes Comunes</h2>";

// 1. Add `service_type` column
echo "<h3>1. Actualizando Tabla `patient_exams`</h3>";
try {
    // Check if column exists
    $res = $db->query("SHOW COLUMNS FROM patient_exams LIKE 'service_type'");
    if ($res->num_rows == 0) {
        $sql = "ALTER TABLE patient_exams 
                ADD COLUMN service_type ENUM('Pre-ocupacional', 'Periodico', 'Post-ocupacional', 'Post-incapacidad', 'Otro') 
                DEFAULT 'Otro' AFTER note";
        if ($db->query($sql)) {
            echo "<div style='color:green'>Columna `service_type` agregada.</div>";
        } else {
            throw new Exception($db->error);
        }
    } else {
        echo "<div style='color:orange'>La columna `service_type` ya existe.</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red'>Error: " . $e->getMessage() . "</div>";
}

// 2. Seed Common Exams
echo "<h3>2. Sembrando Exámenes Comunes</h3>";
$commonExams = [
    ['Evaluación Médica General', 35000, 'Ficha médica, examen físico, signos vitales.'],
    ['Audiometría', 25000, 'Prueba de audición.'],
    ['Visiometría / Optometría', 25000, 'Examen de la vista.'],
    ['Pruebas de Laboratorio (Básico)', 45000, 'Glucosa, colesterol, función renal/hepática.'],
    ['Psicosensometría', 40000, 'Evalúa coordinación (conductores, etc).'],
    ['Valoración Osteomuscular', 30000, 'Evaluación física específica.'],
    ['Espirometría', 30000, 'Prueba de función pulmonar.'],
    ['Electrocardiograma', 45000, 'Registro de actividad eléctrica del corazón.']
];

foreach ($commonExams as $ex) {
    $name = $ex[0];
    $cost = $ex[1];
    $desc = $ex[2];

    // Check if exists by name
    $check = $db->prepare("SELECT id FROM exams WHERE name = ?");
    $check->bind_param("s", $name);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        $ins = $db->prepare("INSERT INTO exams (name, cost, description) VALUES (?, ?, ?)");
        $ins->bind_param("sds", $name, $cost, $desc);
        if ($ins->execute()) {
            echo "<li>Creado: <strong>$name</strong> ($ " . number_format($cost) . ")</li>";
        } else {
            echo "<li>Error creando $name: " . $ins->error . "</li>";
        }
    } else {
        echo "<li>Existe: $name (Skip)</li>";
    }
}

echo "<hr><p>Proceso finalizado.</p>";
