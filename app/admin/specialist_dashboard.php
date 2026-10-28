<?php
// public_html/admin/specialist_dashboard.php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDBConnection();
$userId = getUserId();

// Determine allowed exams for this user
// If Admin or Developer, they can see ALL (or maybe we force them to use manage view? 
// For now, let's assume Admin/Dev sees EVERYTHING in queue for debugging)
$allowedIds = [];
$isSuper = isAdmin() || isDesarrollador();

if (!$isSuper) {
    $resUser = $db->query("SELECT allowed_exam_ids FROM usuarios WHERE id = $userId");
    $u = $resUser->fetch_assoc();
    $allowedIds = json_decode($u['allowed_exam_ids'] ?? '[]', true);
    if (empty($allowedIds)) {
        // No permission to do exams
        die('<div class="alert alert-warning">No tienes asignados tipos de exámenes para realizar. Contacta al administrador. <a href="dashboard.php">Volver</a></div>');
    }
}

// ACTION: Complete Exam (Modified for Clinical Data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_exam') {
    $assignId = (int)$_POST['id'];
    
    // Security check
    $resCheck = $db->query("SELECT exam_id FROM patient_exams WHERE id=$assignId");
    $exInfo = $resCheck->fetch_assoc();
    
    if ($isSuper || (!empty($allowedIds) && in_array($exInfo['exam_id'], $allowedIds))) {
        $now = date('Y-m-d H:i:s');
        
        // Clinical Data
        $diagnosis = sanitizeInput($_POST['diagnosis'] ?? '');
        $recommendations = sanitizeInput($_POST['recommendations'] ?? '');
        $aptitude = sanitizeInput($_POST['aptitude'] ?? '');
        $results = isset($_POST['results']) ? json_encode($_POST['results']) : null;

        $stmt = $db->prepare("UPDATE patient_exams SET status='completed', completed_at=?, completed_by=?, 
                              diagnosis=?, recommendations=?, aptitude=?, results_data=? 
                              WHERE id=?");
        $stmt->bind_param("sissssi", $now, $userId, $diagnosis, $recommendations, $aptitude, $results, $assignId);
        $stmt->execute();
        
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'No autorizado para este examen']);
    }
    exit;
}

// ACTION: Cancel Exam


// ACTION: Call Patient (Queue)
// ACTION: Call Patient (Queue)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'call_patient') {
    header('Content-Type: application/json'); // Force JSON header
    try {
        $assignId = (int)$_POST['id'];
        
        // Update called_at timestamp
        $stmt = $db->prepare("UPDATE patient_exams SET called_at = NOW() WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare Failed: " . $db->error);
        }
        $stmt->bind_param("i", $assignId);
        
        if($stmt->execute()) {
            echo json_encode(['status'=>'success']);
        } else {
            throw new Exception("Execute Failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        http_response_code(500); // Optional: keep 200 to handle in JS, or use 500 but return JSON.
        // Actually, returning 200 with error status is safer for simple JS fetch handling unless we want to parse info from 500 body.
        // Let's stick to returning JSON with error message, but ensure no HTML leaks.
        echo json_encode(['status'=>'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ACTION: Cancel Exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_exam') {
    $assignId = (int)$_POST['id'];
    $observation = trim($_POST['observation'] ?? '');
    
    // Security check
    $resCheck = $db->query("SELECT exam_id FROM patient_exams WHERE id=$assignId");
    $exInfo = $resCheck->fetch_assoc();
    
    if ($isSuper || in_array($exInfo['exam_id'], $allowedIds)) {
        $now = date('Y-m-d H:i:s');
        $note = "Cancelado por especialista: " . $observation;
        $stmt = $db->prepare("UPDATE patient_exams SET status='cancelled', completed_at=?, completed_by=?, note=CONCAT(COALESCE(note, ''), ' | ', ?) WHERE id=?");
        $stmt->bind_param("sisi", $now, $userId, $note, $assignId);
        $stmt->execute();
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'No autorizado para este examen']);
    }
    exit;
}

// DATA: Get Pending Exams
$sql = "SELECT pe.id, pe.assigned_at, pe.service_type, pe.note, 
               p.nombres, p.apellidos, p.numero_documento, e.name as exam_name 
        FROM patient_exams pe
        JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
        JOIN exams e ON pe.exam_id = e.id
        WHERE pe.status = 'pending' 
        AND DATE(pe.assigned_at) >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) "; // Show last 3 days pending

if (!$isSuper && !empty($allowedIds)) {
    $idsList = implode(',', $allowedIds);
    $sql .= " AND pe.exam_id IN ($idsList) ";
}

$sql .= " ORDER BY pe.assigned_at ASC";
$result = $db->query($sql);

// DATA: Get History (Last 50)
// DATA: Get History (Last 50 - Completed OR Cancelled)
$sqlHist = "SELECT pe.id, pe.completed_at, pe.service_type, pe.status,
               p.nombres, p.apellidos, p.numero_documento, e.name as exam_name 
        FROM patient_exams pe
        JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
        JOIN exams e ON pe.exam_id = e.id
        WHERE pe.status IN ('completed', 'cancelled') AND pe.completed_by = $userId
        ORDER BY pe.completed_at DESC LIMIT 50";
$resHist = $db->query($sqlHist);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tablero Especialista</title>
    <!-- Auto-refresh every 60s -->
    <meta http-equiv="refresh" content="60"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .patient-card {
            transition: transform 0.2s;
            border-left: 5px solid var(--bs-warning);
        }
        .patient-card:hover {
            transform: scale(1.01);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 px-3">
    <a class="navbar-brand" href="#">
        <i class="bi bi-activity"></i> Atención Especialista
    </a>
    <div class="d-flex align-items-center text-white">
        <div class="dropdown me-3">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle me-2"></i>
                <strong><?php echo $_SESSION['username'] ?? 'User'; ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                <li><a class="dropdown-item" href="profile.php">Mi Perfil</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php">Salir</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <ul class="nav nav-pills" id="pills-tab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="pills-pending-tab" data-bs-toggle="pill" data-bs-target="#pills-pending" type="button">
                        <i class="bi bi-hourglass-split"></i> Pendientes (<span id="count_display"><?php echo $result->num_rows; ?></span>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button">
                        <i class="bi bi-clock-history"></i> Mi Historial
                    </button>
                </li>
            </ul>
        </div>

        <div class="d-flex gap-2" id="controls_pending">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="Buscar documento..." onkeyup="filtrarPacientes()">
            </div>
            <div class="btn-group">
                <button class="btn btn-outline-secondary" id="btnGrid" onclick="cambiarVista('grid')"><i class="bi bi-grid"></i></button>
                <button class="btn btn-outline-secondary active" id="btnList" onclick="cambiarVista('list')"><i class="bi bi-list-ul"></i></button>
            </div>
            <button class="btn btn-primary btn-sm" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>

    <div class="tab-content" id="pills-tabContent">
        <!-- PESTAÑA PENDIENTES -->
        <div class="tab-pane fade show active" id="pills-pending" role="tabpanel">

    <?php if($result->num_rows == 0): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-check-circle fs-1 text-success"></i>
            <p class="mt-2 text-dark">No hay pacientes pendientes para tus especialidades.</p>
        </div>
    <?php else: ?>
        <div id="view_grid" class="row d-none">
            <?php 
            // Reset pointer
            $result->data_seek(0);
            while($row = $result->fetch_assoc()): 
                $searchStr = strtolower($row['nombres'].' '.$row['apellidos'].' '.$row['numero_documento']);
            ?>
            <div class="col-md-6 col-lg-4 mb-3 item-card" data-search="<?php echo $searchStr; ?>">
                <div class="card patient-card shadow-sm h-100">
                    <div class="card-body position-relative">
                        <span class="badge bg-secondary position-absolute top-0 end-0 m-2 opacity-75">
                            <?php echo date('H:i', strtotime($row['assigned_at'])); ?>
                        </span>
                        
                        <h5 class="card-title fw-bold text-primary mb-1">
                            <?php echo $row['nombres'] . ' ' . $row['apellidos']; ?>
                        </h5>
                        <div class="text-muted small mb-2"><i class="bi bi-card-heading"></i> <?php echo $row['numero_documento']; ?></div>
                        
                        <div class="alert alert-light border mb-2 p-2">
                            <strong class="d-block text-dark"><i class="bi bi-clipboard-pulse"></i> <?php echo $row['exam_name']; ?></strong>
                            <small class="text-muted text-uppercase" style="font-size:0.75rem"><?php echo $row['service_type']; ?></small>
                            <?php if($row['note']): ?>
                                <div class="mt-1 small fst-italic text-danger border-top pt-1">Nota: <?php echo $row['note']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-auto">
                             <button class="btn btn-outline-primary w-100 fw-bold py-2 mb-2" onclick="llamarPaciente('<?php echo $row['nombres']; ?>', <?php echo $row['id']; ?>)">
                                <i class="bi bi-megaphone"></i> LLAMAR
                            </button>
                            <button class="btn btn-success w-100 fw-bold py-2 mb-2" onclick="marcarCompletado(<?php echo $row['id']; ?>, this)">
                                <i class="bi bi-check-lg"></i> REALIZADO
                            </button>
                            <button class="btn btn-outline-danger w-100 py-1 btn-sm" onclick="cancelarExamen(<?php echo $row['id']; ?>, this)">
                                <i class="bi bi-x-circle"></i> NO REALIZADO
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <?php endwhile; ?>
        </div>

        <div id="view_list">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover table-striped mb-0 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Hora</th>
                                <th>Paciente</th>
                                <th>Documento</th>
                                <th>Examen</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $result->data_seek(0);
                            while($row = $result->fetch_assoc()): 
                                $searchStr = strtolower($row['nombres'].' '.$row['apellidos'].' '.$row['numero_documento']);
                            ?>
                            <tr class="item-row" data-search="<?php echo $searchStr; ?>">
                                <td><?php echo date('H:i', strtotime($row['assigned_at'])); ?></td>
                                <td class="fw-bold"><?php echo $row['nombres'] . ' ' . $row['apellidos']; ?></td>
                                <td><?php echo $row['numero_documento']; ?></td>
                                <td>
                                    <?php echo $row['exam_name']; ?>
                                    <?php if($row['note']): ?><br><small class="text-danger fst-italic"><?php echo $row['note']; ?></small><?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="llamarPaciente('<?php echo $row['nombres']; ?>', <?php echo $row['id']; ?>)">
                                        <i class="bi bi-megaphone"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success fw-bold" onclick="marcarCompletado(<?php echo $row['id']; ?>, this)">
                                        <i class="bi bi-check-lg"></i> LISTO
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger ms-1" title="No Realizado" onclick="cancelarExamen(<?php echo $row['id']; ?>, this)">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>
    </div>
        
        <!-- PESTAÑA HISTORIAL -->
        <div class="tab-pane fade" id="pills-history" role="tabpanel">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha/Hora</th>
                                    <th>Paciente</th>
                                    <th>Documento</th>
                                    <th>Examen</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($resHist->num_rows == 0): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">No has realizado exámenes aún.</td></tr>
                                <?php else: ?>
                                    <?php while($h = $resHist->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($h['completed_at'])); ?></td>
                                        <td class="fw-bold"><?php echo $h['nombres'] . ' ' . $h['apellidos']; ?></td>
                                        <td><?php echo $h['numero_documento']; ?></td>
                                        <td><?php echo $h['exam_name']; ?></td>

                                        <td>
                                            <?php if($h['status'] == 'completed'): ?>
                                                <span class="badge bg-success">Realizado</span>
                                                <a href="generate_certificate.php?id=<?php echo $h['id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark ms-2" title="Descargar Certificado">
                                                    <i class="bi bi-file-earmark-pdf"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Cancelado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- End TabContent -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/ui-manager.js"></script>
<script>
    // Toggle controls when tab changes
    const tabEls = document.querySelectorAll('button[data-bs-toggle="pill"]')
    tabEls.forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            const target = event.target.getAttribute('data-bs-target');
            if(target === '#pills-pending') {
                document.getElementById('controls_pending').classList.remove('d-none');
            } else {
                document.getElementById('controls_pending').classList.add('d-none');
            }
        })
    })
    function cambiarVista(vista) {
        if(vista === 'grid') {
            document.getElementById('view_grid').classList.remove('d-none');
            document.getElementById('view_list').classList.add('d-none');
            document.getElementById('btnGrid').classList.add('active');
            document.getElementById('btnList').classList.remove('active');
        } else {
            document.getElementById('view_grid').classList.add('d-none');
            document.getElementById('view_list').classList.remove('d-none');
            document.getElementById('btnGrid').classList.remove('active');
            document.getElementById('btnList').classList.add('active');
        }
    }

    function filtrarPacientes() {
        const query = document.getElementById('searchInput').value.toLowerCase();
        let total = 0;

        // Filter Grid Items
        document.querySelectorAll('.item-card').forEach(el => {
            const txt = el.getAttribute('data-search');
            if(txt.includes(query)) {
                el.style.display = '';
                total++;
            } else {
                el.style.display = 'none';
            }
        });

        // Filter List Items
        document.querySelectorAll('.item-row').forEach(el => {
            const txt = el.getAttribute('data-search');
            if(txt.includes(query)) {
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });
        
        document.getElementById('count_display').innerText = total;
    }
    function llamarPaciente(nombre, id) {
        // Show loading state
        Swal.fire({
            title: 'Llamando...',
            text: `Anunciando a: ${nombre}`,
            icon: 'info',
            timer: 2000,
            showConfirmButton: false
        });
        
        // Send to backend
        const fd = new FormData();
        fd.append('action', 'call_patient');
        fd.append('id', id);
        
        fetch('specialist_dashboard.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.status === 'success') {
                    // Optional: Visual indicator that patient was called
                    console.log('Paciente llamado');
                }
            })
            .catch(err => console.error(err));
    }

    function marcarCompletado(id, btn) {
        // Clinical Data Modal
        UI.modalForm({
            title: 'Registrar Resultados Clínicos',
            width: '800px', // Wider implementation
            html: `
                <div class="text-start">
                    <p class="text-muted small">Por favor diligencie los hallazgos del examen.</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Diagnóstico / Hallazgos</label>
                        <textarea id="cli_diagnosis" class="form-control" rows="3" placeholder="Describa los hallazgos médicos..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Recomendaciones</label>
                        <textarea id="cli_recommendations" class="form-control" rows="3" placeholder="Recomendaciones para el paciente o empresa..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Concepto de Aptitud <span class="text-danger">*</span></label>
                        <select id="cli_aptitude" class="form-select form-select-lg">
                            <option value="">-- Seleccione Concepto --</option>
                            <option value="apto">APTO (Sin Restricciones)</option>
                            <option value="apto_restriccion">APTO CON RESTRICCIONES</option>
                            <option value="no_apto">NO APTO / APLAZADO</option>
                        </select>
                    </div>
                </div>
            `,
            confirmText: 'Guardar y Finalizar',
            preConfirmFn: () => {
                const diag = document.getElementById('cli_diagnosis').value;
                const rec = document.getElementById('cli_recommendations').value;
                const apt = document.getElementById('cli_aptitude').value;

                if (!apt) {
                    Swal.showValidationMessage('Debe seleccionar un Concepto de Aptitud');
                    return false;
                }

                return {
                    action: 'complete_exam',
                    id: id,
                    diagnosis: diag,
                    recommendations: rec,
                    aptitude: apt,
                    results: {} // Future expansion for structured data
                };
            },
            callback: (data) => {
                // Disable button
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando...';

                // Ajax
                const fd = new FormData();
                for(let k in data) {
                    if(typeof data[k] === 'object') {
                        fd.append(k, JSON.stringify(data[k]));
                    } else {
                        fd.append(k, data[k]);
                    }
                }
                
                fetch('specialist_dashboard.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if(res.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Guardado!',
                                text: 'Resultados registrados correctamente.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', res.message, 'error');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-check-lg"></i> REALIZADO';
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Fallo de conexión', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-check-lg"></i> REALIZADO';
                    });
            }
        });
    }
    function cancelarExamen(id, btn) {
        Swal.fire({
            title: '¿Marcar como NO REALIZADO?',
            text: "Por favor, indique el motivo:",
            input: 'textarea',
            inputPlaceholder: 'Escriba la razón aquí...',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Confirmar No Realización',
            inputValidator: (value) => {
                if (!value) {
                    return '¡Debe escribir un motivo!'
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Disable button
                btn.disabled = true;
                
                // Ajax
                const fd = new FormData();
                fd.append('action', 'cancel_exam');
                fd.append('id', id);
                fd.append('observation', result.value);
                
                fetch('specialist_dashboard.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if(res.status === 'success') {
                            Swal.fire('Registrado', 'El examen ha sido marcado como no realizado.', 'info').then(() => location.reload());
                        } else {
                            Swal.fire('Error', res.message, 'error');
                            btn.disabled = false;
                        }
                    });
            }
        });
    }
</script>
</body>
</html>
