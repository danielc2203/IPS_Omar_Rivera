<?php
// public_html/kiosk.php
require_once 'config.php';

// --- AUTH CHECK: KIOSK REQUIRES ADMIN LOGIN INITIALLY TO SETUP? 
// Or is it public? Usually Kiosk is set up by an admin.
// For now, let's leave it public but hidden (by filename).

// --- OBTENER LISTA DE EMPRESAS (Para JS) ---
$db_init = getDBConnection();
$res_emp = $db_init->query("SELECT id, nombre FROM empresas WHERE id != 'admin-ips' AND estado='activo' ORDER BY nombre ASC");
$listaEmpresas = ($res_emp) ? $res_emp->fetch_all(MYSQLI_ASSOC) : [];
$db_init->close();

// --- AJAX: OBTENER TEXTO DE PLANTILLA (RE-USE INDEX LOGIC if called here) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_template') {
    $tplId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $text = getConsentTemplateContent($tplId);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'content' => $text]);
    exit;
}

// --- PROCESAR EL GUARDADO (RE-USE INDEX LOGIC) ---
// To avoid duplication issues, we can point the AJAX to index.php. 
// BUT index.php renders HTML if no action is set.
// Let's just point the FETCH in JS to 'index.php' for the save action!
// That way we don't duplicate the save logic.

$consentTextRaw = getConsentText(); 
$declarationTextRaw = getActiveSwornDeclaration();
$logoUrl = getSetting('logo_url', '');
$siteName = getSetting('site_name', 'Sistema de Consentimientos');
$activeTemplates = getAllActiveConsentTemplates();


// KIOSK THEME
$kioskBg = getSetting('kiosk_bg_color', '#f8f9fa');
$kioskHeader = getSetting('kiosk_header_color', '#0d6efd');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kiosco - Firma Digital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        body {
            background-color: <?php echo $kioskBg; ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            /* Allow scrolling on small screens */
            overflow-y: auto; 
            user-select: none;
        }
        .kiosk-card {
            width: 100%;
            min-height: 100vh;
            border: none;
            display: flex;
            flex-direction: column;
            background: white;
        }
        .kiosk-header {
            padding: 2rem 1rem;
            text-align: center;
            background: <?php echo $kioskHeader; ?>;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            z-index: 10;
            position: relative;
        }
        .btn-home {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-home:hover {
            background: white;
            color: #0d6efd;
        }
        @media (max-width: 768px) {
            .btn-home { width: 45px; height: 45px; font-size: 1.2rem; top: 10px; right: 10px; }
        }
        .kiosk-body {
            flex: 1;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }
        .logo-img {
            max-height: 70px;
            background: white;
            padding: 8px;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        /* Responsive Form Inputs */
        /* Responsive Form Inputs */
        .form-floating-lg > .form-control, .form-floating-lg > .form-select {
            height: 5rem;
            padding-top: 2rem;
            padding-bottom: 0.5rem;
            padding-left: 1rem;
            font-size: 1.3rem;
            border-radius: 12px;
            line-height: 1.2;
        }
        .form-floating-lg > label {
            padding: 1rem 1rem;
            font-size: 1.1rem;
            opacity: 0.8;
            transform-origin: 0 0;
        }
        .form-floating-lg > .form-select {
            padding-top: 2rem;
            padding-bottom: 0.5rem;
        }

        /* Tablet/Desktop Tweaks */
        @media (min-width: 768px) {
            .form-floating-lg > .form-control, .form-floating-lg > .form-select {
                height: 6rem;
                font-size: 1.5rem;
                padding-top: 2.2rem;
            }
            .form-floating-lg > label {
                font-size: 1.25rem;
                padding: 1.2rem;
            }
            .logo-img { max-height: 90px; }
        }

        .btn-kiosk {
            padding: 1rem;
            font-size: 1.2rem;
            border-radius: 10px;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .btn-kiosk:active { transform: scale(0.98); }
        
        @media (min-width: 768px) {
            .btn-kiosk { padding: 1.2rem; font-size: 1.5rem; }
        }

        /* Custom UI overrides for SweetAlert in Kiosk */
        .swal2-popup {
            font-size: 1rem !important;
            padding: 1.5rem !important;
            width: 90% !important;
            max-width: 600px !important;
        }
    </style>
</head>
<body>

    <div class="kiosk-card">
        <div class="kiosk-header">
            <a href="index.php" class="btn-home" title="Volver al Inicio">
                <i class="bi bi-house-door-fill"></i>
            </a>
            <?php if ($logoUrl && file_exists($logoUrl)): ?>
                <img src="<?php echo $logoUrl . '?v=' . time(); ?>" alt="Logo" class="logo-img shadow-sm">
            <?php else: ?>
                <div class="display-3 mb-2"><i class="bi bi-hospital"></i></div>
            <?php endif; ?>
            <h1 class="display-5 fw-bold"><?php echo $siteName; ?></h1>
            <p class="lead opacity-75">Módulo de Atención al Paciente</p>
        </div>

        <div class="kiosk-body">
            <h2 class="text-center text-muted mb-5">Toque la pantalla o ingrese sus datos para comenzar</h2>
            
            <form id="searchForm" onsubmit="return false;" autocomplete="off">
                
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="form-floating form-floating-lg">
                            <select class="form-select" id="template_id">
                                <?php if(empty($activeTemplates)): ?>
                                    <option value="0">General</option>
                                <?php else: ?>
                                    <?php foreach($activeTemplates as $tpl): ?>
                                        <option value="<?php echo $tpl['id']; ?>"><?php echo htmlspecialchars($tpl['name']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <label for="template_id">Procedimiento</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                         <div class="form-floating form-floating-lg">
                            <select class="form-select" id="tipo_doc">
                                <?php foreach(getDocumentTypes() as $code => $name): ?>
                                    <option value="<?php echo $code; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="tipo_doc">Tipo</label>
                        </div>
                    </div>
                    <div class="col-md-5">
                         <div class="form-floating form-floating-lg">
                            <input type="number" class="form-control" id="num_doc" placeholder="Número" required>
                            <label for="num_doc">No. Documento</label>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2 mt-4">
                    <button type="button" id="btn-buscar" class="btn btn-primary btn-kiosk">
                        <i class="bi bi-hand-index-thumb"></i> INICIAR FIRMA
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-lg mt-3 rounded-pill" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refrescar
                    </button>
                </div>

            </form>
        </div>
        
        <div class="text-center p-3 text-muted bg-light">
            <small>Modo Kiosco v1.0 - Protegido por SSL</small>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script src="assets/js/ui-manager.js"></script>

<script>
    // Configuración Kiosco
    let LEGAL_TEXT_TEMPLATE = <?php echo json_encode($consentTextRaw); ?>;
    let DECLARATION_TEXT_TEMPLATE = <?php echo json_encode($declarationTextRaw); ?>;
    const LISTA_EMPRESAS = <?php echo json_encode($listaEmpresas); ?>;
    const CSRF_TOKEN = "<?php echo $_SESSION['csrf_token']; ?>";

    document.getElementById('btn-buscar').addEventListener('click', buscarPaciente);

    // --- AUTO-FILL FROM URL (QR CODE SUPPORT) ---
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const pType = urlParams.get('document_type');
        const pNum = urlParams.get('document_number');
        const pAuto = urlParams.get('auto');

        if(pType && pNum) {
            const selType = document.getElementById('tipo_doc');
            const inpNum = document.getElementById('num_doc');
            
            if(selType && inpNum) {
                selType.value = pType;
                inpNum.value = pNum;
                
                // If auto=true, trigger search automatically
                if(pAuto === 'true') {
                    // Small delay to ensure UI is ready (and maybe to show the user what happened)
                    setTimeout(buscarPaciente, 500); 
                }
            }
        }
    });

    // Reuse Logic from index.php but adapted
    function buscarPaciente() {
        const tipoDoc = document.getElementById('tipo_doc').value;
        const numDoc = document.getElementById('num_doc').value.trim();
        const templateId = document.getElementById('template_id').value;

        if (!numDoc) {
            UI.error('Atención', 'Ingrese su documento');
            return;
        }

        UI.loading('Buscando...');

        // Use index.php handlers or api
        fetch(`api_check_patient.php?document_type=${tipoDoc}&document_number=${numDoc}`)
            .then(r => r.json())
            .then(data => {
                    if (data.status === 'error') {
                        UI.close();
                        UI.error('Error', data.message);
                    } else {
                        // CHECK DUPLICATE SIGNATURE
                        if (data.already_signed_today === true) {
                            UI.close();
                            Swal.fire({
                                icon: 'warning',
                                title: '¡Atención!',
                                html: `El paciente con documento <b>${numDoc}</b> ya ha firmado un consentimiento el día de hoy.<br><br>¿Desea realizar una nueva firma?`,
                                showCancelButton: true,
                                confirmButtonText: 'Sí, Firmar Nuevo',
                                cancelButtonText: 'Cancelar',
                                confirmButtonColor: '#ffc107',
                                cancelButtonColor: '#6c757d'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    proceedToSignature(data);
                                }
                            });
                        } else {
                            proceedToSignature(data);
                        }
                    }

                    function proceedToSignature(data) {
                        const isFound = (data.status === 'found');
                        const patientData = data.data;

                        UI.loading('Cargando plantilla...');
                        fetch(`index.php?action=get_template&id=${templateId}`)
                            .then(r => r.json())
                            .then(resTpl => {
                                UI.close();
                                if(resTpl.status === 'success') {
                                    LEGAL_TEXT_TEMPLATE = resTpl.content; 
                                    if (!isFound) {
                                        preguntarEmpresa(tipoDoc, numDoc);
                                    } else {
                                        lanzarModalFirma(tipoDoc, numDoc, patientData, true);
                                    }
                                }
                            });
                    }
            })
            .catch(err => UI.error('Offline', 'Sin conexión'));
    }

    // Copied Helper Functions
    function preguntarEmpresa(tipoDoc, numDoc) {
        let options = '<option value="particular">No / Es Particular</option>';
        LISTA_EMPRESAS.forEach(emp => {
            options += `<option value="${emp.id}">${emp.nombre}</option>`;
        });

        UI.modalForm({
            title: '¿Viene de alguna empresa?',
            html: `
                <div class="mb-3">
                    <select id="sel_origen" class="form-select form-select-lg">
                        ${options}
                    </select>
                </div>
            `,
            confirmText: 'Continuar',
            preConfirmFn: () => {
                const sel = document.getElementById('sel_origen');
                return { id: sel.value, name: sel.options[sel.selectedIndex].text };
            },
            callback: (res) => {
                const data = { first_name: '', last_name: '', email: '', phone: '', company_id: res.id, company_name: res.name, empresa_particular: '' };
                lanzarModalFirma(tipoDoc, numDoc, data, false);
            }
        });
    }

    function lanzarModalFirma(tipoDoc, numDoc, data, readOnly) {
         const templateId = document.getElementById('template_id').value;
         const badgeColor = readOnly ? 'success' : 'warning';
         const attr = readOnly ? 'readonly' : '';

         UI.modalForm({
            title: 'Firma de Consentimiento',
            width: '90%', // Wider for Kiosk
            confirmText: 'FIRMAR AHORA',
            html: `
                <div class="text-start">
                    <div class="row g-3 mb-3">
                        <div class="col-6"><label>Nombres</label><input id="s_nombres" class="form-control form-control-lg" value="${data.first_name}" ${attr}></div>
                        <div class="col-6"><label>Apellidos</label><input id="s_apellidos" class="form-control form-control-lg" value="${data.last_name}" ${attr}></div>
                        <div class="col-6"><label>Email (Opcional)</label><input id="s_email" class="form-control form-control-lg" value="${data.email}"></div>
                        <div class="col-6"><label>Teléfono</label><input id="s_telefono" class="form-control form-control-lg" value="${data.phone}"></div>
                        <div id="p_particular_div" class="col-12" style="display: ${data.company_id === 'particular' && !readOnly ? 'block' : 'none'}">
                            <label class="fw-bold text-primary">Nombre de Empresa (Particular)</label>
                            <input id="s_emp_particular" class="form-control form-control-lg" value="${data.empresa_particular || ''}" placeholder="Ej: Mi Negocio">
                        </div>
                    </div>

                    <div class="card bg-light border-0 mb-3">
                        <div class="card-body" id="texto_legal_content" style="height: 300px; overflow-y: auto; font-size: 1.1rem; text-align: justify;"></div>
                    </div>

                    <!-- WITNESS SECTION -->
                    <div class="form-check form-switch mb-3 p-3 border rounded">
                        <input class="form-check-input" type="checkbox" id="chk_witness" style="transform: scale(1.5); margin-left: 0.5rem; margin-right: 1.5rem;">
                        <label class="form-check-label fw-bold" for="chk_witness">¿Requiere Firma de Testigo / Acompañante?</label>
                    </div>

                    <div id="witness-area" class="d-none border-start border-4 border-info ps-3 mb-3">
                         <h5 class="text-info">Datos del Testigo</h5>
                         <div class="row g-2 mb-2">
                             <div class="col-6">
                                 <input id="w_name" class="form-control" placeholder="Nombre Completo Testigo">
                             </div>
                             <div class="col-6">
                                 <input id="w_doc" class="form-control" placeholder="No. Documento Testigo">
                             </div>
                         </div>
                         <label class="fw-bold small text-muted">Firma del Testigo</label>
                         <div style="border: 2px dashed #ccc; border-radius: 5px; background: #fff;">
                            <canvas id="sig-canvas-w" style="width: 100%; height: 150px;"></canvas>
                         </div>
                         <button type="button" class="btn btn-sm btn-link text-danger" id="clear-sig-w">Borrar Firma Testigo</button>
                    </div>

                    <div class="form-check p-3 bg-white border rounded shadow-sm mb-3">
                        <input class="form-check-input" type="checkbox" id="chk_aceptar" style="transform: scale(2); margin-left: 0.5rem; margin-right: 1.5rem;">
                        <label class="form-check-label fs-5 fw-bold" for="chk_aceptar">ACEPTO EL CONSENTIMIENTO INFORMADO</label>
                    </div>

                    <div id="declaration-container" class="card bg-info bg-opacity-10 border-info mb-3 ${DECLARATION_TEXT_TEMPLATE ? '' : 'd-none'}">
                        <div class="card-body">
                            <h6 class="fw-bold text-info"><i class="bi bi-shield-check"></i> Declaración Juramentada</h6>
                            <div id="declaration_content" style="max-height: 200px; overflow-y: auto; font-size: 1rem; text-align: justify;"></div>
                            <div class="form-check mt-3 p-2 bg-white border rounded shadow-sm">
                                <input class="form-check-input" type="checkbox" id="chk_aceptar_dec" style="transform: scale(1.8); margin-left: 0.5rem; margin-right: 1.2rem;">
                                <label class="form-check-label fw-bold" for="chk_aceptar_dec">ACEPTO LA DECLARACIÓN JURAMENTADA</label>
                            </div>
                        </div>
                    </div>

                    <div class="text-center">
                        <label class="fw-bold text-danger">FIRME AQUÍ (Paciente)</label>
                        <div style="border: 3px dashed #999; border-radius: 10px; background: #fff;">
                            <canvas id="sig-canvas" style="width: 100%; height: 250px;"></canvas>
                        </div>
                        <button type="button" class="btn btn-outline-secondary mt-2 w-50" id="clear-sig">Borrar Firma</button>
                    </div>
                </div>
            `,
            didOpenFn: () => {
                // PAD PACIENTE
                const canvas = document.getElementById('sig-canvas');
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = 250 * ratio; 
                canvas.getContext("2d").scale(ratio, ratio);
                window.signaturePad = new SignaturePad(canvas, { backgroundColor: 'rgb(255, 255, 255)' });
                document.getElementById('clear-sig').onclick = () => window.signaturePad.clear();

                // PAD TESTIGO
                const canvasW = document.getElementById('sig-canvas-w');
                canvasW.width = canvasW.offsetWidth * ratio;
                canvasW.height = 150 * ratio;
                canvasW.getContext("2d").scale(ratio, ratio);
                window.signaturePadW = new SignaturePad(canvasW, { backgroundColor: 'rgb(255, 255, 255)' });
                document.getElementById('clear-sig-w').onclick = () => window.signaturePadW.clear();

                // TOGGLE WITNESS
                const chkWitness = document.getElementById('chk_witness');
                const witnessArea = document.getElementById('witness-area');
                chkWitness.addEventListener('change', (e) => {
                    if(e.target.checked) {
                        witnessArea.classList.remove('d-none');
                        // Resize canvas again just in case display:none broke it? No, width is already set, but let's refresh
                        const wRatio = Math.max(window.devicePixelRatio || 1, 1);
                        canvasW.width = canvasW.offsetWidth * wRatio;
                        canvasW.height = 150 * wRatio;
                        canvasW.getContext("2d").scale(wRatio, wRatio);
                        window.signaturePadW.clear(); // Clear on toggle to avoid scaling artifacts
                    } else {
                        witnessArea.classList.add('d-none');
                    }
                });

                const inNom = document.getElementById('s_nombres');
                const inApe = document.getElementById('s_apellidos');
                const divTexto = document.getElementById('texto_legal_content');
                const divDec = document.getElementById('declaration_content');

                function actualizar() {
                    const nom = inNom.value.trim(); const ape = inApe.value.trim();
                    const nombreCompleto = (nom || ape) ? (nom + ' ' + ape).toUpperCase() : '_________________';
                    
                    divTexto.innerHTML = LEGAL_TEXT_TEMPLATE.replace(/{{FULL_NAME}}/g, `<b>${nombreCompleto}</b>`).replace(/{{DOCUMENT_TYPE}}/g, tipoDoc).replace(/{{DOCUMENT_NUMBER}}/g, numDoc).replace(/\n/g, '<br>');
                    
                    if (divDec && DECLARATION_TEXT_TEMPLATE) {
                        divDec.innerHTML = DECLARATION_TEXT_TEMPLATE.replace(/{{FULL_NAME}}/g, `<b>${nombreCompleto}</b>`).replace(/{{DOCUMENT_TYPE}}/g, tipoDoc).replace(/{{DOCUMENT_NUMBER}}/g, numDoc).replace(/\n/g, '<br>');
                    }
                }
                inNom.addEventListener('input', actualizar); inApe.addEventListener('input', actualizar);
                actualizar();

                const confirmBtn = Swal.getConfirmButton();
                confirmBtn.disabled = true;

                const chks = [document.getElementById('chk_aceptar')];
                const decChk = document.getElementById('chk_aceptar_dec');
                if (decChk) chks.push(decChk);

                chks.forEach(chk => {
                    chk.addEventListener('change', () => {
                        const allChecked = chks.every(c => c.checked);
                        confirmBtn.disabled = !allChecked;
                    });
                });
            },
            preConfirmFn: () => {
                const nom = document.getElementById('s_nombres').value;
                const ape = document.getElementById('s_apellidos').value;
                
                const chkConsent = document.getElementById('chk_aceptar').checked;
                const chkDec = document.getElementById('chk_aceptar_dec') ? document.getElementById('chk_aceptar_dec').checked : true;

                if(!chkConsent || !chkDec) { Swal.showValidationMessage('Debe aceptar ambos términos'); return false; }
                if(!nom || !ape || window.signaturePad.isEmpty()) { Swal.showValidationMessage('Faltan datos o firma del paciente'); return false; }
                
                // Witness Validation
                const useWitness = document.getElementById('chk_witness').checked;
                let wName = '', wDoc = '', wSig = '';
                
                if (useWitness) {
                    wName = document.getElementById('w_name').value;
                    wDoc = document.getElementById('w_doc').value;
                    if (window.signaturePadW.isEmpty() || !wName || !wDoc) {
                         Swal.showValidationMessage('Faltan datos o firma del testigo');
                         return false;
                    }
                    wSig = window.signaturePadW.toDataURL();
                }

                return {
                    action: 'save_consent',
                    document_type: tipoDoc, document_number: numDoc,
                    first_name: nom, last_name: ape,
                    email: document.getElementById('s_email').value,
                    phone: document.getElementById('s_telefono').value,
                    company_id: data.company_id,
                    empresa_particular: document.getElementById('s_emp_particular') ? document.getElementById('s_emp_particular').value : '',
                    signature: window.signaturePad.toDataURL(),
                    template_id: templateId,
                    csrf_token: CSRF_TOKEN,
                    witness_name: wName,
                    witness_doc_number: wDoc,
                    witness_signature: wSig
                };
            },
            callback: (formData) => {
                UI.loading('Registrando...');
                const data = new FormData();
                for (let key in formData) data.append(key, formData[key]);

                // POST TO INDEX.PHP
                fetch('index.php', {method: 'POST', body: data})
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Gracias!',
                                text: 'Su registro ha sido exitoso.',
                                timer: 3000,
                                timerProgressBar: true,
                                showConfirmButton: false,
                                willClose: () => {
                                    location.reload(); // AUTO RESET
                                }
                            });
                        } else {
                            UI.error('Error', res.message);
                        }
                    })
                    .catch(err => UI.error('Error', 'Fallo de red'));
            }
         });
    }

</script>
</body>
</html>
