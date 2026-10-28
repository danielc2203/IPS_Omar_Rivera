<?php
// public_html/admin/download_withdrawal_pdf.php
require_once '../config.php';

$tcpdfPath = '../assets/lib/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) die("Error: TCPDF not found.");
require_once $tcpdfPath;

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$id = $_GET['id'] ?? 0;
$db = getDBConnection();

$sql = "SELECT w.*, p.nombres, p.apellidos, p.tipo_documento, p.numero_documento, e.nombre as nombre_empresa, e.nit
        FROM withdrawals w
        JOIN pacientes_pre_registrados p ON w.patient_id = p.id
        LEFT JOIN empresas e ON p.empresa_id = e.id
        WHERE w.id = ?";

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) die("Documento no encontrado.");
$data = $result->fetch_assoc();

// Obtener Configuración Global para el LOGO
$configSql = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_url'");
$logoUrlConf = $configSql->fetch_assoc()['setting_value'] ?? '';

// TCPDF Implementation
class WithdrawalPDF extends TCPDF {
    public $logoFile;
    public function Header() {
        if ($this->logoFile && file_exists($this->logoFile)) {
            $this->Image($this->logoFile, 15, 10, 25, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        $this->SetY(15);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'DOCUMENTO DE DESISTIMIENTO VOLUNTARIO', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Line(15, 30, 195, 30);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Generado el ' . date('d/m/Y H:i') . ' | Pág. '.$this->getAliasNumPage(), 0, false, 'C');
    }
}

$pdf = new WithdrawalPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Logo
$logoRelativo = '../' . explode('?', $logoUrlConf)[0];
$pdf->logoFile = $logoRelativo;

$pdf->SetTitle('Desistimiento - ' . $data['numero_documento']);
$pdf->SetMargins(15, 40, 15);
$pdf->SetHeaderMargin(10);
$pdf->AddPage();

$exams = json_decode($data['exams_json'], true);
$examList = "<ul>";
foreach($exams as $e) $examList .= "<li><b>{$e['name']}</b></li>";
$examList .= "</ul>";

$html = '
<style>
    .box { border: 1px solid #ccc; padding: 10px; background-color: #f9f9f9; }
    h4 { color: #d9534f; border-bottom: 1px solid #d9534f; }
</style>

<h4>1. DATOS DEL PACIENTE</h4>
<table cellpadding="4">
    <tr><td><b>Nombre:</b></td><td>'.strtoupper($data['nombres'].' '.$data['apellidos']).'</td></tr>
    <tr><td><b>Documento:</b></td><td>'.$data['tipo_documento'].' '.$data['numero_documento'].'</td></tr>
    <tr><td><b>Empresa:</b></td><td>'.($data['nombre_empresa'] ?: 'Particular').'</td></tr>
    <tr><td><b>Fecha/Hora:</b></td><td>'.date('d/m/Y H:i', strtotime($data['created_at'])).'</td></tr>
</table>

';

// 4. DECLARACIÓN (DINÁMICA DESDE PLANTILLAS)
$tplRaw = getActiveWithdrawalTemplate();
$fullName = strtoupper($data['nombres'].' '.$data['apellidos']);
$tplProcessed = str_replace(
    ['{{FULL_NAME}}', '{{DOCUMENT_TYPE}}', '{{DOCUMENT_NUMBER}}'],
    [$fullName, $data['tipo_documento'], $data['numero_documento']],
    $tplRaw
);

$html .= '

<h4>2. EXÁMENES / SERVICIOS DESISTIDOS</h4>
<p>El paciente manifiesta de forma libre y voluntaria su decisión de <b>NO realizarse</b> los siguientes exámenes o procedimientos en esta IPS:</p>
'.$examList.'

<h4>3. MOTIVO (MANIFESTADO POR EL PACIENTE)</h4>
<div class="box">
    '.nl2br(htmlspecialchars($data['reason'] ?: 'No manifiesta motivo específico.')).'
</div>

<h4>4. DECLARACIÓN</h4>
<p>'.nl2br($tplProcessed).'</p>

<br><br>
';

$pdf->writeHTML($html, true, false, true, false, '');

// Signature
if (!empty($data['signature'])) {
    $parts = explode(',', $data['signature']);
    if (count($parts) > 1) {
        $imgBin = base64_decode($parts[1]);
        $pdf->Image('@'.$imgBin, 20, $pdf->GetY(), 60, 0, 'PNG');
        $pdf->Ln(25);
        $pdf->Line(20, $pdf->GetY(), 90, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(20);
        $pdf->Cell(70, 5, strtoupper($data['nombres'].' '.$data['apellidos']), 0, 1, 'L');
        $pdf->SetX(20);
        $pdf->Cell(70, 5, "C.C. ".$data['numero_documento'], 0, 1, 'L');
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetX(20);
        $pdf->Cell(70, 5, "Firma del Paciente", 0, 1, 'L');
    }
}

$pdf->Output('Desistimiento_'.$data['numero_documento'].'.pdf', 'I');
