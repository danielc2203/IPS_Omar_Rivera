<?php
// admin/migration_roles.php
require_once '../config.php';

// Solo admin o CLI
if (php_sapi_name() !== 'cli' && (!isLoggedIn() || !isAdmin())) {
    die("Acceso denegado");
}

$db = getDBConnection();

// Alter table to change ENUM
$sql = "ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin', 'empresa', 'recepcionista', 'profesional', 'auditor') NOT NULL DEFAULT 'empresa'";

if ($db->query($sql)) {
    echo "Columna 'rol' actualizada correctamente con los nuevos roles.<br>";
} else {
    echo "Error al actualizar 'rol': " . $db->error;
}
?>
