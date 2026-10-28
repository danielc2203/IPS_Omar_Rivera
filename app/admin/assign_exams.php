<?php
// public_html/admin/assign_exams.php
require_once '../config.php';

if (!isLoggedIn()) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado']);
    exit();
}

$db = getDBConnection();
header('Content-Type: application/json');

function getAssignedExams($db, $patientId) {
    // V2: Included column note
    $sql = "SELECT pe.id, e.name, pe.price_snapshot, pe.assigned_at, pe.note 
            FROM patient_exams pe 
            JOIN exams e ON pe.exam_id = e.id 
            WHERE pe.patient_id = ? 
            ORDER BY pe.assigned_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper: Calcular precio real para un paciente (Revisar override empresa)
function resolvePrice($db, $examId, $companyId) {
    if ($companyId) {
        $stmt = $db->prepare("SELECT price FROM company_exam_prices WHERE company_id = ? AND exam_id = ?");
        $stmt->bind_param("si", $companyId, $examId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return (float)$row['price'];
        }
    }
    // Si no hay override, precio default
    $res = $db->query("SELECT cost FROM exams WHERE id = $examId");
    if ($r = $res->fetch_assoc()) return (float)$r['cost'];
    return 0.00;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'get_patient_data') {
            $patientId = (int)$_POST['patient_id'];
            
            // 1. Obtener Empresa del paciente (para calcular precios)
            $resPat = $db->query("SELECT empresa_id FROM pacientes_pre_registrados WHERE id = $patientId");
            $pat = $resPat->fetch_assoc();
            $companyId = $pat ? $pat['empresa_id'] : null;

            // 2. Obtener exámenes disponibles (Activos)
            // V2: Join with override prices to show correct price in UI directly
            $sqlExams = "SELECT e.id, e.name, 
                                COALESCE(cep.price, e.cost) as final_price,
                                CASE WHEN cep.price IS NOT NULL THEN 1 ELSE 0 END as is_override
                         FROM exams e 
                         LEFT JOIN company_exam_prices cep ON e.id = cep.exam_id AND cep.company_id = ?
                         WHERE e.is_active = 1 
                         AND e.id NOT IN (SELECT exam_id FROM patient_exams WHERE patient_id = ?)
                         ORDER BY e.name ASC";
            
            $stmt = $db->prepare($sqlExams);
            $stmt->bind_param("si", $companyId, $patientId);
            $stmt->execute();
            $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // 3. Obtener Protocolos disponibles para esa empresa (o globales)
            $sqlProtos = "SELECT id, name, description FROM protocols 
                          WHERE company_id IS NULL OR company_id = ? 
                          ORDER BY name ASC";
            $stmtP = $db->prepare($sqlProtos);
            $stmtP->bind_param("s", $companyId);
            $stmtP->execute();
            $protocols = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

            // 4. Ya asignados
            $assigned = getAssignedExams($db, $patientId);
            
            echo json_encode(['status' => 'success', 
                'exams' => $exams, 
                'protocols' => $protocols,
                'assigned' => $assigned,
                'company_id' => $companyId
            ]);

        } elseif ($action === 'get_protocol_items') {
             // Retorna IDs de examenes de un protocolo
             $protoId = (int)$_POST['protocol_id'];
             $res = $db->query("SELECT exam_id FROM protocol_items WHERE protocol_id = $protoId");
             $ids = [];
             while($r = $res->fetch_assoc()) $ids[] = $r['exam_id'];
             echo json_encode(['status' => 'success', 'exam_ids' => $ids]);

        } elseif ($action === 'assign_cart') {
            // V2: Recibe un JSON 'cart' con objetos: { exam_id, price, note }
            // Esto permite "cobros extras o descuentos" (manual price)
            
            if (!isDesarrollador()) throw new Exception("Solo los desarrolladores pueden asignar exámenes.");

            $patientId = (int)$_POST['patient_id'];
            $cart = json_decode($_POST['cart'], true);
            
            if (empty($cart)) throw new Exception("Carrito vacío");

            $userId = getUserId();
            $stmt = $db->prepare("INSERT INTO patient_exams (patient_id, exam_id, price_snapshot, note, assigned_by, service_type) VALUES (?, ?, ?, ?, ?, ?)");
            
            $count = 0;
            // V2: service_type comes in POST separately or inside cart?
            // Decision: Let's assume it's global for the cart or per item? 
            // Better global for the assignment batch.
            $serviceType = sanitizeInput($_POST['service_type'] ?? 'Otro');

            foreach ($cart as $item) {
                // $item fields: exam_id, price (final), note
                $eId = (int)$item['exam_id'];
                $price = (float)$item['price'];
                $note = sanitizeInput($item['note'] ?? '');
                
                $stmt->bind_param("iidsis", $patientId, $eId, $price, $note, $userId, $serviceType);
                if($stmt->execute()) $count++;
            }

            echo json_encode(['status' => 'success', 'message' => "$count exámenes asignados."]);

        } elseif ($action === 'remove_exam') {
             if (!isDesarrollador()) throw new Exception("No autorizado.");
             $assignmentId = (int)$_POST['assignment_id'];
             // Security check ownership? Not needed for dev currently
             $stmt = $db->prepare("DELETE FROM patient_exams WHERE id = ?");
             $stmt->bind_param("i", $assignmentId);
             $stmt->execute();
             echo json_encode(['status' => 'success']);
        } 
        else {
            throw new Exception("Acción no válida");
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}
