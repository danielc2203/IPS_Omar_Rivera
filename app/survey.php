<?php
require_once 'config.php';
$db = getDBConnection();

$error = '';
$success = false;
$consentId = 0;
$patientName = '';

// 1. Validar Token (usamos el ID del consentimiento por ahora, en producción ideal usar hash)
// 1. PRIMERO: Procesar POST si existe (importante: hacerlo antes de validar GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hashPost = sanitizeInput($_POST['consent_hash'] ?? '');
    $exp = sanitizeInput($_POST['experience'] ?? '');
    $rec = sanitizeInput($_POST['recommend'] ?? '');
    $comm = sanitizeInput($_POST['comments'] ?? '');
    
    if ($hashPost && $exp && $rec) {
        // Buscar ID por Hash
        $find = $db->prepare("SELECT id FROM consents WHERE security_hash = ?");
        $find->bind_param("s", $hashPost);
        $find->execute();
        $resFind = $find->get_result();
        
        if ($rowC = $resFind->fetch_assoc()) {
            $consentIdPost = $rowC['id'];
            
            // Verificar si ya respondió (doble check)
            $check = $db->query("SELECT id FROM survey_responses WHERE consent_id = $consentIdPost");
            if ($check->num_rows == 0) {
                $stmt = $db->prepare("INSERT INTO survey_responses (consent_id, q_experience, q_recommend, comments) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $consentIdPost, $exp, $rec, $comm);

            if ($stmt->execute()) {
                $success = true;
            } else {
                $error = "Error al guardar: " . $db->error;
            }
        } else {
             $error = "Ya se ha registrado una respuesta para esta encuesta.";
        }
    }
    } else {
        $error = "Por favor complete todos los campos requeridos.";
    }
}

// 2. SEGUNDO: Validar Token para mostrar formulario (solo si no hubo éxito aún)
if (!$success && isset($_GET['t'])) {
    $hash = $_GET['t'];
    
    // Verificar si existe y si ya fue respondida
    $stmt = $db->prepare("SELECT c.id, c.first_name, c.last_name, 
                          (SELECT COUNT(*) FROM survey_responses WHERE consent_id = c.id) as responses
                          FROM consents c WHERE c.security_hash = ?");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $consentHash = $hash; // Guardamos para el formulario hidden
        if ($row['responses'] > 0) {
            // Si acabamos de guardar (POST), success es true y no entramos aquí.
            // Si entramos aquí es porque venimos de GET y ya estaba respondida.
            $error = 'Ya hemos recibido su respuesta para esta visita. ¡Gracias!';
        } else {
            $patientName = $row['first_name'] . ' ' . $row['last_name'];
        }
    } else {
        $error = 'Enlace no válido o expirado.';
    }
} elseif (!$success && !isset($_POST['consent_hash'])) {
    // Si no es success, Y no es POST (o sea, cargó sin parametro t)
    // (Si fue POST fallido, el error ya se seteó arriba)
    if(empty($error)) $error = 'Enlace no válido.';
}

// Obtener branding
$logoUrl = getSetting('logo_url', '');
if($logoUrl && file_exists($logoUrl)) $logoUrl .= '?v='.time();
$siteName = getSetting('site_name', 'IPS');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encuesta de Satisfacción</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        body { background-color: #f9fbfd; font-family:var(--bs-body-font-family); }
        .survey-card { max-width: 600px; margin: 2rem auto; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .survey-header { text-align: center; padding: 2rem; background: #fff; border-bottom: 1px solid #eee; }
        .logo-img { max-height: 80px; margin-bottom: 1rem; }
        .question-box { background: #fff; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #f0f0f0; }
        .question-title { font-weight: bold; margin-bottom: 1rem; color: #333; }
        .form-check { margin-bottom: 0.5rem; }
        .btn-submit { padding: 0.8rem 2rem; font-weight: bold; letter-spacing: 0.5px; }
    </style>
</head>
<body>

<div class="container">
    
    <?php if ($success): ?>
        <div class="survey-card card text-center p-5">
            <div class="mb-4 text-success display-1"><i class="bi bi-check-circle-fill"></i></div>
            <h3>¡Gracias por su opinión!</h3>
            <p class="text-muted">Su retroalimentación nos ayuda a mejorar cada día.</p>
            <div class="mt-4"><small class="text-muted">La excelente atención de nuestros clientes nos hace diferentes.</small></div>
            <a href="index.php" class="btn btn-outline-primary mt-4">Volver al Inicio</a>
        </div>
    <?php elseif ($error): ?>
        <div class="survey-card card text-center p-5">
            <div class="mb-4 text-warning display-1"><i class="bi bi-exclamation-circle"></i></div>
            <h3>Atención</h3>
            <p class="text-muted"><?php echo $error; ?></p>
            <a href="index.php" class="btn btn-outline-primary mt-4">Ir al Inicio</a>
        </div>
    <?php else: ?>

        <div class="card survey-card">
            <div class="survey-header rounded-top">
                <?php if($logoUrl): ?><img src="<?php echo $logoUrl; ?>" class="logo-img"><?php endif; ?>
                <h4 class="fw-bold mb-1">ENCUESTA DE SATISFACCIÓN</h4>
                <p class="text-muted mb-0"><?php echo strtoupper($siteName); ?></p>
            </div>
            
            <div class="card-body p-4 bg-light">
                <form method="POST">
                    <input type="hidden" name="consent_hash" value="<?php echo $consentHash; ?>">
                    
                    <div class="mb-4">
                        <label class="fw-bold text-secondary small">PACIENTE</label>
                        <div class="fs-5 fw-bold"><?php echo $patientName; ?></div>
                        <div class="text-muted small"><?php echo date('d/m/Y'); ?></div>
                    </div>

                    <div class="question-box">
                        <div class="question-title">1. ¿Cómo calificaría su experiencia global respecto a los servicios de salud recibidos?</div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="experience" id="exp1" value="muy_buena" required>
                            <label class="form-check-label" for="exp1">A. Muy Buena</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="experience" id="exp2" value="buena">
                            <label class="form-check-label" for="exp2">B. Buena</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="experience" id="exp3" value="regular">
                            <label class="form-check-label" for="exp3">C. Regular</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="experience" id="exp4" value="mala">
                            <label class="form-check-label" for="exp4">D. Mala</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="experience" id="exp5" value="muy_mala">
                            <label class="form-check-label" for="exp5">E. Muy Mala</label>
                        </div>
                    </div>

                    <div class="question-box">
                        <div class="question-title">2. ¿Recomendaría a sus familiares y amigos esta IPS?</div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="recommend" id="rec1" value="si" required>
                            <label class="form-check-label" for="rec1">A. Sí</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="recommend" id="rec2" value="probablemente_si">
                            <label class="form-check-label" for="rec2">B. Probablemente Sí</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="recommend" id="rec3" value="definitivamente_no">
                            <label class="form-check-label" for="rec3">C. Definitivamente No</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="recommend" id="rec4" value="probablemente_no">
                            <label class="form-check-label" for="rec4">D. Probablemente No</label>
                        </div>
                    </div>

                    <div class="question-box">
                        <div class="question-title">Comentarios Adicionales (Opcional)</div>
                        <textarea name="comments" class="form-control" rows="3" placeholder="Escriba aquí..."></textarea>
                    </div>

                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-submit shadow-sm rounded-pill w-100">
                            ENVIAR RESPUESTA
                        </button>
                        <p class="fw-bold mt-4 small text-uppercase" style="font-family: serif; letter-spacing: 1px;">
                            "La excelente atención de nuestros clientes nos hace diferentes"
                        </p>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>

</body>
</html>
