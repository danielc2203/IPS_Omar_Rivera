<?php
// public_html/admin/debug_verify_billing.php
require_once '../config.php';

if (php_sapi_name() !== 'cli') die("CLI only");

$db = getDBConnection();
echo "--- INICIO VERIFICACION ---\n";

// 1. Crear Examen de Prueba
$db->query("INSERT INTO exams (name, cost, description) VALUES ('Test Exam', 999.00, 'Test DB')");
$examId = $db->insert_id;
echo "1. Examen creado ID: $examId\n";

// 2. Obtener un paciente existente (o crear dummy)
$res = $db->query("SELECT id FROM pacientes_pre_registrados LIMIT 1");
if ($res->num_rows > 0) {
    $pat = $res->fetch_assoc();
    $patId = $pat['id'];
    echo "2. Usando paciente ID: $patId\n";

    // 3. Asignar Examen
    $stmt = $db->prepare("INSERT INTO patient_exams (patient_id, exam_id, price_snapshot) VALUES (?, ?, ?)");
    $price = 999.00;
    $stmt->bind_param("iid", $patId, $examId, $price);
    if ($stmt->execute()) {
        $assId = $stmt->insert_id;
        echo "3. Examen asignado ID: $assId\n";
        
        // 4. Verificar Lectura
        $check = $db->query("SELECT * FROM patient_exams WHERE id = $assId")->fetch_assoc();
        if ($check && $check['price_snapshot'] == 999.00) {
            echo "4. VERIFICACION EXITOSA: Dato leido correctamente.\n";
        } else {
            echo "4. FALLO: No se pudo leer el registro.\n";
        }

        // Limpieza
        $db->query("DELETE FROM patient_exams WHERE id = $assId");
        echo "5. Limpieza asignacion OK\n";
    } else {
        echo "3. FALLO asignacion: " . $stmt->error . "\n";
    }

} else {
    echo "SKIP: No hay pacientes para probar.\n";
}

// Limpieza Examen
$db->query("DELETE FROM exams WHERE id = $examId");
echo "6. Limpieza examen OK\n";
echo "--- FIN ---\n";
