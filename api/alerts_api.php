<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$creator_id = $_SESSION['user_id']; // <-- Quién crea la tarea

if ($method === 'DELETE') {
    if (isset($_GET['reminder_id'])) {
        $reminder_id = intval($_GET['reminder_id']);
        $stmt = $conn->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $reminder_id, $creator_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Recordatorio no encontrado o no autorizado para eliminar.']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $stmt->error]);
        }
        $stmt->close();
        $conn->close();
        exit; 
    }
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $user_id = $data['assign_to'] ?? null;
    $assign_to_group = $data['assign_to_group'] ?? null;
    $instruction = $data['instruction'] ?? '';
    $type = $data['type'] ?? '';
    $task_id = $data['task_id'] ?? null;
    $alert_id = $data['alert_id'] ?? null;
    $title = $data['title'] ?? null;
    $priority = $data['priority'] ?? 'Media';
    $start_datetime = $data['start_datetime'] ?? null;
    $end_datetime = $data['end_datetime'] ?? null;

    if ($type === 'Recordatorio') {
        if (!$user_id) {
             echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario para el recordatorio.']);
             exit;
        }
        $taskTitleQuery = $conn->query("SELECT title FROM tasks WHERE id = " . intval($task_id));
        $taskTitle = $taskTitleQuery ? $taskTitleQuery->fetch_assoc()['title'] : "ID " . ($alert_id ?? $task_id);
        
        $message = "Recordatorio sobre la tarea: '" . $taskTitle . "'";
        $stmt = $conn->prepare("INSERT INTO reminders (user_id, message, alert_id, task_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isii", $user_id, $message, $alert_id, $task_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Recordatorio creado.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al crear el recordatorio: ' . $stmt->error]);
        }
        $stmt->close();
        $conn->close();
        exit;
    }

    if ($type !== 'Recordatorio' && !$user_id && !$assign_to_group) {
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario o un grupo.']);
        exit;
    }

    // --- Logic for Group Assignments ---
    if ($assign_to_group) {
        $conn->begin_transaction();
        try {
            $userIds = [];
            $stmt_users = ($assign_to_group === 'todos')
                ? $conn->prepare("SELECT id FROM users")
                : $conn->prepare("SELECT id FROM users WHERE role = ?");

            if ($assign_to_group !== 'todos') {
                $stmt_users->bind_param("s", $assign_to_group);
            }
            $stmt_users->execute();
            $result_users = $stmt_users->get_result();
            while ($row = $result_users->fetch_assoc()) {
                $userIds[] = $row['id'];
            }
            $stmt_users->close();

            if (empty($userIds)) {
                throw new Exception("No se encontraron usuarios para el grupo seleccionado.");
            }

            $stmt_task = null;
            if ($type === 'Manual') {
                if (!$title) throw new Exception("El título es requerido para tareas manuales grupales.");
                $stmt_task = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, assigned_to_group, type, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, ?, 'Manual', ?, ?, ?)");
            } elseif ($type === 'Asignacion' && $alert_id) {
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, assigned_to_group, instruction, type, created_by_user_id) VALUES (?, ?, ?, ?, 'Asignacion', ?)");
            } else {
                throw new Exception("Parámetros no válidos para asignación grupal.");
            }

            foreach ($userIds as $uid) {
                if ($type === 'Manual') {
                    $stmt_task->bind_param("sssisssi", $title, $instruction, $priority, $uid, $assign_to_group, $start_datetime, $end_datetime, $creator_id);
                } elseif ($type === 'Asignacion') {
                    $stmt_task->bind_param("iissi", $alert_id, $uid, $assign_to_group, $instruction, $creator_id);
                }
                $stmt_task->execute();
            }
            $stmt_task->close();

            if ($type === 'Asignacion' && $alert_id) {
                $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = " . intval($alert_id));
            }
            
            $conn->commit();
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Error en la asignación grupal: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // --- Logic for Individual Assignments ---
    $stmt = null; 

    if ($type === 'Asignacion') {
        if ($task_id) {
            $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ?, assigned_to_group = NULL WHERE id = ?");
            $stmt->bind_param("isi", $user_id, $instruction, $task_id);
        } elseif ($alert_id) { 
            $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type, created_by_user_id) VALUES (?, ?, ?, 'Asignacion', ?)");
            $stmt->bind_param("iisi", $alert_id, $user_id, $instruction, $creator_id);
        }
    } elseif ($type === 'Manual') {
        if ($title) { 
             $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?)");
             $stmt->bind_param("sssissi", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime, $creator_id);
        }
    }

    if ($stmt) {
        if ($stmt->execute()) {
            if ($type === 'Asignacion' && $alert_id) {
                $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = " . intval($alert_id));
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al ejecutar la consulta: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo preparar la consulta.']);
    }

}

$conn->close();
?>