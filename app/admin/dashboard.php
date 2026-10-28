<?php
// public_html/admin/dashboard.php
require_once '../config.php';

// 1. SEGURIDAD CORRECTA:
// Solo verificamos que esté logueado. NO verificamos si es admin.
// Así permitimos entrar a Admin Y Empresas.
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Redirect Specialists to their own dashboard
if (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'profesional' && !isAdmin()) {
    header('Location: specialist_dashboard.php');
    exit();
}

$db = getDBConnection();
$userId = getUserId(); 

// 2. FILTRAR DATOS (Admin ve todo, Empresa ve lo suyo)
$whereClause = "";
$params = [];
$types = "";

if (isEmpresa()) {
    // Si es empresa, forzamos el filtro usando su ID de EMPRESA (no de usuario)
    $whereClause = "WHERE company_id = ?";
    $params[] = getMyCompanyId();
    $types = "s";
}

// Función auxiliar
function countData($db, $sqlCondition, $whereBase, $paramsBase, $typesBase) {
    $prefix = $whereBase ? "$whereBase AND" : "WHERE";
    $sql = "SELECT COUNT(*) as total FROM consents $prefix $sqlCondition";
    $stmt = $db->prepare($sql);
    if (!empty($paramsBase)) $stmt->bind_param($typesBase, ...$paramsBase);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

// Obtener datos
$hoy = countData($db, "DATE(date_signed) = CURDATE()", $whereClause, $params, $types);
$mes = countData($db, "MONTH(date_signed) = MONTH(CURRENT_DATE()) AND YEAR(date_signed) = YEAR(CURRENT_DATE())", $whereClause, $params, $types);
$total = countData($db, "1=1", $whereClause, $params, $types);

// NUEVO: Contar encuestas pendientes (solo para admin/recepcion)
$pendingSurveys = 0;
if (isAdmin()) {
    $resPS = $db->query("SELECT COUNT(*) as total FROM consents c LEFT JOIN survey_responses s ON c.id = s.consent_id WHERE s.id IS NULL");
    $pendingSurveys = $resPS->fetch_assoc()['total'];
}

// Tabla Reciente
$sqlRecent = "SELECT c.*, e.nombre as nombre_empresa 
              FROM consents c 
              LEFT JOIN empresas e ON c.company_id = e.id 
              $whereClause 
              ORDER BY c.date_signed DESC LIMIT 5";
$stmt = $db->prepare($sqlRecent);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$recientes = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
</head>
<body>
<?php 
    $logoUrl = getSetting('logo_url');
    if($logoUrl) $logoUrl = '../'.$logoUrl; 
    $siteName = getSetting('site_name', 'Sistema Consentimientos');
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-3 col-lg-2 d-md-block sidebar collapse show">
            <div class="p-3 text-center border-bottom border-secondary">
                <?php if($logoUrl && file_exists($logoUrl)): ?>
                    <img src="<?php echo $logoUrl . '?v=' . time(); ?>" alt="Logo" class="img-fluid mb-2" style="max-height: 50px;">
                <?php else: ?>
                    <h6 class="fw-bold m-0"><?php echo strtoupper($siteName); ?></h6>
                <?php endif; ?>
                <?php 
                    $roleLabel = 'EMPRESA';
                    if(isAdmin()) $roleLabel = 'ADMINISTRADOR';
                    if(isDesarrollador() && !isAdmin()) $roleLabel = 'DESARROLLADOR'; // Pure dev
                    if(isAdmin() && isDesarrollador()) $roleLabel = 'ADMIN (DEV MODE)';
                ?>
                <small class="text-warning d-block"><?php echo $roleLabel; ?></small>
            </div>
            <ul class="nav flex-column mt-3">
                <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="patients.php"><i class="bi bi-people me-2"></i> Pacientes</a></li>
                <li class="nav-item"><a class="nav-link" href="withdrawals.php"><i class="bi bi-file-earmark-x me-2"></i> Desistimientos</a></li>
                
                <?php if(isAdmin() || isRecepcionista()): ?>
                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                        <span>Recepción</span>
                    </h6>
                    <li class="nav-item">
                        <a class="nav-link" href="reception_dashboard.php" target="_blank">
                            <i class="bi bi-window-split me-2"></i> Monitor
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tv_queue.php" target="_blank">
                            <i class="bi bi-tv me-2"></i> Pantalla TV
                        </a>
                    </li>
                <?php endif; ?>

                <?php if(isAdmin()): ?>
                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                        <span>Administración</span>
                    </h6>
                    <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people-fill me-2"></i> Usuarios</a></li>
                    <li class="nav-item"><a class="nav-link" href="empresas.php"><i class="bi bi-building me-2"></i> Empresas</a></li>
                    <li class="nav-item"><a class="nav-link" href="exams.php"><i class="bi bi-activity me-2"></i> Exámenes</a></li>
                    <li class="nav-item"><a class="nav-link" href="consent_templates.php"><i class="bi bi-file-text me-2"></i> Plantillas</a></li>
                    <li class="nav-item"><a class="nav-link" href="backup_manager.php"><i class="bi bi-database me-2"></i> Backups</a></li>
                    <li class="nav-item"><a class="nav-link" href="surveys.php"><i class="bi bi-card-checklist me-2"></i> Encuestas</a></li>
                    <li class="nav-item"><a class="nav-link" href="configuracion.php"><i class="bi bi-gear me-2"></i> Configuración</a></li>
                    <li class="nav-item"><a class="nav-link" href="logs.php"><i class="bi bi-shield-lock me-2"></i> Auditoría</a></li>
                <?php endif; ?>

                <?php if(isDesarrollador()): ?>
                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-warning">
                        <span><i class="bi bi-code-slash"></i> Desarrollador</span>
                    </h6>
                    <li class="nav-item"><a class="nav-link" href="exams.php"><i class="bi bi-activity me-2"></i> Exámenes</a></li>
                    <li class="nav-item"><a class="nav-link" href="protocols.php"><i class="bi bi-collection me-2"></i> Protocolos</a></li>
                    <li class="nav-item"><a class="nav-link" href="company_pricing.php"><i class="bi bi-tag me-2"></i> Precios x Empresa</a></li>
                    <li class="nav-item"><a class="nav-link" href="billing.php"><i class="bi bi-currency-dollar me-2"></i> Facturación</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_specialists.php"><i class="bi bi-person-badge me-2"></i> Especialistas</a></li>
                <?php endif; ?>
                
                <li class="nav-item mt-4">
                    <?php if(isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'desarrollador'): ?>
                        <a class="nav-link text-warning" href="toggle_dev.php">
                            <i class="bi bi-toggle-<?php echo isDesarrollador() ? 'on' : 'off'; ?> me-2"></i> Modo Dev
                        </a>
                    <?php endif; ?>
                    <a class="nav-link text-info fw-bold" href="manual.php"><i class="bi bi-book me-2"></i> Manual Usuario</a>
                    <a class="nav-link" href="profile.php"><i class="bi bi-person-circle me-2"></i> Mi Perfil</a>
                    <a class="nav-link" href="change_password.php"><i class="bi bi-key me-2"></i> Contraseña</a>
                    <a class="nav-link text-danger" href="logout.php"><i class="bi bi-box-arrow-left me-2"></i> Salir</a>
                </li>
            </ul>
        </div>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <!-- NUEVO: Gráfica y Stats Avanzados -->
            <?php
            $fechas = [];
            $conteos = [];
            $pieLabels = [];
            $pieData = [];
            $chartError = null;

            try {
                // Datos para el gráfico (últimos 7 días)
                $sqlChart = "
                    SELECT DATE(date_signed) as fecha, COUNT(*) as total 
                    FROM consents 
                    $whereClause 
                    " . ($whereClause ? "AND" : "WHERE") . " date_signed >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                    GROUP BY DATE(date_signed)
                    ORDER BY fecha ASC
                ";
                $stmt = $db->prepare($sqlChart);
                if(!$stmt) throw new Exception("Error preparando Chart Query: " . $db->error);
                if (!empty($params)) $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $resChart = $stmt->get_result();

                
                // Rellenar días vacíos
                $periodo = new DatePeriod(
                    new DateTime('-6 days'),
                    new DateInterval('P1D'),
                    new DateTime('+1 day')
                );

                $dataMap = [];
                while($row = $resChart->fetch_assoc()) {
                    $dataMap[$row['fecha']] = $row['total'];
                }

                foreach ($periodo as $key => $value) {
                    $fmt = $value->format('Y-m-d');
                    $fechas[] = $value->format('d/m'); // Label visual
                    $conteos[] = $dataMap[$fmt] ?? 0;
                }

                // Datos para Gráfico Circular
                // Si es ADMIN: Mostrar por EMPRESA
                // Si es EMPRESA: Mostrar por PLANTILLA (ya que por empresa sería 100% ellos mismos)
                
                if (isAdmin()) {
                    // Query por Empresa
                     $sqlPie = "SELECT COALESCE(e.nombre, 'Particular') as name, COUNT(c.id) as total 
                               FROM consents c
                               LEFT JOIN empresas e ON c.company_id = e.id 
                               $whereClause 
                               GROUP BY COALESCE(e.nombre, 'Particular')";
                     $chartTitle = "Por Empresa";
                } else {
                    // Query por Plantilla (Original)
                    $sqlPie = "SELECT ct.name, COUNT(c.id) as total 
                               FROM consents c
                               LEFT JOIN consent_types ct ON c.template_id = ct.id 
                               $whereClause 
                               GROUP BY ct.name";
                    $chartTitle = "Por Plantilla";
                }
                           
                $stmt = $db->prepare($sqlPie);
                if(!$stmt) throw new Exception("Error preparando Pie Query: " . $db->error);
                if (!empty($params) && !isAdmin()) $stmt->bind_param($types, ...$params); 
                // Nota: Si es admin no hay params en $whereClause (es vacio), si es empresa sí. 
                // Pero mi lógica de $whereClause arriba depende de isEmpresa(). 
                // Si isAdmin(), $whereClause es vacío y $params vacío.
                
                $stmt->execute();
                $resPie = $stmt->get_result();
                
                while($row = $resPie->fetch_assoc()) {
                    $name = $row['name'] ? $row['name'] : 'Sin Definir';
                    $pieLabels[] = $name;
                    $pieData[] = $row['total'];
                }

                // --- NUEVO: Estadísticas de Especialistas ---
                $specialistsLabels = [];
                $specialistsData = [];
                $specialistsTable = [];
                
                if (isAdmin() || isDesarrollador()) {
                    $sqlSpec = "SELECT u.nombre_completo, COUNT(pe.id) as total_exams
                                FROM patient_exams pe
                                JOIN usuarios u ON pe.completed_by = u.id
                                WHERE pe.status = 'completed'
                                GROUP BY pe.completed_by
                                ORDER BY total_exams DESC";
                    $resSpec = $db->query($sqlSpec);
                    while($row = $resSpec->fetch_assoc()) {
                        $name = $row['nombre_completo'];
                        $specialistsTable[] = $row;
                        // Top 5 for Chart
                        if(count($specialistsLabels) < 5) {
                            $specialistsLabels[] = $name;
                            $specialistsData[] = $row['total_exams'];
                        }
                    }
                }

            } catch (Exception $e) {
                $chartError = $e->getMessage();
                // No detenemos la ejecución, solo mostramos error en lugar de gráficos
            }
            ?>

            <?php if($chartError): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Error cargando gráficas:</strong> <?php echo $chartError; ?>
                </div>
            <?php endif; ?>

             <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="row">
                        <div class="col-md-7 mb-3 mb-md-0">
                             <div class="card shadow-sm h-100">
                                <div class="card-header bg-white">
                                     <strong><i class="bi bi-graph-up"></i> Actividad (7 días)</strong>
                                </div>
                                <div class="card-body">
                                    <canvas id="weeklyChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                             <div class="card shadow-sm h-100">
                                <div class="card-header bg-white">
                                     <strong><i class="bi bi-pie-chart"></i> <?php echo $chartTitle ?? 'Distribución'; ?></strong>
                                </div>
                                <div class="card-body">
                                    <!-- Altura fija para evitar que crezca demasiado -->
                                    <div style="height: 200px; display: flex; justify-content: center;">
                                        <canvas id="pieChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Bloque de Resumen -->
                 <div class="col-lg-4">
                    <div class="row g-3">
                         <div class="col-12"><div class="card bg-primary text-white p-3 shadow-sm d-flex flex-row justify-content-between align-items-center">
                            <div><h3><?php echo $hoy; ?></h3><small>Firmas Hoy</small></div>
                            <i class="bi bi-calendar-check fs-1 opacity-50"></i>
                        </div></div>
                        <div class="col-12"><div class="card bg-success text-white p-3 shadow-sm d-flex flex-row justify-content-between align-items-center">
                            <div><h3><?php echo $mes; ?></h3><small>Este Mes</small></div>
                            <i class="bi bi-calendar3 fs-1 opacity-50"></i>
                        </div></div>
                        <div class="col-12"><div class="card bg-dark text-white p-3 shadow-sm d-flex flex-row justify-content-between align-items-center">
                             <div><h3><?php echo $total; ?></h3><small>Total Histórico</small></div>
                            <i class="bi bi-archive fs-1 opacity-50"></i>
                         </div></div>
                         <?php if(isAdmin() && $pendingSurveys > 0): ?>
                         <div class="col-12">
                             <a href="surveys.php" class="text-decoration-none">
                                 <div class="card bg-warning text-dark p-3 shadow-sm d-flex flex-row justify-content-between align-items-center">
                                     <div><h3><?php echo $pendingSurveys; ?></h3><small>Encuestas Pendientes</small></div>
                                     <i class="bi bi-star-half fs-1 opacity-25"></i>
                                 </div>
                             </a>
                         </div>
                         <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- BLOQUE ESPECIALISTAS (Solo Admin/Dev) -->
            <?php if((isAdmin() || isDesarrollador()) && !empty($specialistsTable)): ?>
            <div class="row mb-4">
                <div class="col-md-6 mb-3 mb-md-0">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <strong><i class="bi bi-person-badge"></i> Top Especialistas (Atenciones)</strong>
                        </div>
                        <div class="card-body">
                            <canvas id="specialistChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <strong><i class="bi bi-list-ol"></i> Detalle de Productividad</strong>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 250px;">
                                <table class="table table-striped mb-0 small">
                                    <thead><tr><th>Especialista</th><th class="text-end">Pacientes Atendidos</th></tr></thead>
                                    <tbody>
                                        <?php foreach($specialistsTable as $s): ?>
                                        <tr>
                                            <td><?php echo $s['nombre_completo']; ?></td>
                                            <td class="text-end fw-bold text-success"><?php echo $s['total_exams']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Últimos Registros</strong>
                    <a href="patients.php" class="btn btn-sm btn-outline-primary">Ver Todos</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Fecha</th><th>Paciente</th><th>Documento</th><th>PDF</th></tr></thead>
                            <tbody>
                                <?php while($row = $recientes->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d/m H:i', strtotime($row['date_signed'])); ?></td>
                                    <td>
                                        <div class="fw-bold"><?php echo $row['first_name'].' '.$row['last_name']; ?></div>
                                        <?php if(isAdmin()): ?>
                                            <small class="text-muted">
                                                <?php 
                                                if ($row['company_id'] === 'particular' && !empty($row['empresa_particular'])) {
                                                    echo htmlspecialchars($row['empresa_particular']) . ' (P)';
                                                } else {
                                                    echo htmlspecialchars($row['nombre_empresa'] ?? 'Particular');
                                                }
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo $row['document_number']; ?></span></td>
                                    <td><a href="download_pdf.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-pdf"></i></a></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('weeklyChart').getContext('2d');
    const myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($fechas); ?>,
            datasets: [{
                label: 'Consentimientos Firmados',
                data: <?php echo json_encode($conteos); ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    const ctxPie = document.getElementById('pieChart').getContext('2d');
    new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($pieLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($pieData); ?>,
                backgroundColor: [
                    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'
                ],
                hoverOffset: 4
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
            }
        }
    });

    <?php if(!empty($specialistsLabels)): ?>
    const ctxSpec = document.getElementById('specialistChart').getContext('2d');
    new Chart(ctxSpec, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($specialistsLabels); ?>,
            datasets: [{
                label: 'Pacientes Atendidos',
                data: <?php echo json_encode($specialistsData); ?>,
                backgroundColor: 'rgba(25, 135, 84, 0.6)',
                borderColor: 'rgba(25, 135, 84, 1)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            scales: { x: { beginAtZero: true } },
            plugins: { legend: { display: false } }
        }
    });
    <?php endif; ?>
</script>
</body>
</html>