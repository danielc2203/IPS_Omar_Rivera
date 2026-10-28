<?php
// public_html/index.php
require_once 'config.php';

// --- OBTENER LISTA DE EMPRESAS (Para JS) ---
$db_init = getDBConnection();
$res_emp = $db_init->query("SELECT id, nombre FROM empresas WHERE id != 'admin-ips' AND estado='activo' ORDER BY nombre ASC");
$listaEmpresas = ($res_emp) ? $res_emp->fetch_all(MYSQLI_ASSOC) : [];

// Configuración de módulos
$enableSurvey = getSetting('enable_kiosk_survey', '1') == '1';
$colClass = $enableSurvey ? 'col-md-3' : 'col-md-4';

$db_init->close();

// --- AJAX: OBTENER TEXTO DE PLANTILLA ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_template') {
    $tplId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $text = getConsentTemplateContent($tplId);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'content' => $text]);
    exit;
}

// --- PROCESAR EL GUARDADO DE LA FIRMA (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_consent') {
    header('Content-Type: application/json');
    
    // Desactivar visualización de errores PHP para no romper el JSON response
    error_reporting(0);
    
    $db = getDBConnection();
    
    try {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Error de seguridad (Token CSRF Inválido). Recargue la página.");
        }

        $docType = sanitizeInput($_POST['document_type']);
        $docNumber = sanitizeInput($_POST['document_number']);
        $firstName = sanitizeInput($_POST['first_name']);
        $lastName = sanitizeInput($_POST['last_name']);
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $phone = sanitizeInput($_POST['phone']);
        $companyId = sanitizeInput($_POST['company_id']); 
        $empresaParticular = sanitizeInput($_POST['empresa_particular'] ?? '');
        $signature = $_POST['signature']; 
        $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
        
        // Datos de Testigo (Opcionales)
        $witnessName = sanitizeInput($_POST['witness_name'] ?? '');
        $witnessDoc = sanitizeInput($_POST['witness_doc_number'] ?? '');
        $witnessSig = $_POST['witness_signature'] ?? '';

        // Validación básica
        if (empty($companyId)) $companyId = 'particular';
        if (empty($signature)) throw new Exception("La firma es obligatoria");

        // Texto del consentimiento (Real desde BD según ID)
        $rawText = getConsentTemplateContent($templateId);
        $declarationTextRaw = getActiveSwornDeclaration();

        // 2. Reemplazar variables (Misma lógica que en JS)
        $fullName = mb_strtoupper("$firstName $lastName");
        
        $consentText = str_replace(
            ['{{FULL_NAME}}', '{{DOCUMENT_TYPE}}', '{{DOCUMENT_NUMBER}}'],
            [$fullName, $docType, $docNumber],
            $rawText
        );

        $declarationText = str_replace(
            ['{{FULL_NAME}}', '{{DOCUMENT_TYPE}}', '{{DOCUMENT_NUMBER}}'],
            [$fullName, $docType, $docNumber],
            $declarationTextRaw
        );

        // Combinar ambos textos para el registro histórico y PDF
        if (!empty($declarationText)) {
            $consentText .= "\n\n--- DECLARACIÓN JURAMENTADA ---\n\n" . $declarationText;
        }

        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];
        
        // --- CALCULAR HASH DE SEGURIDAD (Huella Digital) ---
        // Concatenamos datos críticos + firma + salt secreto + witness
        $dataToHash = $firstName . $lastName . $docNumber . $consentText . $signature . $witnessSig . $ip . SECRET_KEY;
        $securityHash = hash('sha256', $dataToHash);
        
        // Agregamos el hash a la consulta
        $stmt = $db->prepare("
            INSERT INTO consents 
            (first_name, last_name, document_type, document_number, phone, email, company_id, empresa_particular, consent_text_version, signature, ip_address, security_hash, user_agent, witness_name, witness_doc_number, witness_signature) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("ssssssssssssssss", 
            $firstName, $lastName, $docType, $docNumber, $phone, $email, $companyId, $empresaParticular, $consentText, $signature, $ip, $securityHash, $ua, $witnessName, $witnessDoc, $witnessSig
        );
        
        if ($stmt->execute()) {
            $consentId = $stmt->insert_id;
            
            // --- LOGGING ---
            if(function_exists('logActivity')) {
                logActivity('Firma Consentimiento', 'consents', "ID: $consentId - TPL: $templateId - Paciente: $docNumber");
            }

            // --- AUTO-REGISTRO DE PARTICULARES ---
            // Si el paciente no estaba pre-registrado, lo agregamos ahora para que aparezca en el panel Admin
            // Buscamos si ya existe
            $check = $db->prepare("SELECT id FROM pacientes_pre_registrados WHERE numero_documento = ?");
            $check->bind_param("s", $docNumber);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                // No existe, insertar con la empresa seleccionada (o particular por defecto)
                $ins = $db->prepare("INSERT INTO pacientes_pre_registrados (tipo_documento, numero_documento, nombres, apellidos, email, telefono, empresa_id, empresa_particular, estado_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
                $ins->bind_param("ssssssss", $docType, $docNumber, $firstName, $lastName, $email, $phone, $companyId, $empresaParticular);
                $ins->execute();
            }

            // --- ENVÍO DE CORREO (PDF + ENCUESTA) ---
            if (getSetting('enable_patient_emails', '0') == '1') {
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Links
                    $token = hash_hmac('sha256', $consentId, SECRET_KEY);
                    $pdfLink = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/admin/download_pdf.php?id=" . $consentId . "&token=" . $token;
                    $surveyLink = "https://" . $_SERVER['HTTP_HOST'] . "/survey.php?t=" . $securityHash;
                    
                    $senderEmail = getSetting('sender_email', 'info@tecco.com.co');
                    $siteName = getSetting('site_name', 'IPS');
                    
                    $subject = "Copia Consentimiento Informado - $siteName";
                    
                    $message = "
                    <html>
                    <head>             
                      <title>Consentimiento Firmado</title>
                    </head>
                    <body style='font-family: Arial, sans-serif; color: #333;'>
                      <div style='max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px; border-radius: 5px;'>
                        <h2 style='color: #0d6efd;'>Consentimiento Firmado</h2>
                        <p>Hola <strong>$firstName</strong>,</p>
                        <p>Adjunto encontrarás el enlace para descargar tu consentimiento informado firmado correctamente.</p>
                        
                        <div style='text-align: center; margin: 20px 0;'>
                            <a href='$pdfLink' style='background-color: #0d6efd; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Descargar Documento PDF</a>
                        </div>
                        
                        <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                        
                        <p><strong>Nos gustaría conocer tu opinión</strong></p>
                        <p>Por favor, tómese un minuto para responder esta breve encuesta sobre su experiencia:</p>
                        <p><a href='$surveyLink'>Responder Encuesta de Satisfacción</a></p>
                        
                        <hr style='border: none; border-top: 1px solid #eee; margin-top: 30px;'>
                        <p style='font-size: 11px; color: #999;'>$siteName</p>
                      </div>
                    </body>
                    </html>
                    ";
                    
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: $siteName <$senderEmail>" . "\r\n";
                    $headers .= "Reply-To: $senderEmail" . "\r\n";
                    
                    // Enviar sin bloquear el flujo principal si falla
                    @mail($email, $subject, $message, $headers);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Consentimiento guardado correctamente.']);
        } else {
            throw new Exception("Error al guardar: " . $stmt->error);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $db->close();
    exit;
}

// OBTENER TEXTO LEGAL PARA EL FRONTEND (Legacy Default)
$consentTextRaw = getConsentText(); 

// Obtener datos visuales
$logoUrl = getSetting('logo_url', '');
$siteName = getSetting('site_name', 'Sistema de Consentimientos');
$activeTemplates = getAllActiveConsentTemplates();
?>

<!DOCTYPE html>
<html lang="es">
<head>             
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPS | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <?php applyTheme(); ?>

    <style>
        body {
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .portal-card {
            max-width: 900px;
            width: 100%;
            border: none;
            background: transparent;
        }
        .logo-main {
            max-height: 120px;
            margin-bottom: 2rem;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .action-card {
            border: none;
            border-radius: 1.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
            height: 100%;
            overflow: hidden;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }
        .action-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .icon-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 1.5rem;
            transition: all 0.3s;
        }
        .card-kiosk:hover .icon-circle {
            background-color: #0d6efd;
            color: white;
            transform: scale(1.1);
        }
        .card-admin:hover .icon-circle {
            background-color: #212529;
            color: white;
            transform: scale(1.1);
        }
        .card-withdrawal:hover .icon-circle {
            background-color: #dc3545;
            color: white;
            transform: scale(1.1);
        }
        .card-survey:hover .icon-circle {
            background-color: #6f42c1;
            color: white;
            transform: scale(1.1);
        }
        
        /* Specific Colors */
        .card-kiosk .icon-circle { background-color: #e7f1ff; color: #0d6efd; }
        .card-withdrawal .icon-circle { background-color: #fff5f5; color: #dc3545; }
        .card-survey .icon-circle { background-color: #f3e5f5; color: #6f42c1; }
        .card-admin .icon-circle { background-color: #f8f9fa; color: #212529; }
        
        h3 { font-weight: 800; letter-spacing: -0.5px; }
    </style>
</head>
<body>

    <div class="portal-card text-center">
        
        <?php if ($logoUrl && file_exists($logoUrl)): ?>
            <img src="<?php echo $logoUrl . '?v=' . time(); ?>" alt="Logo" class="logo-main">
        <?php else: ?>
            <div class="display-1 text-primary mb-4"><i class="bi bi-hospital-fill"></i></div>
        <?php endif; ?>

        <h1 class="fw-bold mb-4 text-dark"><?php echo $siteName; ?></h1>

        <div class="row g-4 justify-content-center">
            
            <!-- KIOSK BUTTON -->
            <div class="<?php echo $colClass; ?>">
                <a href="kiosk.php" class="text-decoration-none text-dark">
                    <div class="card action-card card-kiosk p-4">
                        <div class="card-body">
                            <div class="icon-circle shadow-sm">
                                <i class="bi bi-tablet"></i>
                            </div>
                            <h3 class="mb-2">Modo Kiosco</h3>
                            <p class="text-muted small">Firmar Consentimientos</p>
                            <span class="btn btn-outline-primary rounded-pill px-4 fw-bold mt-2">Ingresar <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </div>
                </a>
            </div>

            <!-- WITHDRAWAL BUTTON -->
            <div class="<?php echo $colClass; ?>">
                <a href="withdrawal.php" class="text-decoration-none text-dark">
                    <div class="card action-card card-withdrawal p-4">
                        <div class="card-body">
                            <div class="icon-circle shadow-sm">
                                <i class="bi bi-file-earmark-x"></i>
                            </div>
                            <h3 class="mb-2 text-danger">Desistimiento</h3>
                            <p class="text-muted small">Renuncia a Exámenes</p>
                            <span class="btn btn-outline-danger rounded-pill px-4 fw-bold mt-2">Acceder <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </div>
                </a>
            </div>

            <!-- SURVEY BUTTON -->
            <?php if($enableSurvey): ?>
            <div class="<?php echo $colClass; ?>">
                <a href="kiosk_survey.php" class="text-decoration-none text-dark">
                    <div class="card action-card card-survey p-4">
                        <div class="card-body">
                            <div class="icon-circle shadow-sm">
                                <i class="bi bi-star"></i>
                            </div>
                            <h3 class="mb-2 text-purple" style="color: #6f42c1;">Encuesta</h3>
                            <p class="text-muted small">Calificar Atención</p>
                            <span class="btn btn-outline-purple rounded-pill px-4 fw-bold mt-2" style="border-color: #6f42c1; color: #6f42c1;">Calificar <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <!-- ADMIN BUTTON -->
            <div class="<?php echo $colClass; ?>">
                <a href="admin/dashboard.php" class="text-decoration-none text-dark">
                    <div class="card action-card card-admin p-4">
                        <div class="card-body">
                            <div class="icon-circle shadow-sm">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                            <h3 class="mb-2">Admin</h3>
                            <p class="text-muted small">Gestión y Reportes</p>
                            <span class="btn btn-outline-dark rounded-pill px-4 fw-bold mt-2">Acceder <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </div>
                </a>
            </div>

        </div>
        
        <div class="mt-5 text-muted small">
            &copy; <?php echo date('Y'); ?> <?php echo $siteName; ?>. Todos los derechos reservados.
            <br>
            <a href="javascript:void(0)" onclick="showManifesto()" class="text-muted text-decoration-none mt-2 d-inline-block hover-opacity" style="font-size: 0.75rem; opacity: 0.6;">Declaración de Desarrollador</a>
        </div>
    </div>

    <script>
        function showManifesto() {
            Swal.fire({
                title: '<strong>Declaración de Responsabilidad Técnica</strong>',
                icon: 'info',
                html: `
                    <div class="text-start" style="font-size: 0.95rem; line-height: 1.5;">
                        <p>Yo, <strong>Jose Daniel Castro Reyes</strong>, como ingeniero responsable del desarrollo, doy fe de que esta aplicación ha sido diseñada y construida a solicitud de la institución para garantizar la máxima confidencialidad y seguridad en el manejo de la información de los pacientes.</p>
                        <p><strong>Es importante aclarar que:</strong></p>
                        <ul>
                            <li>Este sistema es una herramienta dedicada exclusivamente a la gestión de <strong>consentimientos informados</strong> y <strong>encuestas de satisfacción</strong>.</li>
                            <li><strong>No es una plataforma de salud integral</strong> ni maneja historias clínicas.</li>
                            <li>La información procesada se limita al control administrativo de los documentos mencionados y no interviene en procedimientos médicos de ninguna índole.</li>
                        </ul>
                        <hr>
                        <p class="mb-1 small"><strong>Contacto del Desarrollador:</strong></p>
                        <p class="small mb-0"><i class="bi bi-envelope-at me-2"></i> danielc2203@gmail.com</p>
                        <p class="small"><i class="bi bi-whatsapp me-2"></i> 3016834994</p>
                    </div>
                `,
                confirmButtonText: 'Entendido',
                confirmButtonColor: 'var(--bs-primary)',
                width: '600px'
            });
        }
    </script>

</body>
</html>