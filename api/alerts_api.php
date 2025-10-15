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
    $instruction = $data['instruction'] ?? '';
    $type = $data['type'] ?? '';
    $task_id = $data['task_id'] ?? null;
    $alert_id = $data['alert_id'] ?? null;
    $title = $data['title'] ?? null;
    $priority = $data['priority'] ?? 'Media';
    $start_datetime = $data['start_datetime'] ?? null;
    $end_datetime = $data['end_datetime'] ?? null;

    if ($type !== 'Recordatorio' && !$user_id) {
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario.']);
        exit;
    }
     if ($type === 'Recordatorio' && !$user_id) {
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario para el recordatorio.']);
        exit;
    }

    $stmt = null; // Inicializar stmt

    if ($type === 'Recordatorio') {
        $message = '';
        if ($alert_id) {
            $stmt_msg = $conn->prepare("SELECT title FROM alerts WHERE id = ?");
            $stmt_msg->bind_param("i", $alert_id);
            $stmt_msg->execute();
            $result = $stmt_msg->get_result();
            if ($row = $result->fetch_assoc()) {
                $message = "Recordatorio sobre la alerta: '" . $row['title'] . "'";
            }
        } elseif ($task_id) {
            $stmt_msg = $conn->prepare("SELECT title FROM tasks WHERE id = ?");
            $stmt_msg->bind_param("i", $task_id);
            $stmt_msg->execute();
            $result = $stmt_msg->get_result();
            if ($row = $result->fetch_assoc()) {
                $message = "Recordatorio sobre la tarea: '" . $row['title'] . "'";
            }
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
             // CORRECCIÓN APLICADA AQUÍ: Los tipos de datos y la cantidad de parámetros eran incorrectos.
             $stmt->bind_param("sssiss", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime);
        }
    }

    // Ejecutar la consulta si $stmt se preparó correctamente
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
        // Si $stmt es null, significa que no se cumplió ninguna condición para prepararlo
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

