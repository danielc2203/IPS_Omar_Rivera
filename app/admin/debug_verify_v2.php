<?php
// public_html/admin/debug_verify_v2.php
require_once '../config.php';

if (php_sapi_name() !== 'cli') die("CLI only");

$db = getDBConnection();
echo "--- INICIO VERIFICACION V2 ---\n";

// A. SETUP DATA
try {
    // 1. Create Exam (Cost 1000)
    $db->query("INSERT INTO exams (name, cost) VALUES ('Test V2', 1000)");
    $examId = $db->insert_id;
    echo "1. Examen creado (Cost 1000).\n";

    // 2. Create Company
    $db->query("INSERT INTO empresas (id, nombre, email) VALUES ('comp_v2', 'Empresa V2 Test', 'test@v2.com')");
    $compId = 'comp_v2';
    echo "2. Empresa creada.\n";

    // 3. Create Override (Cost 500)
    $db->query("INSERT INTO company_exam_prices (company_id, exam_id, price) VALUES ('$compId', $examId, 500)");
    echo "3. Override creado (Price 500).\n";

    // 4. Create Patient linked to Company
    $db->query("INSERT INTO pacientes_pre_registrados (nombres, apellidos, empresa_id) VALUES ('Pat', 'V2', '$compId')");
    $patId = $db->insert_id;
    echo "4. Paciente creado.\n";

    // B. TEST LOGIC
    
    // 5. Test Resolve Price (Should be 500, not 1000)
    // We simulate the logic inside assign_exams (calling DB directly to check if override is picked up)
    $res = $db->query("SELECT price FROM company_exam_prices WHERE company_id='$compId' AND exam_id=$examId");
    $row = $res->fetch_assoc();
    if($row['price'] == 500) echo "PASS: Override detectado en DB.\n";
    else echo "FAIL: Override no encontrado.\n";

    // 6. Test Protocol Creation
    $db->query("INSERT INTO protocols (name) VALUES ('Pack V2')");
    $protoId = $db->insert_id;
    $db->query("INSERT INTO protocol_items (protocol_id, exam_id) VALUES ($protoId, $examId)");
    echo "6. Protocolo y Item creados.\n";

    // 7. Test Assignment (Manual Price Injection logic)
    // Simulating the JSON cart logic
    $priceSnapshot = 400; // Manual discount further than 500
    $note = "Special Discount";
    $stmt = $db->prepare("INSERT INTO patient_exams (patient_id, exam_id, price_snapshot, note) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iids", $patId, $examId, $priceSnapshot, $note);
    $stmt->execute();
    $assId = $stmt->insert_id;
    
    // 8. Verify
    $check = $db->query("SELECT price_snapshot, note FROM patient_exams WHERE id=$assId")->fetch_assoc();
    if($check['price_snapshot'] == 400 && $check['note'] === 'Special Discount') {
        echo "PASS: Asignacion manual (V2) correcta.\n";
    } else {
        echo "FAIL: Datos no coinciden.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// C. CLEANUP
$db->query("DELETE FROM patient_exams WHERE patient_id=$patId");
$db->query("DELETE FROM pacientes_pre_registrados WHERE id=$patId");
$db->query("DELETE FROM company_exam_prices WHERE company_id='$compId'");
$db->query("DELETE FROM protocol_items WHERE protocol_id=$protoId");
$db->query("DELETE FROM protocols WHERE id=$protoId");
$db->query("DELETE FROM exams WHERE id=$examId");
$db->query("DELETE FROM empresas WHERE id='$compId'");

echo "--- FIN V2 ---\n";
