<?php
// public_html/admin/billing.php
require_once '../config.php';

if (!isLoggedIn() || !isDesarrollador()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// --- FILTROS ---
$month = $_GET['month'] ?? date('Y-m');
$companyId = $_GET['company_id'] ?? '';

// Obtener Empresas
$resEmp = $db->query("SELECT id, nombre FROM empresas ORDER BY nombre ASC");
$companies = $resEmp->fetch_all(MYSQLI_ASSOC);

// --- CONSULTA ---
$sql = "SELECT pe.*, e.name as exam_name, p.nombres, p.apellidos, 
               p.tipo_documento, p.numero_documento, emp.nombre as company_name, pe.service_type
        FROM patient_exams pe
        JOIN exams e ON pe.exam_id = e.id
        JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
        JOIN empresas emp ON p.empresa_id = emp.id
        WHERE pe.status = 'completed' AND DATE_FORMAT(pe.assigned_at, '%Y-%m') = ? ";

$params = [$month];
$types = "s";

if ($companyId) {
    // Si la empresa es 'particular' (o vacía si se quiere todo)
    // Asumimos que companyId viene del select
    $sql .= " AND p.empresa_id = ? ";
    $params[] = $companyId;
    $types .= "s";
}

$sql .= " ORDER BY pe.assigned_at DESC";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$totalCost = 0;
$items = [];
while($row = $result->fetch_assoc()) {
    $totalCost += $row['price_snapshot'];
    $items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Facturación | Developer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    <?php applyTheme(); ?>
    <style>
        .summary-card {
            border-left: 4px solid var(--bs-primary);
        }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand" href="dashboard.php">Panel Desarrollador</a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <h3 class="mb-4"><i class="bi bi-currency-dollar"></i> Reporte de Cobros</h3>
        
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Periodo (Mes)</label>
                        <input type="month" name="month" class="form-control" value="<?php echo $month; ?>" onchange="this.form.submit()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Empresa</label>
                        <select name="company_id" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Todas las Empresas --</option>
                            <?php foreach($companies as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $companyId == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo $c['nombre']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-light">
                            <div class="card-body py-2">
                                <small class="text-muted text-uppercase">Total Periodo</small>
                                <h3 class="fw-bold mb-0 text-success">$ <?php echo number_format($totalCost, 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 text-end">
                         <?php if(!empty($items)): ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
                         <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tableBilling" class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Fecha</th>
                                <th>Empresa</th>
                                <th>Paciente</th>
                                <th>Documento</th>
                                <th>Detalle Examen</th>
                                <th>Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // $grandTotal ya fue calculado arriba. Usamos $items
                            foreach($items as $row): 
                            ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($row['assigned_at'])); ?></td>
                                <td><?php echo $row['company_name']; ?></td>
                                <td><?php echo $row['nombres'] . ' ' . $row['apellidos']; ?></td>
                                <td><?php echo $row['tipo_documento'] . ' ' . $row['numero_documento']; ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo $row['service_type'] ?? 'N/A'; ?></span><br>
                                    <strong class="text-primary"><?php echo $row['exam_name']; ?></strong>
                                </td>
                                <td class="text-end fw-bold">$ <?php echo number_format($row['price_snapshot'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                         <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="5" class="text-end">TOTAL:</td>
                                <td class="text-end">$ <?php echo number_format($totalCost, 0); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Export Buttons -->
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>

    <script>
        $(document).ready(function() {
            var table = $('#tableBilling').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'excel', className: 'btn btn-success btn-sm', title: 'Reporte_Facturacion_<?php echo $month; ?>' },
                    { extend: 'pdf', className: 'btn btn-danger btn-sm', title: 'Reporte_Facturacion_<?php echo $month; ?>' }
                ],
                language: { url: "//cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json" },
                pageLength: 50
            });
        });
    </script>
</body>
</html>
