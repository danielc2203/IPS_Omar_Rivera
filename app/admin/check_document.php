<?php
require_once '../config.php';

header('Content-Type: application/json');

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

// Check if required parameters are present
if (!isset($_POST['document_type']) || !isset($_POST['document_number'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetros incompletos']);
    exit;
}

$document_type = sanitizeInput($_POST['document_type']);
$document_number = sanitizeInput($_POST['document_number']);
$company_id = $_SESSION['company_id'] ?? 1; // Default to 1 for now

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM patients WHERE document_type = ? AND document_number = ? AND company_id = ?");
$stmt->bind_param("ssi", $document_type, $document_number, $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $patient = $result->fetch_assoc();
    echo json_encode([
        'exists' => true,
        'patient' => [
            'first_name' => $patient['first_name'],
            'last_name' => $patient['last_name'],
            'email' => $patient['email'],
            'phone' => $patient['phone'],
            'document_type' => $patient['document_type'],
            'document_number' => $patient['document_number']
        ]
    ]);
} else {
    echo json_encode(['exists' => false]);
}

$stmt->close();
$db->close();
?>
