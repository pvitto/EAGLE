<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Operador'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// --- LÓGICA GET AÑADIDA ---
if ($method === 'GET') {
    $planilla = $_GET['planilla'] ?? null;

    if (!$planilla) {
        echo json_encode(['success' => false, 'error' => 'No se proporcionó número de planilla.']);
        exit;
    }

    // Preparamos la consulta para evitar inyección SQL
    $stmt = $conn->prepare("
        SELECT 
            ci.id, ci.invoice_number, ci.seal_number, ci.declared_value,
            c.name as client_name
        FROM check_ins ci
        JOIN clients c ON ci.client_id = c.id
        WHERE ci.invoice_number = ? AND ci.status = 'Pendiente'
    ");
    $stmt->bind_param("s", $planilla);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Planilla no encontrada o ya fue procesada.']);
    }
    $stmt->close();
    $conn->close();
    exit;
}
// --- FIN LÓGICA GET ---


if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conn->begin_transaction();
    try {
        // Guarda el conteo del operador (sin cambios)
        $stmt_insert = $conn->prepare(
            "INSERT INTO operator_counts (check_in_id, operator_id, bills_100k, bills_50k, bills_20k, bills_10k, bills_5k, bills_2k, coins, total_counted, discrepancy, observations) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt_insert->bind_param("iiiiiiiddids", 
            $data['check_in_id'], $_SESSION['user_id'], $data['bills_100k'], $data['bills_50k'], $data['bills_20k'],
            $data['bills_10k'], $data['bills_5k'], $data['bills_2k'], $data['coins'], $data['total_counted'],
            $data['discrepancy'], $data['observations']
        );
        $stmt_insert->execute();
        $stmt_insert->close();

        // Actualiza el estado del check-in (sin cambios)
        $new_status = ($data['discrepancy'] == 0) ? 'Procesado' : 'Discrepancia';
        $stmt_update = $conn->prepare("UPDATE check_ins SET status = ? WHERE id = ?");
        $stmt_update->bind_param("si", $new_status, $data['check_in_id']);
        $stmt_update->execute();
        $stmt_update->close();

        // LÓGICA PARA CREAR ALERTA AUTOMÁTICA PARA DIGITADOR
        if ($data['discrepancy'] != 0) {
            $check_in_id = $data['check_in_id'];
            $res = $conn->query("SELECT invoice_number FROM check_ins WHERE id = $check_in_id");
            $invoice_number = $res->fetch_assoc()['invoice_number'];
            $discrepancy_formatted = number_format($data['discrepancy'], 0, ',', '.');

            $alert_title = "Discrepancia en Planilla: " . $invoice_number;
            $alert_desc = "Diferencia de $" . $discrepancy_formatted . ". Requiere revisión y seguimiento.";
            
            // Usamos la nueva columna check_in_id
            $stmt_alert = $conn->prepare("INSERT INTO alerts (title, description, priority, status, suggested_role, check_in_id) VALUES (?, ?, 'Critica', 'Pendiente', 'Digitador', ?)");
            $stmt_alert->bind_param("ssi", $alert_title, $alert_desc, $check_in_id);
            $stmt_alert->execute();
            $alert_id = $stmt_alert->insert_id;
            $stmt_alert->close();

            // Asignar la tarea a todos los usuarios con rol 'Digitador'
            $digitadores_res = $conn->query("SELECT id FROM users WHERE role = 'Digitador'");
            if ($digitadores_res->num_rows > 0 && $alert_id) {
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type, status) VALUES (?, ?, ?, 'Asignacion', 'Pendiente')");
                $instruction = "Realizar seguimiento a la discrepancia, contactar a los responsables y documentar la resolución.";
                while($row = $digitadores_res->fetch_assoc()) {
                    $digitador_id = $row['id'];
                    $stmt_task->bind_param("iis", $alert_id, $digitador_id, $instruction);
                    $stmt_task->execute();
                }
                $stmt_task->close();
                $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = $alert_id");
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Conteo guardado exitosamente.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
    }
}

$conn->close();
?>