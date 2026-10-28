<?php
// public_html/admin/profile.php
require_once '../config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDBConnection();
$userId = getUserId();
$msg = '';
$msgType = '';

// FETCH USER DATA
$stmt = $db->prepare("SELECT username, email, nombre_completo, registro_medico, especialidad, firma_digital FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// HANDLE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $nombre = sanitizeInput($_POST['nombre']);
        $registro = sanitizeInput($_POST['registro']);
        $especialidad = sanitizeInput($_POST['especialidad']);
        $email = sanitizeInput($_POST['email']);
        
        $signature = $_POST['signature_data'] ?? '';
        
        // Update Query
        $sqlUp = "UPDATE usuarios SET nombre_completo=?, registro_medico=?, especialidad=?, email=?";
        $params = [$nombre, $registro, $especialidad, $email];
        $types = "ssss";

        // If signature is provided and not empty (and distinct from placeholder)
        if (!empty($signature)) {
            $sqlUp .= ", firma_digital=?";
            $params[] = $signature;
            $types .= "s";
        }

        $sqlUp .= " WHERE id=?";
        $params[] = $userId;
        $types .= "i";

        $stmtUp = $db->prepare($sqlUp);
        $stmtUp->bind_param($types, ...$params);
        
        if ($stmtUp->execute()) {
            $msg = "Perfil actualizado correctamente.";
            $msgType = "success";
            // Refresh data
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            // Update Session Name
            $_SESSION['username'] = $nombre; 
        } else {
            $msg = "Error al actualizar: " . $db->error;
            $msgType = "danger";
        }
    }
    
    if ($action === 'change_password') {
        $passCurrent = $_POST['current_password'];
        $passNew = $_POST['new_password'];
        $passConfirm = $_POST['confirm_password'];

        // Verify Current
        $stmtChk = $db->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmtChk->bind_param("i", $userId);
        $stmtChk->execute();
        $hash = $stmtChk->get_result()->fetch_assoc()['password_hash'];

        if (password_verify($passCurrent, $hash)) {
            if ($passNew === $passConfirm) {
                if (strlen($passNew) >= 6) {
                    $newHash = password_hash($passNew, PASSWORD_DEFAULT);
                    $stmtUp = $db->prepare("UPDATE usuarios SET password_hash=? WHERE id=?");
                    $stmtUp->bind_param("si", $newHash, $userId);
                    if ($stmtUp->execute()) {
                        $msg = "Contraseña cambiada con éxito.";
                        $msgType = "success";
                    }
                } else {
                    $msg = "La contraseña debe tener al menos 6 caracteres.";
                    $msgType = "warning";
                }
            } else {
                $msg = "Las contraseñas nuevas no coinciden.";
                $msgType = "warning";
            }
        } else {
            $msg = "La contraseña actual es incorrecta.";
            $msgType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .signature-pad {
            border: 2px dashed #ccc;
            border-radius: 5px;
            cursor: crosshair;
            background: white;
            width: 100%;
            height: 200px;
        }
    </style>
</head>
<body class="bg-light">

<!-- Navbar Simple -->
<nav class="navbar navbar-dark bg-dark mb-4 px-3">
    <a class="navbar-brand" href="dashboard.php"><i class="bi bi-person-circle"></i> Mi Perfil</a>
    <a href="<?php echo isProfesional() ? 'specialist_dashboard.php' : 'dashboard.php'; ?>" class="btn btn-outline-light btn-sm">Volver al Tablero</a>
</nav>

<div class="container pb-5">
    
    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show" role="alert">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Columna Datos Personales + Firma -->
        <div class="col-md-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Información Personal y Firma</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="profileForm">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="signature_data" id="signature_data">

                        <div class="mb-3">
                            <label class="form-label">Nombre Completo</label>
                            <input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($user['nombre_completo']); ?>" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Usuario (Login)</label>
                                <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-primary">Datos Profesionales</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Especialidad</label>
                                <input type="text" name="especialidad" class="form-control" placeholder="Ej: Medicina General, Optometría" value="<?php echo htmlspecialchars($user['especialidad'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registro Médico / Tarjeta Profesional</label>
                                <input type="text" name="registro" class="form-control" placeholder="Ej: 123456-MD" value="<?php echo htmlspecialchars($user['registro_medico'] ?? ''); ?>">
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-primary">Firma Digital para Certificados</h6>
                        <p class="small text-muted">Dibuje su firma en el recuadro. Esta firma aparecerá en los certificados que genere.</p>
                        
                        <div class="mb-2">
                             <canvas id="sigCanvas" class="signature-pad"></canvas>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                             <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSignature()">Borrar Firma</button>
                             <?php if(!empty($user['firma_digital'])): ?>
                                <span class="badge bg-success align-self-center">Firma guardada actualmente</span>
                             <?php else: ?>
                                <span class="badge bg-warning text-dark align-self-center">Sin firma registrada</span>
                             <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" onclick="prepareSave()">Guardar Cambios</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Columna Contraseña -->
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Cambiar Contraseña</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label class="form-label">Contraseña Actual</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña</label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirmar Nueva</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                        </div>
                        
                        <button type="submit" class="btn btn-dark w-100">Actualizar Contraseña</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Signature Pad CDN -->
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
<script>
    var canvas = document.getElementById('sigCanvas');
    
    // Resize canvas for high DPI
    function resizeCanvas() {
        var ratio =  Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext("2d").scale(ratio, ratio);
    }
    window.onresize = resizeCanvas;
    resizeCanvas();

    var signaturePad = new SignaturePad(canvas, {
        backgroundColor: 'rgba(255, 255, 255, 0)',
        penColor: 'rgb(0, 0, 0)'
    });

    function clearSignature() {
        signaturePad.clear();
    }

    function prepareSave() {
        if (!signaturePad.isEmpty()) {
            document.getElementById('signature_data').value = signaturePad.toDataURL('image/png');
        }
    }
    
    // Check if we need to load existing signature?
    // Usually purely client side for security we don't repaint the exact strokes easily 
    // unless we saved JSON. We saved Image.
    // So we just leave it empty to "Draw New" or keep old if not modified.
    // Backend logic handles "If empty signature_data, don't overwrite db".
</script>
</body>
</html>
