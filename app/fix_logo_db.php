<?php
require_once 'config.php';
$db = getDBConnection();

$res = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_url'");
if ($row = $res->fetch_assoc()) {
    $currentVal = $row['setting_value'];
    // Quitar todo después de ? con explode
    $cleanVal = explode('?', $currentVal)[0];
    
    if ($cleanVal !== $currentVal) {
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'logo_url'");
        $stmt->bind_param("s", $cleanVal);
        $stmt->execute();
        echo "FIXED: Logo URL cleaned. Was: $currentVal | Now: $cleanVal";
    } else {
        echo "OK: Logo URL was already clean: $currentVal";
    }
} else {
    echo "INFO: No logo_url setting found.";
}
?>
