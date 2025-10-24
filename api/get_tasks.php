<?php
require '../config.php';
require '../check_session.php';
require '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];

try {
    // --- LÓGICA DE FILTRADO (copiada y adaptada de index.php) ---
    $task_filter = '';
    if ($current_user_role !== 'Admin') {
        $escaped_role = $conn->real_escape_string($current_user_role);
        $task_filter = " AND (t.assigned_to_user_id = {$current_user_id} OR (t.assigned_to_group = '{$escaped_role}' AND t.assigned_to_user_id IS NULL))";
    }

    $alert_filter = $task_filter;
    if ($current_user_role === 'Digitador') {
        $escaped_role = $conn->real_escape_string($current_user_role);
        $alert_filter = " AND ((t.assigned_to_user_id = {$current_user_id} OR (t.assigned_to_group = '{$escaped_role}' AND t.assigned_to_user_id IS NULL)) OR a.suggested_role = '{$escaped_role}')";
    }

    $all_pending_items = [];

    // --- Cargar Alertas Pendientes ---
    $alerts_sql = "
        SELECT a.*, MIN(t.id) AS task_id, MIN(t.status) AS task_status, COALESCE(MAX(t.assigned_to_group), a.suggested_role) AS assigned_to_group, MAX(CASE WHEN t.assigned_to_user_id = {$current_user_id} THEN t.id ELSE NULL END) as user_task_id, GROUP_CONCAT(DISTINCT CASE WHEN t.assigned_to_group IS NULL THEN u_assigned.name ELSE NULL END SEPARATOR ', ') as assigned_names, MIN(t.type) AS task_type, MIN(t.instruction) as instruction, MIN(t.start_datetime) as start_datetime, MIN(t.end_datetime) as end_datetime, ci.invoice_number
        FROM alerts a
        LEFT JOIN tasks t ON t.alert_id = a.id AND t.status = 'Pendiente'
        LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
        LEFT JOIN check_ins ci ON a.check_in_id = ci.id
        WHERE a.status IN ('Pendiente', 'Asignada') {$alert_filter}
        GROUP BY a.id
        ORDER BY FIELD(a.priority, 'Critica', 'Alta', 'Media', 'Baja'), a.created_at DESC
    ";
    $alerts_result = $conn->query($alerts_sql);
    if ($alerts_result) {
        while ($row = $alerts_result->fetch_assoc()) {
            $row['item_type'] = 'alert';
            $all_pending_items[] = $row;
        }
    } else { throw new Exception("Error loading alerts: " . $conn->error); }

    // --- Cargar Tareas Manuales Pendientes ---
    $manual_tasks_sql = "
        SELECT t.id, MIN(t.id) as task_id, t.title, t.instruction, t.priority, MIN(t.status) as task_status, t.assigned_to_user_id, t.assigned_to_group, GROUP_CONCAT(DISTINCT CASE WHEN t.assigned_to_group IS NULL THEN u.name ELSE NULL END SEPARATOR ', ') as assigned_names, t.start_datetime, t.end_datetime, MAX(CASE WHEN t.assigned_to_user_id = {$current_user_id} THEN t.id ELSE NULL END) as user_task_id
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to_user_id = u.id
        WHERE t.alert_id IS NULL AND t.type = 'Manual' AND t.status = 'Pendiente' {$task_filter}
        GROUP BY IF(t.assigned_to_group IS NOT NULL, CONCAT(t.title, t.assigned_to_group, t.created_at), t.id)
        ORDER BY FIELD(t.priority, 'Critica', 'Alta', 'Media', 'Baja'), t.created_at DESC
    ";
    $manual_tasks_result = $conn->query($manual_tasks_sql);
    if ($manual_tasks_result) {
        while($row = $manual_tasks_result->fetch_assoc()) {
            $row['item_type'] = 'manual_task';
            $all_pending_items[] = $row;
        }
    } else { throw new Exception("Error loading manual tasks: " . $conn->error); }

    // --- Procesar y Ordenar Items (lógica de prioridad dinámica) ---
    $now = new DateTime();
    foreach ($all_pending_items as &$item) { // Pasar por referencia para modificar
        $original_priority = $item['priority'] ?? 'Media';
        $current_priority = $original_priority;
        if (!empty($item['end_datetime'])) {
            try {
               $end_time = new DateTime($item['end_datetime']);
               $diff_minutes = ($now->getTimestamp() - $end_time->getTimestamp()) / 60;
                if ($diff_minutes >= 0) { $current_priority = 'Alta'; }
                elseif ($diff_minutes > -15 && ($original_priority === 'Baja' || $original_priority === 'Media')) { $current_priority = 'Media'; }
            } catch (Exception $e) { /* Ignorar error de fecha inválida */ }
        }
        $item['current_priority'] = $current_priority;

        if (empty($item['user_task_id']) && !empty($item['assigned_to_group']) && $item['assigned_to_group'] == $current_user_role) {
             $item['user_task_id'] = $item['task_id'] ?? $item['id'];
        }
    }
    unset($item); // Romper la referencia

    // Devolver la lista completa de tareas procesadas
    echo json_encode(['success' => true, 'tasks' => $all_pending_items]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Error in get_tasks_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor al obtener tareas.']);
}

$conn->close();
?>
