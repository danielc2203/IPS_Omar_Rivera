<?php
// public_html/admin/send_email.php
require_once '../config.php';

// 1. SEGURIDAD
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Método no permitido');
}

ob_clean(); // Limpiar buffer
header('Content-Type: application/json');

try {
    $db = getDBConnection();
    $id = sanitizeInput($_POST['id']);
    
    // Si envían un email destino específico, usarlo. Si no, buscar el del paciente.
    $targetEmail = sanitizeInput($_POST['email'] ?? '');

    // Verificar existencia y obtener datos
    $sql = "SELECT c.*, e.nombre as nombre_empresa 
            FROM consents c 
            LEFT JOIN empresas e ON c.company_id = e.id 
            WHERE c.id = ?";
    
    // Si es empresa, validar propiedad
    if (isEmpresa()) {
        $sql .= " AND c.company_id = ?";
    }

    $stmt = $db->prepare($sql);
    
    if (isEmpresa()) {
        $myEmpId = getMyCompanyId();
        $stmt->bind_param("is", $id, $myEmpId);
    } else {
        $stmt->bind_param("i", $id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Documento no encontrado o sin permisos.");
    }
    
    $data = $result->fetch_assoc();
    
    // Definir email de destino
    if (empty($targetEmail)) {
        $targetEmail = $data['email'];
    }
    
    if (empty($targetEmail)) {
        throw new Exception("No hay un correo electrónico asociado para enviar.");
    }

    // 2. GENERAR LINK SEGURO
    $token = hash_hmac('sha256', $id, SECRET_KEY);
    
    // Detectar protocolo y host
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF']); // /admin
    
    $link = $protocol . $host . $path . "/download_pdf.php?id=" . $id . "&token=" . $token;

    // 3. ENVIAR CORREO
    $subject = "Copia Consentimiento Informado - " . $data['document_number'];
    
    $message = "
    <html>
    <head>
      <title>Consentimiento Informado</title>
      <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .btn { display: inline-block; padding: 10px 20px; color: #fff; background-color: #0d6efd; text-decoration: none; border-radius: 5px; }
        .footer { font-size: 12px; color: #777; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px; }
      </style>
    </head>
    <body>
      <div class='container'>
        <h2>Hola, {$data['first_name']}</h2>
        <p>Adjunto encontrarás el enlace para descargar tu consentimiento informado firmado correctamente.</p>
        <p><strong>Documento:</strong> {$data['document_type']} {$data['document_number']}</p>
        <p><strong>Fecha:</strong> " . date('d/m/Y', strtotime($data['date_signed'])) . "</p>
        <br>
        <p style='text-align: center;'>
            <a href='$link' class='btn'>Descargar Documento PDF</a>
        </p>
        <br>
        <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
        <p><small>$link</small></p>
        
        <div class='footer'>
            Este es un mensaje automático. Por favor no responder.<br>
            Tecco Salud
        </div>
      </div>
    </body>
    </html>
    ";

    // Headers para HTML
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: Notificaciones <no-reply@$host>" . "\r\n";

    if (mail($targetEmail, $subject, $message, $headers)) {
        echo json_encode(['status' => 'success', 'message' => 'Correo enviado a ' . $targetEmail]);
    } else {
        throw new Exception("Fallo al enviar el correo (función mail() retornó false).");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
