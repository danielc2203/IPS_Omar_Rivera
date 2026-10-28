<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
$db = getDBConnection();

echo "<h1>Diagnóstico de BD</h1>";

// 1. Check Consents columns
echo "<h3>Columnas de 'consents'</h3>";
$res = $db->query("SHOW COLUMNS FROM consents");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "<br>";
}

// 2. Check Survey_Responses columns
echo "<h3>Columnas de 'survey_responses'</h3>";
$res = $db->query("SHOW COLUMNS FROM survey_responses");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "<br>";
}

// 3. Test Query
echo "<h3>Test Query Missing</h3>";
$sqlMissing = "SELECT c.id, c.created_at FROM consents c 
               LEFT JOIN survey_responses s ON c.id = s.consent_id 
               WHERE s.id IS NULL LIMIT 5";
$res = $db->query($sqlMissing);
if($res) {
    echo "Query OK. Found: " . $res->num_rows . " rows.<br>";
    while($row = $res->fetch_assoc()) {
        echo "ID: " . $row['id'] . " - Date: " . ($row['created_at'] ?? 'NULL') . "<br>";
    }
} else {
    echo "Query Failed: " . $db->error;
}
?>
