<?php
require_once '../config.php';

if (!isLoggedIn() || !(isAdmin() || isAuditor())) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();
$siteName = getSetting('site_name', 'Sistema');
$logoUrl = getSetting('logo_url');
if ($logoUrl) $logoUrl = '../' . $logoUrl;

// --- FILTROS ---
$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');
if ($start > $end) { $t = $start; $start = $end; $end = $t; }
$company = $_GET['company'] ?? 'all';

$empresas = [];
$resE = $db->query("SELECT id, nombre FROM empresas ORDER BY nombre ASC");
if ($resE) while ($e = $resE->fetch_assoc()) $empresas[] = $e;

// --- CONSULTA DEL PERIODO (consentimientos + encuesta) ---
$sql = "SELECT c.id, c.date_signed, c.document_type, c.document_number, c.first_name, c.last_name,
               COALESCE(e.nombre, 'Particular') AS empresa,
               s.q_experience, s.q_recommend, s.comments, s.created_at AS survey_at
        FROM consents c
        LEFT JOIN empresas e ON e.id = c.company_id
        LEFT JOIN survey_responses s ON s.consent_id = c.id
        WHERE c.date_signed BETWEEN ? AND ?";
$params = [$start . ' 00:00:00', $end . ' 23:59:59'];
$types = 'ss';
if ($company !== 'all') { $sql .= ' AND c.company_id = ?'; $params[] = $company; $types .= 's'; }
$sql .= ' ORDER BY c.date_signed ASC, s.created_at ASC';
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// Agrupar por atención (paciente + día)
$att = [];
while ($r = $res->fetch_assoc()) {
    $key = strtoupper(trim($r['document_type'])) . '|' . trim($r['document_number']) . '|' . substr($r['date_signed'], 0, 10);
    if (!isset($att[$key])) $att[$key] = $r + ['has' => false];
    if ($r['q_experience'] !== null && (!$att[$key]['has'] || $r['survey_at'] >= $att[$key]['survey_at'])) {
        $att[$key] = array_merge($att[$key], [
            'q_experience' => $r['q_experience'], 'q_recommend' => $r['q_recommend'],
            'comments' => $r['comments'], 'survey_at' => $r['survey_at'], 'has' => true,
        ]);
    }
}
$stmt->close();

$totalAtendidos = count($att);
$respuestas = array_values(array_filter($att, fn($a) => $a['has']));
usort($respuestas, fn($a, $b) => strcmp($b['survey_at'], $a['survey_at']));
$totalEncuestas = count($respuestas);
$sinEncuesta = $totalAtendidos - $totalEncuestas;

$sumScore = 0; $sat = 0; $siRecomienda = 0;
$map = ['muy_buena' => 5, 'buena' => 4, 'regular' => 3, 'mala' => 2, 'muy_mala' => 1];
foreach ($respuestas as $r) {
    $sumScore += $map[$r['q_experience']] ?? 0;
    if (in_array($r['q_experience'], ['muy_buena', 'buena'], true)) $sat++;
    if (in_array($r['q_recommend'], ['si', 'probablemente_si'], true)) $siRecomienda++;
}
$promedioExp   = $totalEncuestas ? round($sumScore / $totalEncuestas, 1) : 0;
$porcentajeSat = $totalEncuestas ? round($sat / $totalEncuestas * 100, 1) : 0;
$porcentajeRec = $totalEncuestas ? round($siRecomienda / $totalEncuestas * 100, 1) : 0;
$cobertura     = $totalAtendidos ? round($totalEncuestas / $totalAtendidos * 100, 1) : 0;

$qs = http_build_query(['start' => $start, 'end' => $end, 'company' => $company]);
$recLabels = ['si' => 'Definitivamente sí', 'probablemente_si' => 'Probablemente sí', 'definitivamente_no' => 'Definitivamente no', 'probablemente_no' => 'Probablemente no'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encuestas | <?php echo htmlspecialchars($siteName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .score-card { transition: transform 0.2s; }
        .score-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 px-3">
    <a class="navbar-brand mb-0 h1 d-flex align-items-center" href="dashboard.php">
        <?php if ($logoUrl && file_exists($logoUrl)): ?>
            <img src="<?php echo $logoUrl . '?v=' . time(); ?>" alt="Logo" height="30" class="d-inline-block align-text-top me-2 bg-white rounded px-1">
        <?php endif; ?>
        <span>Panel Administrador</span>
    </a>
    <div>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">Dashboard</a>
        <a href="logs.php" class="btn btn-outline-light btn-sm me-2">Auditoría</a>
        <a href="change_password.php" class="btn btn-outline-light btn-sm me-2">Contraseña</a>
        <a href="logout.php" class="btn btn-danger btn-sm">Salir</a>
    </div>
</nav>

<div class="container pb-5">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div>
            <h2 class="fw-bold m-0">Resultados de Satisfacción</h2>
            <span class="text-muted small">Periodo <?php echo date('d/m/Y', strtotime($start)); ?> al <?php echo date('d/m/Y', strtotime($end)); ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="survey_report.php?<?php echo $qs; ?>&format=pdf" class="btn btn-danger shadow-sm">
                <i class="bi bi-file-earmark-pdf"></i> Informe PDF
            </a>
            <a href="survey_report.php?<?php echo $qs; ?>&format=xlsx" class="btn btn-success shadow-sm">
                <i class="bi bi-file-earmark-excel"></i> Excel detallado
            </a>
        </div>
    </div>

    <form class="card border-0 shadow-sm mb-4" method="GET">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-bold mb-1">Desde</label>
                <input type="date" name="start" class="form-control" value="<?php echo $start; ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-bold mb-1">Hasta</label>
                <input type="date" name="end" class="form-control" value="<?php echo $end; ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-bold mb-1">Empresa</label>
                <select name="company" class="form-select">
                    <option value="all">Todas las empresas</option>
                    <?php foreach ($empresas as $e): ?>
                        <option value="<?php echo htmlspecialchars($e['id']); ?>" <?php echo $company === (string)$e['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card score-card border-0 shadow-sm text-center p-3 bg-white h-100">
                <div class="display-6 fw-bold text-success"><?php echo $porcentajeSat; ?>%</div>
                <div class="text-muted text-uppercase small fw-bold mt-2">Satisfacción global</div>
                <div class="text-muted small">Muy buena + buena</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card score-card border-0 shadow-sm text-center p-3 bg-white h-100">
                <div class="display-6 fw-bold text-primary"><?php echo $porcentajeRec; ?>%</div>
                <div class="text-muted text-uppercase small fw-bold mt-2">Nos recomiendan</div>
                <div class="text-muted small">Promedio <?php echo $promedioExp; ?>/5</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card score-card border-0 shadow-sm text-center p-3 bg-white h-100">
                <div class="display-6 fw-bold text-dark"><?php echo $totalEncuestas; ?></div>
                <div class="text-muted text-uppercase small fw-bold mt-2">Respondieron</div>
                <div class="text-muted small">de <?php echo $totalAtendidos; ?> atendidos (<?php echo $cobertura; ?>%)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card score-card border-0 shadow-sm text-center p-3 bg-white h-100">
                <div class="display-6 fw-bold text-secondary"><?php echo $sinEncuesta; ?></div>
                <div class="text-muted text-uppercase small fw-bold mt-2">No respondieron</div>
                <div class="text-muted small">Se reportan como "no respondió"</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="m-0 fw-bold"><i class="bi bi-list-check me-2"></i> Detalle de Respuestas</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha encuesta</th>
                        <th>Paciente</th>
                        <th>Empresa</th>
                        <th>Experiencia</th>
                        <th>¿Recomienda?</th>
                        <th>Comentarios</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($respuestas)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No hay respuestas en este periodo.</td></tr>
                    <?php else: ?>
                        <?php foreach ($respuestas as $r): ?>
                            <tr>
                                <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($r['survey_at'])); ?></td>
                                <td><small><?php echo htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])); ?><br><span class="text-muted"><?php echo htmlspecialchars($r['document_type'] . ' ' . $r['document_number']); ?></span></small></td>
                                <td><small><?php echo htmlspecialchars($r['empresa']); ?></small></td>
                                <td>
                                    <?php
                                    if (in_array($r['q_experience'], ['muy_buena', 'buena'])) $cls = 'success';
                                    elseif ($r['q_experience'] == 'regular') $cls = 'warning text-dark';
                                    else $cls = 'danger';
                                    echo "<span class='badge bg-$cls'>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $r['q_experience']))) . "</span>";
                                    ?>
                                </td>
                                <td><small><?php echo htmlspecialchars($recLabels[$r['q_recommend']] ?? $r['q_recommend']); ?></small></td>
                                <td class="text-muted fst-italic"><small><?php echo !empty($r['comments']) ? htmlspecialchars($r['comments']) : '-'; ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small text-center mt-4">Panel de Gestión de Calidad · Indicadores calculados sobre usuarios atendidos (paciente por día) del periodo seleccionado.</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
