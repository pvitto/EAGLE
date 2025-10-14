<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

// Para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    $user_id = $data['assign_to'] ?? null;
    $instruction = $data['instruction'] ?? '';
    $type = $data['type'] ?? '';
    $task_id = $data['task_id'] ?? null;
    $alert_id = $data['alert_id'] ?? null;
    $title = $data['title'] ?? null;
    $priority = $data['priority'] ?? 'Media';

    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario.']);
        exit;
    }

    // LÓGICA PARA CREAR RECORDATORIOS
    if ($type === 'Recordatorio') {
        $message = '';
        if ($alert_id) {
            $stmt = $conn->prepare("SELECT title FROM alerts WHERE id = ?");
            $stmt->bind_param("i", $alert_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $message = "Recordatorio sobre la alerta: '" . $row['title'] . "'";
            }
        } elseif ($task_id) {
            $stmt = $conn->prepare("SELECT title FROM tasks WHERE id = ?");
            $stmt->bind_param("i", $task_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $message = "Recordatorio sobre la tarea: '" . $row['title'] . "'";
            }
        }
        
        if (!empty($message)) {
            $stmt = $conn->prepare("INSERT INTO reminders (user_id, message) VALUES (?, ?)");
            $stmt->bind_param("is", $user_id, $message);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al crear recordatorio: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'error' => 'No se encontró el item para el recordatorio.']);
        }
    } 
    // LÓGICA PARA ASIGNAR/REASIGNAR/CREAR TAREAS
    elseif ($type === 'Asignacion' || $type === 'Manual') {
        if ($task_id) { // Re-asignar tarea existente
            $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ? WHERE id = ?");
            if(!$stmt) { exit(json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error])); }
            $stmt->bind_param("isi", $user_id, $instruction, $task_id);
        } else { // Crear nueva tarea
            if ($type === 'Manual' && $title) { // Tarea Manual
                 // CORRECCIÓN FINAL: Se añade alert_id = NULL explícitamente en la consulta
                 $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, alert_id) VALUES (?, ?, ?, ?, 'Manual', NULL)");
                 if(!$stmt) { exit(json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error])); }
                 $stmt->bind_param("sssi", $title, $instruction, $priority, $user_id);
            } elseif ($alert_id) { // Tarea de Alerta
                $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type) VALUES (?, ?, ?, 'Asignacion')");
                if(!$stmt) { exit(json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error])); }
                $stmt->bind_param("iis", $alert_id, $user_id, $instruction);
            }
        }
        
        if (isset($stmt) && $stmt->execute()) {
            if ($type === 'Asignacion' && $alert_id) {
                $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = $alert_id");
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . ($stmt->error ?? $conn->error)]);
        }
        if (isset($stmt)) $stmt->close();

    } else {
        echo json_encode(['success' => false, 'error' => 'Solicitud no reconocida.']);
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

