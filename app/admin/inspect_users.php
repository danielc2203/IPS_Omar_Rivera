<?php
require_once '../config.php';
$db = getDBConnection();
$res = $db->query("DESCRIBE usuarios");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
