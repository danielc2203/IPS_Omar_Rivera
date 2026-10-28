<?php
// admin/bulk_download.php
require_once '../config.php';

// 1. CARGAR LIBRERÍA TCPDF
$tcpdfPath = '../assets/lib/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) { die("Error: TCPDF not found."); }
require_once $tcpdfPath;

if (!isLoggedIn()) { die("Acceso denegado"); }
$db = getDBConnection();

// --- FILTROS ---
$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate = $_GET['end'] ?? date('Y-m-t');
$companyId = $_GET['company'] ?? 'all';

// --- QUERY ---
$sql = "SELECT c.*, e.nombre as nombre_empresa, 
               COALESCE(p.nombres, c.first_name) as current_first_name,
               COALESCE(p.apellidos, c.last_name) as current_last_name
        FROM consents c 
        LEFT JOIN empresas e ON c.company_id = e.id 
        LEFT JOIN pacientes_pre_registrados p ON c.document_number = p.numero_documento AND c.document_type = p.tipo_documento
        WHERE DATE(c.date_signed) BETWEEN ? AND ?";

$types = "ss";
$params = [$startDate, $endDate];

// Filtro Empresa
if (isEmpresa()) {
    $sql .= " AND c.company_id = ?";
    $params[] = getMyCompanyId();
    $types .= "s";
} elseif ($companyId !== 'all') {
    $sql .= " AND c.company_id = ?";
    $params[] = $companyId;
    $types .= "s"; // company_id is varchar(36)
}

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("No se encontraron registros para generar el ZIP.");
}

// --- PREPARAR ZIP ---
$zipName = "consents_{$startDate}_{$endDate}.zip";
$zipPath = sys_get_temp_dir() . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Error al crear archivo ZIP temporal.");
}

// Config Global
$resConf = $db->query("SELECT setting_key, setting_value FROM settings");
$conf = [];
while($row = $resConf->fetch_assoc()) $conf[$row['setting_key']] = $row['setting_value'];

// --- CLASE PDF MINIMALISTA (Copia) ---
class BulkPDF extends TCPDF {
    public $logoFile;
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
        $this->Cell(0, 10, 'Pag. '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

$logoRelativo = '../' . explode('?', $conf['logo_url'] ?? '')[0];

// --- GENERAR PDFS ---
$count = 0;
while ($data = $result->fetch_assoc()) {
    $pdf = new BulkPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->logoFile = $logoRelativo;
    $pdf->SetCreator('Sistema');
    $pdf->SetMargins(15, 30, 15);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->AddPage();
    
    // Contenido
    $fechaFirma = date('d/m/Y h:i A', strtotime($data['date_signed']));
    $empresaNombre = $data['nombre_empresa'] ?? 'Particular';
    $nombreComp = $data['current_first_name'] . ' ' . $data['current_last_name'];
    
    $html = '
    <style>
        h3 { color: #0d6efd; font-size: 13pt; border-bottom: 1px solid #ccc; line-height: 2; }
        .tabla-info { width: 100%; border-collapse: collapse; padding: 5px; }
        .tabla-info td { border-bottom: 1px solid #eee; }
        .label { font-weight: bold; width: 30%; }
        .caja-legal { background-color: #f8f9fa; border: 1px solid #e9ecef; padding: 10px; font-size: 11pt; }
    </style>
    <h3>1. Información</h3>
    <table class="tabla-info">
        <tr><td class="label">Empresa:</td><td>'. htmlspecialchars($empresaNombre) .'</td></tr>
        <tr><td class="label">Paciente:</td><td>'. htmlspecialchars($nombreComp) .'</td></tr>
        <tr><td class="label">Doc:</td><td>'. htmlspecialchars($data['document_type'] . ' ' . $data['document_number']) .'</td></tr>
        <tr><td class="label">Fecha:</td><td>'. $fechaFirma .'</td></tr>
    </table>
    <h3>2. Declaración</h3>
    <div class="caja-legal">'. nl2br($data['consent_text_version']) .'</div>
    <br><br>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Firma Paciente
    if (!empty($data['signature'])) {
        $parts = explode(',', $data['signature']);
        if (count($parts) > 1) {
            $imgBin = base64_decode($parts[1]);
            $y = $pdf->GetY();
            if ($y > 220) { $pdf->AddPage(); $y = 30; }
            
            $pdf->Image('@'.$imgBin, 20, $y, 60, 0, 'PNG');
            $pdf->Line(20, $y + 30, 80, $y + 30);
            $pdf->SetXY(20, $y + 31);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(60, 4, strtoupper($nombreComp), 0, 1, 'L');
            $pdf->SetX(20);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell(60, 4, $data['document_number'], 0, 1, 'L');
            
            // Firma Testigo
            if (!empty($data['witness_signature']) && !empty($data['witness_name'])) {
                 $partsW = explode(',', $data['witness_signature']);
                 if (count($partsW) > 1) {
                     $imgBinW = base64_decode($partsW[1]);
                     $pdf->Image('@'.$imgBinW, 110, $y, 60, 0, 'PNG');
                     $pdf->Line(110, $y + 30, 170, $y + 30);
                     $pdf->SetXY(110, $y + 31);
                     $pdf->SetFont('helvetica', 'B', 8);
                     $pdf->Cell(60, 4, strtoupper($data['witness_name']), 0, 1, 'L');
                     $pdf->SetX(110);
                     $pdf->SetFont('helvetica', '', 8);
                     $pdf->Cell(60, 4, 'TESTIGO', 0, 1, 'L');
                 }
            }
        }
    }
    
    // Add to ZIP
    $pdfContent = $pdf->Output('', 'S'); // String output
    $fileName = $data['document_number'] . '_' . date('Ymd_Hi', strtotime($data['date_signed'])) . '.pdf';
    $zip->addFromString($fileName, $pdfContent);
    $count++;
}

$zip->close();
$stmt->close();

if (file_exists($zipPath)) {
    // Entregar ZIP
    if(ob_get_length()) ob_end_clean(); // Limpiar buffer previo
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.basename($zipName).'"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($zipPath));
    flush();
    readfile($zipPath);
    unlink($zipPath); // Borrar temporal
    exit;
} else {
    die("Error al generar el archivo ZIP.");
}
?>
