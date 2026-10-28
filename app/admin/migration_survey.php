<?php
require_once '../config.php';
$db = getDBConnection();

// Crear tabla de respuestas
$sql = "CREATE TABLE IF NOT EXISTS survey_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consent_id INT NOT NULL,
    q_experience VARCHAR(50) NOT NULL COMMENT 'muy_buena, buena, regular, mala, muy_mala',
    q_recommend VARCHAR(50) NOT NULL COMMENT 'si, probablemente_si, definitivamente_no, probablemente_no',
    comments TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (consent_id),
    FOREIGN KEY (consent_id) REFERENCES consents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($db->query($sql) === TRUE) {
    echo "Tabla 'survey_responses' creada o ya existe.<br>";
} else {
    echo "Error creando tabla: " . $db->error . "<br>";
}

// Insertar configuración por defecto para el correo
$sqlConfig = "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('sender_email', 'info@tecco.com.co')";
if ($db->query($sqlConfig) === TRUE) {
    echo "Configuración 'sender_email' inicializada.<br>";

    // Verificar si existe para mostrarlo
    $res = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'sender_email'");
    if($row = $res->fetch_assoc()) {
        echo "Correo remitente actual: " . $row['setting_value'];
    }
} else {
    echo "Error config: " . $db->error;
}

$db->close();
?>
