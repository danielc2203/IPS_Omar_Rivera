<?php
// admin/migration_witness.php
require_once __DIR__ . '/../config.php';

echo "<h1>Migración: Agregar campos de Testigo</h1>";

$db = getDBConnection();

// Campos a agregar: witness_name, witness_doc_number, witness_signature
$cols = [
    'witness_name' => "VARCHAR(255) NULL AFTER signature",
    'witness_doc_number' => "VARCHAR(50) NULL AFTER witness_name",
    'witness_signature' => "MEDIUMTEXT NULL AFTER witness_doc_number" // Base64
];

foreach ($cols as $colName => $def) {
    echo "Verificando $colName... ";
    $res = $db->query("SHOW COLUMNS FROM consents LIKE '$colName'");
    if($res->num_rows > 0) {
        echo "Ya existe.<br>";
    } else {
        if($db->query("ALTER TABLE consents ADD COLUMN $colName $def")) {
            echo "<b>Agregada.</b><br>";
        } else {
            echo "<span style='color:red'>Error: " . $db->error . "</span><br>";
        }
    }
}
echo "Terminado.";
?>
