<?php
// public_html/admin/logs.php
require_once '../config.php';

// Solo Admin puede ver esto
if (!isLoggedIn() || !isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// Paginación simple
$limit = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// --- 1.2 FILTROS ---
$search = $_GET['search'] ?? '';
$user_id = $_GET['user_id'] ?? '';

// Obtener lista de usuarios para el filtro
$users_list = $db->query("SELECT id, username FROM usuarios ORDER BY username ASC");

// --- 2. CONSULTA DINÁMICA ---
$sqlBase = " FROM registro_actividades r 
             LEFT JOIN usuarios u ON r.usuario_id = u.id 
             WHERE 1=1 ";
$params = [];
$types = "";

if (!empty($search)) {
    $sqlBase .= " AND (r.accion LIKE ? OR r.detalles LIKE ? OR r.tabla_afectada LIKE ?) ";
    $term = "%$search%";
    array_push($params, $term, $term, $term);
    $types .= "sss";
}

if ($user_id !== '') {
    if ($user_id === 'system') {
        $sqlBase .= " AND r.usuario_id IS NULL ";
    } else {
        $sqlBase .= " AND r.usuario_id = ? ";
        $params[] = (int)$user_id;
        $types .= "i";
    }
}

// Total registros con filtros
$stmtTotal = $db->prepare("SELECT COUNT(*) as total $sqlBase");
if ($params) $stmtTotal->bind_param($types, ...$params);
$stmtTotal->execute();
$totalRows = $stmtTotal->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Consulta Final con Paginación
$sql = "SELECT r.*, u.username $sqlBase ORDER BY r.fecha_registro DESC LIMIT ? OFFSET ?";
$p_final = array_merge($params, [$limit, $offset]);
$t_final = $types . "ii";

$stmt = $db->prepare($sql);
$stmt->bind_param($t_final, ...$p_final);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría | Panel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand mb-0 h1" href="dashboard.php">Administración</a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
            <a href="logs.php" class="btn btn-outline-light btn-sm me-2 disabled">Auditoría</a>
            <a href="change_password.php" class="btn btn-outline-light btn-sm me-2">Contraseña</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold m-0"><i class="bi bi-shield-lock-fill"></i> Auditoría de Sistema</h2>
            <a href="export_logs.php?search=<?php echo urlencode($search); ?>&user_id=<?php echo $user_id; ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> Exportar a Excel
            </a>
        </div>

        <!-- Filtros -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small fw-bold">Buscar Acción, Detalles o Tabla</label>
                        <input type="text" name="search" class="form-control" placeholder="Ej: Editar, Paciente, usuarios..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Filtrar por Usuario</label>
                        <select name="user_id" class="form-select">
                            <option value="">-- Todos los Usuarios --</option>
                            <option value="system" <?php echo ($user_id === 'system') ? 'selected' : ''; ?>>Sistema / Automático</option>
                            <?php while($u = $users_list->fetch_assoc()): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo ($user_id == $u['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <a href="logs.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Acción</th>
                                <th>Detalles</th>
                                <th>Tabla Afectada</th>
                                <th>IP Origen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows === 0): ?>
                                <tr><td colspan="5" class="text-center py-4">No hay registros aún.</td></tr>
                            <?php endif; ?>

                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['fecha_registro']; ?></td>
                                <td><span class="badge bg-secondary"><?php echo $row['username'] ?? 'Sistema'; ?></span></td>
                                <td class="fw-bold"><?php echo $row['accion']; ?></td>
                                <td class="small"><?php echo htmlspecialchars($row['detalles']); ?></td>
                                <td><code><?php echo $row['tabla_afectada']; ?></code></td>
                                <td class="small text-muted"><?php echo $row['ip_origen']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Paginación -->
            <?php if($totalPages > 1): ?>
            <div class="card-footer bg-white d-flex justify-content-center pt-3">
                <nav>
                    <ul class="pagination">
                        <?php 
                        $queryString = "&search=" . urlencode($search) . "&user_id=" . urlencode($user_id);
                        ?>
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1 . $queryString; ?>">Anterior</a>
                        </li>
                        <?php 
                        // Mostrar máximo 10 páginas para no saturar
                        $startPage = max(1, $page - 5);
                        $endPage = min($totalPages, $page + 5);
                        
                        for($i=$startPage; $i<=$endPage; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i . $queryString; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1 . $queryString; ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
