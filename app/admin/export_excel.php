<?php
// admin/export_excel.php
require_once '../config.php';

if (!isLoggedIn()) { die("Acceso denegado"); }

$db = getDBConnection();

// --- 1. FILTROS ---
$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate = $_GET['end'] ?? date('Y-m-t');
// Asegurar que el final del rango incluya todo el día
$endDateTime = $endDate . ' 23:59:59';

// Forzar filtro de empresa si no es admin
$companyId = 'all';
if (isEmpresa()) {
    $companyId = getMyCompanyId();
} elseif (isAdmin() && isset($_GET['company']) && $_GET['company'] !== 'all') {
    $companyId = $_GET['company'];
}

// --- 2. CONFIGURACIÓN DEL ARCHIVO ---
$filename = "reporte_pacientes_" . date('Ymd_His') . ".csv";

// Headers para forzar descarga
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Abrir salida
$output = fopen('php://output', 'w');

// BOM para Excel (UTF-8)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados de Columnas
fputcsv($output, [
    'Fecha Firma', 
    'Tipo Doc', 
    'Numero Documento', 
    'Nombres', 
    'Apellidos', 
    'Email', 
    'Telefono', 
    'Empresa',
    'Con Firma', 
    'Testigo'
], ";"); // Usamos punto y coma para Excel en español

// --- 3. CONSULTA SQL ROBUSTA ---
// Unimos consents con pacientes para tener datos actualizados
$sql = "SELECT c.date_signed, c.document_type, c.document_number, 
               COALESCE(p.nombres, c.first_name) as first_name, 
               COALESCE(p.apellidos, c.last_name) as last_name, 
               COALESCE(p.email, c.email) as email, 
               COALESCE(p.telefono, c.phone) as phone,
               e.nombre as company_name,
               c.signature,
               c.witness_name
        FROM consents c 
        LEFT JOIN pacientes_pre_registrados p ON c.document_number = p.numero_documento AND c.document_type = p.tipo_documento
        LEFT JOIN empresas e ON c.company_id = e.id
        WHERE c.date_signed BETWEEN ? AND ? ";

$params = [$startDate, $endDateTime];
$types = "ss";

if ($companyId !== 'all') {
    $sql .= " AND c.company_id = ? ";
    $params[] = $companyId;
    $types .= "s";
}

$sql .= " ORDER BY c.date_signed DESC";

$stmt = $db->prepare($sql);
if(!$stmt) {
    // Si falla la preparación, enviar error al CSV
    fputcsv($output, ['Error en consulta SQL']);
    fclose($output);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// --- 4. ESCRIBIR DATOS ---
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        date('d/m/Y H:i', strtotime($row['date_signed'])),
        $row['document_type'],
        $row['document_number'],
        $row['first_name'],
        $row['last_name'],
        $row['email'],
        $row['phone'],
        $row['company_name'] ?? 'Particular',
        !empty($row['signature']) ? 'SI' : 'NO',
        !empty($row['witness_name']) ? $row['witness_name'] : 'NO'
    ], ";");
}

fclose($output);
$stmt->close();
$db->close();
exit;
?>
