<?php
// public_html/admin/download_pdf.php
require_once '../config.php';

// 1. CARGAR LIBRERÍA TCPDF
$tcpdfPath = '../assets/lib/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    die("Error crítico: No se encuentra la librería TCPDF en: $tcpdfPath");
}
require_once $tcpdfPath;

// 2. SEGURIDAD (Login o Token)
$id = $_GET['id'] ?? 0;
$token = $_GET['token'] ?? '';
$isPublicAccess = false;

if (!empty($token) && !empty($id)) {
    // Validar token (hash del ID)
    $expectedToken = hash_hmac('sha256', $id, SECRET_KEY);
    if (hash_equals($expectedToken, $token)) {
        $isPublicAccess = true;
    }
}

if (!$isPublicAccess && !isLoggedIn()) {
    header('Location: login.php');
    exit();
}
$db = getDBConnection();

// AQUÍ ESTABA EL ERROR:
// Usamos getMyCompanyId() para obtener el ID real de la empresa (ej: 'constr-1')
$empresaId = getMyCompanyId(); 

// 3. CONSULTA SEGURA
// Seleccionamos los datos del consentimiento y de la empresa asociada
$sql = "SELECT c.*, c.empresa_particular, e.nombre as nombre_empresa, e.nit,
               COALESCE(p.nombres, c.first_name) as current_first_name,
               COALESCE(p.apellidos, c.last_name) as current_last_name
        FROM consents c 
        LEFT JOIN empresas e ON c.company_id = e.id 
        LEFT JOIN pacientes_pre_registrados p ON c.document_number = p.numero_documento AND c.document_type = p.tipo_documento
        WHERE c.id = ?";

if (isEmpresa() && !$isPublicAccess) {
    // Si es empresa, agregamos el candado para que SOLO vea los suyos
    // Comparando c.company_id con la variable corregida $empresaId
    $sql .= " AND c.company_id = ?"; 
}

$stmt = $db->prepare($sql);

if (isEmpresa() && !$isPublicAccess) {
    // Bind: "is" -> Integer (id consentimiento), String (id empresa)
    $stmt->bind_param("is", $id, $empresaId);
} else {
    // Si es admin o ACCESO PÚBLICO, solo bind del ID del consentimiento
    $stmt->bind_param("i", $id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Mensaje de error amigable
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;">
            <h2 style="color:red">Acceso Denegado</h2>
            <p>El documento no existe o usted no tiene permisos para visualizarlo.</p>
            <p><a href="dashboard.php">Volver al inicio</a></p>
         </div>');
}

$data = $result->fetch_assoc();

// Obtener Configuración Global
$configSql = $db->query("SELECT setting_key, setting_value FROM settings");
$conf = [];
while ($row = $configSql->fetch_assoc()) $conf[$row['setting_key']] = $row['setting_value'];

// 4. GENERACIÓN DEL PDF (Igual que antes)
class ConsentPDF extends TCPDF {
    public $logoFile;
    public $siteTitle;

    public function Header() {
        if ($this->logoFile && file_exists($this->logoFile)) {
            $this->Image($this->logoFile, 15, 10, 25, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'CONSENTIMIENTO INFORMADO', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Line(15, 28, 195, 28);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Generado el ' . date('d/m/Y h:i A') . ' | Pág. '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

$pdf = new ConsentPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Rutas y Metadatos
$logoRelativo = '../' . explode('?', $conf['logo_url'] ?? '')[0];
$pdf->logoFile = $logoRelativo;
$pdf->siteTitle = $conf['site_name'] ?? 'Sistema Médico';

$pdf->SetCreator('Sistema Consentimientos');
$pdf->SetTitle('Consentimiento - ' . $data['document_number']);
$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
$pdf->AddPage();

// Contenido HTML
$fechaFirma = date('d/m/Y h:i A', strtotime($data['date_signed']));
$empresaNombre = $data['nombre_empresa'] ?? 'Particular / Independiente';
if ($data['company_id'] === 'particular' && !empty($data['empresa_particular'])) {
    $empresaNombre = $data['empresa_particular'];
}

$html = '
<style>
    h3 { color: #0d6efd; font-size: 13pt; border-bottom: 1px solid #ccc; line-height: 2; }
    p { text-align: justify; line-height: 1.5; font-size: 11pt; }
    .tabla-info { width: 100%; border-collapse: collapse; padding: 5px; }
    .tabla-info td { border-bottom: 1px solid #eee; }
    .label { font-weight: bold; color: #555; width: 30%; }
    .value { color: #000; width: 70%; }
    .caja-legal { background-color: #f8f9fa; border: 1px solid #e9ecef; color: #333; padding: 10px; font-size: 12pt; line-height: 1.6; }
</style>

<h3>1. Información del Paciente</h3>
<table class="tabla-info">
    <tr style="background-color: #f2f2f2;">
        <td class="label">Empresa Responsable:</td>
        <td class="value">'. htmlspecialchars($empresaNombre) .'</td>
    </tr>
    <tr>
        <td class="label">Nombre Completo:</td>
        <td class="value">'. htmlspecialchars($data['current_first_name'] . ' ' . $data['current_last_name']) .'</td>
    </tr>
    <tr>
        <td class="label">Documento:</td>
        <td class="value">'. htmlspecialchars($data['document_type'] . ' ' . $data['document_number']) .'</td>
    </tr>
    <tr>
        <td class="label">Contacto:</td>
        <td class="value">'. htmlspecialchars($data['email'] . ' / ' . $data['phone']) .'</td>
    </tr>
    <tr>
        <td class="label">Fecha de Firma:</td>
        <td class="value">'. $fechaFirma .'</td>
    </tr>
</table>

<h3>2. Declaración de Voluntad</h3>
<div class="caja-legal">
    '. nl2br($data['consent_text_version']) .'
</div>
<br><br>
';

$pdf->writeHTML($html, true, false, true, false, '');

// Firma
// Firma PACIENTE
if (!empty($data['signature'])) {
    $parts = explode(',', $data['signature']);
    if (count($parts) > 1) {
        $imgBin = base64_decode($parts[1]);
        
        $y = $pdf->GetY();
        if ($y > 220) { $pdf->AddPage(); $y = 30; }

        $pdf->Image('@'.$imgBin, 20, $y, 70, 0, 'PNG'); // Movido a la izquierda si entra doble, o centrado si es solo.
        $pdf->Line(20, $y + 35, 90, $y + 35);
        
        $pdf->SetY($y + 36);
        $pdf->SetX(20);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 5, strtoupper($data['current_first_name'] . ' ' . $data['current_last_name']), 0, 1, 'L');
        
        $pdf->SetX(20);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(70, 5, $data['document_type'] . ': ' . $data['document_number'], 0, 1, 'L');
        
        $pdf->SetX(20);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(70, 4, "Paciente", 0, 1, 'L');
        
        // Firma TESTIGO (Si existe)
        if (!empty($data['witness_signature']) && !empty($data['witness_name'])) {
             // Si hay testigo, lo ponemos al lado derecho (aprox X=110)
             $partsW = explode(',', $data['witness_signature']);
             if (count($partsW) > 1) {
                 $imgBinW = base64_decode($partsW[1]);
                 
                 // Misma Y que el paciente
                 $pdf->Image('@'.$imgBinW, 110, $y, 70, 0, 'PNG');
                 $pdf->Line(110, $y + 35, 180, $y + 35);
                 
                 // Posicionamos cursor para texto derecho (manualmente con SetXY)
                 $currY = $y + 36;
                 
                 $pdf->SetXY(110, $currY);
                 $pdf->SetFont('helvetica', 'B', 9);
                 $pdf->Cell(70, 5, strtoupper($data['witness_name']), 0, 1, 'L');
                 
                 $pdf->SetXY(110, $currY + 5);
                 $pdf->SetFont('helvetica', '', 9);
                 $pdf->Cell(70, 5, 'Doc: ' . $data['witness_doc_number'], 0, 1, 'L');
                 
                 $pdf->SetXY(110, $currY + 10);
                 $pdf->SetFont('helvetica', 'I', 7);
                 $pdf->Cell(70, 4, "Acompañante / Testigo", 0, 1, 'L');
             }
        }
        
        // Metadata Global (Abajo de todo)
        $pdf->SetY($y + 55); 
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 4, "Firmado digitalmente el $fechaFirma desde IP: " . ($data['ip_address'] ?? 'Desconocida'), 0, 1, 'C');
        
        // Mostrar Hash
        if (!empty($data['security_hash'])) {
            $pdf->Ln(1);
            $pdf->SetFont('courier', '', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(0, 3, "Sello Digital (SHA256): " . $data['security_hash'], 0, 1, 'C');
        }
    }
}

$pdf->Output('Consentimiento_'.$data['document_number'].'.pdf', 'I');
?>