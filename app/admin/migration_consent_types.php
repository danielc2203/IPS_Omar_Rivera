<?php
// admin/migration_consent_types.php
require_once '../config.php';

// Solo admin o CLI
if (php_sapi_name() !== 'cli' && (!isLoggedIn() || !isAdmin())) {
    die("Acceso denegado");
}

$db = getDBConnection();

// SQL para crear la tabla
$sql = "CREATE TABLE IF NOT EXISTS consent_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($db->query($sql)) {
    echo "Tabla 'consent_types' creada correctamente.<br>";
    
    // Migrar el consentimiento actual (de la tabla settings) como el primero
    $currentText = getSetting('consent_text');
    if ($currentText) {
        $check = $db->query("SELECT count(*) as c FROM consent_types");
        $row = $check->fetch_assoc();
        if ($row['c'] == 0) {
            $stmt = $db->prepare("INSERT INTO consent_types (name, content) VALUES (?, ?)");
            $name = "Consentimiento General";
            $stmt->bind_param("ss", $name, $currentText);
            if ($stmt->execute()) {
                echo "Consentimiento actual migrado como 'Consentimiento General'.";
            } else {
                echo "Error al migrar consentimiento actual: " . $db->error;
            }
        } else {
            echo "La tabla ya tiene datos, no se realizó migración inicial.";
        }
    }
} else {
    echo "Error al crear la tabla: " . $db->error;
}
?>
