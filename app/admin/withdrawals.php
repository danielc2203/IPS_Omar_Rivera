<?php
// public_html/admin/withdrawals.php
require_once '../config.php';

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$db = getDBConnection();

// --- FILTROS ---
$where = "1=1";
$params = [];
$types = "";

if (isEmpresa()) {
    $empresaId = getMyCompanyId();
    $where .= " AND p.empresa_id = ?";
    $params[] = $empresaId;
    $types .= "s";
}

if (!empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where .= " AND (p.nombres LIKE ? OR p.apellidos LIKE ? OR p.numero_documento LIKE ?)";
    $params = array_merge($params, [$search, $search, $search]);
    $types .= "sss";
}

$sql = "SELECT w.*, p.nombres, p.apellidos, p.tipo_documento, p.numero_documento, e.nombre as nombre_empresa
        FROM withdrawals w
        JOIN pacientes_pre_registrados p ON w.patient_id = p.id
        LEFT JOIN empresas e ON p.empresa_id = e.id
        WHERE $where
        ORDER BY w.created_at DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$logoUrl = getSetting('logo_url');
if($logoUrl) $logoUrl = '../'.$logoUrl;
$siteName = getSetting('site_name', 'Sistema de Consentimientos');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Desistimientos | Panel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2 d-md-block sidebar collapse show">
           <div class="p-3 text-center border-bottom border-secondary">
                <?php if($logoUrl && file_exists($logoUrl)): ?>
                    <img src="<?php echo $logoUrl; ?>" alt="Logo" class="img-fluid mb-2" style="max-height: 50px;">
                <?php else: ?>
                    <h6 class="fw-bold m-0 text-white"><?php echo strtoupper($siteName); ?></h6>
                <?php endif; ?>
                <small class="text-warning d-block">ADMINISTRACIÓN</small>
            </div>
            <ul class="nav flex-column mt-3">
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="patients.php"><i class="bi bi-people me-2"></i> Pacientes</a></li>
                <li class="nav-item"><a class="nav-link active" href="withdrawals.php"><i class="bi bi-file-earmark-x me-2"></i> Desistimientos</a></li>
                <li class="nav-item"><a class="nav-link" href="exams.php"><i class="bi bi-activity me-2"></i> Exámenes</a></li>
                <li class="nav-item"><a class="nav-link" href="consent_templates.php"><i class="bi bi-file-text me-2"></i> Plantillas</a></li>
                <li class="nav-item"><a class="nav-link text-danger" href="logout.php"><i class="bi bi-box-arrow-left me-2"></i> Salir</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="bi bi-file-earmark-x text-danger"></i> Desistimientos Registrados</h1>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-10">
                            <input type="text" name="search" class="form-control" placeholder="Buscar por nombre o documento..." value="<?php echo $_GET['search'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Buscar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle" id="withdrawTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Paciente</th>
                                    <th>Empresa</th>
                                    <th>Exámenes Desistidos</th>
                                    <th>Motivo</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $result->fetch_assoc()): 
                                    $exams = json_decode($row['exams_json'], true);
                                    $examNames = array_column($exams, 'name');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($row['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo strtoupper($row['nombres'] . ' ' . $row['apellidos']); ?></div>
                                        <small class="text-muted"><?php echo $row['tipo_documento'] . ' ' . $row['numero_documento']; ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo $row['nombre_empresa'] ?: 'Particular'; ?></span></td>
                                    <td>
                                        <?php foreach($examNames as $name): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 my-1"><?php echo $name; ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo htmlspecialchars($row['reason'] ?: 'Sin motivo especificado'); ?></td>
                                    <td class="text-end">
                                        <a href="download_withdrawal_pdf.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Descargar PDF" target="_blank">
                                            <i class="bi bi-file-pdf"></i>
                                        </a>
                                    </td>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#withdrawTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            order: [[0, 'desc']],
            pageLength: 25
        });
    });
</script>
</body>
</html>
