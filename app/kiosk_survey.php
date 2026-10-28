<?php
// public_html/kiosk_survey.php
require_once 'config.php';

$db = getDBConnection();

// --- AJAX: FIND PENDING SURVEYS ---
if (isset($_GET['action']) && $_GET['action'] === 'find_surveys') {
    header('Content-Type: application/json');
    $type = sanitizeInput($_GET['document_type'] ?? '');
    $num  = sanitizeInput($_GET['document_number'] ?? '');

    if (!$type || !$num) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos']);
        exit;
    }

    // Buscar el último consentimiento de este paciente que no tenga encuesta
    $sql = "SELECT c.id, c.security_hash, c.date_signed, ct.name as clinic_process
            FROM consents c
            LEFT JOIN survey_responses s ON c.id = s.consent_id
            LEFT JOIN consent_types ct ON c.template_id = ct.id
            WHERE c.document_type = ? AND c.document_number = ? AND s.id IS NULL
            ORDER BY c.date_signed DESC
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $type, $num);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        echo json_encode(['status' => 'found', 'hash' => $row['security_hash']]);
    } else {
        echo json_encode(['status' => 'not_found', 'message' => 'No encontramos encuestas pendientes para este documento hoy. ¡Gracias por su visita!']);
    }
    exit;
}

$logoUrl = getSetting('logo_url', '');
$siteName = getSetting('site_name', 'Sistema de Consentimientos');
$kioskBg = getSetting('kiosk_bg_color', '#f8f9fa');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Encuesta | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: <?php echo $kioskBg; ?>; min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow-y: auto; user-select: none; }
        .kiosk-card { width: 100%; min-height: 100vh; border: none; display: flex; flex-direction: column; background: white; }
        .kiosk-header { padding: 3rem 1rem; text-align: center; background: #6f42c1; color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); z-index: 10; position: relative; }
        .btn-home { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.2); color: white; border: 2px solid white; border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; text-decoration: none; transition: all 0.2s; }
        .btn-home:hover { background: white; color: #6f42c1; }
        .kiosk-body { flex: 1; padding: 2rem; display: flex; flex-direction: column; justify-content: center; max-width: 800px; margin: 0 auto; width: 100%; }
        .form-floating-lg > .form-control, .form-floating-lg > .form-select { height: 5rem; padding-top: 2rem; padding-bottom: 0.5rem; padding-left: 1rem; font-size: 1.3rem; border-radius: 12px; }
        .form-floating-lg > label { padding: 1rem 1rem; font-size: 1.1rem; opacity: 0.8; }
    </style>
</head>
<body>

    <div class="kiosk-card">
        <div class="kiosk-header">
            <a href="index.php" class="btn-home"><i class="bi bi-house-door-fill"></i></a>
            <h1 class="display-4 fw-bold"><i class="bi bi-star-fill"></i> Su Opinión nos Importa</h1>
            <p class="lead opacity-75">Encuesta de Satisfacción del Paciente</p>
        </div>

        <div class="kiosk-body">
            <h2 class="text-center text-muted mb-5">Ingrese su documento para calificar su atención de hoy</h2>
            <form id="findForm" onsubmit="return false;" autocomplete="off">
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
                    <button type="button" onclick="buscarEncuesta()" class="btn btn-purple btn-lg p-3 fw-bold rounded-pill shadow" style="background-color: #6f42c1; color: white;">
                       CONTINUAR <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/ui-manager.js"></script>
<script>
    function buscarEncuesta() {
        const type = document.getElementById('tipo_doc').value;
        const num = document.getElementById('num_doc').value.trim();
        if(!num) { UI.error('Atención', 'Ingrese su número de documento'); return; }

        UI.loading('Buscando su visita...');
        fetch(`kiosk_survey.php?action=find_surveys&document_type=${type}&document_number=${num}`)
            .then(r => r.json())
            .then(data => {
                UI.close();
                if(data.status === 'found') {
                    location.href = `survey.php?t=${data.hash}`;
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Sin encuestas pendientes',
                        text: data.message,
                        confirmButtonText: 'Cerrar',
                        confirmButtonColor: '#6f42c1'
                    });
                }
            })
            .catch(() => UI.error('Error', 'No se pudo conectar con el servidor'));
    }
</script>
</body>
</html>
