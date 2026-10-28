<?php
// public_html/admin/manage_specialists.php
require_once '../config.php';

if (!isLoggedIn() || (!isAdmin() && !isDesarrollador())) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();
$message = '';
$error = '';

// --- ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

    if ($action === 'create_update') {
        $id = (int)($_POST['user_id'] ?? 0);
        $username = sanitizeInput($_POST['username']);
        $fullname = sanitizeInput($_POST['nombre_completo']);
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $allowedExams = $_POST['exams'] ?? []; // Array of IDs

        // Validation
        if (empty($username) || empty($fullname) || empty($email)) {
            $error = "Usuario, nombre y email son obligatorios.";
        } else {
            $allowedJson = json_encode(array_map('intval', $allowedExams));
            $passHash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

            if ($id > 0) {
                // UPDATE
                $sql = "UPDATE usuarios SET username=?, nombre_completo=?, email=?, allowed_exam_ids=? WHERE id=?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param("ssssi", $username, $fullname, $email, $allowedJson, $id);
                if ($stmt->execute()) {
                    if ($passHash) {
                        $db->query("UPDATE usuarios SET password_hash='$passHash' WHERE id=$id");
                    }
                    $message = "Especialista actualizado.";
                } else {
                    throw new Exception("Error al actualizar: " . $stmt->error);
                }
            } else {
                // CREATE
                if (empty($password)) {
                    $error = "La contraseña es obligatoria para nuevos usuarios.";
                } else {
                    // Check duplicate username OR email
                    $stmtCheck = $db->prepare("SELECT id FROM usuarios WHERE username=? OR email=?");
                    $stmtCheck->bind_param("ss", $username, $email);
                    $stmtCheck->execute();
                    $check = $stmtCheck->get_result();

                    if ($check->num_rows > 0) {
                        $error = "El usuario o email ya existe.";
                    } else {
                        $sql = "INSERT INTO usuarios (username, password_hash, nombre_completo, email, rol, activo, allowed_exam_ids) 
                                VALUES (?, ?, ?, ?, 'profesional', 1, ?)";
                        $stmt = $db->prepare($sql);
                        if(!$stmt) throw new Exception("Prepare failed: " . $db->error);
                        
                        $stmt->bind_param("sssss", $username, $passHash, $fullname, $email, $allowedJson);
                        if ($stmt->execute()) {
                            $message = "Especialista creado exitosamente.";
                        } else {
                            throw new Exception("Error al crear: " . $stmt->error);
                        }
                    }
                }
            }
        }
    }
    
    if ($action === 'toggle_status') {
        $id = (int)$_POST['user_id'];
        $curr = (int)$_POST['current_status'];
        $new = $curr ? 0 : 1;
        $db->query("UPDATE usuarios SET activo=$new WHERE id=$id");
        exit(json_encode(['status'=>'success']));
    }

    } catch (Exception $e) {
        $error = "Excepción: " . $e->getMessage();
    }
}

// --- DATA ---
// 1. All Specialists
$resUsers = $db->query("SELECT * FROM usuarios WHERE rol='profesional' ORDER BY nombre_completo ASC");
$specialists = $resUsers->fetch_all(MYSQLI_ASSOC);

// 2. All Exams (for assignment)
$resExams = $db->query("SELECT id, name FROM exams WHERE is_active=1 ORDER BY name ASC");
$allExams = $resExams->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión Especialistas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-person-badge"></i> Gestión de Especialistas</h3>
            <div>
                <button class="btn btn-success me-2" onclick="openModal()"><i class="bi bi-plus-lg"></i> Nuevo Especialista</button>
                <a href="dashboard.php" class="btn btn-outline-secondary">Volver</a>
            </div>
        </div>

        <?php if($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <table class="table table-hover" id="tableSpecs">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>Estado</th>
                            <th>Exámenes</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($specialists as $s): 
                            $allowed = json_decode($s['allowed_exam_ids'] ?? '[]', true);
                            $tags = count($allowed) . " Asignados";
                        ?>
                        <tr>
                            <td><?php echo $s['nombre_completo']; ?></td>
                            <td><?php echo $s['username']; ?></td>
                            <td><?php echo $s['email']; ?></td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" <?php echo $s['activo'] ? 'checked':''; ?> onchange="toggleStatus(<?php echo $s['id']; ?>, <?php echo $s['activo']; ?>)">
                                </div>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo $tags; ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-info text-white" 
                                        onclick='editUser(<?php echo json_encode($s); ?>)'>
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Hidden Template for Exams Checkboxes -->
    <div id="examsTemplate" class="d-none">
        <label class="form-label fw-bold mt-2 text-start d-block">Exámenes Autorizados:</label>
        <div class="border rounded p-2 text-start" style="max-height: 200px; overflow-y: auto;">
            <?php foreach($allExams as $e): ?>
            <div class="form-check">
                <input class="form-check-input swal-exam-check" type="checkbox" value="<?php echo $e['id']; ?>" id="swal_ex_<?php echo $e['id']; ?>">
                <label class="form-check-label small" for="swal_ex_<?php echo $e['id']; ?>">
                    <?php echo $e['name']; ?>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function(){ $('#tableSpecs').DataTable(); });

        function toggleStatus(id, curr) {
            $.post('manage_specialists.php', { action: 'toggle_status', user_id: id, current_status: curr });
        }

        function openModal(user = null) {
            const isEdit = !!user;
            const title = isEdit ? 'Editar Especialista' : 'Nuevo Especialista';
            const examsHtml = document.getElementById('examsTemplate').innerHTML;

            Swal.fire({
                title: title,
                html: `
                    <div class="text-start">
                        <label>Nombre Completo</label>
                        <input id="swal_nombre" class="form-control mb-2" value="${user ? user.nombre_completo : ''}">
                        
                        <label>Usuario</label>
                        <input id="swal_username" class="form-control mb-2" value="${user ? user.username : ''}">
                        
                        <label>Email</label>
                        <input id="swal_email" class="form-control mb-2" value="${user ? user.email || '' : ''}">
                        
                        <label>Contraseña ${isEdit ? '<small class="text-muted">(Dejar vacío para mantener)</small>' : ''}</label>
                        <input id="swal_password" type="password" class="form-control mb-2">
                        
                        ${examsHtml}
                    </div>
                `,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: 'Guardar',
                cancelButtonText: 'Cancelar',
                didOpen: () => {
                    // Pre-check exams if edit
                    if(user && user.allowed_exam_ids) {
                        const allowed = JSON.parse(user.allowed_exam_ids);
                        allowed.forEach(id => {
                            const el = Swal.getPopup().querySelector('#swal_ex_' + id);
                            if(el) el.checked = true;
                        });
                    }
                },
                preConfirm: () => {
                    const nombre = Swal.getPopup().querySelector('#swal_nombre').value;
                    const username = Swal.getPopup().querySelector('#swal_username').value;
                    const email = Swal.getPopup().querySelector('#swal_email').value;
                    const password = Swal.getPopup().querySelector('#swal_password').value;
                    
                    // Get checked exams
                    const exams = [];
                    Swal.getPopup().querySelectorAll('.swal-exam-check:checked').forEach(c => exams.push(c.value));

                    if (!nombre || !username || !email) {
                        Swal.showValidationMessage('Todos los campos marcados son obligatorios');
                        return false;
                    }
                    if (!isEdit && !password) {
                        Swal.showValidationMessage('La contraseña es obligatoria para nuevos usuarios');
                        return false;
                    }

                    return { nombre, username, email, password, exams };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Submit via hidden form or Ajax? Let's use form submission to keep existing PHP logic working easily
                    // We need to create a form and submit it
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'manage_specialists.php';

                    const addField = (name, val) => {
                        const i = document.createElement('input');
                        i.type = 'hidden';
                        i.name = name;
                        i.value = val;
                        form.appendChild(i);
                    };

                    addField('action', 'create_update');
                    addField('user_id', isEdit ? user.id : 0);
                    addField('nombre_completo', result.value.nombre);
                    addField('username', result.value.username);
                    addField('email', result.value.email);
                    addField('password', result.value.password);
                    
                    result.value.exams.forEach(id => addField('exams[]', id));

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function editUser(user) {
            openModal(user);
        }
    </script>
</body>
</html>
