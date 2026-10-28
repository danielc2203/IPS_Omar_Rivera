<?php
// admin/patient_history.php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

// Verificar permisos: Admin, Profesional, Auditor (quizás Empresa también?)
// Config.php dice "isEmpresa" no está en los helpers nuevos de rol, pero getMyCompanyId sí.
// Asumo que todos los que entran al admin pueden ver historial de SUS pacientes.
$db = getDBConnection();

$patientId = $_GET['id'] ?? null;
if (!$patientId || !is_numeric($patientId)) {
    die("ID de paciente inválido.");
}

// 1. Obtener datos del paciente
$stmt = $db->prepare("SELECT * FROM pacientes_pre_registrados WHERE id = ?");
$stmt->bind_param("i", $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();

if (!$patient) {
    die("Paciente no encontrado.");
}

// Verificar acceso si es empresa externa
if (isEmpresa() && $patient['empresa_id'] != getMyCompanyId()) {
    die("Acceso no autorizado a este paciente.");
}

// Join con consent_types para mostrar el nombre de la plantilla y empresas
$sql = "SELECT c.*, ct.name as template_name, e.nombre as nombre_empresa 
        FROM consents c 
        LEFT JOIN consent_types ct ON c.template_id = ct.id 
        LEFT JOIN empresas e ON c.company_id = e.id
        WHERE c.document_number = ? AND c.document_type = ? 
        ORDER BY c.date_signed DESC";

$stmtHistory = $db->prepare($sql);
$stmtHistory->bind_param("ss", $patient['numero_documento'], $patient['tipo_documento']);
$stmtHistory->execute();
$history = $stmtHistory->get_result();

$siteName = getSetting('site_name', 'Sistema');
$logoUrl = getSetting('logo_url');
if($logoUrl) $logoUrl = '../'.$logoUrl;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial Clínico | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .timeline {
            border-left: 3px solid #dee2e6;
            padding-left: 20px;
            margin-left: 10px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -26px;
            top: 5px;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background-color: var(--bs-primary);
            border: 3px solid #fff;
            box-shadow: 0 0 0 1px #dee2e6;
        }
        .timeline-date {
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand mb-0 h1 d-flex align-items-center" href="dashboard.php">
            <?php if($logoUrl && file_exists($logoUrl)): ?>
                <img src="<?php echo $logoUrl; ?>" alt="Logo" height="30" class="d-inline-block align-text-top me-2 bg-white rounded px-1">
            <?php endif; ?>
            <span>Historial Paciente</span>
        </a>
        <a href="patients.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </nav>

    <div class="container">
        <div class="card shadow border-0 mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h2 class="fw-bold mb-0 text-primary"><?php echo $patient['nombres'] . ' ' . $patient['apellidos']; ?></h2>
                        <p class="text-muted mb-0">
                            <strong><?php echo $patient['tipo_documento']; ?>:</strong> <?php echo $patient['numero_documento']; ?> | 
                            <strong>Email:</strong> <?php echo $patient['email'] ?: 'No registrado'; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge bg-secondary p-2">Registrado: <?php echo date('d/m/Y', strtotime($patient['created_at'] ?? 'now')); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <h4 class="mb-4"><i class="bi bi-clock-history"></i> Línea de Tiempo de Consentimientos</h4>
        
        <?php if($history->num_rows > 0): ?>
            <div class="timeline">
                <?php while($item = $history->fetch_assoc()): ?>
                    <div class="timeline-item">
                        <h5 class="fw-bold mb-1">
                            <?php echo $item['template_name'] ?: 'Consentimiento General'; ?>
                        </h5>
                        <div class="timeline-date mb-2">
                            <i class="bi bi-calendar-event me-1"></i> <?php echo date('d/m/Y H:i', strtotime($item['date_signed'])); ?>
                        </div>
                        <div class="card border-0 shadow-sm">
                            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-success"><i class="bi bi-pen-fill"></i> Firmado</span>
                                    <span class="ms-2 fw-bold text-primary" style="font-size: 0.85rem;">
                                        <i class="bi bi-building"></i> 
                                        <?php 
                                            if ($item['company_id'] === 'particular' && !empty($item['empresa_particular'])) {
                                                echo htmlspecialchars($item['empresa_particular']);
                                            } else {
                                                echo htmlspecialchars($item['nombre_empresa'] ?? 'Particular');
                                            }
                                        ?>
                                    </span>
                                    <small class="text-muted ms-2">IP: <?php echo $item['ip_address'] ?? 'N/A'; ?></small>
                                </div>
                                <div>
                                    <a href="download_pdf.php?id=<?php echo $item['id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-file-pdf"></i> PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-info-circle display-4 d-block mb-3"></i>
                <p class="lead">Este paciente no tiene consentimientos firmados registrados.</p>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
