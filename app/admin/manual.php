<?php
require_once '../config.php';

// Seguridad: Solo usuarios logueados
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$siteName = getSetting('site_name', 'Sistema de Consentimientos');
$logoUrl = getSetting('logo_url', '');
if ($logoUrl) $logoUrl = '../' . $logoUrl;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual | <?php echo $siteName; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        body { background-color: #f8f9fa; }
        .doc-sidebar {
            position: sticky;
            top: 20px;
            height: calc(100vh - 40px);
            overflow-y: auto;
            border-right: 1px solid #dee2e6;
            padding-right: 15px;
        }
        .doc-content {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 40px;
        }
        .nav-link-doc {
            padding: 8px 15px;
            border-radius: 6px;
            color: #495057;
            text-decoration: none;
            display: block;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        .nav-link-doc:hover { background-color: rgba(var(--bs-primary-rgb), 0.1); color: var(--bs-primary); }
        .nav-link-doc.active { background-color: var(--bs-primary); color: white !important; font-weight: 600; }
        
        section { scroll-margin-top: 30px; margin-bottom: 50px; }
        h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 40px; font-weight: 700; color: var(--bs-primary); }
        h3 { margin-top: 30px; font-weight: 600; color: #333; }
        .callout { padding: 20px; border-left: 5px solid; border-radius: 4px; margin: 20px 0; }
        .callout-info { background: #e7f1ff; border-color: #0d6efd; }
        .callout-warning { background: #fff3cd; border-color: #ffc107; }
        .callout-success { background: #d1e7dd; border-color: #198754; }
        
        .badge-func { font-size: 0.75rem; vertical-align: middle; margin-left: 5px; }
        pre { background: #f1f3f5; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef; }
        code { color: #d63384; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark sticky-top px-3">
    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <?php if($logoUrl && file_exists($logoUrl)): ?>
            <img src="<?php echo $logoUrl; ?>" height="30" class="me-2 bg-white rounded px-1">
        <?php endif; ?>
        <span>Manual Técnico y de Funcionamiento</span>
    </a>
    <div>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Volver al Panel</a>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="row">
        <!-- Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 d-none d-md-block">
            <div class="doc-sidebar" id="scrollspy-nav">
                <h6 class="text-uppercase text-muted fw-bold small mb-3">Introducción</h6>
                <nav class="nav flex-column mb-4">
                    <a class="nav-link-doc" href="#intro-general">Visión General</a>
                    <a class="nav-link-doc" href="#arquitectura">Arquitectura</a>
                </nav>

                <h6 class="text-uppercase text-muted fw-bold small mb-3">Módulos Públicos</h6>
                <nav class="nav flex-column mb-4">
                    <a class="nav-link-doc" href="#consent-flow">Consentimiento Informado</a>
                    <a class="nav-link-doc" href="#kiosk-mode">Modo Kiosco</a>
                    <a class="nav-link-doc" href="#withdrawals-public">Desistimientos</a>
                    <a class="nav-link-doc" href="#surveys-public">Encuestas de Satisfacción</a>
                </nav>

                <h6 class="text-uppercase text-muted fw-bold small mb-3">Gestión Admin</h6>
                <nav class="nav flex-column mb-4">
                    <a class="nav-link-doc" href="#dashboard-admin">Dashboard e Indicadores</a>
                    <a class="nav-link-doc" href="#patients-admin">Gestión de Pacientes</a>
                    <a class="nav-link-doc" href="#exams-protocols">Exámenes y Protocolos</a>
                    <a class="nav-link-doc" href="#templates-admin">Plantillas Dinámicas</a>
                </nav>

                <h6 class="text-uppercase text-muted fw-bold small mb-3">Técnico & Seguridad</h6>
                <nav class="nav flex-column mb-4">
                    <a class="nav-link-doc" href="#settings-tech">Configuración Visual</a>
                    <a class="nav-link-doc" href="#security-hashes">hashes y Auditoría</a>
                    <a class="nav-link-doc" href="#backups-tech">Mantenimiento y Copias</a>
                </nav>
            </div>
        </div>

        <!-- Content Area -->
        <div class="col-md-9 col-lg-10">
            <div class="doc-content" data-bs-spy="scroll" data-bs-target="#scrollspy-nav" data-bs-smooth-scroll="true" tabindex="0">
                
                <section id="intro-general">
                    <h1>Manual del Sistema de Consentimientos</h1>
                    <p class="lead text-muted">Documentación completa sobre el funcionamiento técnico y operativo de la plataforma.</p>
                    
                    <h2>1. Visión General</h2>
                    <p>Esta plataforma ha sido desarrollada para la <strong>IPS</strong> con el objetivo de digitalizar y optimizar el proceso de firma de consentimientos informados, gestión de desistimientos y recolección de encuestas de satisfacción.</p>
                    <div class="callout callout-info">
                        <strong>Propósito Principal:</strong> Garantizar la seguridad de la información, la trazabilidad legal de las firmas y la eficiencia administrativa en el proceso de recepción de pacientes.
                    </div>
                    <p>El sistema se divide en tres frentes principales:</p>
                    <ul>
                        <li><strong>Portal de Pacientes (Kiosco):</strong> Interfaz táctil para que los pacientes lean y firmen sus documentos.</li>
                        <li><strong>Panel Administrativo:</strong> Herramientas para gestionar pacientes, usuarios, empresas y reportes.</li>
                        <li><strong>Módulo de Especialistas:</strong> Interfaz simplificada para que los médicos marquen la realización de exámenes.</li>
                    </ul>
                </section>

                <section id="arquitectura">
                    <h2>2. Arquitectura Técnica</h2>
                    <p>El sistema está construido sobre una arquitectura robusta y ligera:</p>
                    <ul>
                        <li><strong>Lenguaje:</strong> PHP 8.x (Backend) y Vanilla JS (Frontend).</li>
                        <li><strong>Base de Datos:</strong> MariaDB / MySQL.</li>
                        <li><strong>Estética:</strong> Bootstrap 5 con un Motor de Temas dinámico basado en variables CSS (`:root`).</li>
                        <li><strong>Seguridad:</strong> Tokens CSRF, Hashing SHA-256 para integridad de documentos y sesiones protegidas.</li>
                    </ul>
                </section>

                <section id="consent-flow">
                    <h2>3. El Proceso de Consentimiento Informado</h2>
                    <p>Este es el núcleo del sistema. Permite capturar la voluntad del paciente de manera legal y digital.</p>
                    
                    <h3>Tipos de Registro</h3>
                    <ul>
                        <li><strong>Pre-registro (Recomendado):</strong> Recepción ingresa los datos del paciente y le asigna los exámenes o protocolos. Esto permite que cuando el paciente llegue al Kiosco, solo deba digitar su cédula y firmar inmediatamente.</li>
                        <li><strong>Auto-registro:</strong> Si la configuración lo permite, un paciente nuevo puede ingresar sus datos directamente en el Kiosco, aunque lo ideal es que ya esté en la base de datos para asegurar el vínculo con su empresa.</li>
                    </ul>

                    <h3>Lógica de Selección de Documentos</h3>
                    <p>El sistema es inteligente: no muestra todos los consentimientos existentes, sino solo aquellos que corresponden a los exámenes asignados al paciente en su "Ruta de Atención".</p>
                    <div class="callout callout-info">
                        <strong>Relación Examen-Plantilla:</strong> Cada examen en el sistema puede estar vinculado a una plantilla específica. Si un paciente tiene asignados 3 exámenes con plantillas distintas, el Kiosco le pedirá firmar los 3 documentos por separado.
                    </div>
                </section>

                <section id="kiosk-mode">
                    <h2>4. Modo Kiosco</h2>
                    <h3>Flujo de Firma Digital</h3>
                    <p>Diseñado para tablets, el flujo minimiza errores:</p>
                    <ol>
                        <li><strong>Ingreso:</strong> El paciente digita su documento.</li>
                        <li><strong>Selección:</strong> Si tiene múltiples pendientes, aparece una lista clara para elegir.</li>
                        <li><strong>Lectura:</strong> Se muestra el texto legal completo. Se recomienda que el personal asista al paciente para asegurar la comprensión.</li>
                        <li><strong>Captura de Firma:</strong> Se habilita un panel de dibujo táctil. Una vez firmado, no se puede modificar.</li>
                        <li><strong>Finalización:</strong> El sistema genera el PDF con el Hash de seguridad y lo guarda en el servidor.</li>
                    </ol>
                </section>

                <section id="withdrawals-public">
                    <h2>5. Desistimientos Informados</h2>
                    <p>Permite que el paciente decline la realización de un procedimiento de forma legal.</p>
                    <ul>
                        <li>Se accede desde la pantalla principal (`index.php`) o el menú de recepción.</li>
                        <li>Genera un documento PDF similar al consentimiento pero con validez de renuncia.</li>
                    </ul>
                </section>

                <section id="surveys-public">
                    <h2>6. Encuestas de Satisfacción</h2>
                    <p>El sistema mide la calidad del servicio a través de dos canales principales:</p>
                    
                    <h3>Canales de Recolección</h3>
                    <ul>
                        <li><strong>Email Automático:</strong> El sistema envía un correo electrónico al paciente con un link único minutos después de que este firma su consentimiento.</li>
                        <li><strong>Modo Kiosco:</strong> Al finalizar todas las firmas en la tablet, el sistema puede preguntar al paciente si desea calificar su experiencia en ese mismo momento.</li>
                    </ul>

                    <h3>Gestión de Resultados</h3>
                    <p>Desde el Panel Admin en <strong>"Encuestas"</strong>, se pueden ver los promedios de calificación y los comentarios de texto libre dejados por los pacientes.</p>
                    <div class="callout callout-success">
                        <strong>Autocompletado de Encuestas:</strong> Los administradores tienen una función de autocompletado rápido para casos donde el paciente entrega la encuesta física o verbalmente al personal.
                    </div>
                </section>

                <section id="dashboard-admin">
                    <h2>5. Panel Administrativo: Dashboard</h2>
                    <p>Es el centro de control para administradores y empresas externas.</p>
                    <ul>
                        <li><strong>Estadísticas en Tiempo Real:</strong> Gráficas de actividad semanal, distribución por empresa y productividad por especialista.</li>
                        <li><strong>Filtros Dinámicos:</strong> Las empresas externas solo pueden ver los datos pertenecientes a sus propios pacientes.</li>
                        <li><strong>Monitor de Recepción:</strong> Una vista especial para ver quién está en sala de espera y quién ya firmó.</li>
                    </ul>
                </section>

                <section id="patients-admin">
                    <h2>6. Gestión de Pacientes</h2>
                    <p>El módulo `patients.php` permite a la recepción gestionar la entrada de personas:</p>
                    <ul>
                        <li><strong>Pre-registro:</strong> Registrar al paciente antes de que llegue al kiosco para agilizar el flujo.</li>
                        <li><strong>Asignación de Protocolos:</strong> Al registrar un paciente, se le puede asignar un "Cargo/Perfil" (Protocolo) que carga automáticamente los exámenes necesarios.</li>
                        <li><strong>Carga Masiva:</strong> Importación de listas desde archivos CSV.</li>
                        <li><strong>Descarga Masiva:</strong> Generación de archivos ZIP con todos los PDFs de una empresa o rango de fechas.</li>
                    </ul>
                </section>

                <section id="exams-protocols">
                    <h2>7. Exámenes, Protocolos y Facturación</h2>
                    <p>Estos módulos (disponibles principalmente para el rol Desarrollador/Admin) permiten configurar la lógica de negocio:</p>
                    <ul>
                        <li><strong>Exámenes:</strong> Catálogo de servicios con sus costos base.</li>
                        <li><strong>Protocolos:</strong> Agrupaciones de exámenes (Ej: "Protocolo Conductor" = Visiometría + Audiometría).</li>
                        <li><strong>Facturación:</strong> Generación de reportes de cobro basados en los exámenes efectivamente realizados por los especialistas.</li>
                    </ul>
                </section>

                <section id="templates-admin">
                    <h2>8. Plantillas Dinámicas</h2>
                    <p>El corazón legal del sistema: `consent_templates.php`.</p>
                    <p>Permite crear y editar los textos legales. Soporta variables que se llenan solas:</p>
                    <ul>
                        <li><code>{{FULL_NAME}}</code>: Nombre completo del paciente.</li>
                        <li><code>{{DOCUMENT_NUMBER}}</code>: Cédula o documento.</li>
                        <li><code>{{DOCUMENT_TYPE}}</code>: CC, TI, etc.</li>
                    </ul>
                    <div class="callout callout-warning">
                        <strong>Nota Técnica:</strong> Cada vez que se cambia una plantilla, los consentimientos firmados anteriormente mantienen la versión original que se firmó en su momento (inmutabilidad).
                    </div>
                </section>

                <section id="settings-tech">
                    <h2>9. Personalización y Temas</h2>
                    <p>El módulo `configuracion.php` permite ajustar la identidad visual sin tocar código:</p>
                    <ul>
                        <li><strong>Logo:</strong> Se sube y se replica en todos los encabezados y PDFs.</li>
                        <li><strong>Colores:</strong> Selectores de color para el tema principal, barra lateral y modo kiosco.</li>
                        <li><strong>Notificaciones:</strong> Interruptor para activar/desactivar el envío automático de PDFs por correo a los pacientes.</li>
                        <li><strong>Restablecer:</strong> Botón para volver a los colores corporativos originales en caso de error.</li>
                    </ul>
                </section>

                <section id="security-hashes">
                    <h2>10. Seguridad e Integridad (Security Hash)</h2>
                    <p>Cada vez que un paciente firma, el sistema genera una <strong>Huella Digital (Hash SHA-256)</strong> única.</p>
                    <pre>Security Hash = sha256(Nombre + Documento + Texto Firmado + Trazo de Firma + Salt Secreto)</pre>
                    <p>Si alguien intentara modificar el PDF o la base de datos manualmente, el Hash dejaría de coincidir, invalidando legalmente el documento.</p>
                    <p><strong>Auditoría:</strong> Cada acción de creación, edición o descarga queda registrada en la tabla de <code>registro_actividades</code> (logs.php) con IP y usuario responsable.</p>
                </section>

                <section id="backups-tech">
                    <h2>11. Mantenimiento y Backups</h2>
                    <p>El sistema incluye un gestor de copias de seguridad (`backup_manager.php`):</p>
                    <ul>
                        <li>Permite generar un volcado SQL de la base de datos en un clic.</li>
                        <li>Listado de backups anteriores para descarga manual.</li>
                        <li>Seguridad: Los archivos de backup se guardan fuera del alcance público directo del servidor.</li>
                    </ul>
                </section>

                <hr class="my-5">
                <div class="text-center text-muted pb-4">
                    <small>Este sistema no maneja historias clínicas ni información sensible de salud protegida por el secreto médico-paciente. Es una herramienta de control administrativo y legal.</small><br>
                    <small>Desarrollado por <strong>Jose Daniel Castro Reyes</strong></small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
