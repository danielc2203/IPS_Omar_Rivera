<?php
// public_html/admin/login.php
require_once '../config.php';

// Si ya tiene sesión válida, va directo al dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    
    // 1. Validar Token CSRF
    if (!verifyCSRFToken($token)) {
        $error = 'La sesión expiró. Por favor recargue la página.';
    } else {
        // 2. Autenticar
        $user = authenticateUser($username, $password);
        
        if ($user) {
            // --- INICIO DE SESIÓN EXITOSO ---
            
            // Regenerar ID por seguridad
            session_regenerate_id(true);
            
            // Guardar datos en sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_rol'] = $user['rol'];
            $_SESSION['empresa_id'] = $user['empresa_id'];
            $_SESSION['nombre_completo'] = $user['nombre_completo'];
            
            // ⚠️ EL SECRETO: Forzar guardado inmediato
            // Esto detiene la escritura y asegura que los datos existan antes de saltar
            session_write_close();
            
            if ($user['rol'] === 'profesional') {
                header('Location: specialist_dashboard.php');
            } else {
                header('Location: dashboard.php');
            }
            exit();
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
// Obtener datos visuales
$logoUrl = getSetting('logo_url', ''); // Ruta relativa desde root
if(!empty($logoUrl)) $logoUrl = '../' . $logoUrl; // Ajuste para admin/
$siteName = getSetting('site_name', 'Sistema de Consentimientos');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <?php applyTheme(); ?>
    
    <style>
        body {
            background-color: #f0f2f5;
            background-image: radial-gradient(#e2e2e2 1px, transparent 1px);
            background-size: 20px 20px;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            transition: transform 0.3s;
        }
        .login-card:hover {
            transform: translateY(-5px);
        }
        .login-header {
            background: linear-gradient(to right, rgba(255,255,255,0.9), rgba(255,255,255,0.8));
            padding: 2rem 1rem 1rem;
            text-align: center;
        }
        .logo-img {
            max-width: 180px;
            max-height: 80px;
            object-fit: contain;
            margin-bottom: 1rem;
        }
        .btn-primary {
            padding: 0.8rem;
            font-size: 1.1rem;
            border-radius: 0.5rem;
            letter-spacing: 0.5px;
        }
        .form-floating > label {
            padding-left: 2.5rem; 
        }
        .form-floating > .form-control {
            padding-left: 2.5rem;
        }
        .input-icon {
            position: absolute;
            top: 1.1rem;
            left: 1rem;
            z-index: 5;
            color: #aaa;
        }
    </style>
</head>
<body>
    
    <div class="card login-card shadow-lg">
        <div class="login-header">
            <?php if ($logoUrl && file_exists('../' . getSetting('logo_url'))): ?>
                <img src="<?php echo $logoUrl . '?v=' . time(); ?>" alt="Logo" class="logo-img">
            <?php else: ?>
                <div class="display-1 text-primary mb-2"><i class="bi bi-shield-lock"></i></div>
            <?php endif; ?>
            <h4 class="fw-bold text-dark mb-0"><?php echo $siteName; ?></h4>
            <p class="text-muted small">Acceso Administrativo Segura</p>
        </div>
        
        <div class="card-body p-4 pt-2">
            
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center small" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="position-relative mb-3">
                    <i class="bi bi-person input-icon"></i>
                    <div class="form-floating">
                        <input type="text" name="username" class="form-control" id="floatingUser" placeholder="Usuario" required autofocus>
                        <label for="floatingUser">Usuario / Email</label>
                    </div>
                </div>

                <div class="position-relative mb-4">
                    <i class="bi bi-key input-icon"></i>
                    <div class="form-floating">
                        <input type="password" name="password" class="form-control" id="floatingPass" placeholder="Contraseña" required>
                        <label for="floatingPass">Contraseña</label>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary shadow-sm fw-bold">
                        INGRESAR AL SISTEMA
                    </button>
                    <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0 mt-2">
                        <i class="bi bi-arrow-left"></i> Volver al Inicio
                    </a>
                </div>
            </form>
        </div>
        <div class="card-footer bg-light text-center py-3">
            <small class="text-muted text-uppercase" style="font-size: 0.7rem;">
                &copy; <?php echo date('Y'); ?> Tecco S.A.S - Todos los derechos reservados
            </small>
        </div>
    </div>

</body>
</html>