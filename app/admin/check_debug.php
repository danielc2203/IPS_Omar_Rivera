<?php
require_once '../config.php';
session_start();
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
echo "isDesarrollador(): " . (isDesarrollador() ? 'YES' : 'NO') . "<br>";
echo "isAdmin(): " . (isAdmin() ? 'YES' : 'NO') . "<br>";
