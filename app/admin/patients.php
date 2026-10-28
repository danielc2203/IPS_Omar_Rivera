<?php
// public_html/admin/patients.php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

// Restrict Specialists
if (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'profesional') {
    header('Location: specialist_dashboard.php');
    exit();
}

$db = getDBConnection();
$userId = getUserId(); // ID de la empresa logueada

// --- 1. PROCESAMIENTO AJAX (JSON) ---
// Este bloque captura el envío del formulario del modal
// Este bloque captura el envío del formulario del modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Limpiamos el buffer de salida para asegurar que solo salga JSON
    ob_clean();
    header('Content-Type: application/json');

    try {
        $empresaId = getMyCompanyId(); // Por defecto, la empresa logueada
        
        // 1. REGISTRAR NUEVO (o Insert + Update)
        if ($_POST['action'] === 'register') {
            if (!canCreatePatients()) throw new Exception("No tiene permisos para crear pacientes.");
            
            // Lógica para ADMINISTRADOR
            if (isAdmin()) {
                if (empty($_POST['empresa_id'])) {
                    throw new Exception("Como administrador, debe seleccionar una empresa.");
                }
                $empresaId = $_POST['empresa_id'];
            }
            if (!$empresaId) throw new Exception("Error de sesión: No se identificó la empresa.");

            $docType = sanitizeInput($_POST['tipo_documento']);
            $docNum = sanitizeInput($_POST['numero_documento']);
            $names = sanitizeInput($_POST['nombres']);
            $lastnames = sanitizeInput($_POST['apellidos']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['telefono']);
            $empParticular = sanitizeInput($_POST['empresa_particular'] ?? '');

            $sql = "INSERT INTO pacientes_pre_registrados 
                    (numero_documento, tipo_documento, nombres, apellidos, empresa_id, empresa_particular, email, telefono, estado_registro)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')
                    ON DUPLICATE KEY UPDATE 
                    nombres = VALUES(nombres), apellidos = VALUES(apellidos), email = VALUES(email), telefono = VALUES(telefono), empresa_id = VALUES(empresa_id), empresa_particular = VALUES(empresa_particular)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssssssss", $docNum, $docType, $names, $lastnames, $empresaId, $empParticular, $email, $phone);
            
            if ($stmt->execute()) {
                $patientId = $stmt->insert_id;
                // If 0, it was an update, so fetch ID by document
                if ($patientId == 0) {
                    $pidRes = $db->query("SELECT id FROM pacientes_pre_registrados WHERE numero_documento='$docNum' LIMIT 1");
                    if ($row = $pidRes->fetch_assoc()) $patientId = $row['id'];
                }

                // Handle Protocol Auto-Assignment
                $protocolId = (int)($_POST['protocol_id'] ?? 0);
                if ($patientId > 0 && $protocolId > 0) {
                    // Fetch items (Corrected: table does not have price_override)
                    $itemsRes = $db->query("SELECT exam_id FROM protocol_items WHERE protocol_id = $protocolId");
                    if ($itemsRes) {
                        $items = $itemsRes->fetch_all(MYSQLI_ASSOC);
                        // Get Default Prices
                        $allExamIds = array_column($items, 'exam_id');
                        if (!empty($allExamIds)) {
                            $idsStr = implode(',', array_map('intval', $allExamIds));
                            // Fix: Column is 'cost', not 'price'
                            $defPricesRes = $db->query("SELECT id, cost FROM exams WHERE id IN ($idsStr)");
                            $defPrices = [];
                            while($dp = $defPricesRes->fetch_assoc()) $defPrices[$dp['id']] = $dp['cost'];
                            
                            $serviceType = 'Pre-ocupacional'; // Default for new entry
                            $stmtIns = $db->prepare("INSERT INTO patient_exams (patient_id, exam_id, price_snapshot, service_type, status) VALUES (?, ?, ?, ?, 'pending')");
                            
                            foreach($items as $it) {
                                $eid = $it['exam_id'];
                                // No override available in protocol_items, use default
                                $finalPrice = $defPrices[$eid] ?? 0;
                                
                                // Fix bind types: iids (int, int, double, string)
                                $stmtIns->bind_param("iids", $patientId, $eid, $finalPrice, $serviceType);
                                $stmtIns->execute();
                            }
                        }
                    }
                }

                if(function_exists('logActivity')) logActivity('Registro/Actualización Paciente', 'pacientes_pre_registrados', "Doc: $docType $docNum - Nombre: $names $lastnames - Empresa: $empresaId");
                echo json_encode(['status' => 'success', 'message' => 'Paciente guardado correctamente.']);
            } else {
                throw new Exception("Error BD: " . $stmt->error);
            }
        
        // 2. EDITAR EXISTENTE
        } elseif ($_POST['action'] === 'edit_patient') {
            // ... (validaciones previas)
            
            // Validar que el ID sea numérico (seguridad básica)
            $id = (int)$_POST['id'];
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['telefono']);
            
            // Si es empresa, asegurar que el paciente le pertenezca
            $checkSql = "SELECT id FROM pacientes_pre_registrados WHERE id = ?";
            if (isEmpresa()) $checkSql .= " AND empresa_id = '$empresaId'";
            
            $stmtCheck = $db->prepare($checkSql);
            $stmtCheck->bind_param("i", $id);
            $stmtCheck->execute();
            if ($stmtCheck->get_result()->num_rows === 0) {
                 throw new Exception("Paciente no encontrado o no autorizado.");
            }

            $nombres = sanitizeInput($_POST['nombres']);
            $apellidos = sanitizeInput($_POST['apellidos']);
            $empParticular = sanitizeInput($_POST['empresa_particular'] ?? '');

            $stmt = $db->prepare("UPDATE pacientes_pre_registrados SET email = ?, telefono = ?, nombres = ?, apellidos = ?, empresa_particular = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $email, $phone, $nombres, $apellidos, $empParticular, $id);
            
            if ($stmt->execute()) {
                if(function_exists('logActivity')) logActivity('Edición Contacto Paciente', 'pacientes_pre_registrados', "Editado ID: $id - Nombre: $nombres $apellidos - Email: $email - Tel: $phone; Empresa Particular: $empParticular");
                echo json_encode(['status' => 'success', 'message' => 'Datos actualizados.']);
            } else {
                 throw new Exception("Error al actualizar.");
            }
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    
    exit(); // DETENEMOS la ejecución aquí
}

// --- 2. PREPARACIÓN DE DATOS PARA LA VISTA ---

// Si es admin, necesitamos la lista de empresas para el Select del formulario JS
$listaEmpresas = [];
if (isAdmin()) {
    // Traemos todas menos el propio admin-ips
    $res = $db->query("SELECT id, nombre FROM empresas WHERE id != 'admin-ips' AND estado='activo' ORDER BY nombre ASC");
    $listaEmpresas = $res->fetch_all(MYSQLI_ASSOC);
}

// Fetch Protocols (Cargos)
$resProtocols = $db->query("SELECT id, name FROM protocols ORDER BY name ASC");
$listaProtocolos = $resProtocols->fetch_all(MYSQLI_ASSOC);

// --- CONSULTA DE PACIENTES CON FILTROS ---
$whereClause = "";
$params = [];
$types = "";

if (isEmpresa()) {
    // La empresa solo ve SUS pacientes
    $whereClause = "WHERE p.empresa_id = ?";
    $params[] = getMyCompanyId();
    $types .= "s";
} else {
    // Admin ve todo, PERO verificamos si hay filtro en la URL
    if (isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa'])) {
        $whereClause = "WHERE p.empresa_id = ?";
        $params[] = $_GET['filtro_empresa'];
        $types .= "s";
    }
}

// --- FILTRO DE ESTADO (FIRMADO / PENDIENTE) ---
if (isset($_GET['filtro_estado']) && !empty($_GET['filtro_estado'])) {
    $estado = $_GET['filtro_estado'];
    
    // Si ya existe WHERE, usamos AND, si no WHERE
    $prefix = empty($whereClause) ? "WHERE" : "AND";
    
    if ($estado === 'firmado') {
        $whereClause .= " $prefix EXISTS (SELECT 1 FROM consents c WHERE c.document_number = p.numero_documento AND c.document_type = p.tipo_documento)";
    } elseif ($estado === 'pendiente') {
        $whereClause .= " $prefix NOT EXISTS (SELECT 1 FROM consents c WHERE c.document_number = p.numero_documento AND c.document_type = p.tipo_documento)";
    }
}

$query = "
    SELECT p.*, e.nombre as nombre_empresa,
           (SELECT date_signed FROM consents c 
            WHERE c.document_number = p.numero_documento 
            AND c.document_type = p.tipo_documento 
            ORDER BY c.date_signed DESC LIMIT 1) as fecha_firma,
           (SELECT id FROM consents c 
            WHERE c.document_number = p.numero_documento 
            AND c.document_type = p.tipo_documento 
            ORDER BY c.date_signed DESC LIMIT 1) as consentimiento_id,
           (SELECT COUNT(*) FROM patient_exams WHERE patient_id = p.id) as total_exams,
           (SELECT COUNT(*) FROM patient_exams WHERE patient_id = p.id AND status='completed') as completed_exams,
           (SELECT COUNT(*) FROM patient_exams WHERE patient_id = p.id AND status='cancelled') as cancelled_exams
    FROM pacientes_pre_registrados p
    JOIN empresas e ON p.empresa_id = e.id
    $whereClause
    ORDER BY p.fecha_creacion DESC
";

$stmt = $db->prepare($query);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pacientes | Panel Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <?php 
            $logoUrl = getSetting('logo_url');
            if($logoUrl) $logoUrl = '../'.$logoUrl;
        ?>
        <a class="navbar-brand mb-0 h1 d-flex align-items-center" href="dashboard.php">
            <?php if($logoUrl && file_exists($logoUrl)): ?>
                <img src="<?php echo $logoUrl . '?v=' . time(); ?>" alt="Logo" height="30" class="d-inline-block align-text-top me-2 bg-white rounded px-1">
            <?php endif; ?>
            <span>Panel <?php echo isAdmin() ? 'Administrador' : 'Empresa'; ?></span>
        </a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
            <a href="withdrawals.php" class="btn btn-outline-light btn-sm me-2">Desistimientos</a>
            <?php if(isAdmin()): ?>
                <a href="surveys.php" class="btn btn-outline-light btn-sm me-2">Encuestas</a>
                <a href="configuracion.php" class="btn btn-outline-light btn-sm me-2">Configuración</a>
                <a href="logs.php" class="btn btn-outline-light btn-sm me-2">Auditoría</a>
            <?php endif; ?>
            <a href="manual.php" class="btn btn-outline-info btn-sm me-2 fw-bold"><i class="bi bi-book"></i> Manual</a>
            <a href="change_password.php" class="btn btn-outline-light btn-sm me-2">Contraseña</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="fw-bold text-dark">Pacientes Registrados</h2>
            <?php if(isAdmin()): ?>
            <button class="btn btn-success shadow-sm me-2" onclick="abrirModalZip()">
                <i class="bi bi-file-earmark-zip"></i> Descarga Masiva (ZIP)
            </button>
            <?php endif; ?>
            <?php if(canCreatePatients()): ?>
            <button class="btn btn-primary shadow-sm" onclick="abrirModalRegistro()">
                <i class="bi bi-person-plus-fill"></i> Nuevo Paciente
            </button>
            <?php endif; ?>
            <!-- Botón Exportar Universal -->
            <?php if(isEmpresa() || isAdmin()): ?>
            <button class="btn btn-success shadow-sm ms-2" onclick="abrirModalExportar('excel')">
                <i class="bi bi-file-earmark-excel"></i> Reporte Excel
            </button>
            <?php endif; ?>
        </div>

    <div class="card shadow-sm border-0">
        <?php if(isAdmin()): ?>
            <div class="card-header bg-white py-3">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-auto">
                        <label class="fw-bold">Filtrar por:</label>
                    </div>
                    <div class="col-auto">
                        <select name="filtro_empresa" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Todas las Empresas</option>
                            <option value="particular" <?php echo (isset($_GET['filtro_empresa']) && $_GET['filtro_empresa'] === 'particular') ? 'selected' : ''; ?>>Pacientes Particulares</option>
                            <?php foreach($listaEmpresas as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo (isset($_GET['filtro_empresa']) && $_GET['filtro_empresa'] === $emp['id']) ? 'selected' : ''; ?>>
                                    <?php echo $emp['nombre']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-auto">
                        <select name="filtro_estado" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Todos los Estados</option>
                            <option value="firmado" <?php echo (isset($_GET['filtro_estado']) && $_GET['filtro_estado'] === 'firmado') ? 'selected' : ''; ?>>Firmado</option>
                            <option value="pendiente" <?php echo (isset($_GET['filtro_estado']) && $_GET['filtro_estado'] === 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                        </select>
                    </div>

                    <?php if((isset($_GET['filtro_empresa']) && $_GET['filtro_empresa'] != '') || (isset($_GET['filtro_estado']) && $_GET['filtro_estado'] != '')): ?>
                        <div class="col-auto">
                            <a href="patients.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i> Limpiar</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaPacientes" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Documento</th>
                            <th>Nombre Completo</th>
                            <?php if(isAdmin()): ?><th>Empresa</th><?php endif; ?>
                            <th>Contacto</th>
                            <th class="text-center">Progreso</th> 
                            <th>Registro</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary"><?php echo $row['tipo_documento']; ?></span>
                                <span class="fw-bold"><?php echo $row['numero_documento']; ?></span>
                            </td>
                            <td><?php echo $row['nombres'] . ' ' . $row['apellidos']; ?></td>
                            
                            <?php if(isAdmin()): ?>
                                <td>
                                    <small class="text-primary fw-bold">
                                        <?php 
                                            if ($row['empresa_id'] === 'particular' && !empty($row['empresa_particular'])) {
                                                echo $row['empresa_particular'] . ' (P)';
                                            } else {
                                                echo $row['nombre_empresa'] ?? 'Particular';
                                            }
                                        ?>
                                    </small>
                                </td>
                            <?php endif; ?>
                            
                            <td>
                                <div class="small text-muted">
                                    <?php if($row['email']): ?><div><i class="bi bi-envelope"></i> <?php echo $row['email']; ?></div><?php endif; ?>
                                    <?php if($row['telefono']): ?><div><i class="bi bi-phone"></i> <?php echo $row['telefono']; ?></div><?php endif; ?>
                                </div>
                                <button class="btn btn-link btn-sm p-0 text-decoration-none" onclick="editarPaciente(<?php echo $row['id']; ?>, '<?php echo $row['email']; ?>', '<?php echo $row['telefono']; ?>', '<?php echo $row['nombres']; ?>', '<?php echo $row['apellidos']; ?>', '<?php echo $row['empresa_id']; ?>', '<?php echo addslashes($row['empresa_particular']); ?>')">
                                    <small>Editar</small>
                                </button>
                                | 
                                <a href="patient_history.php?id=<?php echo $row['id']; ?>" class="small text-decoration-none text-secondary">
                                    Historial
                                </a>
                            </td>
                            
                            <td class="text-center" style="width: 140px;">
                                <?php
                                    $totalEx = (int)($row['total_exams'] ?? 0);
                                    $doneEx = (int)($row['completed_exams'] ?? 0);
                                    $cancEx = (int)($row['cancelled_exams'] ?? 0);
                                    $processed = $doneEx + $cancEx;
                                    
                                    if ($totalEx > 0) {
                                        $pct = round(($processed / $totalEx) * 100);
                                        $color = ($pct == 100) ? 'bg-success' : 'bg-warning';
                                        
                                        // Label logic
                                        if ($pct == 100) {
                                            $label = '<i class="bi bi-check-all"></i> Listo';
                                            if ($cancEx > 0) $label .= " <small class='text-danger ms-1'>($cancEx Canc)</small>";
                                        } else {
                                            $label = "$processed / $totalEx";
                                        }
                                        
                                        echo "<div class='progress' style='height: 20px; position:relative;'>
                                                <div class='progress-bar $color' role='progressbar' style='width: {$pct}%'></div>
                                                <span class='position-absolute w-100 text-center text-dark fw-bold' style='font-size:0.75rem; line-height:20px; text-shadow: 0px 0px 2px white;'>$label</span>
                                              </div>";
                                    } else {
                                        echo '<span class="badge bg-light text-muted border">Sin Ruta</span>';
                                    }
                                ?>
                            </td>
                            
                            <td data-order="<?php echo $row['fecha_creacion']; ?>">
                                <div class="small fw-bold"><?php echo date('d/m/Y', strtotime($row['fecha_creacion'])); ?></div>
                                <div class="text-muted smaller" style="font-size: 0.7rem;"><?php echo date('H:i', strtotime($row['fecha_creacion'])); ?></div>
                            </td>
                            
                            <td>
                                <?php if($row['fecha_firma']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Firmado</span>
                                    <div class="small text-muted mt-1"><?php echo date('d/m/Y', strtotime($row['fecha_firma'])); ?></div>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <?php if($row['consentimiento_id']): ?>
                                    <a href="download_pdf.php?id=<?php echo $row['consentimiento_id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Ver PDF">
                                        <i class="bi bi-file-pdf"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-info" title="Enviar por Correo" onclick="enviarCorreo(<?php echo $row['consentimiento_id']; ?>, '<?php echo $row['email']; ?>')">
                                        <i class="bi bi-envelope"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-dark" title="Ver QR de Acceso" 
                                        onclick="verQR('<?php echo $row['tipo_documento']; ?>', '<?php echo $row['numero_documento']; ?>', '<?php echo $row['nombres']; ?>')">
                                        <i class="bi bi-qr-code"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if(isDesarrollador()): ?>
                                    <button class="btn btn-sm btn-outline-warning mt-1" title="Gestionar Exámenes"
                                        onclick="abrirModalExamenes(<?php echo $row['id']; ?>, '<?php echo $row['nombres'].' '.$row['apellidos']; ?>')">
                                        <i class="bi bi-activity"></i> Coins
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Scripts Exportación -->
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="../assets/js/ui-manager.js"></script>

    <script>

        const IS_ADMIN = <?php echo isAdmin() ? 'true' : 'false'; ?>;
        const LISTA_EMPRESAS = <?php echo json_encode($listaEmpresas); ?>;
        const LISTA_PROTOCOLOS = <?php echo json_encode($listaProtocolos); ?>;
        const DOC_TYPES = <?php echo json_encode(getDocumentTypes()); ?>;

        // Inicializar DataTable con Botones
        $(document).ready(function() {
            $('#tablaPacientes').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json" },
                order: [[<?php echo isAdmin() ? 5 : 4; ?>, 'desc']],
                dom: 'Bfrtip',
                buttons: [
                    { 
                        text: '<i class="bi bi-upload"></i> Importar CSV', 
                        className: 'btn btn-primary btn-sm me-2',
                        action: function ( e, dt, node, config ) {
                            abrirModalImportar();
                        },
                        init: function(api, node, config) {
                           if (!<?php echo canCreatePatients() ? 'true' : 'false'; ?>) $(node).hide();
                        }
                    }
                    <?php if(canExport()): ?>
                    ,{ extend: 'excel', text: '<i class="bi bi-file-earmark-excel"></i> Excel', className: 'btn btn-success btn-sm' },
                    { extend: 'pdf', text: '<i class="bi bi-file-earmark-pdf"></i> PDF', className: 'btn btn-danger btn-sm' },
                    { extend: 'print', text: '<i class="bi bi-printer"></i> Imprimir', className: 'btn btn-secondary btn-sm' }
                    <?php endif; ?>
                ]
            });
        });
        
        // --- FUNCIONES NUEVAS ---
        
        // 1. IMPORTAR CSV
        function abrirModalImportar() {
            let selectEmpresaHTML = '';
            if (IS_ADMIN) {
                let options = '<option value="">Seleccione empresa...</option>';
                LISTA_EMPRESAS.forEach(emp => {
                    options += `<option value="${emp.id}">${emp.nombre}</option>`;
                });
                selectEmpresaHTML = `<div class="mb-3"><label class="fw-bold">Destino Importación</label><select id="imp_empresa" class="form-select">${options}</select></div>`;
            }

            const html = `
                <div class="text-start">
                    <p class="small text-muted">Suba un archivo CSV separado por punto y coma (;) con las columnas:<br><strong>TipoDoc, Numero, Nombres, Apellidos, Email, Telefono</strong></p>
                    ${selectEmpresaHTML}
                    <div class="mb-3">
                        <label class="form-label fw-bold">Archivo CSV</label>
                        <input class="form-control" type="file" id="csvFile" accept=".csv">
                    </div>
                </div>
            `;
            
            UI.modalForm({
                title: 'Carga Masiva de Pacientes',
                html: html,
                confirmText: 'Subir y Procesar',
                preConfirmFn: () => {
                    const fileInput = document.getElementById('csvFile');
                    if(fileInput.files.length === 0) {
                        Swal.showValidationMessage('Seleccione un archivo');
                        return false;
                    }
                    
                    const formData = new FormData();
                    formData.append('csv_file', fileInput.files[0]);
                    
                    if (IS_ADMIN) {
                         const emp = document.getElementById('imp_empresa').value;
                         if(!emp) {Swal.showValidationMessage('Seleccione empresa'); return false;}
                         formData.append('empresa_id_import', emp);
                    }
                    
                    return fetch('import_patients.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(res => {
                            if(res.status === 'error') throw new Error(res.message);
                            return res;
                        })
                        .catch(err => { Swal.showValidationMessage(err); });
                },
                callback: (res) => {
                    UI.success('Proceso Completado', res.message, () => location.reload());
                }
            });
        }

        // 2. VER QR
        function verQR(tipo, num, nombre) {
            // URL Base para el QR
            const baseUrl = window.location.origin + window.location.pathname.replace('admin/patients.php', '') + 'kiosk.php';
            const fullUrl = `${baseUrl}?document_type=${tipo}&document_number=${num}&auto=true`;
            
            Swal.fire({
                title: 'Acceso Rápido',
                html: `
                    <div class="d-flex justify-content-center my-3">
                        <div id="qrcode"></div>
                    </div>
                    <p class="small text-muted mb-0">${nombre}</p>
                    <p class="small text-primary">${tipo} ${num}</p>
                    <div class="mt-3">
                        <a href="${fullUrl}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-link"></i> Abrir Enlace Directo</a>
                    </div>
                `,
                didOpen: () => {
                    new QRCode(document.getElementById("qrcode"), {
                        text: fullUrl,
                        width: 180,
                        height: 180
                    });
                }
            });
        }
        
        // Función Enviar Correo
        function enviarCorreo(id, emailDefault) {
            Swal.fire({
                title: 'Enviar Consentimiento',
                input: 'email',
                inputLabel: 'Correo electrónico de destino',
                inputValue: emailDefault,
                showCancelButton: true,
                confirmButtonText: 'Enviar',
                cancelButtonText: 'Cancelar',
                showLoaderOnConfirm: true,
                preConfirm: (email) => {
                    if (!email) {
                        Swal.showValidationMessage('Debe ingresar un correo');
                        return false;
                    }
                    
                    const formData = new FormData();
                    formData.append('id', id);
                    formData.append('email', email);
                    
                    return fetch('send_email.php', { method: 'POST', body: formData })
                        .then(response => {
                            if (!response.ok) throw new Error(response.statusText);
                            return response.json();
                        })
                        .then(data => {
                            if (data.status !== 'success') throw new Error(data.message);
                            return data;
                        })
                        .catch(error => {
                            Swal.showValidationMessage(`Error: ${error}`);
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('¡Enviado!', result.value.message, 'success');
                }
            });
        }



        function abrirModalRegistro() {
            
            // Construir el HTML del Select de Empresas (Solo si es Admin)
            let selectEmpresaHTML = '';
            
            if (IS_ADMIN) {
                let options = '<option value="">Seleccione una empresa...</option>';
                options += '<option value="particular">Particular / Independiente</option>'; // Opción manual
                
                LISTA_EMPRESAS.forEach(emp => {
                    options += `<option value="${emp.id}">${emp.nombre}</option>`;
                });

                selectEmpresaHTML = `
                    <div class="col-12 mb-2">
                        <label class="fw-bold text-primary">Asignar a Empresa <span class="text-danger">*</span></label>
                        <select id="new_empresa" class="form-select border-primary" onchange="toggleParticularField('new')">
                            ${options}
                        </select>
                    </div>
                    <div id="new_particular_div" class="col-12 mb-2" style="display:none">
                        <label class="fw-bold text-primary">Nombre Empresa (Particular)</label>
                        <input id="new_emp_particular" class="form-control border-primary" placeholder="Ej: Mi Empresa S.A.S">
                    </div>
                    <hr>
                `;
            }

            // Crear Options de Protocolos
            let optionsProto = '<option value="">-- Ninguno / Manual --</option>';
            LISTA_PROTOCOLOS.forEach(p => {
                 optionsProto += `<option value="${p.id}">${p.name}</option>`;
            });

            // HTML del Formulario
            const formHtml = `
                <div class="text-start">
                    <div class="row g-2">
                        ${selectEmpresaHTML}
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold text-success"><i class="bi bi-briefcase"></i> Cargo / Perfil (Opcional)</label>
                            <select id="new_protocol" class="form-select border-success">
                                ${optionsProto}
                            </select>
                            <small class="text-muted">Al seleccionar un cargo, se asignarán automáticamente los exámenes.</small>
                        </div>

                        <div class="col-4">
                            <label class="fw-bold small">Tipo Doc</label>
                            <select id="new_tipo" class="form-select">
                                ${Object.entries(DOC_TYPES).map(([code, name]) => `<option value="${code}">${code}</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-8">
                            <label class="fw-bold small">Número Documento</label>
                            <input id="new_doc" type="number" class="form-control" placeholder="Ej: 1010...">
                        </div>
                        <div class="col-6">
                            <label class="fw-bold small">Nombres</label>
                            <input id="new_nom" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="fw-bold small">Apellidos</label>
                            <input id="new_ape" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="fw-bold small">Email</label>
                            <input id="new_email" type="email" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="fw-bold small">Teléfono</label>
                            <input id="new_tel" type="tel" class="form-control">
                        </div>
                    </div>
                </div>
            `;

            // Llamada al UI Manager
            UI.modalForm({
                title: 'Nuevo Paciente',
                html: formHtml,
                confirmText: 'Registrar Paciente',
                preConfirmFn: () => {
                    const doc = document.getElementById('new_doc').value;
                    const nom = document.getElementById('new_nom').value;
                    const ape = document.getElementById('new_ape').value;
                    
                    // Validaciones JS
                    if (!doc || !nom || !ape) {
                        Swal.showValidationMessage('Documento, Nombres y Apellidos son obligatorios');
                        return false;
                    }

                    // Objeto de datos a enviar
                    let data = {
                        action: 'register',
                        tipo_documento: document.getElementById('new_tipo').value,
                        numero_documento: doc,
                        nombres: nom,
                        apellidos: ape,
                        email: document.getElementById('new_email').value,
                        telefono: document.getElementById('new_tel').value,
                        protocol_id: document.getElementById('new_protocol').value,
                        empresa_particular: document.getElementById('new_emp_particular') ? document.getElementById('new_emp_particular').value : ''
                    };

                    // Si es admin, validamos que haya seleccionado empresa
                    if (IS_ADMIN) {
                        const empSelect = document.getElementById('new_empresa').value;
                        if (!empSelect) {
                            Swal.showValidationMessage('Debe seleccionar una empresa para el paciente');
                            return false;
                        }
                        data.empresa_id = empSelect;
                    }

                    return data;
                },
                callback: (dataToSend) => {
                    UI.loading('Guardando en base de datos...');

                    // Convertir a FormData para envío POST
                    const formData = new FormData();
                    for (let key in dataToSend) formData.append(key, dataToSend[key]);

                    fetch('patients.php', { method: 'POST', body: formData })
                        .then(r => r.json()) // Esperamos JSON
                        .then(res => {
                            if (res.status === 'success') {
                                UI.success('¡Registro Exitoso!', res.message, () => location.reload());
                            } else {
                                UI.error('Error', res.message);
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            UI.error('Error', 'Fallo de conexión o respuesta inválida del servidor.');
                        });
                }
            });
        }
        // Función Editar Paciente
        function editarPaciente(id, currentEmail, currentTel, currentName, currentLastName, empresaId, empresaParticular) {
            const isParticular = (empresaId === 'particular');
            UI.modalForm({
                title: 'Editar Datos del Paciente',
                html: `
                    <div class="row g-2 mb-3 text-start">
                        ${isParticular ? `
                        <div class="col-12 mb-2">
                            <label class="form-label small fw-bold">Nombre Empresa (Particular)</label>
                            <input id="edit_emp_particular" type="text" class="form-control" value="${empresaParticular}">
                        </div>
                        ` : ''}
                        <div class="col-6">
                            <label class="form-label small fw-bold">Nombres</label>
                            <input id="edit_nom" type="text" class="form-control" value="${currentName}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Apellidos</label>
                            <input id="edit_ape" type="text" class="form-control" value="${currentLastName}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Email</label>
                            <input id="edit_email" type="email" class="form-control" value="${currentEmail}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Teléfono</label>
                            <input id="edit_tel" type="text" class="form-control" value="${currentTel}">
                        </div>
                    </div>
                `,
                confirmText: 'Actualizar Datos',
                preConfirmFn: () => {
                    const nom = document.getElementById('edit_nom').value;
                    const ape = document.getElementById('edit_ape').value;
                    
                    if(!nom || !ape) {
                        Swal.showValidationMessage('Nombres y Apellidos son obligatorios');
                        return false;
                    }

                    return {
                        action: 'edit_patient',
                        id: id,
                        nombres: nom,
                        apellidos: ape,
                        email: document.getElementById('edit_email').value,
                        telefono: document.getElementById('edit_tel').value,
                        empresa_particular: document.getElementById('edit_emp_particular') ? document.getElementById('edit_emp_particular').value : ''
                    };
                },
                callback: (data) => {
                    const formData = new FormData();
                    for(let k in data) formData.append(k, data[k]);
                    
                    fetch('patients.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(res => {
                            if(res.status === 'success') {
                                UI.success('Actualizado', res.message, () => location.reload());
                            } else {
                                UI.error('Error', res.message);
                            }
                        })
                        .catch(err => UI.error('Error', err));
                }
            });
        }
        // 4. MODAL EXPORTAR (ZIP o EXCEL)
        function abrirModalExportar(tipo) {
            const isZip = (tipo === 'zip');
            const titulo = isZip ? 'Descarga Masiva de Consentimientos (ZIP)' : 'Reporte Genereal (Excel/CSV)';
            const confirmText = isZip ? 'Generar ZIP' : 'Descargar Excel';
            
            let selectEmpresaHTML = '';
            if (IS_ADMIN) {
                let options = '<option value="all">Todas las Empresas</option>';
                LISTA_EMPRESAS.forEach(emp => {
                    options += `<option value="${emp.id}">${emp.nombre}</option>`;
                });
                selectEmpresaHTML = `<div class="mb-3"><label class="fw-bold">Empresa</label><select id="export_empresa" class="form-select">${options}</select></div>`;
            }

            const today = new Date().toISOString().split('T')[0];
            const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];

            UI.modalForm({
                title: titulo,
                html: `
                    <div class="text-start">
                        <p class="small text-muted">Seleccione el rango de fechas para generar el reporte.</p>
                        ${selectEmpresaHTML}
                        
                        <!-- Quick Filters -->
                        <div class="mb-2 text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('today')">Hoy</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('week')">Semana</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('month')">Mes</button>
                            </div>
                        </div>

                        <div class="row g-2">
                             <div class="col-6">
                                <label class="fw-bold small">Fecha Inicio</label>
                                <input type="date" id="zip_start" class="form-control" value="${firstDay}">
                             </div>
                             <div class="col-6">
                                <label class="fw-bold small">Fecha Fin</label>
                                <input type="date" id="zip_end" class="form-control" value="${today}">
                             </div>
                        </div>
                    </div>
                `,
                confirmText: confirmText,
                preConfirmFn: () => {
                    const start = document.getElementById('zip_start').value;
                    const end = document.getElementById('zip_end').value;
                    let company = 'all';
                    if (IS_ADMIN) company = document.getElementById('export_empresa').value;
                    
                    if(!start || !end) {
                        Swal.showValidationMessage('Seleccione rango de fechas');
                        return false;
                    }

                    // Trigger Download
                    const script = isZip ? 'bulk_download.php' : 'export_excel.php';
                    const url = `${script}?start=${start}&end=${end}&company=${company}`;
                    window.location.href = url;
                    return true;
                },
                callback: (res) => {}
            });
        }

        // Legacy wrapper si aun se usa en algun lado
        function abrirModalZip() { abrirModalExportar('zip'); }
        
        // Helper function for Date Range (Moved outside to avoid script tag issues)
        function setDateRange(type) {
            const today = new Date();
            let start = new Date();
            const end = today.toISOString().split('T')[0];
            
            if (type === 'today') {
                // Start is today
            } else if (type === 'week') {
                // Start is Monday of this week
                const day = today.getDay();
                const diff = today.getDate() - day + (day == 0 ? -6 : 1); 
                start.setDate(diff);
            } else if (type === 'month') {
                start = new Date(today.getFullYear(), today.getMonth(), 1);
            }
            
            document.getElementById('zip_start').value = start.toISOString().split('T')[0];
            document.getElementById('zip_end').value = end;
        }


        // --- GESTION DE EXAMENES V2 (Developer) ---
        // Global Cart state for current modal session
        let CURRENT_CART = [];
        let CURRENT_PAT_ID = 0;
        let CURRENT_PAT_NAME = '';
        let CACHED_EXAMS = []; // {id, name, final_price, is_override}
        
        function abrirModalExamenes(patientId, patientName) {
            CURRENT_PAT_ID = patientId;
            CURRENT_PAT_NAME = patientName;
            CURRENT_CART = [];
            
            UI.loading('Cargando catálogo...');
            
            const fd = new FormData();
            fd.append('action', 'get_patient_data');
            fd.append('patient_id', patientId);

            fetch('assign_exams.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    Swal.close();
                    if(res.status !== 'success') { UI.error('Error', res.message); return; }
                    
                    CACHED_EXAMS = res.exams;
                    renderV2Modal(res.exams, res.protocols, res.assigned);
                })
                .catch(err => UI.error('Error', 'No se pudo cargar la información.'));
        }

        function renderV2Modal(exams, protocols, assigned) {
             // 1. History items
             let historyHtml = '<div class="list-group list-group-flush mb-3" style="max-height:150px; overflow-y:auto">';
             if(assigned.length === 0) historyHtml += '<div class="text-muted small text-center p-2">Sin historial</div>';
             assigned.forEach(a => {
                 let note = a.note ? `<br><small class="text-muted fst-italic">Nota: ${a.note}</small>` : '';
                 historyHtml += `
                     <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-2">
                         <div style="line-height:1.2">
                             <span class="fw-bold small">${a.name}</span> <span class="badge bg-light text-success border ms-1">$${new Intl.NumberFormat().format(a.price_snapshot)}</span>
                             <div class="text-muted" style="font-size:0.7rem">
                                <strong>${a.service_type || 'N/A'}</strong> - ${a.assigned_at}${note}
                             </div>
                         </div>
                         <button class="btn btn-sm text-danger p-0" onclick="eliminarExamen(${a.id},${CURRENT_PAT_ID},'${CURRENT_PAT_NAME}')"><i class="bi bi-x-circle"></i></button>
                     </div>`;
             });
             historyHtml += '</div>';

             // 2. Protocols Select
             let protoOpts = '<option value="">-- Seleccionar Paquete --</option>';
             protocols.forEach(p => protoOpts += `<option value="${p.id}">${p.name}</option>`);

             // 3. Exams Datalist
             let examOpts = '<option value="">-- Buscar Examen --</option>';
             exams.forEach(e => {
                 let priceLabel = new Intl.NumberFormat().format(e.final_price);
                 let badge = e.is_override == 1 ? '⭐' : '';
                 examOpts += `<option value="${e.id}" data-price="${e.final_price}">${e.name} ${badge} ($${priceLabel})</option>`;
             });

             const html = `
                 <div class="text-start">
                     <h6 class="fw-bold text-secondary border-bottom pb-1 mb-2">Historial</h6>
                     ${historyHtml}
                     
                     <h6 class="fw-bold text-primary mt-3 border-bottom pb-1 mb-2">Nueva Asignación</h6>
                     
                     <div class="mb-2">
                        <label class="small fw-bold">Tipo de Servicio / Momento:</label>
                        <select id="service_type_sel" class="form-select form-select-sm">
                            <option value="Pre-ocupacional">Pre-ocupacional (Ingreso)</option>
                            <option value="Periodico">Periódico</option>
                            <option value="Post-ocupacional">Post-ocupacional (Egreso)</option>
                            <option value="Post-incapacidad">Post-incapacidad</option>
                            <option value="Otro" selected>Otro</option>
                        </select>
                     </div>

                     <div class="d-flex justify-content-center mb-2">
                        <ul class="nav nav-pills nav-fill small w-100" id="assignTabs" role="tablist">
                          <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1" data-bs-toggle="pill" data-bs-target="#tab-single" type="button">Individual</button>
                          </li>
                          <li class="nav-item" role="presentation">
                            <button class="nav-link py-1" data-bs-toggle="pill" data-bs-target="#tab-proto" type="button">Protocolos / Packs</button>
                          </li>
                        </ul>
                     </div>

                     <div class="tab-content border rounded p-2 bg-light mb-2">
                        <!-- Single -->
                        <div class="tab-pane fade show active" id="tab-single">
                            <select id="v2_exam_sel" class="form-select mb-2" onchange="previewItem()">${examOpts}</select>
                        </div>
                        <!-- Proto -->
                        <div class="tab-pane fade" id="tab-proto">
                            <select id="v2_proto_sel" class="form-select mb-2" onchange="addProtoItems()">${protoOpts}</select>
                            <small class="text-muted">Al seleccionar, se añadirán los exámenes al carrito.</small>
                        </div>
                     </div>
                     
                     <!-- Carrito -->
                     <table class="table table-sm mt-3 mb-0" style="font-size:0.9rem">
                        <thead><tr class="table-secondary"><th>Item</th><th style="width:90px">Precio</th><th style="width:30px"></th></tr></thead>
                        <tbody id="cart_body"></tbody>
                        <tfoot>
                            <tr>
                                <td class="fw-bold text-end">Total:</td>
                                <td class="fw-bold text-success" id="cart_total">$ 0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                     </table>
                     <div class="d-grid mt-2">
                        <button class="btn btn-success fw-bold" onclick="commitCart()">Confirmar Asignación</button>
                     </div>
                 </div>
             `;

             Swal.fire({
                 title: `Cobros: ${CURRENT_PAT_NAME}`,
                 html: html,
                 width: '600px',
                 showConfirmButton: false,
                 showCloseButton: true,
                 didOpen: () => updateCartUI()
             });
        }

        // Add Single Item to Cart
        function previewItem() {
            const sel = document.getElementById('v2_exam_sel');
            const exId = sel.value;
            console.log("previewItem triggered, ID:", exId);
            if(!exId) return;

            const exam = CACHED_EXAMS.find(e => e.id == exId);
            if(exam) {
                console.log("Exam found:", exam);
                let price = parseFloat(exam.final_price);
                if (isNaN(price)) price = 0;
                
                CURRENT_CART.push({
                    exam_id: exam.id,
                    name: exam.name,
                    price: price, // Default price or override
                    note: ''
                });
                sel.value = ""; // Reset
                updateCartUI();
            } else {
                console.error("Exam not found in cache for ID:", exId);
            }
        }

        // Add Protocol Items
        function addProtoItems() {
            const sel = document.getElementById('v2_proto_sel');
            const protoId = sel.value;
            if(!protoId) return;

            // Bloquear UI temporarly
            const originalText = sel.options[sel.selectedIndex].text;
            sel.options[sel.selectedIndex].text = "Cargando...";

            const fd = new FormData();
            fd.append('action', 'get_protocol_items');
            fd.append('protocol_id', protoId);
            
            fetch('assign_exams.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') {
                        res.exam_ids.forEach(eid => {
                            const exam = CACHED_EXAMS.find(e => e.id == eid);
                            if(exam) {
                                CURRENT_CART.push({
                                    exam_id: exam.id,
                                    name: exam.name,
                                    price: parseFloat(exam.final_price),
                                    note: `Pack: ${originalText}`
                                });
                            }
                        });
                        updateCartUI();
                    }
                    // Reset select
                    sel.value = "";
                    sel.options[sel.selectedIndex].text = originalText; // Restore (technically index changed 0)
                });
        }

        function updateCartUI() {
            const tbody = document.getElementById('cart_body');
            const totalEl = document.getElementById('cart_total');
            if(!tbody) return;

            tbody.innerHTML = '';
            let total = 0;
            CURRENT_CART.forEach((item, index) => {
                total += item.price;
                
                // Allow editable price
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div class="fw-bold text-truncate" style="max-width:220px" title="${item.name}">${item.name}</div>
                            <input type="text" class="form-control form-control-sm p-0 border-0 bg-transparent text-muted fst-italic" 
                                   style="font-size:0.75rem" placeholder="Añadir nota..." value="${item.note}" 
                                   onchange="updateCartItem(${index}, 'note', this.value)">
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm text-end p-1" 
                                   value="${item.price}" 
                                   onchange="updateCartItem(${index}, 'price', this.value)">
                        </td>
                        <td><button class="btn btn-link text-danger p-0" onclick="removeCartItem(${index})"><i class="bi bi-x"></i></button></td>
                    </tr>
                `;
            });
            totalEl.innerText = '$ ' + new Intl.NumberFormat().format(total);

            // Refesh Dropdown options to exclude items in cart
            const sel = document.getElementById('v2_exam_sel');
            if(sel) {
                let opts = '<option value="">-- Buscar Examen --</option>';
                CACHED_EXAMS.forEach(e => {
                    // Check if already in cart
                    if(!CURRENT_CART.find(c => c.exam_id == e.id)) {
                         let priceLabel = new Intl.NumberFormat().format(e.final_price);
                         let badge = e.is_override == 1 ? '⭐' : '';
                         opts += `<option value="${e.id}" data-price="${e.final_price}">${e.name} ${badge} ($${priceLabel})</option>`;
                    }
                });
                sel.innerHTML = opts;
            }
        }

        function updateCartItem(index, key, val) {
            if(key === 'price') CURRENT_CART[index].price = parseFloat(val) || 0;
            if(key === 'note') CURRENT_CART[index].note = val;
            updateCartUI(); // Re-render totals stuff
        }

        function removeCartItem(index) {
            CURRENT_CART.splice(index, 1);
            updateCartUI();
        }

        function commitCart() {
            if(CURRENT_CART.length === 0) {
                Swal.showValidationMessage('El carrito está vacío. Debe seleccionar exámenes.');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'assign_cart');
            fd.append('patient_id', CURRENT_PAT_ID);
            fd.append('cart', JSON.stringify(CURRENT_CART));
            fd.append('service_type', document.getElementById('service_type_sel').value); 

            fetch('assign_exams.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') {
                        // Reload modal
                        abrirModalExamenes(CURRENT_PAT_ID, CURRENT_PAT_NAME);
                    } else {
                        UI.error('Error', res.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    UI.error('Error', 'Fallo de red al asignar.');
                });
        }

         function eliminarExamen(assId, patientId, patientName) {
             const fd = new FormData();
            fd.append('action', 'remove_exam');
            fd.append('assignment_id', assId);
            fetch('assign_exams.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => { if(res.status === 'success') abrirModalExamenes(patientId, patientName); });
        }

        function toggleParticularField(prefix) {
            const select = document.getElementById(prefix + '_empresa');
            const div = document.getElementById(prefix + '_particular_div');
            if (select && div) {
                div.style.display = (select.value === 'particular') ? 'block' : 'none';
            }
        }

    </script>
</body>
</html>