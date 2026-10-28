<?php
// public_html/admin/debug_hash.php
require_once '../config.php';

$msg = "";

// Solo procesar si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'];
    $pass = $_POST['password'];
    
    $db = getDBConnection();
    
    // 1. Buscar el usuario en la BD
    $stmt = $db->prepare("SELECT id, username, password_hash, rol, empresa_id FROM usuarios WHERE username = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $hash_en_bd = $row['password_hash'];
        $es_valida = password_verify($pass, $hash_en_bd);
        
        $msg .= "<div class='alert alert-info'>";
        $msg .= "<h4>Resultados para: <b>" . htmlspecialchars($user) . "</b></h4>";
        $msg .= "<ul>";
        $msg .= "<li><strong>ID Usuario:</strong> " . $row['id'] . "</li>";
        $msg .= "<li><strong>Rol:</strong> " . $row['rol'] . "</li>";
        $msg .= "<li><strong>ID Empresa:</strong> " . ($row['empresa_id'] ?? 'N/A') . "</li>";
        $msg .= "<li><strong>Hash en Base de Datos:</strong> <br><small style='font-family:monospace; word-break:break-all;'>" . $hash_en_bd . "</small></li>";
        $msg .= "</ul>";
        
        if ($es_valida) {
            $msg .= "<div class='alert alert-success'><strong>✅ ¡CORRECTO!</strong> La contraseña coincide con el hash. <br>Si no puedes entrar, el problema NO es la contraseña (revisa login.php, sesiones o cookies).</div>";
        } else {
            $msg .= "<div class='alert alert-danger'><strong>❌ INCORRECTO.</strong> La contraseña no coincide.<br>";
            $msg .= "Hash que se generaría con la contraseña que escribiste ('" . htmlspecialchars($pass) . "'):<br>";
            $msg .= "<small style='font-family:monospace; word-break:break-all;'>" . password_hash($pass, PASSWORD_DEFAULT) . "</small></div>";
        }
        $msg .= "</div>";
    } else {
        $msg .= "<div class='alert alert-warning'>⚠️ El usuario <strong>" . htmlspecialchars($user) . "</strong> NO existe en la tabla 'usuarios'.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Debug Hash</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
    <div class="container" style="max-width: 600px">
        <div class="card shadow">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">🕵️‍♂️ Diagnóstico de Contraseñas</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Usuario a probar</label>
                        <input type="text" name="username" class="form-control" placeholder="Ej: admin o constr-1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contraseña</label>
                        <input type="text" name="password" class="form-control" placeholder="La contraseña que intentas usar" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Verificar</button>
                </form>
                
                <div class="mt-4">
                    <?php echo $msg; ?>
                </div>
            </div>
            <div class="card-footer text-muted small">
                Este archivo es solo para pruebas. Bórralo cuando termines por seguridad.
            </div>
        </div>
    </div>
</body>
</html>