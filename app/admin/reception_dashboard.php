<?php
// admin/reception_dashboard.php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDBConnection();
$today = date('Y-m-d');

// Fetch patient flow for TODAY
// Group by patient to show overall status
$sql = "SELECT p.nombres, p.apellidos, p.numero_documento,
               MIN(pe.assigned_at) as arrival_time,
               MAX(pe.called_at) as last_call,
               SUM(CASE WHEN pe.status='completed' THEN 1 ELSE 0 END) as done_count,
               COUNT(pe.id) as total_exams,
               GROUP_CONCAT(DISTINCT e.name SEPARATOR ', ') as exams_list
        FROM patient_exams pe
        JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
        JOIN exams e ON pe.exam_id = e.id
        WHERE DATE(pe.assigned_at) = '$today'
        GROUP BY p.id, p.nombres, p.apellidos, p.numero_documento
        ORDER BY arrival_time DESC";

$res = $db->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor de Recepción</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <meta http-equiv="refresh" content="30"> <!-- Auto refresh every 30s -->
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    
<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> Recepción
        </a>
        <div class="d-flex text-white">
            <span class="me-3"><?php echo date('d/m/Y'); ?></span>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light">Volver</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <h3 class="mb-3">Flujo de Pacientes (Hoy)</h3>
    
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-primary">
                    <tr>
                        <th>Hora Llegada</th>
                        <th>Paciente</th>
                        <th>Exámenes</th>
                        <th>Estado Actual</th>
                        <th>Tiempo Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($res && $res->num_rows > 0): ?>
                        <?php while($row = $res->fetch_assoc()): 
                            $arrival = strtotime($row['arrival_time']);
                            $waited = time() - $arrival;
                            
                            // Determine Status
                            $statusBadge = '';
                            if ($row['done_count'] == $row['total_exams']) {
                                $statusBadge = '<span class="badge bg-success">FINALIZADO</span>';
                            } elseif ($row['last_call']) {
                                $statusBadge = '<span class="badge bg-warning text-dark"><i class="bi bi-megaphone-fill"></i> EN CONSULTORIO</span>';
                            } else {
                                $statusBadge = '<span class="badge bg-secondary">EN SALA</span>';
                            }
                        ?>
                        <tr>
                            <td><?php echo date('H:i', $arrival); ?></td>
                            <td class="fw-bold">
                                <?php echo $row['nombres'] . ' ' . $row['apellidos']; ?>
                                <div class="small text-muted"><?php echo $row['numero_documento']; ?></div>
                            </td>
                            <td><small><?php echo $row['exams_list']; ?></small></td>
                            <td><?php echo $statusBadge; ?></td>
                            <td><?php echo gmdate("H:i", $waited); ?> hrs</td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No hay pacientes registrados hoy.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
