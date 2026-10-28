<?php
// admin/generate_certificate.php
require_once '../config.php';
require_once '../assets/lib/tcpdf/tcpdf.php';

// Auth Check
// Public access might be needed via token later, but for now restricted to logged users
// or we reuse the token logic if we want to email it.
$id = $_GET['id'] ?? 0;
// Basic Auth
if (!isLoggedIn()) { die("Acceso denegado"); }

$db = getDBConnection();
$userId = getUserId();

// Fetch Exam Data
$sql = "SELECT pe.*, e.name as exam_name, 
               p.nombres, p.apellidos, p.tipo_documento, p.numero_documento, p.email, p.telefono,
               emp.nombre as empresa_name,
               u.nombre_completo as spec_name, u.firma_digital as spec_signature, u.registro_medico, u.especialidad
        FROM patient_exams pe
        JOIN exams e ON pe.exam_id = e.id
        JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
        JOIN empresas emp ON p.empresa_id = emp.id
        LEFT JOIN usuarios u ON pe.completed_by = u.id
        WHERE pe.id = ?";

// Security: If specialist, check if allowed? 
// For now, allow any logged in user (Admin/Specialist/Company) to view if they have access to the patient.
// (Simplification for Phase A)

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) die("Examen no encontrado");
if ($data['status'] !== 'completed') die("El examen no ha sido completado aún.");

// SETTINGS
$siteName = getSetting('site_name', 'IPS Salud Ocupacional');
$logoUrl = '../' . getSetting('logo_url', '');

// PDF CLASS
class CertificatePDF extends TCPDF {
    public $headerLogo;
    public $headerTitle;
    public function Header() {
        if ($this->headerLogo && file_exists($this->headerLogo)) {
            $this->Image($this->headerLogo, 15, 10, 30, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 15, 'CERTIFICADO DE APTITUD MÉDICA', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Line(15, 28, 195, 28);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Generado por ' . $this->headerTitle . ' el ' . date('d/m/Y H:i'), 0, false, 'C');
    }
}

$pdf = new CertificatePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->headerLogo = $logoUrl;
$pdf->headerTitle = $siteName;

$pdf->SetCreator('Sistema Médico');
$pdf->SetTitle('Certificado ' . $data['numero_documento']);
$pdf->SetMargins(15, 30, 15);
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
$pdf->AddPage();

// STYLES
$styleLabel = 'font-weight:bold; color:#444;';
$styleVal = 'color:#000;';

// CONTENT
$html = '
<style>
    h4 { color: #0d6efd; border-bottom: 2px solid #eee; padding-bottom: 5px; margin-top: 20px; }
    td { padding: 5px; }
    .box-aptitude { 
        border: 2px solid #333; 
        background-color: #f8f9fa; 
        text-align: center; 
        padding: 20px; 
        font-size: 18pt; 
        font-weight: bold; 
        color: #000;
        border-radius: 10px;
    }
    .apt-APTO { color: #198754; border-color: #198754; background-color: #d1e7dd; }
    .apt-NO_APTO { color: #dc3545; border-color: #dc3545; background-color: #f8d7da; }
    .apt-RESTRICCION { color: #fd7e14; border-color: #fd7e14; background-color: #ffe5d0; }
</style>

<table cellpadding="4">
    <tr>
        <td width="20%" style="'.$styleLabel.'">Paciente:</td>
        <td width="30%" style="'.$styleVal.'">'.strtoupper($data['nombres'].' '.$data['apellidos']).'</td>
        <td width="20%" style="'.$styleLabel.'">Identificación:</td>
        <td width="30%" style="'.$styleVal.'">'.$data['tipo_documento'].' '.$data['numero_documento'].'</td>
    </tr>
    <tr>
        <td style="'.$styleLabel.'">Empresa:</td>
        <td style="'.$styleVal.'">'.$data['empresa_name'].'</td>
        <td style="'.$styleLabel.'">Fecha Examen:</td>
        <td style="'.$styleVal.'">'.date('d/m/Y H:i', strtotime($data['completed_at'])).'</td>
    </tr>
    <tr>
        <td style="'.$styleLabel.'">Examen Realizado:</td>
        <td colspan="3" style="'.$styleVal.'"><b>'.$data['exam_name'].'</b> <span style="font-size:8pt; color:#666">('.$data['service_type'].')</span></td>
    </tr>
</table>

<h4>HALLAZGOS Y DIAGNÓSTICO</h4>
<p>'.nl2br($data['diagnosis']).'</p>

<h4>RECOMENDACIONES</h4>
<p>'.nl2br($data['recommendations']).'</p>

<br><br>
<h4>CONCEPTO DE APTITUD</h4>
';

// Logic for Aptitude Display
$aptLabel = 'PENDIENTE';
$aptClass = '';

switch($data['aptitude']) {
    case 'apto': $aptLabel = 'APTO'; $aptClass = 'apt-APTO'; break;
    case 'no_apto': $aptLabel = 'NO APTO'; $aptClass = 'apt-NO_APTO'; break;
    case 'apto_restriccion': $aptLabel = 'APTO CON RESTRICCIONES'; $aptClass = 'apt-RESTRICCION'; break;
    case 'aplazado': $aptLabel = 'APLAZADO'; $aptClass = 'apt-RESTRICCION'; break;
}

$html .= '<div class="box-aptitude '.$aptClass.'">'.$aptLabel.'</div>';
$pdf->writeHTML($html, true, false, true, false, '');

// SIGNATURE
$y = $pdf->GetY() + 30;
if ($y > 240) { $pdf->AddPage(); $y = 40; }

// Specialist Signature
if (!empty($data['spec_signature'])) {
    $parts = explode(',', $data['spec_signature']);
    if(count($parts)>1) {
        $imgBin = base64_decode($parts[1]);
        $pdf->Image('@'.$imgBin, 70, $y - 25, 60, 0, 'PNG');
    }
}
$pdf->Line(60, $y, 150, $y);
$pdf->SetXY(60, $y + 2);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(90, 5, $data['spec_name'], 0, 1, 'C');
$pdf->SetX(60);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(90, 5, $data['especialidad'], 0, 1, 'C');
$pdf->SetX(60);
$pdf->Cell(90, 5, 'Reg. Médico: ' . $data['registro_medico'], 0, 1, 'C');

$pdf->Output('Certificado_'.$id.'.pdf', 'I');
?>
