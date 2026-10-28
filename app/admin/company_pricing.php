<?php
// public_html/admin/company_pricing.php
require_once '../config.php';

// ACCESO RESTRINGIDO
if (!isLoggedIn() || !isDesarrollador()) {
    header('Location: dashboard.php');
    exit();
}

$db = getDBConnection();

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'save_price') {
            $companyId = $_POST['company_id'];
            $examId = (int)$_POST['exam_id'];
            $price = trim($_POST['price']);
            
            if ($price === '') {
                // Si está vacío, borrar override (volver a precio default)
                $stmt = $db->prepare("DELETE FROM company_exam_prices WHERE company_id=? AND exam_id=?");
                $stmt->bind_param("si", $companyId, $examId);
            } else {
                // Insert/Update override (Upsert)
                // Usando REPLACE o "ON DUPLICATE KEY"
                $stmt = $db->prepare("INSERT INTO company_exam_prices (company_id, exam_id, price) VALUES (?, ?, ?)
                                      ON DUPLICATE KEY UPDATE price = VALUES(price)");
                 $stmt->bind_param("sid", $companyId, $examId, $price);
            }
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success']);
            } else throw new Exception($stmt->error);
            
        } elseif ($_POST['action'] === 'get_prices') {
             $companyId = $_POST['company_id'];
             
             // Obtener TODOS los examenes con sus precios default y los overrides de esta empresa
             $sql = "SELECT e.id, e.name, e.cost as default_cost, cep.price as custom_price 
                     FROM exams e 
                     LEFT JOIN company_exam_prices cep ON e.id = cep.exam_id AND cep.company_id = ?
                     WHERE e.is_active = 1
                     ORDER BY e.name ASC";
             $stmt = $db->prepare($sql);
             $stmt->bind_param("s", $companyId);
             $stmt->execute();
             $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
             
             echo json_encode(['status' => 'success', 'data' => $data]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// Obtener lista de empresas
$resEmp = $db->query("SELECT id, nombre FROM empresas WHERE estado='activo' ORDER BY nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Precios por Empresa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php applyTheme(); ?>
    <style>
        .price-input { max-width: 150px; }
        .changed { background-color: #e8f5e9; border-color: #4caf50; }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4 px-3">
        <a class="navbar-brand" href="dashboard.php">Panel Desarrollador</a>
        <div><a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a></div>
    </nav>

    <div class="container">
        <h3><i class="bi bi-tag-fill"></i> Precios por Empresa</h3>
        <p class="text-muted">Configure precios personalizados que anulen el costo estándar para una empresa específica.</p>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <label class="fw-bold">Seleccione Empresa:</label>
                <select id="sel_company" class="form-select mt-2" onchange="loadPrices()">
                    <option value="">-- Seleccionar --</option>
                    <?php while($row = $resEmp->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo $row['nombre']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <div id="pricing_area" class="card shadow-sm border-0 d-none">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Examen</th>
                                <th>Precio Estándar</th>
                                <th>Precio Empresa (Override)</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="table_body">
                            <!-- JS Injection -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/ui-manager.js"></script>

    <script>
        function loadPrices() {
            const companyId = document.getElementById('sel_company').value;
            const area = document.getElementById('pricing_area');
            const tbody = document.getElementById('table_body');

            if (!companyId) {
                area.classList.add('d-none');
                return;
            }

            UI.loading('Cargando precios...');
            
            const fd = new FormData();
            fd.append('action', 'get_prices');
            fd.append('company_id', companyId);

            fetch('company_pricing.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    Swal.close();
                    if(res.status === 'success') {
                        renderTable(res.data);
                        area.classList.remove('d-none');
                    } else {
                        UI.error('Error', res.message);
                    }
                })
                .catch(err => UI.error('Error', 'Fallo carga'));
        }

        function renderTable(rows) {
            const tbody = document.getElementById('table_body');
            tbody.innerHTML = '';

            rows.forEach(row => {
                const hasCustom = (row.custom_price !== null);
                const val = hasCustom ? parseFloat(row.custom_price) : '';
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="fw-bold text-primary">${row.name}</td>
                    <td class="text-secondary">$ ${new Intl.NumberFormat().format(row.default_cost)}</td>
                    <td>
                        <div class="input-group input-group-sm price-input">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" 
                                   id="in_${row.id}" 
                                   value="${val}" 
                                   placeholder="Default"
                                   onchange="savePrice(${row.id}, this)">
                        </div>
                    </td>
                    <td>
                        <span id="st_${row.id}" class="badge ${hasCustom ? 'bg-success' : 'bg-light text-dark border'}">
                            ${hasCustom ? 'Personalizado' : 'Estándar'}
                        </span>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function savePrice(examId, inputElem) {
            const companyId = document.getElementById('sel_company').value;
            const price = inputElem.value;
            
            inputElem.classList.add('bg-light'); // visual feedback processing

            const fd = new FormData();
            fd.append('action', 'save_price');
            fd.append('company_id', companyId);
            fd.append('exam_id', examId);
            fd.append('price', price);

            fetch('company_pricing.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    inputElem.classList.remove('bg-light');
                    if (res.status === 'success') {
                         inputElem.classList.add('changed');
                         setTimeout(() => inputElem.classList.remove('changed'), 1000);
                         
                         // Update status badge
                         const badge = document.getElementById('st_' + examId);
                         if (price === '') {
                             badge.className = 'badge bg-light text-dark border';
                             badge.innerText = 'Estándar';
                         } else {
                             badge.className = 'badge bg-success';
                             badge.innerText = 'Personalizado';
                         }
                    } else {
                        UI.error('Error', res.message);
                    }
                });
        }
    </script>
</body>
</html>
