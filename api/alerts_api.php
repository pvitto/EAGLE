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

            $stmt_task = null;
            if ($type === 'Manual') {
                if (!$title) throw new Exception("El título es requerido para tareas manuales grupales.");
                // MODIFICADO: Se añade 'assigned_to_group'
                $stmt_task = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, assigned_to_group, type, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?, 'Manual', ?, ?)");
            } elseif ($type === 'Asignacion' && $alert_id) {
                 // MODIFICADO: Se añade 'assigned_to_group'
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, assigned_to_group, instruction, type) VALUES (?, ?, ?, ?, 'Asignacion')");
            } else {
                throw new Exception("Parámetros no válidos para asignación grupal.");
            }

            foreach ($userIds as $uid) {
                if ($type === 'Manual') {
                    // MODIFICADO: Se bindea el nuevo parámetro 'assigned_to_group'
                    $stmt_task->bind_param("sssisss", $title, $instruction, $priority, $uid, $assign_to_group, $start_datetime, $end_datetime);
                } elseif ($type === 'Asignacion') {
                    // MODIFICADO: Se bindea el nuevo parámetro 'assigned_to_group'
                    $stmt_task->bind_param("iisss", $alert_id, $uid, $assign_to_group, $instruction);
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
    
    // ===== LÓGICA PARA ASIGNACIÓN INDIVIDUAL Y RECORDATORIOS =====
    $stmt = null; 

    if ($type === 'Recordatorio') {
        //... (código sin cambios)
    } elseif ($type === 'Asignacion') {
        if ($task_id) {
            // MODIFICADO: Limpiar el 'assigned_to_group' al re-asignar individualmente
            $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ?, assigned_to_group = NULL WHERE id = ?");
            $stmt->bind_param("isi", $user_id, $instruction, $task_id);
        } elseif ($alert_id) { 
            $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type) VALUES (?, ?, ?, 'Asignacion')");
            $stmt->bind_param("iis", $alert_id, $user_id, $instruction);
        }
    } elseif ($type === 'Manual') {
        if ($title) { 
             $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, start_datetime, end_datetime) VALUES (?, ?, ?, ?, 'Manual', ?, ?)");
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
        echo json_encode(['success' => false, 'error' => 'No se pudo preparar la consulta.']);
    }

} elseif ($method === 'DELETE') {
    //... (código sin cambios)
}

$conn->close();
?>
