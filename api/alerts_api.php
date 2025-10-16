<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

// Para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    // Parámetros para asignación individual y grupal
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

    if ($type !== 'Recordatorio' && !$user_id && !$assign_to_group) {
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario o un grupo.']);
        exit;
    }
    if ($type === 'Recordatorio' && !$user_id) {
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario para el recordatorio.']);
        exit;
    }
    
    // ===== LÓGICA PARA ASIGNACIÓN GRUPAL =====
    if ($assign_to_group) {
        $conn->begin_transaction();
        try {
            // 1. Obtener los IDs de usuario para el grupo seleccionado
            $userIds = [];
            if ($assign_to_group === 'todos') {
                $stmt_users = $conn->prepare("SELECT id FROM users");
            } else {
                $stmt_users = $conn->prepare("SELECT id FROM users WHERE role = ?");
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

            // 2. Preparar la consulta para insertar las tareas
            $stmt_task = null;
            if ($type === 'Manual') {
                if (!$title) throw new Exception("El título es requerido para tareas manuales grupales.");
                $stmt_task = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, alert_id, start_datetime, end_datetime) VALUES (?, ?, ?, ?, 'Manual', NULL, ?, ?)");
            } elseif ($type === 'Asignacion' && $alert_id) {
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type) VALUES (?, ?, ?, 'Asignacion')");
            } else {
                throw new Exception("Parámetros no válidos para asignación grupal.");
            }

            // 3. Iterar y crear una tarea para cada usuario del grupo
            foreach ($userIds as $uid) {
                if ($type === 'Manual') {
                    $stmt_task->bind_param("sssiss", $title, $instruction, $priority, $uid, $start_datetime, $end_datetime);
                } elseif ($type === 'Asignacion') {
                    $stmt_task->bind_param("iis", $alert_id, $uid, $instruction);
                }
                $stmt_task->execute();
            }
            $stmt_task->close();

            // 4. Actualizar el estado de la alerta si la asignación vino de una
            if ($type === 'Asignacion' && $alert_id) {
                $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = " . intval($alert_id));
            }
            
            // 5. Si todo fue exitoso, confirmar la transacción
            $conn->commit();
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Error en la asignación grupal: ' . $e->getMessage()]);
        }
        exit; // Termina el script después de manejar la asignación grupal
    }
    
    // ===== LÓGICA PARA ASIGNACIÓN INDIVIDUAL Y RECORDATORIOS (CÓDIGO EXISTENTE) =====
    $stmt = null; 

    if ($type === 'Recordatorio') {
        $message = '';
        if ($alert_id) {
            $stmt_msg = $conn->prepare("SELECT title FROM alerts WHERE id = ?");
            $stmt_msg->bind_param("i", $alert_id);
            $stmt_msg->execute();
            $result = $stmt_msg->get_result();
            if ($row = $result->fetch_assoc()) { $message = "Recordatorio sobre la alerta: '" . $row['title'] . "'"; }
        } elseif ($task_id) {
            $stmt_msg = $conn->prepare("SELECT title FROM tasks WHERE id = ?");
            $stmt_msg->bind_param("i", $task_id);
            $stmt_msg->execute();
            $result = $stmt_msg->get_result();
            if ($row = $result->fetch_assoc()) { $message = "Recordatorio sobre la tarea: '" . $row['title'] . "'"; }
        }
        
        if (!empty($message)) {
            $stmt = $conn->prepare("INSERT INTO reminders (user_id, message) VALUES (?, ?)");
            $stmt->bind_param("is", $user_id, $message);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se encontró el item para el recordatorio.']);
            exit;
        }

    } elseif ($type === 'Asignacion') {
        if ($task_id) { // Re-asignar tarea existente
            $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ? WHERE id = ?");
            $stmt->bind_param("isi", $user_id, $instruction, $task_id);
        } elseif ($alert_id) { // Crear nueva tarea desde una alerta
            $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type) VALUES (?, ?, ?, 'Asignacion')");
            $stmt->bind_param("iis", $alert_id, $user_id, $instruction);
        }
    } elseif ($type === 'Manual') {
        if ($title) { // Crear Tarea Manual
             $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, alert_id, start_datetime, end_datetime) VALUES (?, ?, ?, ?, 'Manual', NULL, ?, ?)");
             $stmt->bind_param("sssiss", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime);
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
        echo json_encode(['success' => false, 'error' => 'No se pudo preparar la consulta. Verifique los parámetros.']);
    }

} elseif ($method === 'DELETE') {
    $reminder_id = $_GET['reminder_id'] ?? null;
    $current_user_id = $_SESSION['user_id'];

    if ($reminder_id) {
        $stmt = $conn->prepare("UPDATE reminders SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $reminder_id, $current_user_id);
        if ($stmt->execute()) {
             echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se pudo marcar como leído.']);
        }
        $stmt->close();
    }
}

$conn->close();
?>