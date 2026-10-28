<?php
require_once '../config.php';
$db = getDBConnection();

// Verificar si la columna ya existe
$result = $db->query("SHOW COLUMNS FROM consents LIKE 'security_hash'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE consents ADD COLUMN security_hash VARCHAR(64) AFTER ip_address";
    if ($db->query($sql) === TRUE) {
        echo "Columna 'security_hash' agregada exitosamente.";
    } else {
        echo "Error al agregar columna: " . $db->error;
    }
} else {
    echo "La columna 'security_hash' ya existe.";
}
$db->close();
?>
