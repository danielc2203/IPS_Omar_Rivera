<?php
// public_html/admin/migration_billing.php
require_once '../config.php';

if (php_sapi_name() !== 'cli') {
    if (!isLoggedIn()) {
        die("Acceso denegado. Debe iniciar sesión.");
    }
    // Opcional: Solo permitir a un admin ejecutar esto, o a cualquiera durante dev
    if (!isAdmin() && !isDesarrollador()) {
        // Para simplificar, si no hay rol desarrollador aun, dejamos pasar a admin
        if (!isAdmin()) die("Acceso denegado. Solo Admin o Desarrollador.");
    }
}

$db = getDBConnection();

echo "<h2>Migración Sistema de Cobro</h2>";

$queries = [
    // 1. Tabla Tipos de Examen
    "CREATE TABLE IF NOT EXISTS exams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // 2. Tabla Relación Paciente - Examen
    // Guardamos 'price_snapshot' por si el precio del examen cambia a futuro, el cobro historico se mantenga
    "CREATE TABLE IF NOT EXISTS patient_exams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        exam_id INT NOT NULL,
        price_snapshot DECIMAL(10,2) NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        assigned_by INT NULL,
        FOREIGN KEY (patient_id) REFERENCES pacientes_pre_registrados(id) ON DELETE CASCADE,
        FOREIGN KEY (exam_id) REFERENCES exams(id),
        FOREIGN KEY (assigned_by) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
];

foreach ($queries as $sql) {
    if ($db->query($sql) === TRUE) {
        echo "<div style='color:green'>Query OK: " . substr($sql, 0, 50) . "...</div>";
    } else {
        echo "<div style='color:red'>Error: " . $db->error . "</div>";
    }
}

// 3. Insertar datos de prueba si está vacío
$res = $db->query("SELECT COUNT(*) as c FROM exams");
$row = $res->fetch_assoc();
if ($row['c'] == 0) {
    $db->query("INSERT INTO exams (name, cost, description) VALUES 
        ('Examen Básico', 5000.00, 'Exámen de rutina estándar'),
        ('Examen Avanzado', 15000.00, 'Incluye pruebas específicas'),
        ('Audiometría', 12000.00, 'Prueba de audición')");
    echo "<div>Datos semilla insertados en 'exams'.</div>";
}

echo "<hr><p>Proceso finalizado. <a href='dashboard.php'>Volver</a></p>";
