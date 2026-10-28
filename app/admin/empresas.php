<?php
require_once '../config.php';

// Solo Admin entra aquí
if (!isLoggedIn() || !isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// --- BACKEND: PROCESAMIENTO AJAX (JSON) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Acción no válida'];

    try {
        $action = $_POST['action'];

        if ($action === 'create_company') {
            // 1. CREAR EMPRESA Y USUARIO
            $db->begin_transaction();

            $id = uniqid('emp_'); // Generar ID único
            $nombre = sanitizeInput($_POST['nombre']);
            $nit = sanitizeInput($_POST['nit']);
            $email = sanitizeInput($_POST['email']);
            $tel = sanitizeInput($_POST['telefono']);
            
            // Datos de Usuario
            $user = sanitizeInput($_POST['username']);
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

            // Insertar Empresa
            $stmt = $db->prepare("INSERT INTO empresas (id, nombre, nit, telefono, email, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
            $stmt->bind_param("sssss", $id, $nombre, $nit, $tel, $email);
            if (!$stmt->execute()) throw new Exception("Error al crear empresa: " . $stmt->error);

            // Insertar Usuario
            $stmtUser = $db->prepare("INSERT INTO usuarios (username, email, password_hash, nombre_completo, rol, empresa_id, activo) VALUES (?, ?, ?, ?, 'empresa', ?, 1)");
            $stmtUser->bind_param("sssss", $user, $email, $pass, $nombre, $id);
            if (!$stmtUser->execute()) throw new Exception("Error al crear usuario (El usuario o email ya existen).");

            $db->commit();
            if(function_exists('logActivity')) logActivity('Crear Empresa', 'empresas', "Nueva Empresa: $nombre - NIT: $nit - Usuario: $user");
            $response = ['status' => 'success', 'message' => 'Empresa y usuario creados exitosamente.'];

        } elseif ($action === 'change_status') {
            // 2. CAMBIAR ESTADO (Activar/Desactivar)
            $id = $_POST['id'];
            $newStatus = ($_POST['current_status'] === 'activo') ? 'inactivo' : 'activo';
            $userStatus = ($newStatus === 'activo') ? 1 : 0;

            // Actualizar Empresa
            $stmt = $db->prepare("UPDATE empresas SET estado = ? WHERE id = ?");
            $stmt->bind_param("ss", $newStatus, $id);
            $stmt->execute();

            // Actualizar Usuario asociado
            $stmt = $db->prepare("UPDATE usuarios SET activo = ? WHERE empresa_id = ?");
            $stmt->bind_param("is", $userStatus, $id);
            $stmt->execute();

            if(function_exists('logActivity')) logActivity('Cambio Estado Empresa', 'empresas', "ID: $id - Nuevo Estado: $newStatus");
            $response = ['status' => 'success', 'message' => 'Estado actualizado correctamente.'];

        } elseif ($action === 'change_password') {
            // 3. CAMBIAR CONTRASEÑA
            $id = $_POST['id'];
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE usuarios SET password_hash = ? WHERE empresa_id = ?");
            $stmt->bind_param("ss", $pass, $id);
            if ($stmt->execute()) {
                if(function_exists('logActivity')) logActivity('Cambio Clave Empresa', 'usuarios', "ID Empresa: $id");
                $response = ['status' => 'success', 'message' => 'Contraseña actualizada.'];
            } else {
                throw new Exception("Error al actualizar contraseña.");
            }
        } elseif ($action === 'edit_company') {
             // 4. EDITAR DATOS BÁSICOS
             $id = $_POST['id'];
             $nombre = sanitizeInput($_POST['nombre']);
             $nit = sanitizeInput($_POST['nit']);
             $email = sanitizeInput($_POST['email']);
             $tel = sanitizeInput($_POST['telefono']);

             $stmt = $db->prepare("UPDATE empresas SET nombre=?, nit=?, email=?, telefono=? WHERE id=?");
             $stmt->bind_param("sssss", $nombre, $nit, $email, $tel, $id);
             
             if ($stmt->execute()) {
                 // Actualizar también el nombre en la tabla usuarios para mantener consistencia
                 $stmtU = $db->prepare("UPDATE usuarios SET nombre_completo=? WHERE empresa_id=?");
                 $stmtU->bind_param("ss", $nombre, $id);
                 $stmtU->execute();
                 
                 if(function_exists('logActivity')) logActivity('Editar Empresa', 'empresas', "Editado ID: $id - Nombre: $nombre - NIT: $nit - Email: $email");
                 $response = ['status' => 'success', 'message' => 'Datos actualizados.'];
             } else {
                 throw new Exception("Error al editar: " . $stmt->error);
             }
        }

    } catch (Exception $e) {
        $db->rollback();
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

// --- CONSULTA PARA LA VISTA ---
// Traemos empresas y el username asociado
$query = "
    SELECT e.*, u.username 
    FROM empresas e 
    LEFT JOIN usuarios u ON e.id = u.empresa_id 
    ORDER BY e.nombre ASC
";
$result = $db->query($query);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Empresas | Panel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand mb-0 h1" href="dashboard.php">Administración</a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
            <a href="logs.php" class="btn btn-outline-light btn-sm me-2">Auditoría</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="fw-bold">Gestión de Empresas</h2>
            <button class="btn btn-primary" onclick="modalCrearEmpresa()">
                <i class="bi bi-plus-circle"></i> Nueva Empresa
            </button>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tablaEmpresas" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre / NIT</th>
                                <th>Usuario Sistema</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $result->fetch_assoc()): 
                                $isParticular = ($row['id'] === 'particular');
                                $isSystem = ($row['id'] === 'admin-ips');
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo $row['nombre']; ?></div>
                                    <small class="text-muted">NIT: <?php echo $row['nit'] ?? 'N/A'; ?></small>
                                </td>
                                <td>
                                    <?php if(!$isParticular): ?>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-person"></i> <?php echo $row['username'] ?? 'Sin usuario'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <div><i class="bi bi-envelope"></i> <?php echo $row['email']; ?></div>
                                        <div><i class="bi bi-telephone"></i> <?php echo $row['telefono']; ?></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if($row['estado'] === 'activo'): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="patients.php?filtro_empresa=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Ver Pacientes">
                                            <i class="bi bi-people"></i>
                                        </a>

                                        <?php if(!$isParticular && !$isSystem): ?>
                                            <button class="btn btn-sm btn-outline-secondary" title="Editar Datos"
                                                onclick='modalEditar(<?php echo json_encode($row); ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            
                                            <button class="btn btn-sm btn-outline-warning" title="Cambiar Contraseña"
                                                onclick="modalClave('<?php echo $row['id']; ?>', '<?php echo $row['nombre']; ?>')">
                                                <i class="bi bi-key"></i>
                                            </button>
                                            
                                            <button class="btn btn-sm btn-outline-<?php echo ($row['estado'] === 'activo') ? 'danger' : 'success'; ?>" 
                                                title="<?php echo ($row['estado'] === 'activo') ? 'Desactivar' : 'Activar'; ?>"
                                                onclick="toggleEstado('<?php echo $row['id']; ?>', '<?php echo $row['estado']; ?>')">
                                                <i class="bi bi-power"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
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
            $('#tablaEmpresas').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json" }
            });
        });

        // 1. MODAL CREAR EMPRESA
        function modalCrearEmpresa() {
            const html = `
                <div class="text-start row g-2">
                    <div class="col-12"><h6 class="text-primary border-bottom pb-2">Datos de la Empresa</h6></div>
                    <div class="col-6"><label class="small fw-bold">Nombre Empresa</label><input id="c_nombre" class="form-control"></div>
                    <div class="col-6"><label class="small fw-bold">NIT</label><input id="c_nit" class="form-control"></div>
                    <div class="col-6"><label class="small fw-bold">Email</label><input id="c_email" type="email" class="form-control"></div>
                    <div class="col-6"><label class="small fw-bold">Teléfono</label><input id="c_tel" class="form-control"></div>
                    
                    <div class="col-12 mt-3"><h6 class="text-primary border-bottom pb-2">Datos de Acceso (Usuario)</h6></div>
                    <div class="col-6"><label class="small fw-bold">Usuario Login</label><input id="c_user" class="form-control" placeholder="Ej: empresa1"></div>
                    <div class="col-6"><label class="small fw-bold">Contraseña</label><input id="c_pass" type="password" class="form-control"></div>
                </div>
            `;

            UI.modalForm({
                title: 'Registrar Nueva Empresa',
                html: html,
                confirmText: 'Crear Empresa',
                preConfirmFn: () => {
                    const nombre = document.getElementById('c_nombre').value;
                    const user = document.getElementById('c_user').value;
                    const pass = document.getElementById('c_pass').value;

                    if(!nombre || !user || !pass) {
                        Swal.showValidationMessage('Nombre, Usuario y Contraseña son obligatorios');
                        return false;
                    }

                    return {
                        action: 'create_company',
                        nombre: nombre,
                        nit: document.getElementById('c_nit').value,
                        email: document.getElementById('c_email').value,
                        telefono: document.getElementById('c_tel').value,
                        username: user,
                        password: pass
                    };
                },
                callback: (data) => enviarDatos(data)
            });
        }

        // 2. MODAL EDITAR EMPRESA
        function modalEditar(empresa) {
            const html = `
                <div class="text-start row g-2">
                    <input type="hidden" id="e_id" value="${empresa.id}">
                    <div class="col-12"><label class="small fw-bold">Nombre Empresa</label><input id="e_nombre" class="form-control" value="${empresa.nombre}"></div>
                    <div class="col-6"><label class="small fw-bold">NIT</label><input id="e_nit" class="form-control" value="${empresa.nit || ''}"></div>
                    <div class="col-6"><label class="small fw-bold">Teléfono</label><input id="e_tel" class="form-control" value="${empresa.telefono || ''}"></div>
                    <div class="col-12"><label class="small fw-bold">Email</label><input id="e_email" class="form-control" value="${empresa.email || ''}"></div>
                </div>
            `;
            
            UI.modalForm({
                title: 'Editar Empresa',
                html: html,
                confirmText: 'Guardar Cambios',
                preConfirmFn: () => {
                     return {
                        action: 'edit_company',
                        id: document.getElementById('e_id').value,
                        nombre: document.getElementById('e_nombre').value,
                        nit: document.getElementById('e_nit').value,
                        telefono: document.getElementById('e_tel').value,
                        email: document.getElementById('e_email').value
                     };
                },
                callback: (data) => enviarDatos(data)
            });
        }

        // 3. CAMBIAR CONTRASEÑA
        function modalClave(id, nombre) {
            const html = `
                <div class="text-start">
                    <p class="small">Nueva contraseña para: <strong>${nombre}</strong></p>
                    <input id="new_pass" type="password" class="form-control" placeholder="Escriba la nueva clave">
                </div>
            `;

            UI.modalForm({
                title: 'Cambiar Contraseña',
                html: html,
                confirmText: 'Actualizar',
                width: '400px',
                preConfirmFn: () => {
                    const pass = document.getElementById('new_pass').value;
                    if(!pass) {
                        Swal.showValidationMessage('Escriba una contraseña');
                        return false;
                    }
                    return { action: 'change_password', id: id, password: pass };
                },
                callback: (data) => enviarDatos(data)
            });
        }

        // 4. CAMBIAR ESTADO
        function toggleEstado(id, estadoActual) {
            const accion = estadoActual === 'activo' ? 'Desactivar' : 'Activar';
            UI.confirm(
                `¿${accion} Empresa?`,
                `Esto también ${accion.toLowerCase()}á el acceso al sistema para el usuario asociado.`,
                'Sí, cambiar',
                () => {
                    enviarDatos({ action: 'change_status', id: id, current_status: estadoActual });
                }
            );
        }

        // FUNCIÓN GENÉRICA DE ENVÍO
        function enviarDatos(data) {
            UI.loading('Procesando...');
            const formData = new FormData();
            for(let key in data) formData.append(key, data[key]);

            fetch('empresas.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        UI.success('Éxito', res.message, () => location.reload());
                    } else {
                        UI.error('Error', res.message);
                    }
                })
                .catch(err => UI.error('Error', 'Fallo de conexión'));
        }
    </script>
</body>
</html>