<?php
// admin/consent_templates.php
require_once '../config.php';

// Solo Admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();
$siteName = getSetting('site_name', 'Sistema');
$logoUrl = getSetting('logo_url');
if($logoUrl) $logoUrl = '../'.$logoUrl;

// Ensure type column exists if not already present (failsafe)
$db->query("ALTER TABLE consent_types ADD COLUMN IF NOT EXISTS type ENUM('consentimiento', 'declaracion') NOT NULL DEFAULT 'consentimiento'");

// --- ACCIONES BACKEND ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Acción desconocida'];

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'edit') {
            $name = sanitizeInput($_POST['name']);
            $content = $_POST['content']; // HTML permitido, no sanitizeInput
            $type = $_POST['type'] ?? 'consentimiento';
            $isActive = (int)($_POST['is_active'] ?? 0);
            
            if (empty($name) || empty($content)) throw new Exception("Nombre y contenido son obligatorios.");

            if ($action === 'create') {
                $stmt = $db->prepare("INSERT INTO consent_types (name, content, type, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $name, $content, $type, $isActive);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Plantilla creada exitosamente.'];
                    if(function_exists('logActivity')) logActivity('Crear Plantilla', 'consent_types', "Nombre: $name - Tipo: $type");
                } else throw new Exception("Error BD: " . $stmt->error);
            } else {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("UPDATE consent_types SET name = ?, content = ?, type = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("sssii", $name, $content, $type, $isActive, $id);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Plantilla actualizada.'];
                    if(function_exists('logActivity')) logActivity('Editar Plantilla', 'consent_types', "ID: $id - Tipo: $type");
                } else throw new Exception("Error BD: " . $stmt->error);
            }
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            // Validar si hay consentimientos usando esta plantilla? (Opcional, por ahora permitimos borrar pero cuidado)
            // Mejor soft delete o simplemente borrar.
            
            $stmt = $db->prepare("DELETE FROM consent_types WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Plantilla eliminada.'];
                if(function_exists('logActivity')) logActivity('Eliminar Plantilla', 'consent_types', "ID: $id");
            } else throw new Exception("Error al eliminar.");
        } elseif ($action === 'get') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("SELECT * FROM consent_types WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $response = ['status' => 'success', 'data' => $row];
            } else throw new Exception("No encontrado.");
        }

    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

// --- CONSULTA LISTADO ---
$templates = $db->query("SELECT * FROM consent_types ORDER BY is_active DESC, name ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Plantillas | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .variable-tag { cursor: pointer; user-select: none; font-size: 0.85em; }
        .variable-tag:hover { opacity: 0.8; }
    </style>
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
            <a href="logs.php" class="btn btn-outline-light btn-sm me-2">Auditoría</a>
            <a href="change_password.php" class="btn btn-outline-light btn-sm me-2">Contraseña</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold"><i class="bi bi-file-text me-2"></i> Plantillas</h2>
            <button class="btn btn-primary" onclick="openModal()">
                <i class="bi bi-plus-lg"></i> Nueva Plantilla
            </button>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                                <th>Fecha Creación</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $templates->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?php echo $row['name']; ?></td>
                                <td>
                                    <?php if($row['type'] === 'declaracion'): ?>
                                        <span class="badge bg-info text-dark">Declaración</span>
                                    <?php elseif($row['type'] === 'desistimiento'): ?>
                                        <span class="badge bg-danger">Desistimiento</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Consentimiento</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($row['is_active']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?php echo $row['created_at']; ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editTemplate(<?php echo $row['id']; ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(<?php echo $row['id']; ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Script UI Manager & Logic -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/ui-manager.js"></script>
    
    <script>
        function openModal(id = null) {
            let title = 'Nueva Plantilla';
            let confirmBtn = 'Crear Plantilla';
            let initialData = { name: '', content: '', is_active: 1 };
            
            // Si es edición, cargamos datos primero
            if(id) {
                // Fetch datos y abrir
                const formData = new FormData();
                formData.append('action', 'get');
                formData.append('id', id);
                
                fetch('consent_templates.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') {
                        showForm(id, res.data);
                    } else UI.error(res.message);
                });
                return;
            }
            showForm(null, initialData);
        }

        function showForm(id, data) {
            const html = `
                <div class="text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Procedimiento</label>
                        <input id="tpl_name" class="form-control" value="${data.name}" placeholder="Ej: Cirugía General">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tipo de Plantilla</label>
                        <select id="tpl_type" class="form-select">
                            <option value="consentimiento" ${data.type === 'consentimiento' ? 'selected' : ''}>Consentimiento Informado</option>
                            <option value="declaracion" ${data.type === 'declaracion' ? 'selected' : ''}>Declaración Juramentada</option>
                            <option value="desistimiento" ${data.type === 'desistimiento' ? 'selected' : ''}>Desistimiento</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Texto Legal</label>
                        <div class="mb-2">
                            <span class="badge bg-secondary variable-tag me-1" onclick="insertVar('{{FULL_NAME}}')">{{FULL_NAME}}</span>
                            <span class="badge bg-secondary variable-tag me-1" onclick="insertVar('{{DOCUMENT_TYPE}}')">{{DOCUMENT_TYPE}}</span>
                            <span class="badge bg-secondary variable-tag me-1" onclick="insertVar('{{DOCUMENT_NUMBER}}')">{{DOCUMENT_NUMBER}}</span>
                        </div>
                        <textarea id="tpl_content" class="form-control" rows="10" style="font-family: monospace;">${data.content}</textarea>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="tpl_active" ${data.is_active == 1 ? 'checked' : ''}>
                        <label class="form-check-label" for="tpl_active">Plantilla Activa</label>
                    </div>
                </div>
            `;

            UI.modalForm({
                title: id ? 'Editar Plantilla' : 'Nueva Plantilla',
                html: html,
                confirmText: id ? 'Guardar Cambios' : 'Crear',
                preConfirmFn: () => {
                    const name = document.getElementById('tpl_name').value;
                    const content = document.getElementById('tpl_content').value;
                    const type = document.getElementById('tpl_type').value;
                    const isActive = document.getElementById('tpl_active').checked ? 1 : 0;
                    
                    if(!name || !content) {
                        Swal.showValidationMessage('Complete todos los campos');
                        return false;
                    }

                    return {
                        action: id ? 'edit' : 'create',
                        id: id,
                        name: name,
                        content: content,
                        type: type,
                        is_active: isActive
                    };
                },
                callback: (dataToSend) => {
                    const fd = new FormData();
                    for(let k in dataToSend) fd.append(k, dataToSend[k]);

                    fetch('consent_templates.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if(res.status === 'success') {
                                UI.success('Guardado', res.message, () => location.reload());
                            } else {
                                UI.error('Error', res.message);
                            }
                        })
                        .catch(err => UI.error('Error', err));
                }
            });
        }
        
        // Helper para insertar variables
        window.insertVar = function(text) {
             const textarea = document.getElementById('tpl_content');
             if(!textarea) return;
             const start = textarea.selectionStart;
             const end = textarea.selectionEnd;
             const value = textarea.value;
             textarea.value = value.substring(0, start) + text + value.substring(end);
             textarea.selectionStart = textarea.selectionEnd = start + text.length;
             textarea.focus();
        };

        function editTemplate(id) {
            openModal(id);
        }

        function deleteTemplate(id) {
            Swal.fire({
                title: '¿Eliminar plantilla?',
                text: "No se podrá recuperar.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Sí, eliminar'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);
                    fetch('consent_templates.php', { method: 'POST', body: fd })
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
