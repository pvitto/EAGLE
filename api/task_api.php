<?php
session_start();
// La ruta correcta para acceder a la conexión desde la carpeta /api/
require '../db_connection.php';

// 1. Verificar Autenticación
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
    // Capturamos el ID del usuario que está realizando la acción desde la sesión
    $completing_user_id = $_SESSION['user_id'];

    if (!$task_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Falta el ID de la tarea.']);
        exit;
    }

    // Iniciar transacción para asegurar que todo se complete o nada
    $conn->begin_transaction();

    try {
        // 1. Marcar la tarea como completada, registrar la fecha y QUIÉN la completó
        $stmt_task = $conn->prepare("UPDATE tasks SET completed_at = NOW(), status = 'Completada', completed_by_user_id = ? WHERE id = ?");
        $stmt_task->bind_param("ii", $completing_user_id, $task_id);
        $stmt_task->execute();

        // 2. Averiguar si esta tarea estaba ligada a una alerta
        $stmt_find_alert = $conn->prepare("SELECT alert_id FROM tasks WHERE id = ?");
        $stmt_find_alert->bind_param("i", $task_id);
        $stmt_find_alert->execute();
        $result = $stmt_find_alert->get_result();
        $task_data = $result->fetch_assoc();
        $alert_id = $task_data['alert_id'] ?? null;

        // 3. Si estaba ligada a una alerta, marcar la alerta como resuelta
        if ($alert_id) {
            $stmt_alert = $conn->prepare("UPDATE alerts SET status = 'Resuelta' WHERE id = ?");
            $stmt_alert->bind_param("i", $alert_id);
            $stmt_alert->execute();
        }
        
        // Si todo salió bien, confirmar la transacción
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Tarea completada con éxito.']);

    } catch (mysqli_sql_exception $exception) {
        // Si algo falla, revertir todo para mantener la consistencia de los datos
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

