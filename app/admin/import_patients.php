<?php
// public_html/admin/import_patients.php
require_once '../config.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Método no permitido');
}

ob_clean();
header('Content-Type: application/json');

// Validar Login
if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

try {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al subir el archivo.");
    }

    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
    $fileType = $_FILES['csv_file']['type'];
    
    // Validar extensión simple
    $fileName = $_FILES['csv_file']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileExtension !== 'csv') {
        throw new Exception("Solo se permiten archivos CSV.");
    }

    $db = getDBConnection();
    
    // Determinar Empresa
    $empresaId = getMyCompanyId();
    if (isAdmin()) {
        if (empty($_POST['empresa_id_import'])) {
            throw new Exception("Debe seleccionar una empresa destino.");
        }
        $empresaId = $_POST['empresa_id_import'];
    }

    // Procesar CSV
    $handle = fopen($fileTmpPath, "r");
    if ($handle === false) throw new Exception("No se puede leer el archivo.");

    $header = fgetcsv($handle, 1000, ";"); // Intentar con punto y coma
    if (!$header || count($header) < 2) {
        // Reintentar con coma si falló
        rewind($handle);
        $header = fgetcsv($handle, 1000, ",");
    }

    // Validar cabeceras mínimas (flexible)
    // Esperamos: tipo_documento, numero_documento, nombres, apellidos, (opcional: email, telefono)
    
    $imported = 0;
    $errors = 0;
    $rowNum = 1;

    $stmt = $db->prepare("
        INSERT INTO pacientes_pre_registrados 
        (tipo_documento, numero_documento, nombres, apellidos, email, telefono, empresa_id, estado_registro) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'activo')
        ON DUPLICATE KEY UPDATE 
        nombres = VALUES(nombres), apellidos = VALUES(apellidos), email = VALUES(email), telefono = VALUES(telefono), empresa_id = VALUES(empresa_id)
    ");

    while (($row = fgetcsv($handle, 1000, ";")) !== FALSE) {
        // Fallback a coma si no parseó bien columnas
        if(count($row) === 1) $row = str_getcsv($row[0], ",");
        
        $rowNum++;
        
        // Mapeo básico por índice (Asumimos orden plantilla: Tipo, Doc, Nom, Ape, Email, Tel)
        // Ajustar esto según necesidad o hacerlo dinámico
        $tipo = sanitizeInput($row[0] ?? '');
        $doc = sanitizeInput($row[1] ?? '');
        $nom = sanitizeInput($row[2] ?? '');
        $ape = sanitizeInput($row[3] ?? '');
        $email = sanitizeInput($row[4] ?? '');
        $tel = sanitizeInput($row[5] ?? '');

        if (empty($doc) || empty($nom)) {
            $errors++;
            continue; 
        }

        // Normalizar Tipo Doc a mayúsculas
        $tipo = strtoupper($tipo);
        if(!in_array($tipo, ['CC','TI','CE','PAS'])) $tipo = 'CC';

        $stmt->bind_param("sssssss", $tipo, $doc, $nom, $ape, $email, $tel, $empresaId);
        
        if ($stmt->execute()) {
            $imported++;
        } else {
            $errors++;
        }
    }
    
    fclose($handle);
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Proceso finalizado. Importados: $imported. Errores/Omitidos: $errors."
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
