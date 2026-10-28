<?php
// public_html/withdrawal.php
require_once 'config.php';

$db_init = getDBConnection();

// --- AJAX: SAVE WITHDRAWAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_withdrawal') {
    header('Content-Type: application/json');
    error_reporting(0);
    
    try {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Error de seguridad (Token CSRF Inválido).");
        }

        $docType = sanitizeInput($_POST['document_type']);
        $docNumber = sanitizeInput($_POST['document_number']);
        $reason = sanitizeInput($_POST['reason']);
        $examsJson = $_POST['exams_json']; // Expecting JSON string
        $signature = $_POST['signature'];

        if (empty($signature)) throw new Exception("La firma es obligatoria");
        if (empty($examsJson) || $examsJson === '[]') throw new Exception("Debe seleccionar al menos un examen");

        // Find patient ID
        $stmtPat = $db_init->prepare("SELECT id FROM pacientes_pre_registrados WHERE numero_documento = ? AND tipo_documento = ?");
        $stmtPat->bind_param("ss", $docNumber, $docType);
        $stmtPat->execute();
        $resPat = $stmtPat->get_result();
        if ($resPat->num_rows === 0) throw new Exception("Paciente no encontrado en el sistema.");
        $patientId = $resPat->fetch_assoc()['id'];

        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];
        $securityHash = hash('sha256', $patientId . $examsJson . $signature . $ip . SECRET_KEY);

        $stmt = $db_init->prepare("INSERT INTO withdrawals (patient_id, exams_json, reason, signature, ip_address, user_agent, security_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssss", $patientId, $examsJson, $reason, $signature, $ip, $ua, $securityHash);

        if ($stmt->execute()) {
            if(function_exists('logActivity')) logActivity('Registro Desistimiento', 'withdrawals', "ID Paciente: $patientId - Exámenes: $examsJson");
            echo json_encode(['status' => 'success', 'message' => 'Desistimiento registrado correctamente.']);
        } else {
            throw new Exception("Error al guardar: " . $stmt->error);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $db_init->close();
    exit;
}

// --- OBTENER LISTA DE EXÁMENES ---
$res_exams = $db_init->query("SELECT id, name FROM exams WHERE is_active = 1 ORDER BY name ASC");
$listaExamenes = ($res_exams) ? $res_exams->fetch_all(MYSQLI_ASSOC) : [];

$logoUrl = getSetting('logo_url', '');
$siteName = getSetting('site_name', 'Sistema de Consentimientos');
$kioskBg = getSetting('kiosk_bg_color', '#f8f9fa');
$kioskHeader = getSetting('kiosk_header_color', '#0d6efd');
$withdrawTextRaw = getActiveWithdrawalTemplate();

$db_init->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Desistimiento | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        body { background-color: <?php echo $kioskBg; ?>; min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow-y: auto; user-select: none; }
        .kiosk-card { width: 100%; min-height: 100vh; border: none; display: flex; flex-direction: column; background: white; }
        .kiosk-header { padding: 2rem 1rem; text-align: center; background: #dc3545; color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); z-index: 10; position: relative; }
        .btn-home { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.2); color: white; border: 2px solid white; border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; text-decoration: none; transition: all 0.2s; }
        .btn-home:hover { background: white; color: #dc3545; }
        @media (max-width: 768px) { .btn-home { width: 45px; height: 45px; font-size: 1.2rem; top: 10px; right: 10px; } }
        .kiosk-body { flex: 1; padding: 2rem; display: flex; flex-direction: column; justify-content: center; max-width: 900px; margin: 0 auto; width: 100%; }
        .form-floating-lg > .form-control, .form-floating-lg > .form-select { height: 5rem; padding-top: 2rem; padding-bottom: 0.5rem; padding-left: 1rem; font-size: 1.3rem; border-radius: 12px; }
        .form-floating-lg > label { padding: 1rem 1rem; font-size: 1.1rem; opacity: 0.8; }
        .exam-item { cursor: pointer; transition: all 0.2s; border-radius: 10px; border: 2px solid #eee; margin-bottom: 0.5rem; padding: 1rem; display: flex; align-items: center; }
        .exam-item:hover { background-color: #f8f9fa; border-color: #dee2e6; }
        .exam-item.selected { border-color: #dc3545; background-color: #fff5f5; }
        .exam-item input { pointer-events: none; }
    </style>
</head>
<body>

    <div class="kiosk-card">
        <div class="kiosk-header">
            <a href="index.php" class="btn-home" title="Volver al Inicio"><i class="bi bi-house-door-fill"></i></a>
            <h1 class="display-5 fw-bold"><i class="bi bi-file-earmark-x-fill"></i> Módulo de Desistimiento</h1>
            <p class="lead opacity-75">Documento Voluntario de Renuncia a Exámenes</p>
        </div>

        <div class="kiosk-body" id="step-1">
            <h2 class="text-center text-muted mb-5">Ingrese su documento para comenzar</h2>
            <form onsubmit="return false;" autocomplete="off">
                <div class="row g-4 mb-4">
                    <div class="col-md-5">
                         <div class="form-floating form-floating-lg">
                            <select class="form-select" id="tipo_doc">
                                <?php foreach(getDocumentTypes() as $code => $name): ?>
                                    <option value="<?php echo $code; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="tipo_doc">Tipo de Documento</label>
                        </div>
                    </div>
                    <div class="col-md-7">
                         <div class="form-floating form-floating-lg">
                            <input type="number" class="form-control" id="num_doc" placeholder="Número" required>
                            <label for="num_doc">No. Documento</label>
                        </div>
                    </div>
                </div>
                <div class="d-grid mt-4">
                    <button type="button" onclick="iniciarDesistimiento()" class="btn btn-danger btn-lg p-3 fw-bold rounded-pill shadow">
                       CONTINUAR <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>

        <div class="kiosk-body d-none" id="step-2">
            <h3 class="fw-bold mb-4 text-danger"><i class="bi bi-list-check"></i> Seleccione el o los exámenes que NO desea realizarse:</h3>
            <div id="exams-container" class="row row-cols-1 row-cols-md-2 g-2 mb-4">
                <?php foreach($listaExamenes as $exam): ?>
                    <div class="col">
                        <div class="exam-item" onclick="toggleExam(this, <?php echo $exam['id']; ?>, '<?php echo addslashes($exam['name']); ?>')">
                            <input class="form-check-input me-3" type="checkbox" style="transform: scale(1.5)">
                            <span class="fs-5 fw-bold text-dark"><?php echo htmlspecialchars($exam['name']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold fs-5 text-muted">¿Por qué desiste de realizarse estos servicios? (Opcional)</label>
                <textarea id="reason" class="form-control" rows="3" placeholder="Escriba aquí el motivo o déjelo en blanco..."></textarea>
            </div>

            <div class="alert alert-warning mb-4 shadow-sm border-warning border-start border-5">
                <h5 class="fw-bold text-dark mb-2"><i class="bi bi-shield-exclamation"></i> Declaración de Desistimiento</h5>
                <div id="with_text_content" class="small text-justify" style="max-height: 150px; overflow-y: auto;"></div>
            </div>

            <div class="text-center mb-4">
                <label class="fw-bold text-danger fs-4 mb-2">FIRMA DEL PACIENTE</label>
                <div style="border: 3px dashed #dc3545; border-radius: 15px; background: #fff; touch-action: none;">
                    <canvas id="sig-canvas" style="width: 100%; height: 250px;"></canvas>
                </div>
                <button type="button" class="btn btn-outline-secondary mt-2" onclick="signaturePad.clear()">Borrar Firma</button>
            </div>

            <div class="d-grid gap-2">
                <button type="button" onclick="submitWithdrawal()" class="btn btn-danger btn-lg p-3 fw-bold rounded-pill shadow">
                    REGISTRAR DESISTIMIENTO
                </button>
                <button type="button" onclick="location.reload()" class="btn btn-link text-muted mt-2">Reiniciar</button>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script src="assets/js/ui-manager.js"></script>

<script>
    let selectedExams = [];
    let signaturePad;
    const CSRF_TOKEN = "<?php echo $_SESSION['csrf_token']; ?>";
    const WITHDRAW_TEXT_TEMPLATE = <?php echo json_encode($withdrawTextRaw); ?>;
    let patientData = null;

    function iniciarDesistimiento() {
        const type = document.getElementById('tipo_doc').value;
        const num = document.getElementById('num_doc').value.trim();
        if(!num) { UI.error('Atención', 'Ingrese su documento'); return; }

        UI.loading('Verificando...');
        fetch(`api_check_patient.php?document_type=${type}&document_number=${num}`)
            .then(r => r.json())
            .then(data => {
                UI.close();
                if(data.status !== 'found') { 
                    UI.error('Paciente No Encontrado', 'Usted no se encuentra registrado en el sistema. Por favor, pase primero por recepción o regístrese en el Modo Kiosco.'); 
                }
                else {
                    // Mapear nombres según el formato de api_check_patient.php
                    patientData = {
                        nombres: data.data.first_name,
                        apellidos: data.data.last_name
                    };
                    document.getElementById('step-1').classList.add('d-none');
                    document.getElementById('step-2').classList.remove('d-none');
                    actualizarTextoLegal();
                    initSignature();
                }
            })
            .catch(() => UI.error('Error', 'No se pudo conectar'));
    }

    function actualizarTextoLegal() {
        if (!patientData) return;
        const nombreCompleto = (patientData.nombres + ' ' + patientData.apellidos).toUpperCase();
        const tipoDoc = document.getElementById('tipo_doc').value;
        const numDoc = document.getElementById('num_doc').value.trim();
        
        let processed = WITHDRAW_TEXT_TEMPLATE
            .replace(/{{FULL_NAME}}/g, `<b>${nombreCompleto}</b>`)
            .replace(/{{DOCUMENT_TYPE}}/g, tipoDoc)
            .replace(/{{DOCUMENT_NUMBER}}/g, numDoc)
            .replace(/\n/g, '<br>');
            
        document.getElementById('with_text_content').innerHTML = processed;
    }

    function toggleExam(el, id, name) {
        const chk = el.querySelector('input');
        if (el.classList.contains('selected')) {
            el.classList.remove('selected');
            chk.checked = false;
            selectedExams = selectedExams.filter(e => e.id !== id);
        } else {
            el.classList.add('selected');
            chk.checked = true;
            selectedExams.push({id, name});
        }
    }

    function initSignature() {
        const canvas = document.getElementById('sig-canvas');
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = 250 * ratio;
        canvas.getContext("2d").scale(ratio, ratio);
        signaturePad = new SignaturePad(canvas, { backgroundColor: 'rgb(255, 255, 255)' });
    }

    function submitWithdrawal() {
        if (selectedExams.length === 0) { UI.error('Atención', 'Debe seleccionar al menos un examen'); return; }
        if (signaturePad.isEmpty()) { UI.error('Atención', 'La firma es obligatoria'); return; }

        UI.loading('Guardando...');
        const type = document.getElementById('tipo_doc').value;
        const num = document.getElementById('num_doc').value.trim();
        const reason = document.getElementById('reason').value.trim();

        const formData = new FormData();
        formData.append('action', 'save_withdrawal');
        formData.append('document_type', type);
        formData.append('document_number', num);
        formData.append('reason', reason);
        formData.append('exams_json', JSON.stringify(selectedExams));
        formData.append('signature', signaturePad.toDataURL());
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('withdrawal.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if(res.status === 'success') {
                    Swal.fire({ icon: 'success', title: '¡Registrado!', text: res.message, timer: 4000, showConfirmButton: false })
                    .then(() => location.href = 'index.php');
                } else { UI.error('Error', res.message); }
            })
            .catch(() => UI.error('Error', 'Fallo de red'));
    }
</script>
</body>
</html>
