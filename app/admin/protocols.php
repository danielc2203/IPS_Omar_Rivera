<?php
// public_html/admin/protocols.php
require_once '../config.php';

if (!isLoggedIn() || !isDesarrollador()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// --- AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    try {
        if ($_POST['action'] === 'save_protocol') {
            $name = sanitizeInput($_POST['name']);
            $desc = sanitizeInput($_POST['description']);
            $companyId = !empty($_POST['company_id']) ? $_POST['company_id'] : NULL;
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

            if ($id > 0) {
                // Update
                $stmt = $db->prepare("UPDATE protocols SET name=?, description=?, company_id=? WHERE id=?");
                $stmt->bind_param("sssi", $name, $desc, $companyId, $id);
                if($stmt->execute()) echo json_encode(['status'=>'success']);
                else throw new Exception($stmt->error);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO protocols (name, description, company_id) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $desc, $companyId);
                if($stmt->execute()) echo json_encode(['status'=>'success']);
                else throw new Exception($stmt->error);
            }

        } elseif ($_POST['action'] === 'delete_protocol') {
            $id = (int)$_POST['id'];
            $db->query("DELETE FROM protocols WHERE id=$id");
            echo json_encode(['status'=>'success']);

        } elseif ($_POST['action'] === 'get_items') {
            $protoId = (int)$_POST['protocol_id'];
            
            // Items asignados
            $sql = "SELECT pi.id, e.name, e.cost 
                    FROM protocol_items pi 
                    JOIN exams e ON pi.exam_id = e.id 
                    WHERE pi.protocol_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $protoId);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Examenes disponibles (todos)
            $resEx = $db->query("SELECT id, name FROM exams WHERE is_active=1 ORDER BY name ASC");
            $allExams = $resEx->fetch_all(MYSQLI_ASSOC);

            echo json_encode(['status'=>'success', 'items'=>$items, 'all_exams'=>$allExams]);

        } elseif ($_POST['action'] === 'add_item') {
            $protoId = (int)$_POST['protocol_id'];
            $examId = (int)$_POST['exam_id'];
            
            $stmt = $db->prepare("INSERT INTO protocol_items (protocol_id, exam_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $protoId, $examId);
            if($stmt->execute()) echo json_encode(['status'=>'success']);
            else throw new Exception($stmt->error);

        } elseif ($_POST['action'] === 'remove_item') {
            $itemId = (int)$_POST['item_id'];
            $db->query("DELETE FROM protocol_items WHERE id=$itemId");
            echo json_encode(['status'=>'success']);
        }

    } catch (Exception $e) {
        echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
    }
    exit();
}

// Vista
$res = $db->query("
    SELECT p.*, e.nombre as company_name 
    FROM protocols p
    LEFT JOIN empresas e ON p.company_id = e.id
    ORDER BY p.name ASC
");
$protocols = $res->fetch_all(MYSQLI_ASSOC);

// Empresas para dropdown
$resEmp = $db->query("SELECT id, nombre FROM empresas WHERE estado='activo' ORDER BY nombre ASC");
$companies = $resEmp->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Protocolos (Paquetes)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand" href="dashboard.php">Panel Desarrollador</a>
        <div><a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a></div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><i class="bi bi-collection"></i> Protocolos / Paquetes</h3>
            <button class="btn btn-primary" onclick="modalProtocol()"><i class="bi bi-plus-lg"></i> Nuevo Protocolo</button>
        </div>

        <div class="row">
            <?php foreach($protocols as $p): ?>
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong><?php echo $p['name']; ?></strong>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-link text-dark" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick='modalProtocol(<?php echo json_encode($p); ?>)'>Editar Info</a></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteProto(<?php echo $p['id']; ?>)">Eliminar</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if($p['company_name']): ?>
                            <span class="badge bg-info text-dark mb-2">Empresa: <?php echo $p['company_name']; ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary mb-2">Global</span>
                        <?php endif; ?>
                        
                        <p class="small text-muted mb-2"><?php echo $p['description'] ?: 'Sin descripción'; ?></p>
                        
                        <hr class="my-2">
                        <button class="btn btn-sm btn-outline-primary w-100" onclick="manageItems(<?php echo $p['id']; ?>, '<?php echo $p['name']; ?>')">
                            <i class="bi bi-list-check"></i> Gestionar Exámenes
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- GLOBALS FOR JS -->
    <script>
        const COMPANIES = <?php echo json_encode($companies); ?>;
    </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/ui-manager.js"></script>

    <script>
        // 1. CREAR / EDITAR PROTOCOLO
        function modalProtocol(data = null) {
            const isEdit = !!data;
            let options = '<option value="">-- Global (Todas) --</option>';
            COMPANIES.forEach(c => {
                const sel = (isEdit && data.company_id == c.id) ? 'selected' : '';
                options += `<option value="${c.id}" ${sel}>${c.nombre}</option>`;
            });

            const html = `
                <div class="text-start">
                    <input type="hidden" id="p_id" value="${isEdit ? data.id : 0}">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Protocolo</label>
                        <input id="p_name" class="form-control" value="${isEdit ? data.name : ''}" placeholder="Ej: Ingreso Celador">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Empresa (Opcional)</label>
                        <select id="p_comp" class="form-select">${options}</select>
                        <div class="form-text">Si selecciona una empresa, solo aparecerá para pacientes de esa empresa.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción</label>
                        <textarea id="p_desc" class="form-control" rows="2">${isEdit ? data.description : ''}</textarea>
                    </div>
                </div>
            `;

            UI.modalForm({
                title: isEdit ? 'Editar Protocolo' : 'Nuevo Protocolo',
                html: html,
                confirmText: 'Guardar',
                preConfirmFn: () => {
                    const name = document.getElementById('p_name').value;
                    if(!name) {Swal.showValidationMessage('Nombre requerido'); return false;}
                    return {
                        action: 'save_protocol',
                        id: document.getElementById('p_id').value,
                        name: name,
                        company_id: document.getElementById('p_comp').value,
                        description: document.getElementById('p_desc').value
                    };
                },
                callback: (d) => postAction(d)
            });
        }

        // 2. ITEMS DEL PROTOCOLO
        function manageItems(protoId, protoName) {
            UI.loading('Cargando items...');
            const fd = new FormData();
            fd.append('action', 'get_items');
            fd.append('protocol_id', protoId);

            fetch('protocols.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    Swal.close();
                    if(res.status !== 'success') { UI.error('Error', res.message); return; }
                    
                    showItemsModal(protoId, protoName, res.items, res.all_exams);
                });
        }

        function showItemsModal(protoId, protoName, items, allExams) {
            let listHtml = '<ul class="list-group mb-3">';
            if(items.length === 0) listHtml += '<li class="list-group-item text-muted text-center small">Vacío</li>';
            
            items.forEach(i => {
                listHtml += `
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        ${i.name}
                        <button class="btn btn-sm btn-outline-danger border-0" onclick="removeItem(${i.id}, ${protoId}, '${protoName}')"><i class="bi bi-x-lg"></i></button>
                    </li>
                `;
            });
            listHtml += '</ul>';

            let opts = '<option value="">Agregar examen...</option>';
            allExams.forEach(e => {
                // Filtrar si ya está añadido (opcional, por simplicidad mostramos todos)
                opts += `<option value="${e.id}">${e.name}</option>`;
            });

            const html = `
                <div class="text-start">
                    ${listHtml}
                    <div class="input-group">
                        <select id="new_item_sel" class="form-select">${opts}</select>
                        <button class="btn btn-success" onclick="addItem(${protoId}, '${protoName}')"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
            `;

            Swal.fire({
                title: `Items: ${protoName}`,
                html: html,
                showConfirmButton: false, 
                showCloseButton: true
            });
        }

        function addItem(protoId, protoName) {
            const exId = document.getElementById('new_item_sel').value;
            if(!exId) return;
            
            // Close current swal to avoid conflict (or keep open if careful, simpler to reload)
            // But UI manager closes on new calls usually.
            
            postAction({ action: 'add_item', protocol_id: protoId, exam_id: exId }, () => manageItems(protoId, protoName), true);
        }

        function removeItem(itemId, protoId, protoName) {
            postAction({ action: 'remove_item', item_id: itemId }, () => manageItems(protoId, protoName), true);
        }

        function deleteProto(id) {
            UI.confirm('¿Eliminar Protocolo?', 'Se perderá la configuración de items.', 'Sí, eliminar', () => {
                postAction({ action: 'delete_protocol', id: id });
            });
        }

        function postAction(data, cb = null, skipSuccessMsg = false) {
            const fd = new FormData();
            for(let k in data) fd.append(k, data[k]);
            
            fetch('protocols.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') {
                        if(cb) cb();
                        else if (!skipSuccessMsg) UI.success('Éxito', 'Operación realizada', () => location.reload());
                    } else {
                        UI.error('Error', res.message);
                    }
                });
        }
    </script>
</body>
</html>
