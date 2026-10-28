<?php
// admin/survey_report.php — Reporte de satisfacción (PDF de indicadores y Excel detallado)
// Indicadores alineados con Res. 256 de 2016 (archivo MCA, registro tipo 3).
require_once '../config.php';

if (!isLoggedIn() || !(isAdmin() || isAuditor())) {
    header('Location: dashboard.php');
    exit();
}

@set_time_limit(120);
@ini_set('memory_limit', '512M');

$db = getDBConnection();

// ------------------------------------------------------------------ filtros
$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');
if ($start > $end) { $t = $start; $start = $end; $end = $t; }
$company = $_GET['company'] ?? 'all';
$format  = $_GET['format']  ?? 'pdf';

// ------------------------------------------------------------- catálogos
const SR_EXP = [
    'muy_buena' => 'Muy buena',
    'buena'     => 'Buena',
    'regular'   => 'Regular',
    'mala'      => 'Mala',
    'muy_mala'  => 'Muy mala',
];
const SR_REC = [
    'si'                 => 'Definitivamente sí',
    'probablemente_si'   => 'Probablemente sí',
    'definitivamente_no' => 'Definitivamente no',
    'probablemente_no'   => 'Probablemente no',
];

// ------------------------------------------------------------- datos
/**
 * Unidad de análisis: ATENCIÓN = paciente (tipo + número de documento) por día de firma.
 * Un paciente con varios consentimientos el mismo día cuenta una sola vez; se toma su
 * encuesta más reciente asociada a cualquiera de esos consentimientos.
 */
function sr_collect(mysqli $db, string $start, string $end, string $company): array
{
    $sql = "SELECT c.id, c.date_signed, c.document_type, c.document_number,
                   c.first_name, c.last_name, c.company_id,
                   COALESCE(e.nombre, 'Particular') AS empresa,
                   s.q_experience, s.q_recommend, s.comments, s.created_at AS survey_at
            FROM consents c
            LEFT JOIN empresas e ON e.id = c.company_id
            LEFT JOIN survey_responses s ON s.consent_id = c.id
            WHERE c.date_signed BETWEEN ? AND ?";
    $params = [$start . ' 00:00:00', $end . ' 23:59:59'];
    $types  = 'ss';
    if ($company !== 'all') {
        $sql .= ' AND c.company_id = ?';
        $params[] = $company;
        $types   .= 's';
    }
    $sql .= ' ORDER BY c.date_signed ASC, s.created_at ASC';

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Error en consulta: ' . $db->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $att = [];
    while ($r = $res->fetch_assoc()) {
        $day = substr($r['date_signed'], 0, 10);
        $key = strtoupper(trim($r['document_type'])) . '|' . trim($r['document_number']) . '|' . $day;
        if (!isset($att[$key])) {
            $att[$key] = [
                'fecha'     => $r['date_signed'],
                'tipo_doc'  => $r['document_type'],
                'documento' => $r['document_number'],
                'paciente'  => trim($r['first_name'] . ' ' . $r['last_name']),
                'empresa'   => $r['empresa'],
                'exp'       => null,
                'rec'       => null,
                'comentario'=> null,
                'fecha_enc' => null,
            ];
        }
        if ($r['q_experience'] !== null && ($att[$key]['fecha_enc'] === null || $r['survey_at'] >= $att[$key]['fecha_enc'])) {
            $att[$key]['exp']        = $r['q_experience'];
            $att[$key]['rec']        = $r['q_recommend'];
            $att[$key]['comentario'] = $r['comments'];
            $att[$key]['fecha_enc']  = $r['survey_at'];
        }
    }
    $stmt->close();
    return array_values($att);
}

function sr_stats(array $rows): array
{
    $s = [
        'total' => count($rows), 'resp' => 0,
        'exp'   => array_fill_keys(array_keys(SR_EXP), 0), 'exp_nr' => 0,
        'rec'   => array_fill_keys(array_keys(SR_REC), 0), 'rec_nr' => 0,
        'empresas' => [], 'meses' => [], 'comentarios' => [],
    ];
    foreach ($rows as $r) {
        $emp = $r['empresa'];
        $mes = substr($r['fecha'], 0, 7);
        foreach ([['empresas', $emp], ['meses', $mes]] as [$grp, $k]) {
            if (!isset($s[$grp][$k])) $s[$grp][$k] = ['total' => 0, 'resp' => 0, 'sat' => 0, 'rec' => 0];
            $s[$grp][$k]['total']++;
        }
        if ($r['exp'] !== null && isset(SR_EXP[$r['exp']])) {
            $s['resp']++;
            $s['exp'][$r['exp']]++;
            $sat = in_array($r['exp'], ['muy_buena', 'buena'], true);
            $s['empresas'][$emp]['resp']++; $s['meses'][$mes]['resp']++;
            if ($sat) { $s['empresas'][$emp]['sat']++; $s['meses'][$mes]['sat']++; }
        } else {
            $s['exp_nr']++;
        }
        if ($r['rec'] !== null && isset(SR_REC[$r['rec']])) {
            $s['rec'][$r['rec']]++;
            if (in_array($r['rec'], ['si', 'probablemente_si'], true)) {
                $s['empresas'][$emp]['rec']++; $s['meses'][$mes]['rec']++;
            }
        } else {
            $s['rec_nr']++;
        }
        if (!empty(trim((string)$r['comentario']))) {
            $s['comentarios'][] = ['fecha' => $r['fecha_enc'], 'exp' => $r['exp'], 'texto' => trim($r['comentario'])];
        }
    }
    $s['rec_resp'] = $s['total'] - $s['rec_nr'];
    $s['ind_sat']  = $s['resp'] > 0 ? ($s['exp']['muy_buena'] + $s['exp']['buena']) / $s['resp'] * 100 : null;
    $s['ind_rec']  = $s['rec_resp'] > 0 ? ($s['rec']['si'] + $s['rec']['probablemente_si']) / $s['rec_resp'] * 100 : null;
    $s['cobertura']= $s['total'] > 0 ? $s['resp'] / $s['total'] * 100 : null;
    $score = 0;
    foreach (['muy_buena' => 5, 'buena' => 4, 'regular' => 3, 'mala' => 2, 'muy_mala' => 1] as $k => $v) $score += $s['exp'][$k] * $v;
    $s['promedio'] = $s['resp'] > 0 ? $score / $s['resp'] : null;
    ksort($s['meses']);
    uasort($s['empresas'], fn($a, $b) => $b['total'] <=> $a['total']);
    return $s;
}

function sr_pct($n, $d, $dec = 1) { return $d > 0 ? number_format($n / $d * 100, $dec, ',', '.') . '%' : '—'; }
function sr_num($n) { return number_format((float)$n, 0, ',', '.'); }
function sr_fecha($d) { return $d ? date('d/m/Y', strtotime($d)) : ''; }

$MESES = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];

// ------------------------------------------------------------- contexto
try {
    $rows  = sr_collect($db, $start, $end, $company);
} catch (Throwable $e) {
    http_response_code(500);
    die('No fue posible generar el reporte: ' . htmlspecialchars($e->getMessage()));
}
$st = sr_stats($rows);

$siteName   = getSetting('site_name', 'IPS');
$razon      = getSetting('ips_razon_social', $siteName);
$nit        = getSetting('ips_nit', '900938062');
$logoRel    = getSetting('logo_url');
$logoPath   = $logoRel ? realpath(__DIR__ . '/../' . $logoRel) : false;
$exportedAt = date('d/m/Y h:i a');
$exportedBy = $_SESSION['nombre_completo'] ?? ($_SESSION['username'] ?? 'Usuario');
$empresaNom = 'Todas las empresas';
if ($company !== 'all') {
    $q = $db->prepare('SELECT nombre FROM empresas WHERE id = ?');
    $q->bind_param('s', $company);
    $q->execute();
    $empresaNom = ($q->get_result()->fetch_assoc()['nombre'] ?? $company);
}
$periodo  = sr_fecha($start) . ' al ' . sr_fecha($end);
$fileBase = 'reporte_encuestas_' . str_replace('-', '', $start) . '_' . str_replace('-', '', $end) . '_exp' . date('Ymd_His');

if (function_exists('logActivity')) logActivity('Reporte Encuestas ' . strtoupper($format), 'survey_responses', "Periodo $start a $end, empresa $company");

// ================================================================== EXCEL
if ($format === 'xlsx') {
    // Hoja 1: formato oficial MCA (registro tipo 3)
    $mcaHead = ['TIPO DE REGISTRO', 'CONSECUTIVO DE REGISTRO', 'TIPO DE IDENTIFICACION', 'NUMERO DE IDENTIFICACION'];
    foreach (['muy buena', 'buena', 'regular', 'mala', 'muy mala'] as $o)
        $mcaHead[] = 'Número de usuarios que respondieron "' . $o . '" a la pregunta: "¿Cómo calificaría su experiencia global respecto a los servicios de salud que ha recibido a través de su IPS?"';
    $mcaHead[] = 'Número de usuarios que no respondieron la pregunta: "¿Cómo calificaría su experiencia global respecto a los servicios de salud que ha recibido a través de su IPS?"';
    foreach (['definitivamente si', 'probablemente si', 'definitivamente no', 'probablemente no'] as $o)
        $mcaHead[] = 'Número de usuarios que respondieron "' . $o . '" a la pregunta: "¿recomendaría a sus familiares y amigos esta IPS?"';
    $mcaHead[] = 'Número de usuarios que no respondieron la pregunta: "¿recomendaría a sus familiares y amigos esta IPS?"';

    $vals = array_merge(array_values($st['exp']), [$st['exp_nr']], [$st['rec']['si'], $st['rec']['probablemente_si'], $st['rec']['definitivamente_no'], $st['rec']['probablemente_no']], [$st['rec_nr']]);
    $pcts = array_map(fn($v) => $st['total'] > 0 ? round($v / $st['total'] * 100, 1) : 0, $vals);
    $sheet1 = [
        ['_h' => true, 'c' => $mcaHead],
        ['c' => array_merge([3, 1, 'NI', (int)$nit], $vals)],
        ['c' => array_merge(['', '', '% sobre atendidos', $st['total']], $pcts)],
    ];

    // Hoja 2: indicadores
    $sheet2 = [
        ['_t' => true, 'c' => ['REPORTE DE SATISFACCIÓN DE USUARIOS']],
        ['c' => ['Entidad', $razon . ' — NIT ' . $nit]],
        ['c' => ['Periodo', $periodo]],
        ['c' => ['Empresa', $empresaNom]],
        ['c' => ['Fecha de exportación', $exportedAt]],
        ['c' => ['Generado por', $exportedBy]],
        ['c' => ['']],
        ['_h' => true, 'c' => ['Indicador', 'Numerador', 'Denominador', 'Resultado']],
        ['c' => ['Proporción de satisfacción global (muy buena + buena)', $st['exp']['muy_buena'] + $st['exp']['buena'], $st['resp'], $st['ind_sat'] === null ? '—' : round($st['ind_sat'], 1) . '%']],
        ['c' => ['Proporción de usuarios que recomendarían la IPS', $st['rec']['si'] + $st['rec']['probablemente_si'], $st['rec_resp'], $st['ind_rec'] === null ? '—' : round($st['ind_rec'], 1) . '%']],
        ['c' => ['Cobertura de la encuesta (respondieron / atendidos)', $st['resp'], $st['total'], $st['cobertura'] === null ? '—' : round($st['cobertura'], 1) . '%']],
        ['c' => ['Calificación promedio (escala 1 a 5)', '', '', $st['promedio'] === null ? '—' : round($st['promedio'], 2)]],
        ['c' => ['']],
        ['_h' => true, 'c' => ['Empresa', 'Atendidos', 'Respondieron', '% Satisfacción', '% Recomendaría']],
    ];
    foreach ($st['empresas'] as $emp => $e)
        $sheet2[] = ['c' => [$emp, $e['total'], $e['resp'], $e['resp'] ? round($e['sat'] / $e['resp'] * 100, 1) . '%' : '—', $e['resp'] ? round($e['rec'] / $e['resp'] * 100, 1) . '%' : '—']];
    $sheet2[] = ['c' => ['']];
    $sheet2[] = ['_h' => true, 'c' => ['Mes', 'Atendidos', 'Respondieron', '% Satisfacción', '% Recomendaría']];
    foreach ($st['meses'] as $m => $e)
        $sheet2[] = ['c' => [$MESES[substr($m, 5, 2)] . ' ' . substr($m, 0, 4), $e['total'], $e['resp'], $e['resp'] ? round($e['sat'] / $e['resp'] * 100, 1) . '%' : '—', $e['resp'] ? round($e['rec'] / $e['resp'] * 100, 1) . '%' : '—']];

    // Hoja 3: detalle
    $sheet3 = [['_h' => true, 'c' => ['Fecha atención', 'Tipo doc.', 'Documento', 'Paciente', 'Empresa', 'Estado encuesta', 'Fecha encuesta', 'Experiencia global', '¿Recomendaría la IPS?', 'Comentarios']]];
    foreach ($rows as $r) {
        $sheet3[] = ['c' => [
            date('d/m/Y H:i', strtotime($r['fecha'])), $r['tipo_doc'], (string)$r['documento'], $r['paciente'], $r['empresa'],
            $r['exp'] ? 'Respondió' : 'No respondió',
            $r['fecha_enc'] ? date('d/m/Y H:i', strtotime($r['fecha_enc'])) : '',
            $r['exp'] ? (SR_EXP[$r['exp']] ?? $r['exp']) : '',
            $r['rec'] ? (SR_REC[$r['rec']] ?? $r['rec']) : '',
            (string)$r['comentario'],
        ]];
    }

    if (!class_exists('ZipArchive')) {
        // Respaldo: CSV del detalle
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
        $o = fopen('php://output', 'w');
        fprintf($o, "\xEF\xBB\xBF");
        foreach ($sheet3 as $row) fputcsv($o, $row['c'], ';');
        exit();
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    sr_xlsx($tmp, [
        ['name' => 'Indicadores', 'rows' => $sheet2, 'widths' => [52, 16, 16, 16, 16]],
        ['name' => 'Formato MCA', 'rows' => $sheet1, 'widths' => array_merge([12, 14, 14, 16], array_fill(0, 11, 22))],
        ['name' => 'Detalle', 'rows' => $sheet3, 'widths' => [17, 9, 15, 34, 30, 15, 17, 16, 20, 50]],
    ]);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit();
}

/** Escritor XLSX mínimo (sin dependencias). Filas: ['c'=>[...], '_h'=>encabezado, '_t'=>título] */
function sr_xlsx(string $path, array $sheets): void
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function ($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wbSheets = ''; $wbRels = '';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . $x($sh['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $ct .= '</Types>';
    $styleId = count($sheets) + 1;
    $wbRels .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
    // Estilos: 0 normal, 1 encabezado (negrita, fondo, borde, ajuste), 2 título, 3 normal con borde y ajuste
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF1F3A5F"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD0D7DE"/></left><right style="thin"><color rgb="FFD0D7DE"/></right><top style="thin"><color rgb="FFD0D7DE"/></top><bottom style="thin"><color rgb="FFD0D7DE"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>');

    foreach ($sheets as $i => $sh) {
        $cols = '<cols>';
        foreach ($sh['widths'] as $c => $w) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        $cols .= '</cols>';
        $data = '';
        foreach ($sh['rows'] as $r => $row) {
            $rn = $r + 1;
            $s = !empty($row['_h']) ? 1 : (!empty($row['_t']) ? 2 : 3);
            if (count($row['c']) === 1 && $row['c'][0] === '') { $data .= '<row r="' . $rn . '"/>'; continue; }
            $data .= '<row r="' . $rn . '"' . (!empty($row['_h']) && $i === 1 ? ' ht="90" customHeight="1"' : '') . '>';
            foreach ($row['c'] as $c => $v) {
                $ref = $col($c) . $rn;
                if (is_int($v) || is_float($v)) $data .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
                else $data .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
            $data .= '</row>';
        }
        $freeze = ($sh['name'] === 'Detalle') ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '';
        $filter = ($sh['name'] === 'Detalle' && count($sh['rows']) > 1) ? '<autoFilter ref="A1:J' . count($sh['rows']) . '"/>' : '';
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $freeze . $cols . '<sheetData>' . $data . '</sheetData>' . $filter . '</worksheet>');
    }
    $zip->close();
}

// ==================================================================== PDF
$tcpdfPath = __DIR__ . '/../assets/lib/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) die('No se encuentra la librería TCPDF.');
require_once $tcpdfPath;

class SurveyPDF extends TCPDF
{
    public $meta = [];
    public function Header()
    {
        $m = $this->meta;
        if (!empty($m['logo']) && is_file($m['logo'])) {
            @$this->Image($m['logo'], 15, 9, 0, 13, '', '', '', true, 300, '', false, false, 0, 'LT');
        }
        $this->SetY(9);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(31, 58, 95);
        $this->Cell(0, 5, $m['razon'], 0, 1, 'R');
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 4, 'NIT ' . $m['nit'] . '  ·  Reporte de satisfacción de usuarios', 0, 1, 'R');
        $this->SetDrawColor(31, 58, 95);
        $this->SetLineWidth(0.6);
        $this->Line(15, 25, $this->getPageWidth() - 15, 25);
    }
    public function Footer()
    {
        $this->SetY(-14);
        $this->SetDrawColor(210, 215, 222);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 6, 'Exportado el ' . $this->meta['exp'] . ' por ' . $this->meta['by'] . '  ·  Periodo ' . $this->meta['periodo'], 0, 0, 'L');
        $this->Cell(0, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new SurveyPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
$pdf->meta = ['razon' => $razon, 'nit' => $nit, 'logo' => $logoPath, 'exp' => $exportedAt, 'by' => $exportedBy, 'periodo' => $periodo];
$pdf->SetCreator($siteName);
$pdf->SetAuthor($exportedBy);
$pdf->SetTitle('Reporte de satisfacción ' . $periodo);
$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(14);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$navy = [31, 58, 95];
$W = $pdf->getPageWidth() - 30;

// Título
$pdf->SetFont('helvetica', 'B', 17);
$pdf->SetTextColor(...$navy);
$pdf->Cell(0, 9, 'Indicadores de satisfacción del usuario', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9.5);
$pdf->SetTextColor(70, 70, 70);
$pdf->Cell(0, 5, 'Periodo: ' . $periodo . '   ·   Empresa: ' . $empresaNom, 0, 1, 'L');
$pdf->Cell(0, 5, 'Fecha de exportación: ' . $exportedAt . '   ·   Generado por: ' . $exportedBy, 0, 1, 'L');
$pdf->Ln(4);

// Tarjetas KPI
$kpis = [
    ['Satisfacción global', $st['ind_sat'] === null ? '—' : number_format($st['ind_sat'], 1, ',', '.') . '%', 'Muy buena + buena / respondieron', [25, 135, 84]],
    ['Recomendaría la IPS', $st['ind_rec'] === null ? '—' : number_format($st['ind_rec'], 1, ',', '.') . '%', 'Def. sí + prob. sí / respondieron', [13, 110, 253]],
    ['Usuarios atendidos', sr_num($st['total']), sr_num($st['resp']) . ' respondieron (' . sr_pct($st['resp'], $st['total']) . ')', $navy],
    ['Calificación promedio', $st['promedio'] === null ? '—' : number_format($st['promedio'], 2, ',', '.') . ' / 5', 'Escala 1 (muy mala) a 5 (muy buena)', [108, 117, 125]],
];
$gap = 4;
$kw  = ($W - $gap * 3) / 4;
$y0  = $pdf->GetY();
foreach ($kpis as $i => [$lbl, $val, $sub, $color]) {
    $x = 15 + $i * ($kw + $gap);
    $pdf->SetFillColor(246, 248, 250);
    $pdf->SetDrawColor(225, 229, 234);
    $pdf->RoundedRect($x, $y0, $kw, 27, 2, '1111', 'DF');
    $pdf->SetFillColor(...$color);
    $pdf->Rect($x, $y0, 1.6, 27, 'F');
    $pdf->SetXY($x + 4, $y0 + 3);
    $pdf->SetFont('helvetica', '', 7.5); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($kw - 6, 4, mb_strtoupper($lbl), 0, 2, 'L');
    $pdf->SetFont('helvetica', 'B', 17); $pdf->SetTextColor(...$color);
    $pdf->Cell($kw - 6, 10, $val, 0, 2, 'L');
    $pdf->SetFont('helvetica', '', 6.8); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($kw - 6, 3, $sub, 0, 'L');
}
$pdf->SetY($y0 + 33);

// Tabla de distribución con barras
function sr_dist_table($pdf, $title, $question, array $items, $total, $W, $navy)
{
    $pdf->SetFont('helvetica', 'B', 11); $pdf->SetTextColor(...$navy);
    $pdf->Cell(0, 6, $title, 0, 1, 'L');
    $pdf->SetFont('helvetica', 'I', 8); $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, $question, 0, 'L');
    $pdf->Ln(1);
    $c1 = 52; $c2 = 22; $c3 = 22; $c4 = $W - $c1 - $c2 - $c3;
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetFillColor(...$navy); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($c1, 6, 'Respuesta', 0, 0, 'L', true);
    $pdf->Cell($c2, 6, 'Usuarios', 0, 0, 'R', true);
    $pdf->Cell($c3, 6, '% atendidos', 0, 0, 'R', true);
    $pdf->Cell($c4, 6, '', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(40, 40, 40);
    foreach ($items as $k => [$label, $n, $color]) {
        $fill = $k % 2 ? [255, 255, 255] : [246, 248, 250];
        $pdf->SetFillColor(...$fill);
        $y = $pdf->GetY();
        $pdf->Cell($c1, 6, $label, 0, 0, 'L', true);
        $pdf->Cell($c2, 6, sr_num($n), 0, 0, 'R', true);
        $pdf->Cell($c3, 6, sr_pct($n, $total), 0, 0, 'R', true);
        $pdf->Cell($c4, 6, '', 0, 1, 'L', true);
        $bw = $total > 0 ? ($c4 - 6) * $n / $total : 0;
        if ($bw > 0) { $pdf->SetFillColor(...$color); $pdf->Rect(15 + $c1 + $c2 + $c3 + 3, $y + 1.6, max($bw, 0.6), 2.8, 'F'); }
    }
    $pdf->SetFont('helvetica', 'B', 8.5); $pdf->SetFillColor(233, 237, 242);
    $pdf->Cell($c1, 6, 'Total usuarios atendidos', 0, 0, 'L', true);
    $pdf->Cell($c2, 6, sr_num($total), 0, 0, 'R', true);
    $pdf->Cell($c3, 6, $total > 0 ? '100%' : '—', 0, 0, 'R', true);
    $pdf->Cell($c4, 6, '', 0, 1, 'L', true);
    $pdf->Ln(5);
}

$green = [25, 135, 84]; $lgreen = [117, 183, 152]; $yellow = [255, 193, 7]; $orange = [253, 126, 20]; $red = [220, 53, 69]; $gray = [173, 181, 189];
sr_dist_table($pdf, 'Experiencia global', '¿Cómo calificaría su experiencia global respecto a los servicios de salud que ha recibido a través de su IPS?', [
    ['Muy buena', $st['exp']['muy_buena'], $green], ['Buena', $st['exp']['buena'], $lgreen], ['Regular', $st['exp']['regular'], $yellow],
    ['Mala', $st['exp']['mala'], $orange], ['Muy mala', $st['exp']['muy_mala'], $red], ['No respondió', $st['exp_nr'], $gray],
], $st['total'], $W, $navy);
sr_dist_table($pdf, 'Recomendación', '¿Recomendaría a sus familiares y amigos esta IPS?', [
    ['Definitivamente sí', $st['rec']['si'], $green], ['Probablemente sí', $st['rec']['probablemente_si'], $lgreen],
    ['Probablemente no', $st['rec']['probablemente_no'], $orange], ['Definitivamente no', $st['rec']['definitivamente_no'], $red],
    ['No respondió', $st['rec_nr'], $gray],
], $st['total'], $W, $navy);

// Tabla genérica
function sr_table($pdf, $title, array $head, array $widths, array $rows, $navy, $aligns = null)
{
    if ($pdf->GetY() > $pdf->getPageHeight() - 60) $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 11); $pdf->SetTextColor(...$navy);
    $pdf->Cell(0, 7, $title, 0, 1, 'L');
    $drawHead = function () use ($pdf, $head, $widths, $navy, $aligns) {
        $pdf->SetFont('helvetica', 'B', 8); $pdf->SetFillColor(...$navy); $pdf->SetTextColor(255, 255, 255);
        foreach ($head as $i => $h) $pdf->Cell($widths[$i], 6, $h, 0, 0, $aligns[$i] ?? 'L', true);
        $pdf->Ln();
        $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(40, 40, 40);
    };
    $drawHead();
    foreach ($rows as $k => $r) {
        if ($pdf->GetY() > $pdf->getPageHeight() - 25) { $pdf->AddPage(); $drawHead(); }
        $pdf->SetFillColor(...($k % 2 ? [255, 255, 255] : [246, 248, 250]));
        foreach ($r as $i => $v) $pdf->Cell($widths[$i], 6, $pdf->getStringWidth($v) > $widths[$i] - 2 ? mb_substr($v, 0, (int)($widths[$i] / 1.9)) . '…' : $v, 0, 0, $aligns[$i] ?? 'L', true);
        $pdf->Ln();
    }
    $pdf->Ln(5);
}

$al = ['L', 'R', 'R', 'R', 'R', 'R'];
if (count($st['meses']) > 1) {
    $rowsM = [];
    foreach ($st['meses'] as $m => $e)
        $rowsM[] = [$MESES[substr($m, 5, 2)] . ' ' . substr($m, 0, 4), sr_num($e['total']), sr_num($e['resp']), sr_pct($e['resp'], $e['total']), sr_pct($e['sat'], $e['resp']), sr_pct($e['rec'], $e['resp'])];
    sr_table($pdf, 'Evolución mensual', ['Mes', 'Atendidos', 'Respondieron', 'Cobertura', 'Satisfacción', 'Recomendaría'], [46, 26, 26, 26, 28, $W - 152], $rowsM, $navy, $al);
}
if (count($st['empresas']) > 0) {
    $rowsE = [];
    foreach ($st['empresas'] as $emp => $e)
        $rowsE[] = [$emp, sr_num($e['total']), sr_num($e['resp']), sr_pct($e['resp'], $e['total']), sr_pct($e['sat'], $e['resp']), sr_pct($e['rec'], $e['resp'])];
    sr_table($pdf, 'Resultados por empresa', ['Empresa', 'Atendidos', 'Respondieron', 'Cobertura', 'Satisfacción', 'Recomendaría'], [66, 22, 24, 22, 24, $W - 158], $rowsE, $navy, $al);
}

// Nota metodológica
if ($pdf->GetY() > $pdf->getPageHeight() - 45) $pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(...$navy);
$pdf->Cell(0, 5, 'Nota metodológica', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 7.8); $pdf->SetTextColor(80, 80, 80);
$pdf->MultiCell(0, 3.8,
    'Fuente: registros del sistema de consentimientos informados y encuestas de satisfacción diligenciadas por los usuarios (kiosco y enlace por correo electrónico). '
    . 'Usuario atendido: paciente identificado por tipo y número de documento con al menos un consentimiento firmado en el día; varios consentimientos del mismo día cuentan como una sola atención. '
    . 'Proporción de satisfacción global = (usuarios que respondieron "muy buena" o "buena") / (usuarios que respondieron la pregunta) × 100. '
    . 'Proporción de usuarios que recomendarían la IPS = (usuarios que respondieron "definitivamente sí" o "probablemente sí") / (usuarios que respondieron la pregunta) × 100. '
    . 'Los usuarios atendidos sin encuesta se reportan como "No respondió", conforme a la estructura del registro tipo 3 de la Resolución 256 de 2016.',
    0, 'J');

while (ob_get_level()) ob_end_clean();
$pdf->Output($fileBase . '.pdf', ($_GET['inline'] ?? '') === '1' ? 'I' : 'D');
exit();
