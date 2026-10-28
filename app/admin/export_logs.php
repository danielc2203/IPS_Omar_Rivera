<?php
// admin/export_logs.php
require_once '../config.php';

if (!isLoggedIn() || !isAdmin()) { die("Acceso denegado"); }

$db = getDBConnection();

// --- 1. FILTROS ---
$search = $_GET['search'] ?? '';
$user_id = $_GET['user_id'] ?? '';

// --- 2. CONFIGURACIÓN DEL ARCHIVO ---
$filename = "registro_actividades_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel

fputcsv($output, [
    'Fecha', 
    'Usuario', 
    'Acción', 
    'Detalles', 
    'Tabla Afectada', 
    'IP Origen'
], ";");

// --- 3. CONSULTA SQL CON FILTROS ---
$sql = "SELECT r.*, u.username 
        FROM registro_actividades r 
        LEFT JOIN usuarios u ON r.usuario_id = u.id 
        WHERE 1=1 ";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (r.accion LIKE ? OR r.detalles LIKE ? OR r.tabla_afectada LIKE ?) ";
    $term = "%$search%";
    array_push($params, $term, $term, $term);
    $types .= "sss";
}

if ($user_id !== '') {
    if ($user_id === 'system') {
        $sql .= " AND r.usuario_id IS NULL ";
    } else {
        $sql .= " AND r.usuario_id = ? ";
        $params[] = (int)$user_id;
        $types .= "i";
    }
}

$sql .= " ORDER BY r.fecha_registro DESC";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- 4. ESCRIBIR DATOS ---
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['fecha_registro'],
        $row['username'] ?? 'Sistema',
        $row['accion'],
        $row['detalles'],
        $row['tabla_afectada'],
        $row['ip_origen']
    ], ";");
}

fclose($output);
$stmt->close();
$db->close();
exit;
?>
