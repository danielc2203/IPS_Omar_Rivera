<?php
// public_html/admin/migration_billing_v2.php
require_once '../config.php';

if (php_sapi_name() !== 'cli') {
    if (!isLoggedIn()) {
        die("Acceso denegado. Debe iniciar sesión.");
    }
    if (!isAdmin() && !isDesarrollador()) {
        if (!isAdmin()) die("Acceso denegado. Solo Admin o Desarrollador.");
    }
}

$db = getDBConnection();

echo "<h2>Migración Sistema de Cobro V2 (Precios por Empresa y Protocolos)</h2>";

$queries = [
    // 1. Tabla Precios por Empresa (Overrides)
    "CREATE TABLE IF NOT EXISTS company_exam_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id VARCHAR(50) NOT NULL,
        exam_id INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES empresas(id) ON DELETE CASCADE,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
        UNIQUE KEY unique_company_exam (company_id, exam_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // 2. Tabla Protocolos (Paquetes)
    "CREATE TABLE IF NOT EXISTS protocols (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        company_id VARCHAR(50) NULL COMMENT 'Si es NULL es global, si tiene ID es específico',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES empresas(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // 3. Items del Protocolo
    "CREATE TABLE IF NOT EXISTS protocol_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        protocol_id INT NOT NULL,
        exam_id INT NOT NULL,
        FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    
    // 4. Update Tabla asignaciones (Notas)
    // Usamos ADD COLUMN IF NOT EXISTS logic (MariaDB 10.2+ / MySQL 8.0+)
    // Si es versión vieja, el IF NOT EXISTS puede fallar en ADD COLUMN, asi que mejor try catch
    "ALTER TABLE patient_exams ADD COLUMN note TEXT NULL AFTER price_snapshot"
];

foreach ($queries as $sql) {
    try {
        if ($db->query($sql) === TRUE) {
            echo "<div style='color:green'>Query OK: " . substr($sql, 0, 80) . "...</div>";
        } else {
            // Ignoramos error de columna duplicada
            if (strpos($db->error, "Duplicate column") !== false) {
                 echo "<div style='color:orange'>Info: Columna ya existe.</div>";
            } else {
                 echo "<div style='color:red'>Error: " . $db->error . "</div>";
            }
        }
    } catch (Exception $e) {
         echo "<div style='color:red'>Exception: " . $e->getMessage() . "</div>";
    }
}

echo "<hr><p>Migración V2 finalizada.</p>";
