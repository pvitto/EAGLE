<?php
session_start();
require '../db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $task_id = $data['task_id'] ?? null;
    $completing_user_id = $_SESSION['user_id'];

    if (!$task_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Falta el ID de la tarea.']);
        exit;
    }

    $conn->begin_transaction();

    try {
        // 1. Marcar la tarea específica del usuario como completada
        $stmt_task = $conn->prepare("UPDATE tasks SET completed_at = NOW(), status = 'Completada', completed_by_user_id = ? WHERE id = ?");
        $stmt_task->bind_param("ii", $completing_user_id, $task_id);
        $stmt_task->execute();
        $stmt_task->close();

        // 2. Obtener la información de la tarea (alert_id, grupo, título)
        $stmt_find_info = $conn->prepare("SELECT alert_id, assigned_to_group, title, created_at FROM tasks WHERE id = ?");
        $stmt_find_info->bind_param("i", $task_id);
        $stmt_find_info->execute();
        $task_data = $stmt_find_info->get_result()->fetch_assoc();
        $stmt_find_info->close();

        $alert_id = $task_data['alert_id'] ?? null;
        $assigned_to_group = $task_data['assigned_to_group'] ?? null;
        $title = $task_data['title'] ?? null;
        $created_at = $task_data['created_at'] ?? null;

        // 3. Lógica de cierre de grupo
        if ($alert_id) {
            // Es una alerta de discrepancia
            // 3.a. Marcar la alerta principal como 'Resuelta'
            $stmt_alert = $conn->prepare("UPDATE alerts SET status = 'Resuelta' WHERE id = ?");
            $stmt_alert->bind_param("i", $alert_id);
            $stmt_alert->execute();
            $stmt_alert->close();

            // 3.b. Cancelar las otras tareas duplicadas para esta alerta
            $stmt_cancel = $conn->prepare("UPDATE tasks SET status = 'Cancelada' WHERE alert_id = ? AND id != ?");
            $stmt_cancel->bind_param("ii", $alert_id, $task_id);
            $stmt_cancel->execute();
            $stmt_cancel->close();

        } elseif ($assigned_to_group && $title && $created_at) {
            // Es una tarea manual de grupo
            // 3.c. Cancelar las otras tareas duplicadas para este grupo manual
            // Usamos title, created_at y group para identificar el "lote" de tareas
            $stmt_cancel = $conn->prepare("UPDATE tasks SET status = 'Cancelada' WHERE title = ? AND assigned_to_group = ? AND created_at = ? AND id != ?");
            $stmt_cancel->bind_param("sssi", $title, $assigned_to_group, $created_at, $task_id);
            $stmt_cancel->execute();
            $stmt_cancel->close();
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Tarea completada con éxito.']);

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $exception->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
}

$conn->close();
?>