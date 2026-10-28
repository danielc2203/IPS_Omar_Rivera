<?php
// admin/tv_queue.php
require_once '../config.php';

// Allow public access? Or maybe require simple login?
// For now, let's allow public access or maybe restricted by IP if needed.
// Simplest is to check if logged in as at least something, or just secret link.
// Let's assume it's open for now (or use a kiosk token).

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'poll') {
    $db = getDBConnection();
    
    // 1. Get RECENT CALLS (Last 30 seconds) - To trigger "DING" and Flash
    // We use server time.
    $sqlCall = "SELECT pe.id, pe.called_at,
                       p.nombres, p.apellidos, e.name as exam_name
                FROM patient_exams pe
                JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
                JOIN exams e ON pe.exam_id = e.id
                WHERE pe.called_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                AND pe.status != 'completed' 
                ORDER BY pe.called_at DESC LIMIT 1";
    
    // We need to know who is calling? 
    // We don't have 'called_by' column. But likely the person logged in.
    // We can just say "Consultorio" or just the Name.
    
    $resCall = $db->query($sqlCall);
    $calling = $resCall->fetch_assoc();
    
    // 2. Get WAITING LIST (Pending exams, ordered by arrival)
    // Group by Patient to avoid listing same patient 5 times.
    $sqlWait = "SELECT p.nombres, p.apellidos, MIN(pe.assigned_at) as arrived_at
                FROM patient_exams pe
                JOIN pacientes_pre_registrados p ON pe.patient_id = p.id
                WHERE pe.status = 'pending' AND pe.called_at IS NULL
                GROUP BY p.id
                ORDER BY arrived_at ASC
                LIMIT 10";
    $resWait = $db->query($sqlWait);
    $waiting = $resWait->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['calling' => $calling, 'waiting' => $waiting]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turnos - Sala de Espera</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f2f5; overflow: hidden; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar {
            height: 100vh;
            background: #2c3e50;
            color: white;
            padding: 20px;
        }
        .main-call {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            text-align: center;
        }
        .patient-name-lg {
            font-size: 5rem;
            font-weight: 800;
            color: #d32f2f;
            display: block;
            margin-bottom: 2rem;
        }
        .info-text {
            font-size: 2.5rem;
            color: #555;
        }
        .list-group-item {
            background: transparent;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 1.5rem;
            padding: 15px 0;
        }
        .clock {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding-bottom: 10px;
        }
        
        /* Animation for Calling */
        .blink-bg {
            animation: blinker 1s linear infinite;
        }
        @keyframes blinker {
            50% { opacity: 0; background-color: #ffeb3b; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar Waiting List -->
            <div class="col-4 sidebar">
                <div class="clock" id="clock">00:00</div>
                <h3 class="mb-4 text-center border-bottom pb-2"><i class="bi bi-people"></i> En Espera</h3>
                <div class="list-group list-group-flush" id="waitingList">
                    <!-- JS Injected -->
                </div>
            </div>
            
            <!-- Main Call Area -->
            <div class="col-8 main-call border-start border-5 border-dark" id="mainArea">
                <div>
                    <i class="bi bi-hospital fs-1 text-muted mb-4" style="font-size: 8rem !important; display:block;"></i>
                    <h1 class="text-secondary display-4">Por favor espere su turno</h1>
                </div>
            </div>
        </div>
    </div>

    <!-- Audio Service -->
    <!-- Sound effect hosted or base64? Let's use a simple beep or check if user has assets. 
         Using a short base64 beep for simplicity -->
    <script>
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        function playDing() {
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.type = 'sine';
            oscillator.frequency.value = 660; // hz
            gainNode.gain.value = 0.1;
            oscillator.start();
            setTimeout(() => {
                oscillator.frequency.value = 550;
                setTimeout(() => oscillator.stop(), 300);
            }, 300);
        }

        let lastCalledId = null;

        function updateClock() {
            const now = new Date();
            document.getElementById('clock').innerText = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }
        setInterval(updateClock, 1000);

        function poll() {
            fetch('tv_queue.php?action=poll')
                .then(r => r.json())
                .then(data => {
                    // Update Waiting List
                    const listEl = document.getElementById('waitingList');
                    listEl.innerHTML = '';
                    if(data.waiting.length > 0) {
                        data.waiting.forEach(p => {
                            listEl.innerHTML += `<div class="list-group-item">
                                <i class="bi bi-person-circle me-2"></i> ${p.nombres} ${p.apellidos}
                            </div>`;
                        });
                    } else {
                        listEl.innerHTML = '<div class="text-center text-muted mt-5">Sala vacía</div>';
                    }

                    // Update Calling
                    const main = document.getElementById('mainArea');
                    const c = data.calling;
                    
                    if (c) {
                        // New Call?
                        const thisId = c.id + '_' + c.called_at;
                        if (lastCalledId !== thisId) {
                            lastCalledId = thisId;
                            playDing();
                            // Trigger Animation
                            main.classList.add('blink-bg');
                            setTimeout(() => main.classList.remove('blink-bg'), 3000);
                        }
                        
                        main.innerHTML = `
                            <div class="animate__animated animate__zoomIn">
                                <h2 class="text-uppercase text-muted mb-4">LLAMADO A CONSULTORIO</h2>
                                <span class="patient-name-lg">${c.nombres} ${c.apellidos}</span>
                                <div class="info-text mt-4">
                                    <i class="bi bi-arrow-right-circle-fill text-primary"></i> ${c.exam_name}
                                </div>
                            </div>
                        `;
                    } else {
                        // Reset if no recent call
                        if (lastCalledId !== null) { // Only reset if we were showing something
                             // Keep showing last call? Or return to logo?
                             // Usually better to return to logo after 1 min (logic in SQL handles this via LIMIT 1 MINUTE)
                             main.innerHTML = `
                                <div>
                                    <i class="bi bi-info-circle fs-1 text-muted mb-4" style="font-size: 8rem !important; display:block;"></i>
                                    <h1 class="text-secondary display-4">Atentos al llamado</h1>
                                </div>
                             `;
                             lastCalledId = null;
                        }
                    }
                })
                .catch(e => console.error(e));
        }

        // Poll every 3 seconds
        setInterval(poll, 3000);
        poll();
    </script>
</body>
</html>
