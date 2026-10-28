<?php
// public_html/admin/exams.php
require_once '../config.php';

// ACCESO RESTRINGIDO: 'admin' o 'desarrollador'
if (!isLoggedIn() || (!isAdmin() && !isDesarrollador())) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// --- BACKEND: ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Acción desconocida'];

    try {
        if ($_POST['action'] === 'save_exam') {
            $name = sanitizeInput($_POST['name']);
            $cost = (float)($_POST['cost']);
            $desc = sanitizeInput($_POST['description']);
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

            if ($id > 0) {
                // UPDATE
                $stmt = $db->prepare("UPDATE exams SET name=?, cost=?, description=? WHERE id=?");
                $stmt->bind_param("sdsi", $name, $cost, $desc, $id);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Examen actualizado correctamente'];
                } else throw new Exception($stmt->error);
            } else {
                // INSERT
                $stmt = $db->prepare("INSERT INTO exams (name, cost, description) VALUES (?, ?, ?)");
                $stmt->bind_param("sds", $name, $cost, $desc);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Examen creado correctamente'];
                } else throw new Exception($stmt->error);
            }

        } elseif ($_POST['action'] === 'toggle_status') {
            $id = (int)$_POST['id'];
            $status = (int)$_POST['status']; // 1 or 0
            $stmt = $db->prepare("UPDATE exams SET is_active=? WHERE id=?");
            $stmt->bind_param("ii", $status, $id);
            if($stmt->execute()) {
                 $response = ['status' => 'success', 'message' => 'Estado actualizado'];
            } else throw new Exception($stmt->error);
        }

    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

// --- VISTA ---
$res = $db->query("SELECT * FROM exams ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Exámenes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand" href="dashboard.php">Panel Gestión de Exámenes</a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver al Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-activity"></i> Tipos de Exámenes</h3>
            <button class="btn btn-primary" onclick="modalExam()"><i class="bi bi-plus-lg"></i> Nuevo Examen</button>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <table id="tableExams" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Costo</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold"><?php echo $row['name']; ?></td>
                            <td class="text-success">$ <?php echo number_format($row['cost'], 0); ?></td>
                            <td><small class="text-muted"><?php echo $row['description']; ?></small></td>
                            <td>
                                <?php if($row['is_active']): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick='modalExam(<?php echo json_encode($row); ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if($row['is_active']): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="toggleStatus(<?php echo $row['id']; ?>, 0)">
                                        <i class="bi bi-slash-circle"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="toggleStatus(<?php echo $row['id']; ?>, 1)">
                                        <i class="bi bi-check-circle"></i>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/ui-manager.js"></script>

    <script>
        $(document).ready(function() {
            $('#tableExams').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json" }
            });
        });

        function modalExam(data = null) {
            const isEdit = !!data;
            const title = isEdit ? 'Editar Examen' : 'Nuevo Examen';
            
            const html = `
                <div class="text-start">
                    <input type="hidden" id="ex_id" value="${isEdit ? data.id : 0}">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Examen</label>
                        <input id="ex_name" class="form-control" value="${isEdit ? data.name : ''}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Costo (COP)</label>
                        <input id="ex_cost" type="number" class="form-control" value="${isEdit ? data.cost : ''}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción</label>
                        <textarea id="ex_desc" class="form-control" rows="2">${isEdit ? data.description : ''}</textarea>
                    </div>
                </div>
            `;

            UI.modalForm({
                title: title,
                html: html,
                confirmText: 'Guardar',
                preConfirmFn: () => {
                    const name = document.getElementById('ex_name').value;
                    const cost = document.getElementById('ex_cost').value;
                    if(!name || !cost) {
                        Swal.showValidationMessage('Nombre y Costo son obligatorios'); 
                        return false;
                    }
                    return {
                        action: 'save_exam',
                        id: document.getElementById('ex_id').value,
                        name: name,
                        cost: cost,
                        description: document.getElementById('ex_desc').value
                    };
                },
                callback: (formData) => {
                    postData(formData);
                }
            });
        }

        function toggleStatus(id, newStatus) {
            postData({ action: 'toggle_status', id: id, status: newStatus });
        }

        function postData(data) {
            const fd = new FormData();
            for(let k in data) fd.append(k, data[k]);
            
            fetch('exams.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') {
                        UI.success('Éxito', res.message, () => location.reload());
                    } else {
                        UI.error('Error', res.message);
                    }
                })
                .catch(err => UI.error('Error de Conexión'));
        }
    </script>
</body>
</html>
