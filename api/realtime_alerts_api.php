<?php
require '../config.php';
require '../db_connection.php'; // Ajusta la ruta si es necesario
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$since_timestamp = isset($_GET['since']) ? intval($_GET['since']) : (time() - 30); // Revisa últimos 30 seg por defecto

$new_alerts = [];
$user_filter = '';
 if ($current_user_role !== 'Admin') {
     // Filtro para alertas de alta prioridad asignadas al usuario o su grupo
     $user_filter = $conn->real_escape_string(" AND (t.assigned_to_user_id = {$current_user_id} OR (t.assigned_to_group = '{$current_user_role}' AND t.assigned_to_user_id IS NULL))");
 }

// Consulta para buscar alertas/tareas prioritarias NUEVAS (creadas después de 'since')
// Simplificado: Busca cualquier alerta/tarea prioritaria creada recientemente
// Podrías hacerlo más complejo guardando qué alertas ya vio el usuario
$sql = "
    SELECT
        a.id as alert_id, a.title, a.description, a.priority, a.created_at,
        t.id as task_id, t.title as task_title, t.instruction, t.priority as task_priority, t.created_at as task_created_at,
        t.assigned_to_group, t.assigned_to_user_id
    FROM tasks t
    LEFT JOIN alerts a ON t.alert_id = a.id
    WHERE
      t.status = 'Pendiente' AND -- Solo pendientes
      (t.priority IN ('Critica', 'Alta') OR a.priority IN ('Critica', 'Alta')) AND -- Solo prioritarias
      UNIX_TIMESTAMP(t.created_at) > ? -- Creadas después de la última revisión
      {$user_filter} -- Filtro de usuario/grupo
    ORDER BY t.created_at DESC
    LIMIT 5 -- Limitar resultados por si acaso
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $since_timestamp);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Determinar qué datos usar (de alerta o de tarea manual)
        $is_manual = empty($row['alert_id']);
         $priority = $is_manual ? $row['task_priority'] : $row['priority'];
         // Solo notificar pop-up para Crítica o Alta
         if ($priority === 'Critica' || $priority === 'Alta') {
              $new_alerts[] = [
                   'id' => $is_manual ? $row['task_id'] : $row['alert_id'],
                   'title' => $is_manual ? $row['task_title'] : $row['title'],
                   'description' => $is_manual ? $row['instruction'] : $row['description'],
                   'priority' => $priority,
                   'created_at' => $is_manual ? $row['task_created_at'] : $row['created_at'],
                   'type' => $is_manual ? 'manual_task' : 'alert'
              ];
         }
    }
    $stmt->close();
} else {
     error_log("Error preparando consulta en realtime_alerts_api: " . $conn->error);
     // No enviar error al cliente necesariamente, solo loguear
}


$conn->close();

echo json_encode([
    'success' => true,
    'alerts' => $new_alerts,
    'timestamp' => time() // Devuelve el timestamp actual para la próxima consulta
]);
?>