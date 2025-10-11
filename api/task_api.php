<?php
session_start();
require '../db_connection.php';

// 1. Verificar Autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

switch ($method) {
    case 'POST':
        // A. CERRAR/COMPLETAR TAREA
        $data = json_decode(file_get_contents('php://input'), true);
        $task_id = $data['task_id'] ?? null;
        $alert_id = $data['alert_id'] ?? null;
        $action = $data['action'] ?? null; // 'complete' o 'cancel'

        if (!$task_id || !$alert_id || !$action) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos (task_id, alert_id, action).']);
            exit;
        }

        if ($action === 'complete') {
            // Marcar tarea como completada
            $stmt_task = $conn->prepare("UPDATE tasks SET completed_at = NOW(), status = 'Completada' WHERE id = ?");
            $stmt_task->bind_param("i", $task_id);
            $stmt_task->execute();
            $stmt_task->close();

            // Marcar alerta como resuelta
            $stmt_alert = $conn->prepare("UPDATE alerts SET status = 'Resuelta' WHERE id = ?");
            $stmt_alert->bind_param("i", $alert_id);
            $stmt_alert->execute();
            $stmt_alert->close();

            echo json_encode(['success' => true, 'message' => 'Tarea y Alerta completadas.']);
            
        } elseif ($action === 'cancel') {
            // Marcar tarea como cancelada
            $stmt_task = $conn->prepare("UPDATE tasks SET status = 'Cancelada' WHERE id = ?");
            $stmt_task->bind_param("i", $task_id);
            $stmt_task->execute();
            $stmt_task->close();
            
            // Revertir alerta a estado 'Activa' para que pueda ser reasignada
            $stmt_alert = $conn->prepare("UPDATE alerts SET status = 'Activa' WHERE id = ?");
            $stmt_alert->bind_param("i", $alert_id);
            $stmt_alert->execute();
            $stmt_alert->close();

            echo json_encode(['success' => true, 'message' => 'Tarea cancelada y Alerta reactivada.']);

        } else {
             http_response_code(400);
             echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
        }
        
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
        break;
}

$conn->close();
?>