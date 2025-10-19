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
$user_id = $_SESSION['user_id']; // ID del Operador (quien crea la tarea)

if ($method === 'GET') {
    $planilla = $_GET['planilla'] ?? null;
    if (!$planilla) {
        echo json_encode(['success' => false, 'error' => 'No se proporcionó número de planilla.']);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, c.name as client_name
        FROM check_ins ci
        JOIN clients c ON ci.client_id = c.id
        WHERE ci.invoice_number = ? AND ci.status = 'Pendiente'
    ");
    $stmt->bind_param("s", $planilla);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Planilla no encontrada o ya fue procesada.']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conn->begin_transaction();
    try {
        $stmt_insert = $conn->prepare(
            "INSERT INTO operator_counts (check_in_id, operator_id, bills_100k, bills_50k, bills_20k, bills_10k, bills_5k, bills_2k, coins, total_counted, discrepancy, observations) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt_insert->bind_param("iiiiiiidddis", 
            $data['check_in_id'], $user_id, $data['bills_100k'], $data['bills_50k'], $data['bills_20k'],
            $data['bills_10k'], $data['bills_5k'], $data['bills_2k'], $data['coins'], $data['total_counted'],
            $data['discrepancy'], $data['observations']
        );
        $stmt_insert->execute();
        $stmt_insert->close();

        $new_status = ($data['discrepancy'] == 0) ? 'Procesado' : 'Discrepancia';
        $stmt_update = $conn->prepare("UPDATE check_ins SET status = ? WHERE id = ?");
        $stmt_update->bind_param("si", $new_status, $data['check_in_id']);
        $stmt_update->execute();
        $stmt_update->close();

        if ($data['discrepancy'] != 0) {
            $check_in_id = $data['check_in_id'];
            $res = $conn->query("SELECT invoice_number FROM check_ins WHERE id = $check_in_id");
            $invoice_number = $res->fetch_assoc()['invoice_number'];
            $discrepancy_formatted = number_format($data['discrepancy'], 0, ',', '.');
            
            $alert_title = "Discrepancia en Planilla: " . $invoice_number;
            $alert_desc = "Diferencia de $" . $discrepancy_formatted . ". Requiere revisión y seguimiento.";
            
            $stmt_alert = $conn->prepare("INSERT INTO alerts (title, description, priority, status, suggested_role, check_in_id) VALUES (?, ?, 'Critica', 'Pendiente', 'Digitador', ?)");
            $stmt_alert->bind_param("ssi", $alert_title, $alert_desc, $check_in_id);
            $stmt_alert->execute();
            $alert_id = $stmt_alert->insert_id;
            $stmt_alert->close();

            // --- CAMBIO AQUÍ: Asignar Tareas a Grupo 'Digitador' ---
            $digitadores_res = $conn->query("SELECT id FROM users WHERE role = 'Digitador'");
            if ($digitadores_res->num_rows > 0 && $alert_id) {
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, assigned_to_group, instruction, type, status, created_by_user_id) VALUES (?, ?, ?, ?, 'Asignacion', 'Pendiente', ?)");
                $instruction = "Realizar seguimiento a la discrepancia, contactar a los responsables y documentar la resolución.";
                $digitador_group_name = 'Digitador';
                
                while($row = $digitadores_res->fetch_assoc()) {
                    $digitador_id = $row['id'];
                    // Asigna al usuario Y al grupo, y registra QUIÉN (el operador) la creó
                    $stmt_task->bind_param("iissi", $alert_id, $digitador_id, $digitador_group_name, $instruction, $user_id);
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