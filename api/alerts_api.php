<?php
require '../config.php';
require '../db_connection.php'; // Asegúrate que esta ruta sea correcta
header('Content-Type: application/json');

// Verificar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// --- ID del usuario que realiza la acción ---
$creator_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// --- Manejo DELETE para Recordatorios ---
if ($method === 'DELETE') {
    if (isset($_GET['reminder_id'])) {
        $reminder_id = filter_input(INPUT_GET, 'reminder_id', FILTER_VALIDATE_INT);
        if (!$reminder_id) {
            http_response_code(400); echo json_encode(['success' => false, 'error' => 'ID de recordatorio inválido.']); exit;
        }
        $stmt = $conn->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
        if (!$stmt) { http_response_code(500); error_log("Error DB preparando delete reminder: " . $conn->error); echo json_encode(['success' => false, 'error' => 'Error interno al preparar la consulta de eliminación.']); exit; }
        $stmt->bind_param("ii", $reminder_id, $creator_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) { echo json_encode(['success' => true, 'message' => 'Recordatorio eliminado.']); }
            else { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Recordatorio no encontrado o no tienes permiso.']); }
        } else { http_response_code(500); error_log("Error DB eliminando recordatorio: " . $stmt->error); echo json_encode(['success' => false, 'error' => 'Error en la base de datos al eliminar.']); }
        $stmt->close();
    } else { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Falta el ID del recordatorio.']); }
    $conn->close();
    exit;
}

// --- Manejo POST para Tareas y Recordatorios ---
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'JSON inválido en la solicitud: ' . json_last_error_msg()]); exit; }

    // Extraer datos de forma segura
    $user_id = isset($data['assign_to']) ? filter_var($data['assign_to'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $assign_to_group = $data['assign_to_group'] ?? null;
    $instruction = isset($data['instruction']) ? trim($data['instruction']) : '';
    $type = isset($data['type']) ? trim($data['type']) : '';
    $task_id = isset($data['task_id']) ? filter_var($data['task_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $alert_id = isset($data['alert_id']) ? filter_var($data['alert_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $title = isset($data['title']) ? trim($data['title']) : null;
    $priority = $data['priority'] ?? 'Media';
    $start_datetime = !empty($data['start_datetime']) ? $data['start_datetime'] : null;
    $end_datetime = !empty($data['end_datetime']) ? $data['end_datetime'] : null;

    // Validaciones
    if ($type !== 'Recordatorio' && $user_id === null && $assign_to_group === null) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario o un grupo.']); exit; }
    if ($type === 'Recordatorio' && $user_id === null) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario para el recordatorio.']); exit; }
    if (!in_array($priority, ['Baja', 'Media', 'Alta', 'Critica'])) { $priority = 'Media'; }

    // ===== LÓGICA PARA ASIGNACIÓN GRUPAL (CORREGIDA) =====
    if ($assign_to_group) {
        $conn->begin_transaction();
        try {
            $is_reassignment = ($type === 'Asignacion' && $task_id);

            // 1. Obtener detalles de la tarea original si es reasignación
            $original_task_details = ['title' => $title, 'instruction' => $instruction, 'priority' => $priority, 'start_datetime' => $start_datetime, 'end_datetime' => $end_datetime];
            if ($is_reassignment) {
                $stmt_find = $conn->prepare("SELECT title, instruction, priority, start_datetime, end_datetime FROM tasks WHERE id = ?");
                if($stmt_find){ $stmt_find->bind_param("i", $task_id); $stmt_find->execute(); $result = $stmt_find->get_result(); if($row = $result->fetch_assoc()) { $original_task_details = $row; } $stmt_find->close(); }
                // Usar la nueva instrucción si se proveyó
                if (!empty($instruction)) { $original_task_details['instruction'] = $instruction; }
            } else { // Creación nueva
                 if ($type === 'Manual' && !$title) throw new Exception("El título es requerido para tareas manuales grupales.");
            }

            // 2. Obtener usuarios del grupo
            $userIds = [];
            $valid_roles = ['Operador', 'Checkinero', 'Digitador', 'Admin'];
            if ($assign_to_group === 'todos') { $stmt_users = $conn->prepare("SELECT id FROM users"); }
            elseif (in_array($assign_to_group, $valid_roles)) { $stmt_users = $conn->prepare("SELECT id FROM users WHERE role = ?"); if($stmt_users) $stmt_users->bind_param("s", $assign_to_group); }
            else { throw new Exception("Grupo inválido: " . htmlspecialchars($assign_to_group)); }
            if (!$stmt_users) { throw new Exception("Error preparando consulta de usuarios: " . $conn->error); }
            $stmt_users->execute(); $result_users = $stmt_users->get_result(); while ($row = $result_users->fetch_assoc()) { $userIds[] = $row['id']; } $stmt_users->close();
            if (empty($userIds)) { throw new Exception("No se encontraron usuarios para el grupo seleccionado."); }

            // 3. Si es reasignación, eliminar la tarea original
            if($is_reassignment) {
                $stmt_delete = $conn->prepare("DELETE FROM tasks WHERE id = ?");
                if($stmt_delete){ $stmt_delete->bind_param("i", $task_id); $stmt_delete->execute(); $stmt_delete->close(); }
            }

            // 4. Crear las nuevas tareas para cada usuario del grupo
            $stmt_insert = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, assigned_to_group, type, alert_id, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt_insert) { throw new Exception("Error al preparar la inserción de tareas: " . $conn->error); }

            foreach ($userIds as $uid) {
                $final_type = $is_reassignment || $type === 'Manual' ? 'Manual' : 'Asignacion';
                $stmt_insert->bind_param("sssisisiis", $original_task_details['title'], $original_task_details['instruction'], $original_task_details['priority'], $uid, $assign_to_group, $final_type, $alert_id, $original_task_details['start_datetime'], $original_task_details['end_datetime'], $creator_id);
                if (!$stmt_insert->execute()) { throw new Exception("Error al insertar tarea para usuario ID $uid: " . $stmt_insert->error); }
            }
            $stmt_insert->close();

            // 5. Marcar alerta como asignada si aplica
            if ($type === 'Asignacion' && $alert_id) { $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = " . intval($alert_id)); }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Tareas asignadas al grupo con éxito.']);
        } catch (Exception $e) {
            $conn->rollback(); http_response_code(500);
            error_log("Error en asignación grupal: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Error en la asignación grupal: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===== LÓGICA PARA ASIGNACIÓN INDIVIDUAL Y RECORDATORIOS =====
    switch ($type) {
        case 'Recordatorio':
            $taskTitle = "ID " . ($alert_id ?: ($task_id ?: 'Desconocido'));
            try {
                if ($task_id) { $stmt_title = $conn->prepare("SELECT COALESCE(a.title, t.title) as display_title FROM tasks t LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.id = ?"); if ($stmt_title) { $stmt_title->bind_param("i", $task_id); if($stmt_title->execute()){ $result_title = $stmt_title->get_result(); if($row = $result_title->fetch_assoc()) $taskTitle = $row['display_title']; } $stmt_title->close(); } }
                elseif ($alert_id) { $stmt_title = $conn->prepare("SELECT title as display_title FROM alerts WHERE id = ?"); if ($stmt_title) { $stmt_title->bind_param("i", $alert_id); if($stmt_title->execute()){ $result_title = $stmt_title->get_result(); if($row = $result_title->fetch_assoc()) $taskTitle = $row['display_title']; } $stmt_title->close(); } }
            } catch (Exception $e) { error_log("Excepción al buscar título para recordatorio: " . $e->getMessage()); }
            $message = "Recordatorio sobre: '" . $conn->real_escape_string($taskTitle) . "'";
            $stmt = $conn->prepare("INSERT INTO reminders (user_id, message, alert_id, task_id, created_by_user_id) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'Error interno.']); break; }
            $stmt->bind_param("isiii", $user_id, $message, $alert_id, $task_id, $creator_id);
            if ($stmt->execute()) { echo json_encode(['success' => true, 'message' => 'Recordatorio creado.']); } else { http_response_code(500); echo json_encode(['success' => false, 'error' => 'Error al ejecutar la consulta.']); }
            $stmt->close();
            break;

        case 'Asignacion':
            // Esta lógica ahora maneja la creación de tareas desde alertas Y la reasignación de CUALQUIER tarea.
            $is_update = !empty($task_id);

            if ($is_update) {
                // --- REASIGNACIÓN ---
                // 1. Obtener la prioridad original para mantenerla.
                $original_priority = 'Media'; // Default
                $stmt_get_prio = $conn->prepare("SELECT priority FROM tasks WHERE id = ?");
                if ($stmt_get_prio) {
                    $stmt_get_prio->bind_param("i", $task_id);
                    if ($stmt_get_prio->execute()) {
                        $result = $stmt_get_prio->get_result();
                        if ($row = $result->fetch_assoc()) { $original_priority = $row['priority']; }
                    }
                    $stmt_get_prio->close();
                }

                // 2. Preparar la actualización. No se toca 'created_at'.
                $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ?, assigned_to_group = NULL, status = 'Pendiente', priority = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("issii", $user_id, $instruction, $original_priority, $task_id);
                }
            } else if ($alert_id) {
                // --- CREACIÓN DESDE ALERTA (sin cambios) ---
                $prio_res = $conn->query("SELECT priority FROM alerts WHERE id = " . intval($alert_id)); $original_priority = $prio_res ? ($prio_res->fetch_assoc()['priority'] ?? 'Media') : 'Media';
                $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type, status, priority, created_by_user_id) VALUES (?, ?, ?, 'Asignacion', 'Pendiente', ?, ?)");
                if ($stmt) $stmt->bind_param("iissis", $alert_id, $user_id, $instruction, ($priority ?: $original_priority), $creator_id);
            }
            if ($stmt && $stmt->execute()) {
                if ($alert_id && !$is_update) { $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = " . intval($alert_id)); }
                echo json_encode(['success' => true, 'message' => $is_update ? 'Tarea reasignada.' : 'Tarea asignada.']);
            } else { http_response_code(500); error_log("Error DB ejecutando asignación: " . ($stmt ? $stmt->error : $conn->error)); echo json_encode(['success' => false, 'error' => 'Error al ejecutar la consulta de asignación.']); }
            if($stmt) $stmt->close();
            break;

        case 'Manual':
            if (!$title) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'El título es requerido para tareas manuales individuales.']); break; }
            $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?)");
            if (!$stmt) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'Error interno.']); break; }
            $stmt->bind_param("sssissi", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime, $creator_id);
            if ($stmt->execute()) { echo json_encode(['success' => true, 'message' => 'Tarea manual creada.']); } else { http_response_code(500); echo json_encode(['success' => false, 'error' => 'Error al ejecutar la consulta.']); }
            $stmt->close();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Tipo de acción no válido.']);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
}
$conn->close();
?>
