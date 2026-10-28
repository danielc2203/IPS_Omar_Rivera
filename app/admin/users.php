<?php
// admin/users.php
require_once '../config.php';

// Solo admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();
$siteName = getSetting('site_name', 'Sistema');
$logoUrl = getSetting('logo_url');
if($logoUrl) $logoUrl = '../'.$logoUrl;

// --- ACCIONES POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Acción desconocida'];

    try {
        $action = $_POST['action'] ?? '';

        // CREATE / EDIT
        if ($action === 'create' || $action === 'edit') {
            $username = sanitizeInput($_POST['username']);
            $fullName = sanitizeInput($_POST['nombre_completo']);
            $email = sanitizeInput($_POST['email']);
            $role = sanitizeInput($_POST['rol']);
            $password = $_POST['password'] ?? '';
            $active = (int)($_POST['activo'] ?? 0);
            
            if(empty($username) || empty($fullName) || empty($role)) throw new Exception("Campos obligatorios faltantes.");

            if ($action === 'create') {
                if(empty($password)) throw new Exception("La contraseña es obligatoria para nuevos usuarios.");
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Verificar duplicados
                $chk = $db->prepare("SELECT id FROM usuarios WHERE username = ? OR email = ?");
                $chk->bind_param("ss", $username, $email);
                $chk->execute();
                if($chk->get_result()->num_rows > 0) throw new Exception("Usuario o Email ya existe.");

                $stmt = $db->prepare("INSERT INTO usuarios (username, nombre_completo, email, password_hash, rol, activo) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssi", $username, $fullName, $email, $hash, $role, $active);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Usuario creado exitosamente.'];
                    if(function_exists('logActivity')) logActivity('Crear Usuario', 'usuarios', "Nuevo Usuario: $username ($fullName) - Rol: $role");
                } else throw new Exception("Error BD: " . $stmt->error);
            } 
            else { // EDIT
                $id = (int)$_POST['id'];
                
                // Si password no está vacío, actualizarlo
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE usuarios SET username=?, nombre_completo=?, email=?, password_hash=?, rol=?, activo=? WHERE id=?");
                    $stmt->bind_param("sssssii", $username, $fullName, $email, $hash, $role, $active, $id);
                } else {
                    $stmt = $db->prepare("UPDATE usuarios SET username=?, nombre_completo=?, email=?, rol=?, activo=? WHERE id=?");
                    $stmt->bind_param("ssssii", $username, $fullName, $email, $role, $active, $id);
                }
                
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Usuario actualizado.'];
                    if(function_exists('logActivity')) logActivity('Editar Usuario', 'usuarios', "Editado ID: $id - Usuario: $username ($fullName) - Rol: $role; Status: " . ($active ? 'Activo' : 'Inactivo'));
                } else throw new Exception("Error BD: " . $stmt->error);
            }
        } 
        // DELETE
        elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            if($id == getUserId()) throw new Exception("No puedes eliminar tu propia cuenta.");
            
            $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Usuario eliminado.'];
                if(function_exists('logActivity')) logActivity('Eliminar Usuario', 'usuarios', "ID Eliminado: $id");
            } else throw new Exception("Error al eliminar.");
        }
        // GET
        elseif ($action === 'get') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("SELECT id, username, nombre_completo, email, rol, activo FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $response = ['status' => 'success', 'data' => $row];
            } else throw new Exception("No encontrado.");
        }

    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }
    echo json_encode($response);
    exit;
}

// LISTADO
$users = $db->query("SELECT * FROM usuarios WHERE rol != 'empresa' ORDER BY username ASC"); // Excluimos empresas externas, esas se manejan en 'Empresas' (o no?)
// Nota: Originalmente 'empresa' parece ser un rol de usuario también. Si queremos gestionarlos todos aquí, quitamos el WHERE rol != 'empresa'. 
// Pero el usuario pidió gestionar "Roles del personal". Asumo que las empresas tienen su propio módulo (register_company.php parece gestionar EMPRESAS tabla 'empresas', pero ¿tienen usuario de acceso?)
// Revisando auth, login busca en 'usuarios'.
// Voy a mostrar TODOS los usuarios aquí para tener control total.
$users = $db->query("SELECT * FROM usuarios ORDER BY rol, username ASC");

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Usuarios | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand mb-0 h1 d-flex align-items-center" href="dashboard.php">
            <?php if($logoUrl && file_exists($logoUrl)): ?>
                <img src="<?php echo $logoUrl; ?>" alt="Logo" height="30" class="d-inline-block align-text-top me-2 bg-white rounded px-1">
            <?php endif; ?>
            <span>Administración</span>
        </a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold"><i class="bi bi-people-fill me-2"></i> Gestión de Usuarios</h2>
            <button class="btn btn-primary" onclick="openModal()">
                <i class="bi bi-person-plus-fill"></i> Nuevo Usuario
            </button>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $users->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?php echo $row['username']; ?></td>
                                <td><?php echo $row['nombre_completo']; ?> <br> <small class="text-muted"><?php echo $row['email']; ?></small></td>
                                <td>
                                    <?php 
                                        $badge = 'secondary';
                                        if($row['rol'] === 'admin') $badge = 'dark';
                                        if($row['rol'] === 'recepcionista') $badge = 'info';
                                        if($row['rol'] === 'profesional') $badge = 'success';
                                        if($row['rol'] === 'auditor') $badge = 'warning';
                                    ?>
                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst($row['rol']); ?></span>
                                </td>
                                <td>
                                    <?php if($row['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editUser(<?php echo $row['id']; ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if($row['id'] != getUserId()): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?php echo $row['id']; ?>)">
                                        <i class="bi bi-trash"></i>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/ui-manager.js"></script>

    <script>
        function openModal(id = null) {
            let initialData = { username: '', nombre_completo: '', email: '', rol: 'recepcionista', activo: 1 };
            
            if(id) {
                const fd = new FormData();
                fd.append('action', 'get');
                fd.append('id', id);
                fetch('users.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') showForm(id, res.data);
                    else UI.error(res.message);
                });
            } else {
                showForm(null, initialData);
            }
        }

        function showForm(id, data) {
            const isEdit = !!id;
            const html = `
                <div class="text-start">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Usuario</label>
                            <input id="u_username" class="form-control" value="${data.username}">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Rol</label>
                            <select id="u_rol" class="form-select">
                                <option value="admin" ${data.rol === 'admin' ? 'selected' : ''}>Administrador</option>
                                <option value="recepcionista" ${data.rol === 'recepcionista' ? 'selected' : ''}>Recepcionista</option>
                                <option value="profesional" ${data.rol === 'profesional' ? 'selected' : ''}>Profesional Salud</option>
                                <option value="auditor" ${data.rol === 'auditor' ? 'selected' : ''}>Auditor</option>
                                <option value="empresa" ${data.rol === 'empresa' ? 'selected' : ''}>Empresa Externa</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre Completo</label>
                        <input id="u_fullname" class="form-control" value="${data.nombre_completo}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email</label>
                        <input id="u_email" type="email" class="form-control" value="${data.email}">
                    </div>
                     <div class="mb-3">
                        <label class="form-label fw-bold">${isEdit ? 'Nueva Contraseña (Opcional)' : 'Contraseña'}</label>
                        <input id="u_pass" type="password" class="form-control" placeholder="${isEdit ? 'Dejar en blanco para no cambiar' : ''}">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="u_active" ${data.activo == 1 ? 'checked' : ''}>
                        <label class="form-check-label" for="u_active">Usuario Activo</label>
                    </div>
                </div>
            `;

            UI.modalForm({
                title: isEdit ? 'Editar Usuario' : 'Nuevo Usuario',
                html: html,
                confirmText: isEdit ? 'Actualizar' : 'Crear',
                preConfirmFn: () => {
                    const user = document.getElementById('u_username').value;
                    const pass = document.getElementById('u_pass').value;
                    
                    if(!isEdit && !pass) {
                        Swal.showValidationMessage('La contraseña es obligatoria para nuevos usuarios');
                        return false;
                    }
                    
                    const dataToSend = {
                        action: isEdit ? 'edit' : 'create',
                        id: id,
                        username: user,
                        nombre_completo: document.getElementById('u_fullname').value,
                        email: document.getElementById('u_email').value,
                        rol: document.getElementById('u_rol').value,
                        password: pass,
                        activo: document.getElementById('u_active').checked ? 1 : 0
                    };
                    return dataToSend;
                },
                callback: (data) => {
                    const fd = new FormData();
                    for(let k in data) fd.append(k, data[k]);
                    
                    fetch('users.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if(res.status === 'success') UI.success('Guardado', res.message, () => location.reload());
                            else UI.error('Error', res.message);
                        });
                }
            });
        }

        function editUser(id) { openModal(id); }
        
        function deleteUser(id) {
             Swal.fire({
                title: '¿Eliminar usuario?',
                text: "Esta acción es irreversible.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Sí, eliminar'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);
                    fetch('users.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                             if(res.status === 'success') {
                                UI.success('Eliminado', res.message, () => location.reload());
                            } else {
                                UI.error('Error', res.message);
                            }
                        });
                }
            });
        }
    </script>
</body>
</html>
