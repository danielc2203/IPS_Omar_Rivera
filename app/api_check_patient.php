<?php
// public_html/api_check_patient.php
require_once 'config.php';
header('Content-Type: application/json');

// Evitar que errores de PHP rompan el JSON
error_reporting(0); 

$type = $_GET['document_type'] ?? '';
$number = $_GET['document_number'] ?? '';

if (!$type || !$number) {
    echo json_encode(['status' => 'error', 'message' => 'Faltan datos']);
    exit;
}

$db = getDBConnection();

// Buscar en la tabla de pre-registrados
$stmt = $db->prepare("
    SELECT p.nombres, p.apellidos, p.email, p.telefono, p.empresa_id, p.empresa_particular, e.nombre as nombre_empresa 
    FROM pacientes_pre_registrados p 
    JOIN empresas e ON p.empresa_id = e.id 
    WHERE p.tipo_documento = ? AND p.numero_documento = ? AND p.estado_registro = 'activo'
    LIMIT 1
");

$stmt->bind_param("ss", $type, $number);
$stmt->execute();
$stmt->execute();
$result = $stmt->get_result();

// --- CHECK DUPLICATE SIGNATURE TODAY ---
$alreadySigned = false;
$stmtSig = $db->prepare("SELECT id FROM consents WHERE document_number = ? AND DATE(date_signed) = CURDATE() LIMIT 1");
$stmtSig->bind_param("s", $number);
$stmtSig->execute();
if ($stmtSig->get_result()->num_rows > 0) {
    $alreadySigned = true;
}
// ---------------------------------------

if ($result->num_rows > 0) {
    // ENCONTRADO: Viene de empresa
    $data = $result->fetch_assoc();
    echo json_encode([
        'status' => 'found',
        'already_signed_today' => $alreadySigned,
        'type' => 'company',
        'data' => [
            'first_name' => $data['nombres'],
            'last_name' => $data['apellidos'],
            // Masking PII
            'email' => substr($data['email'], 0, 3) . '***@' . explode('@', $data['email'])[1],
            'phone' => substr($data['phone'], 0, 3) . '******' . substr($data['phone'], -2),
            'company_id' => $data['empresa_id'],
            'company_name' => $data['nombre_empresa'],
            'empresa_particular' => $data['empresa_particular']
        ]
    ]);
} else {
    // NO ENCONTRADO: Es particular
    echo json_encode([
        'status' => 'not_found',
        'already_signed_today' => $alreadySigned,
        'type' => 'particular',
        'data' => [
            'first_name' => '', 
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'company_id' => 'particular',
            'company_name' => 'Particular (Independiente)',
            'empresa_particular' => ''
        ]
    ]);
}
$db->close();
?>