<?php
require_once '../config.php';

// Seguridad: Solo Admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// --- BACKEND: PROCESAR GUARDADO (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    ob_clean(); // Limpiar buffer
    header('Content-Type: application/json');
    
    try {
        $uploadDir = '../assets/uploads/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        // 1. Manejo del Logo (Si se subió uno nuevo)
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'logo_empresa.' . $ext; // Nombre fijo para reemplazar el anterior
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $targetPath)) {
                // Guardamos la ruta relativa LIMPIA en la BD
                $_POST['logo_url'] = 'assets/uploads/' . $filename; 
            }
        }

        // 2. Manejo de Tipos de Documento (Tabla Dedicada)
        if (isset($_POST['document_types_json'])) {
            $docs = json_decode($_POST['document_types_json'], true);
            if (is_array($docs)) {
                $db->query("TRUNCATE TABLE document_types"); // Limpiar tabla
                $stmtDoc = $db->prepare("INSERT INTO document_types (code, name) VALUES (?, ?)");
                foreach ($docs as $code => $name) {
                    $stmtDoc->bind_param("ss", $code, $name);
                    $stmtDoc->execute();
                }
            }
        }

        // 3. Guardar cada configuración enviada en la tabla 'settings'
        // Campos permitidos para evitar inyecciones raras
        $allowedKeys = ['site_name', 'support_email', 'sender_email', 'theme_color', 'theme_sidebar_bg', 'theme_font_family', 'consent_text', 'logo_url', 'kiosk_bg_color', 'kiosk_header_color', 'enable_kiosk_survey', 'enable_patient_emails'];
        
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($allowedKeys as $key) {
            if (isset($_POST[$key])) {
                $val = $_POST[$key]; // No sanitizamos en exceso el texto del consentimiento para permitir saltos de línea
                $stmt->bind_param("ss", $key, $val);
                $stmt->execute();
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Configuración actualizada correctamente.']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// --- VISTA: OBTENER CONFIGURACIÓN ACTUAL ---
$settings = [];
$res = $db->query("SELECT setting_key, setting_value FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Valores por defecto si no existen en BD
$defaults = [
    'site_name' => 'Sistema Consentimientos',
    'support_email' => 'soporte@tecco.com.co',
    'theme_color' => '#0d6efd',
    'theme_sidebar_bg' => '#212529',
    'theme_font_family' => 'system',
    'logo_url' => '',
    'consent_text' => "Yo, {{FULL_NAME}}, identificado con {{DOCUMENT_TYPE}} {{DOCUMENT_NUMBER}}...",
    'kiosk_bg_color' => '#f8f9fa',
    'kiosk_header_color' => '#0d6efd',
    'enable_kiosk_survey' => '1',
    'enable_patient_emails' => '0'
];
$conf = array_merge($defaults, $settings);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración | Panel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    
    <style>
        .color-preview { width: 30px; height: 30px; border-radius: 50%; display: inline-block; vertical-align: middle; border: 2px solid #ddd; cursor: pointer;}
        .logo-preview { max-height: 100px; border: 1px dashed #ccc; padding: 5px; border-radius: 5px; background: #f8f9fa; }
        .variable-tag { cursor: pointer; user-select: none; }
        .variable-tag:hover { opacity: 0.8; }
        .color-preset { width: 25px; height: 25px; border-radius: 50%; cursor: pointer; border: 2px solid #fff; box-shadow: 0 0 0 1px #ddd; transition: transform 0.2s; }
        .color-preset:hover { transform: scale(1.2); }
    </style>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <?php 
            $logoUrl = !empty($conf['logo_url']) ? '../' . $conf['logo_url'] : '';
        ?>
        <a class="navbar-brand mb-0 h1 d-flex align-items-center" href="dashboard.php">
            <?php if($logoUrl && file_exists($logoUrl)): ?>
                <img src="<?php echo $logoUrl; ?>" alt="Logo" height="30" class="d-inline-block align-text-top me-2 bg-white rounded px-1">
            <?php endif; ?>
            <span>Administración</span>
        </a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
            <a href="logs.php" class="btn btn-outline-light btn-sm me-2">Auditoría</a>
            <a href="manual.php" class="btn btn-outline-info btn-sm me-2 fw-bold"><i class="bi bi-book"></i> Manual</a>
            <a href="change_password.php" class="btn btn-outline-light btn-sm me-2">Contraseña</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold">Configuración del Sistema</h2>
            <button class="btn btn-success px-4" onclick="guardarTodo()">
                <i class="bi bi-save me-2"></i> Guardar Cambios
            </button>
        </div>

        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="list-group shadow-sm" id="list-tab" role="tablist">
                    <a class="list-group-item list-group-item-action active" data-bs-toggle="list" href="#tab-general">
                        <i class="bi bi-sliders me-2"></i> General
                    </a>
                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#tab-docs">
                        <i class="bi bi-card-checklist me-2"></i> Tipos de Doc.
                    </a>
                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#tab-apariencia">
                        <i class="bi bi-palette me-2"></i> Apariencia & Logo
                    </a>

                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#tab-kiosco">
                        <i class="bi bi-tablet me-2"></i> Kiosco
                    </a>
                </div>
            </div>

            <div class="col-md-9">
                <form id="configForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_config">
                    
                    <div class="tab-content" id="nav-tabContent">
                        
                        <div class="tab-pane fade show active" id="tab-general">
                            <div class="card shadow-sm border-0 p-4">
                                <h5 class="mb-3 border-bottom pb-2 text-primary">Información Básica</h5>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Nombre del Sistema</label>
                                    <input type="text" name="site_name" class="form-control" value="<?php echo htmlspecialchars($conf['site_name']); ?>">
                                    <small class="text-muted">Aparecerá en el título de las pestañas del navegador.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Email de Soporte</label>
                                    <input type="email" name="support_email" class="form-control" value="<?php echo htmlspecialchars($conf['support_email']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Correo Remitente (Sistema)</label>
                                    <input type="email" name="sender_email" class="form-control" value="<?php echo htmlspecialchars($conf['sender_email'] ?? 'info@tecco.com.co'); ?>">
                                    <small class="text-muted">Dirección desde la cual se enviarán las encuestas y notificaciones.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold d-block">Notificaciones por Correo</label>
                                    <div class="form-check form-switch mt-2">
                                        <input type="hidden" name="enable_patient_emails" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" name="enable_patient_emails" id="enableEmails" value="1" <?php echo (getSetting('enable_patient_emails', '0') == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enableEmails">Enviar PDF firmado automáticamente al paciente</label>
                                    </div>
                                    <small class="text-muted">Desactive esta opción para suspender el envío de correos tras la firma del consentimiento.</small>
                                </div>
                            </div>
                        </div>



                        <div class="tab-pane fade" id="tab-docs">
                             <div class="card shadow-sm border-0 p-4">
                                <h5 class="mb-3 border-bottom pb-2 text-primary">Gestión de Tipos de Documento</h5>
                                <p class="small text-muted">Defina los tipos de documento que aparecerán en las listas desplegables del sistema.</p>
                                
                                <input type="hidden" name="document_types_json" id="doc_types_input">
                                
                                <div class="table-responsive mb-3">
                                    <table class="table table-bordered table-hover" id="table-docs">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Código (Ej: CC)</th>
                                                <th>Nombre (Ej: Cédula de Ciudadanía)</th>
                                                <th style="width: 50px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Dynamically Filled -->
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addDocTypeRow()">
                                    <i class="bi bi-plus-lg"></i> Agregar Fila
                                </button>
                             </div>
                        </div>

                        <div class="tab-pane fade" id="tab-apariencia">
                            <div class="card shadow-sm border-0 p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0 text-primary border-bottom pb-2">Identidad Visual</h5>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetApariencia()">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restablecer Colores
                                    </button>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Logo de la Empresa</label>
                                        <input type="file" name="site_logo" class="form-control mb-2" accept="image/*" onchange="previewLogo(this)">
                                        <small class="text-muted d-block">Recomendado: PNG Transparente (Max 2MB)</small>
                                    </div>
                                    <div class="col-md-6 text-center">
                                        <label class="form-label d-block fw-bold">Vista Previa</label>
                                        <?php 
                                            $logoSrc = !empty($conf['logo_url']) ? '../' . $conf['logo_url'] . '?v=' . time() : 'https://via.placeholder.com/150?text=Sin+Logo';
                                        ?>
                                        <img id="logo-img" src="<?php echo $logoSrc; ?>" class="logo-preview img-fluid">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Color Principal del Tema</label>
                                    <div class="d-flex align-items-center gap-3">
                                        <input type="color" name="theme_color" id="theme_color" class="form-control form-control-color" value="<?php echo $conf['theme_color']; ?>" title="Elige un color">
                                        <div class="d-flex gap-2" id="presets_theme_color">
                                            <div class="color-preset" style="background:#0d6efd;" onclick="setPreset('theme_color', '#0d6efd')" title="Azul Corporativo"></div>
                                            <div class="color-preset" style="background:#198754;" onclick="setPreset('theme_color', '#198754')" title="Verde Salud"></div>
                                            <div class="color-preset" style="background:#dc3545;" onclick="setPreset('theme_color', '#dc3545')" title="Rojo Moderno"></div>
                                            <div class="color-preset" style="background:#6610f2;" onclick="setPreset('theme_color', '#6610f2')" title="Violeta Tech"></div>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <span class="text-muted d-block small">Color de botones y enlaces.</span>
                                        <span class="badge bg-primary border border-secondary shadow-sm mt-1">
                                            <i class="bi bi-magic"></i> Contraste Automático: <?php echo (getContrastColor($conf['theme_color']) == '#000000') ? 'Negro (Oscuro)' : 'Blanco (Claro)'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Color de Barra Lateral</label>
                                    <div class="d-flex align-items-center gap-3">
                                        <input type="color" name="theme_sidebar_bg" id="theme_sidebar_bg" class="form-control form-control-color" value="<?php echo $conf['theme_sidebar_bg']; ?>" title="Elige un color">
                                        <div class="d-flex gap-2">
                                            <div class="color-preset" style="background:#212529;" onclick="setPreset('theme_sidebar_bg', '#212529')" title="Oscuro"></div>
                                            <div class="color-preset" style="background:#f8f9fa;" onclick="setPreset('theme_sidebar_bg', '#f8f9fa')" title="Claro"></div>
                                            <div class="color-preset" style="background:#343a40;" onclick="setPreset('theme_sidebar_bg', '#343a40')" title="Gris Profundo"></div>
                                        </div>
                                        <span class="text-muted small"> Fondo del menú lateral.</span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Tipografía Principal</label>
                                    <select name="theme_font_family" class="form-select">
                                        <option value="system" <?php echo ($conf['theme_font_family'] === 'system') ? 'selected' : ''; ?>>Del Sistema (Rápida)</option>
                                        <option value="inter" <?php echo ($conf['theme_font_family'] === 'inter') ? 'selected' : ''; ?>>Inter (Moderna)</option>
                                        <option value="roboto" <?php echo ($conf['theme_font_family'] === 'roboto') ? 'selected' : ''; ?>>Roboto (Google)</option>
                                        <option value="open_sans" <?php echo ($conf['theme_font_family'] === 'open_sans') ? 'selected' : ''; ?>>Open Sans</option>
                                    </select>
                                </div>
                            </div>
                        </div>



                        <div class="tab-pane fade" id="tab-kiosco">
                            <div class="card shadow-sm border-0 p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0 text-primary border-bottom pb-2">Personalización Modo Kiosco</h5>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetKiosco()">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restablecer Colores
                                    </button>
                                </div>
                                <p class="small text-muted">Ajuste los colores de la interfaz de tableta (/kiosk.php).</p>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Color del Encabezado</label>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="color" name="kiosk_header_color" id="kiosk_header_color" class="form-control form-control-color" value="<?php echo $conf['kiosk_header_color']; ?>">
                                            <div class="d-flex gap-2">
                                                <div class="color-preset" style="background:#0d6efd;" onclick="setPreset('kiosk_header_color', '#0d6efd')"></div>
                                                <div class="color-preset" style="background:#212529;" onclick="setPreset('kiosk_header_color', '#212529')"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Color de Fondo General</label>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="color" name="kiosk_bg_color" id="kiosk_bg_color" class="form-control form-control-color" value="<?php echo $conf['kiosk_bg_color']; ?>">
                                            <div class="d-flex gap-2">
                                                <div class="color-preset" style="background:#f8f9fa;" onclick="setPreset('kiosk_bg_color', '#f8f9fa')"></div>
                                                <div class="color-preset" style="background:#ffffff;" onclick="setPreset('kiosk_bg_color', '#ffffff')"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 border-top pt-3">
                                    <h6 class="fw-bold mb-3"><i class="bi bi-gear-wide-connected me-2"></i> Módulos Adicionales</h6>
                                    <div class="form-check form-switch bg-light p-3 rounded border">
                                        <input class="form-check-input ms-0 me-3" type="checkbox" role="switch" id="checkSurvey" name="enable_kiosk_survey" value="1" <?php echo ($conf['enable_kiosk_survey'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="checkSurvey">
                                            Habilitar Módulo de Encuestas en el Home
                                        </label>
                                        <div class="text-muted small ms-5 mt-1">Muestra el botón "Encuesta" en la página principal para que los pacientes califiquen su atención desde el kiosco/tablet.</div>
                                    </div>
                                    <input type="hidden" name="enable_kiosk_survey" value="0"> <!-- Hidden field to ensure '0' is sent if unchecked -->
                                </div>
                            </div>
                        </div>

                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/ui-manager.js"></script>

    <script>
        // Funciones de Reset y Presets
        const DEFAULTS = <?php echo json_encode($defaults); ?>;

        function setPreset(id, color) {
            document.getElementById(id).value = color;
        }

        function resetApariencia() {
            document.getElementById('theme_color').value = DEFAULTS.theme_color;
            document.getElementById('theme_sidebar_bg').value = DEFAULTS.theme_sidebar_bg;
            document.getElementsByName('theme_font_family')[0].value = DEFAULTS.theme_font_family;
            UI.success('Colores restablecidos', 'No olvides guardar los cambios.');
        }

        function resetKiosco() {
            document.getElementById('kiosk_header_color').value = DEFAULTS.kiosk_header_color;
            document.getElementById('kiosk_bg_color').value = DEFAULTS.kiosk_bg_color;
            UI.success('Estilos de kiosco restablecidos', 'No olvides guardar los cambios.');
        }

        // Vista previa de imagen
        function previewLogo(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('logo-img').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Insertar variables en el textarea
        function insertVar(text) {
            const textarea = document.getElementById('consent_text');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const value = textarea.value;
            
            textarea.value = value.substring(0, start) + text + value.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + text.length;
            textarea.focus();
        }

        // Guardar Datos
        function guardarTodo() {
            // Serializar Tipos de Documento
            const rows = document.querySelectorAll('#table-docs tbody tr');
            let docObj = {};
            rows.forEach(tr => {
                const code = tr.querySelector('.doc-code').value.trim().toUpperCase();
                const name = tr.querySelector('.doc-name').value.trim();
                if(code && name) {
                    docObj[code] = name;
                }
            });
            document.getElementById('doc_types_input').value = JSON.stringify(docObj);

            UI.loading('Guardando configuración...');
            
            const form = document.getElementById('configForm');
            const formData = new FormData(form);

            fetch('configuracion.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    UI.success('Guardado', res.message, () => location.reload());
                } else {
                    console.error("Server Error:", res);
                    UI.error('Error', res.message);
                }
            })
            .catch(err => {
                console.error(err);
                UI.error('Error', 'Fallo de conexión al guardar.');
            });
        }
        
        // --- LOGIC FOR DOC TYPES ---
        const currentDocs = <?php echo json_encode(getDocumentTypes()); ?>;
        
        function renderDocTypes() {
            const tbody = document.querySelector('#table-docs tbody');
            tbody.innerHTML = '';
            for (let code in currentDocs) {
                addDocTypeRow(code, currentDocs[code]);
            }
        }
        
        function addDocTypeRow(code = '', name = '') {
            const tbody = document.querySelector('#table-docs tbody');
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="text" class="form-control form-control-sm doc-code" value="${code}" placeholder="COD"></td>
                <td><input type="text" class="form-control form-control-sm doc-name" value="${name}" placeholder="Nombre Completo"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="this.closest('tr').remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        }
        
        // Init
        document.addEventListener('DOMContentLoaded', renderDocTypes);
    </script>
</body>
</html>