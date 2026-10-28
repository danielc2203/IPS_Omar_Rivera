<?php
// public_html/admin/change_password.php
require_once '../config.php';

// Seguridad: Requiere login
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDBConnection();
$userId = $_SESSION['user_id'];
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $message = "Por favor complete todos los campos.";
        $msgType = "danger";
    } elseif ($newPass !== $confirmPass) {
        $message = "Las contraseñas nuevas no coinciden.";
        $msgType = "danger";
    } elseif (strlen($newPass) < 8) {
        $message = "La nueva contraseña debe tener al menos 8 caracteres.";
        $msgType = "danger";
    } else {
        // Verificar contraseña actual
        $stmt = $db->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            if (password_verify($currentPass, $row['password_hash'])) {
                // Contraseña correcta, actualizar
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $update = $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
                $update->bind_param("si", $newHash, $userId);
                
                if ($update->execute()) {
                    $message = "Contraseña actualizada correctamente.";
                    $msgType = "success";
                    if(function_exists('logActivity')) logActivity('Cambio de Contraseña', 'usuarios', "Usuario ID: $userId");
                } else {
                    $message = "Error al actualizar en base de datos.";
                    $msgType = "danger";
                }
            } else {
                $message = "La contraseña actual es incorrecta.";
                $msgType = "danger";
            }
        }
    }
}

// Obtener datos visuales para el layout
$logoUrl = getSetting('logo_url');
if($logoUrl) $logoUrl = '../'.$logoUrl;
$siteName = getSetting('site_name', 'Sistema Consentimientos');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar Contraseña | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .sidebar { min-height: 100vh; }
    </style>
</head>
<body class="bg-light">

    <!-- NAVBAR PARA VISTAS TIPO PATIENTS/LOGS -->
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand mb-0 h1 d-flex align-items-center" href="dashboard.php">
            <?php if($logoUrl && file_exists($logoUrl)): ?>
                <img src="<?php echo $logoUrl; ?>" alt="Logo" height="30" class="d-inline-block align-text-top me-2 bg-white rounded px-1">
            <?php endif; ?>
            <span>Seguridad</span>
        </a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title fw-bold mb-0 text-primary"><i class="bi bi-key-fill"></i> Cambiar Contraseña</h5>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if($message): ?>
                            <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show" role="alert">
                                <?php echo $message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Contraseña Actual</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Nueva Contraseña</label>
                                <input type="password" name="new_password" class="form-control" required minlength="8">
                                <div class="form-text">Mínimo 8 caracteres.</div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold small">Confirmar Nueva Contraseña</label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="8">
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary fw-bold">Actualizar Contraseña</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
