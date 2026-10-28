<?php
// public_html/config.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- CREDENCIALES ---
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_USER', getenv('DB_USER') ?: 'consent_app');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'consentimientos');
define('SECRET_KEY', getenv('APP_SECRET_KEY') ?: '');

// 1. ZONA HORARIA PHP
date_default_timezone_set('America/Bogota');

// --- CONEXIÓN ---
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Error de conexión: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    
    // 2. ZONA HORARIA MYSQL
    $conn->query("SET time_zone = '" . date('P') . "'");
    
    return $conn;
}

// --- SEGURIDAD ---
function sanitizeInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// --- ROLES ---
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
function isAdmin() {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'admin';
}
function isEmpresa() {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'empresa';
}
function isRecepcionista() {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'recepcionista';
}
function isProfesional() {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'profesional';
}
function isAuditor() {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'auditor';
}
function isDesarrollador() {
    // 1. Rol nativo
    if (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'desarrollador') return true;
    // 2. Admin en Modo Dev
    if (isAdmin() && isset($_SESSION['dev_mode']) && $_SESSION['dev_mode'] === true) return true;
    
    return false;
}

// --- PERMISOS ---
function canDelete() {
    return isAdmin();
}
function canExport() {
    return isAdmin() || isAuditor();
}
function canManageSettings() {
    return isAdmin();
}
function canCreatePatients() {
    return isAdmin() || isRecepcionista() || isEmpresa();
}
function canViewPatients() {
    // Todos pueden ver, pero con restricciones de fila (Empresa/Profesional ven asignados, Recepcionista ve todos o asignados)
    // Por simplicidad en este sistema: todos los roles internos pueden ver lista global por ahora.
    return true; 
}
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}
function getMyCompanyId() {
    return $_SESSION['empresa_id'] ?? null;
}

// --- AUTENTICACIÓN ---
function authenticateUser($username, $password) {
    $db = getDBConnection();
    $sql = "SELECT id, username, password_hash, nombre_completo, rol, empresa_id 
            FROM usuarios 
            WHERE username = ? AND activo = 1 LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password_hash'])) {
            return $user;
        }
    }
    return false;
}

// --- CSRF TOKEN (¡Esta función faltaba!) ---
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Inicializar token si no existe
if (empty($_SESSION['csrf_token'])) {
    generateCSRFToken();
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --- CONFIGURACIÓN GLOBAL (Theme & Settings) ---
$GLOBAL_SETTINGS = [];

function loadGlobalSettings() {
    global $GLOBAL_SETTINGS;
    // Evitar recargar si ya está cargado
    if (!empty($GLOBAL_SETTINGS)) return;

    $db = getDBConnection();
    $res = $db->query("SELECT setting_key, setting_value FROM settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $GLOBAL_SETTINGS[$row['setting_key']] = $row['setting_value'];
        }
    }
}

function getSetting($key, $default = '') {
    global $GLOBAL_SETTINGS;
    if (empty($GLOBAL_SETTINGS)) loadGlobalSettings();
    return $GLOBAL_SETTINGS[$key] ?? $default;
}

function applyTheme() {
    $primary = getSetting('theme_color', '#0d6efd');
    $sidebar = getSetting('theme_sidebar_bg', '#212529');
    $font = getSetting('theme_font_family', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif');
    
    // Mapeo de fuentes amigables a stack real
    $fontStacks = [
        'inter' => "'Inter', sans-serif",
        'roboto' => "'Roboto', sans-serif",
        'open_sans' => "'Open Sans', sans-serif",
        'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    ];
    $fontFamily = $fontStacks[$font] ?? $font;

    // Fuentes externas si son necesarias
    $googleFonts = "";
    if ($font === 'inter') $googleFonts = '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">';
    if ($font === 'roboto') $googleFonts = '<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">';
    if ($font === 'open_sans') $googleFonts = '<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">';

    echo $googleFonts;
    
    // Calcular contraste para distintos elementos
    $textOnPrimary = getContrastColor($primary);
    $textOnSidebar = getContrastColor($sidebar);
    
    // Definir colores derivados para estados interactivos del sidebar
    // Si el texto es blanco (fondo oscuro), el hover será blanco con transparencia.
    // Si el texto es negro (fondo claro), el hover será negro con transparencia.
    $hoverBg = ($textOnSidebar == '#ffffff') ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
    
    echo "<style>
        :root {
            --bs-primary: $primary;
            --bs-primary-rgb: " . hex2rgb($primary) . ";
            --sidebar-bg: $sidebar;
            --sidebar-text: $textOnSidebar;
            --sidebar-hover: $hoverBg;
            --font-main: $fontFamily;
            --text-on-primary: $textOnPrimary; 
        }
        
        body { font-family: var(--font-main) !important; }
        
        /* Tema Colores Principales */
        .bg-primary { background-color: var(--bs-primary) !important; color: var(--text-on-primary) !important; }
        .text-primary { color: var(--bs-primary) !important; }
        .btn-primary { 
            background-color: var(--bs-primary) !important; 
            border-color: var(--bs-primary) !important; 
            color: var(--text-on-primary) !important; 
        }
        .btn-outline-primary { color: var(--bs-primary) !important; border-color: var(--bs-primary) !important; }
        .btn-outline-primary:hover { background-color: var(--bs-primary) !important; color: var(--text-on-primary) !important; }
        
        /* Sidebar Moderno */
        .sidebar { 
            background-color: var(--sidebar-bg) !important; 
            min-height: 100vh;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar .nav-link {
            color: var(--sidebar-text) !important;
            opacity: 0.85;
            padding: 12px 20px;
            margin: 4px 10px;
            border-radius: 8px;
            transition: all 0.2s ease-in-out;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .sidebar .nav-link i {
            font-size: 1.1em;
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }
        
        .sidebar .nav-link:hover {
            background-color: var(--sidebar-hover) !important;
            opacity: 1;
            transform: translateX(4px);
        }
        
        .sidebar .nav-link.active {
            background-color: var(--bs-primary) !important;
            color: var(--text-on-primary) !important;
            opacity: 1;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
        }
        
        .sidebar small.text-warning {
            color: var(--bs-primary) !important; /* Reutilizamos el color primario para el rol si resalta */
            filter: brightness(1.2);
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .navbar-dark.bg-dark { background-color: var(--sidebar-bg) !important; }
        .navbar-brand { color: var(--sidebar-text) !important; }
    </style>";
}

function hex2rgb($hex) {
    $hex = str_replace("#", "", $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r, $g, $b";
}

function getContrastColor($hexColor) {
    // R, G, B values from hex
    $hexColor = str_replace('#', '', $hexColor);
    $r = hexdec(substr($hexColor, 0, 2));
    $g = hexdec(substr($hexColor, 2, 2));
    $b = hexdec(substr($hexColor, 4, 2));
    
    // Calcular YIQ
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    
    // Si es oscuro devuelve blanco, si es claro devuelve negro
    return ($yiq >= 128) ? '#000000' : '#ffffff';
}

// --- LOGGING SYSTEM ---
function logActivity($accion, $tabla, $detalles = '') {
    try {
        $db = getDBConnection();
        $userId = $_SESSION['user_id'] ?? null; // Null si es paciente público
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        
        // Crear tabla si no existe (Auto-migración) - Optimizada para no fallar
        // Table structure is static, removed runtime check for performance
        
        
        $stmt = $db->prepare("INSERT INTO registro_actividades (usuario_id, accion, tabla_afectada, detalles, ip_origen) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("issss", $userId, $accion, $tabla, $detalles, $ip);
            $stmt->execute();
        }
    } catch (Exception $e) {
        // Silenciar errores de log para no detener la aplicación
        error_log("Error en logs: " . $e->getMessage());
    }
}

// Helpers existentes
function getConsentText() {
    return getSetting('consent_text', 'Texto no configurado.');
}

function getAllActiveConsentTemplates() {
    $db = getDBConnection();
    $res = $db->query("SELECT id, name FROM consent_types WHERE is_active = 1 AND type = 'consentimiento' ORDER BY name ASC");
    $data = [];
    if ($res) {
        while($row = $res->fetch_assoc()) $data[] = $row;
    }
    return $data;
}

function getActiveSwornDeclaration() {
    $db = getDBConnection();
    $res = $db->query("SELECT content FROM consent_types WHERE is_active = 1 AND type = 'declaracion' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) return $row['content'];
    return ""; // Empty if not set
}

function getActiveWithdrawalTemplate() {
    $db = getDBConnection();
    $res = $db->query("SELECT content FROM consent_types WHERE is_active = 1 AND type = 'desistimiento' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) return $row['content'];
    return "Certifico que he sido informado de la importancia de la realización de estos exámenes para mi valoración médica y los riesgos de no contar con estos resultados. A pesar de ello, desisto de su ejecución y libero de responsabilidad a la IPS y a mi empresa empleadora por las consecuencias derivadas de esta decisión."; // Fallback
}

function getConsentTemplateContent($id) {
    if(!$id) return getConsentText(); // Fallback
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT content FROM consent_types WHERE id = ? AND is_active = 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($row = $res->fetch_assoc()) return $row['content'];
    return getConsentText(); // Fallback
}

// --- HELPER DE TIPOS DE DOCUMENTO (DB TABLE) ---
function getDocumentTypes() {
    $db = getDBConnection();
    $types = [];
    $result = $db->query("SELECT code, name FROM document_types ORDER BY name ASC");
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $types[$row['code']] = $row['name'];
        }
    } else {
        // Fallback si la tabla está vacía o falla
        $types = [
            'CC' => 'Cédula de Ciudadanía',
            'TI' => 'Tarjeta de Identidad',
            'CE' => 'Cédula de Extranjería',
            'PAS' => 'Pasaporte'
        ];
    }
    return $types;
}