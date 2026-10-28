<?php
// public_html/admin/create_developer.php
require_once '../config.php';

// Solo CLI o Admin logueado
if (php_sapi_name() !== 'cli' && (!isLoggedIn() || !isAdmin())) {
    die("Acceso restringido");
}

$db = getDBConnection();

// Datos del Developers
$user = 'dev';
$pass = 'Dev123456*';
$email = 'dev@tecco.com.co';
$name = 'Desarrollador Principal';

// 1. Crear Empresa "Developer Studio" (para consistencia, aunque el rol es global)
// O asignarlo a admin-ips
$empresaId = 'admin-ips'; 

// 2. Insertar Usuario
$hash = password_hash($pass, PASSWORD_DEFAULT);
$sql = "INSERT INTO usuarios (username, email, password_hash, nombre_completo, rol, empresa_id, activo) 
        VALUES (?, ?, ?, ?, 'desarrollador', ?, 1)
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), rol = 'desarrollador'";

$stmt = $db->prepare($sql);
$stmt->bind_param("sssss", $user, $email, $hash, $name, $empresaId);

if ($stmt->execute()) {
    echo "Usuario desarrollador creado/actualizado.\n";
    echo "User: $user\nPass: $pass\n";
} else {
    echo "Error: " . $stmt->error . "\n";
}
