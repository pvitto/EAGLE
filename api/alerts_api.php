<?php
require '../config.php';
require '../db_connection.php'; // Asegúrate que esta ruta sea correcta
header('Content-Type: application/json');

// Para depuración (puedes comentar esto en producción)
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Verificar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    // Asegúrate de terminar el script después de enviar la respuesta JSON
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
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de recordatorio inválido.']);
            $conn->close(); // Cerrar conexión
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
        // Verificar si la preparación falló
        if (!$stmt) {
             http_response_code(500);
             error_log("Error DB preparando delete reminder: " . $conn->error);
             echo json_encode(['success' => false, 'error' => 'Error interno al preparar la consulta de eliminación.']);
             $conn->close();
             exit;
        }
        $stmt->bind_param("ii", $reminder_id, $creator_id); // Solo el usuario dueño puede eliminar

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Recordatorio eliminado.']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Recordatorio no encontrado o no tienes permiso.']);
            }
        } else {
            http_response_code(500);
            error_log("Error DB eliminando recordatorio: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos al eliminar.']);
        }
        $stmt->close();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Falta el ID del recordatorio.']);
    }
    $conn->close(); // Asegúrate de cerrar la conexión al final del bloque DELETE
    exit;
} // Fin DELETE

// --- Manejo POST para Tareas y Recordatorios ---
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Verificar si el JSON es válido
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido en la solicitud: ' . json_last_error_msg()]);
        $conn->close(); // Cerrar conexión
        exit;
    }

    // Extraer datos de forma segura
    $user_id = isset($data['assign_to']) ? filter_var($data['assign_to'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $assign_to_group = $data['assign_to_group'] ?? null;
    $instruction = isset($data['instruction']) ? trim($data['instruction']) : '';
    $type = $data['type'] ?? '';
    $task_id = isset($data['task_id']) ? filter_var($data['task_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $alert_id = isset($data['alert_id']) ? filter_var($data['alert_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $title = isset($data['title']) ? trim($data['title']) : null;
    $priority = $data['priority'] ?? 'Media';
    $start_datetime = !empty($data['start_datetime']) ? $data['start_datetime'] : null; // Podría necesitar validación de formato
    $end_datetime = !empty($data['end_datetime']) ? $data['end_datetime'] : null; // Podría necesitar validación de formato

    // Validaciones
    if ($type !== 'Recordatorio' && $user_id === null && $assign_to_group === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario o un grupo.']);
        $conn->close();
        exit;
    }
    if ($type === 'Recordatorio' && $user_id === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Es necesario seleccionar un usuario para el recordatorio.']);
        $conn->close();
        exit;
    }
    if (!in_array($priority, ['Baja', 'Media', 'Alta', 'Critica'])) {
        $priority = 'Media'; // Valor por defecto seguro si la prioridad es inválida
    }


    // ===== LÓGICA PARA ASIGNACIÓN GRUPAL =====
    if ($assign_to_group) {
        $conn->begin_transaction();
        try {
            $userIds = [];
            $valid_roles = ['Operador', 'Checkinero', 'Digitador', 'Admin'];
            $stmt_users = null;

            if ($assign_to_group === 'todos') {
                $stmt_users = $conn->prepare("SELECT id, role FROM users");
            } elseif (in_array($assign_to_group, $valid_roles)) {
                $stmt_users = $conn->prepare("SELECT id, role FROM users WHERE role = ?");
                if ($stmt_users) {
                    $stmt_users->bind_param("s", $assign_to_group);
                }
            } else {
                throw new Exception("Grupo inválido: " . htmlspecialchars($assign_to_group));
            }

            if (!$stmt_users) { // Verificar si la preparación falló
                 throw new Exception("Error preparando consulta de usuarios: " . $conn->error);
            }

            $stmt_users->execute();
            $result_users = $stmt_users->get_result();
            while ($row = $result_users->fetch_assoc()) {
                $userIds[] = $row; // Guardamos {id, role}
            }
            $stmt_users->close();

            if (empty($userIds)) {
                throw new Exception("No se encontraron usuarios para el grupo seleccionado.");
            }

            $stmt_task = null;
            if ($type === 'Manual') {
                if (!$title) throw new Exception("El título es requerido para tareas manuales grupales.");
                // CORRECCIÓN: Los últimos 2 placeholders (? ?) son para start/end datetime
                $stmt_task = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, assigned_to_group, type, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, ?, 'Manual', ?, ?, ?)");
            } elseif ($type === 'Asignacion' && $alert_id) {
                // Obtener prioridad de la alerta si no se especificó una para la tarea
                 if (empty($priority) || !in_array($priority, ['Baja', 'Media', 'Alta', 'Critica'])) {
                     $prio_res = $conn->query("SELECT priority FROM alerts WHERE id = " . intval($alert_id));
                     $priority = $prio_res ? ($prio_res->fetch_assoc()['priority'] ?? 'Media') : 'Media';
                 }
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, assigned_to_group, instruction, type, status, priority, created_by_user_id) VALUES (?, ?, ?, ?, 'Asignacion', 'Pendiente', ?, ?)");
            } else {
                throw new Exception("Parámetros no válidos para asignación grupal.");
            }

            if (!$stmt_task) throw new Exception("Error al preparar la consulta de inserción de tarea: " . $conn->error);

            foreach ($userIds as $user_row) {
                $uid = $user_row['id'];
                $role_of_user = $user_row['role'];
                $group_name_to_assign = ($assign_to_group === 'todos') ? $role_of_user : $assign_to_group;

                if ($type === 'Manual') {
                     // 8 placeholders -> "sssissiii" (9 si contamos el ID del creador)
                     // title, instruction, priority, uid, group_name_to_assign, start_datetime, end_datetime, creator_id
                    $stmt_task->bind_param("sssisssi", $title, $instruction, $priority, $uid, $group_name_to_assign, $start_datetime, $end_datetime, $creator_id);
                } elseif ($type === 'Asignacion') {
                    // 6 placeholders -> "iissis"
                    // alert_id, uid, group_name_to_assign, instruction, priority, creator_id
                    $stmt_task->bind_param("iissis", $alert_id, $uid, $group_name_to_assign, $instruction, $priority, $creator_id);
                }
                if (!$stmt_task->execute()) {
                    // Log detallado del error
                    error_log("Error insertando tarea grupal para user ID $uid: " . $stmt_task->error . " | Alert ID: " . ($alert_id ?? 'N/A') . " | Group: " . $assign_to_group);
                    // Podrías decidir continuar o detenerte aquí
                    // throw new Exception("Error al insertar tarea para usuario ID $uid: " . $stmt_task->error);
                }
            }
            $stmt_task->close();

            // Marcar alerta como asignada solo después de insertar tareas
            if ($type === 'Asignacion' && $alert_id) {
                $update_alert_stmt = $conn->prepare("UPDATE alerts SET status = 'Asignada' WHERE id = ?");
                 if ($update_alert_stmt) {
                    $update_alert_stmt->bind_param("i", $alert_id);
                    $update_alert_stmt->execute();
                    $update_alert_stmt->close();
                 } else {
                    error_log("Error preparando update de alerta: " . $conn->error);
                    // No lanzar excepción, pero registrar el problema
                 }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Tareas asignadas al grupo con éxito.']);

        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500); // Internal Server Error
            error_log("Error en asignación grupal: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Error en la asignación grupal: ' . $e->getMessage()]);
        }
        $conn->close(); // Cerrar conexión
        exit;
    } // Fin asignación grupal

    // ===== LÓGICA PARA ASIGNACIÓN INDIVIDUAL Y RECORDATORIOS =====
    $stmt = null;
    $is_update = false;

    if ($type === 'Recordatorio') {
        $target_user_id = $user_id; // Ya validado como INT o null al inicio
        // *** CORRECCIÓN Error 1: Lógica para obtener el título ***
        $taskTitle = "ID " . ($alert_id ?: ($task_id ?: 'Desconocido')); // Título por defecto
        $display_title = null;

        try { // Envolver la lógica de consulta en try-catch
            if ($task_id) {
                $stmt_title = $conn->prepare("SELECT COALESCE(a.title, t.title) as display_title FROM tasks t LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.id = ?");
                if ($stmt_title) {
                    $stmt_title->bind_param("i", $task_id);
                    if ($stmt_title->execute()) {
                        $result_title = $stmt_title->get_result();
                        if ($result_title && $result_title->num_rows > 0) {
                            $row = $result_title->fetch_assoc();
                            $display_title = $row['display_title'];
                        }
                    } else {
                        error_log("Error ejecutando consulta de título para tarea ID $task_id: " . $stmt_title->error);
                    }
                    $stmt_title->close();
                } else {
                     error_log("Error preparando consulta de título para tarea ID $task_id: " . $conn->error);
                }
            } elseif ($alert_id) {
                $stmt_title = $conn->prepare("SELECT title as display_title FROM alerts WHERE id = ?");
                 if ($stmt_title) {
                    $stmt_title->bind_param("i", $alert_id);
                     if ($stmt_title->execute()) {
                        $result_title = $stmt_title->get_result();
                        if ($result_title && $result_title->num_rows > 0) {
                            $row = $result_title->fetch_assoc();
                            $display_title = $row['display_title'];
                        }
                    } else {
                        error_log("Error ejecutando consulta de título para alerta ID $alert_id: " . $stmt_title->error);
                    }
                    $stmt_title->close();
                } else {
                     error_log("Error preparando consulta de título para alerta ID $alert_id: " . $conn->error);
                }
            }

            if (!empty($display_title)) {
                $taskTitle = $display_title;
            }
        } catch (Exception $e) {
            error_log("Excepción al buscar título para recordatorio: " . $e->getMessage());
            // Continuar con el título por defecto
        }
        // *** FIN CORRECCIÓN Error 1 ***

        $message = "Recordatorio sobre: '" . $conn->real_escape_string($taskTitle) . "'";
    
 $stmt = $conn->prepare("INSERT INTO reminders (user_id, message, created_by_user_id, created_at) VALUES (?, ?, ?, NOW())");
        // *** CORRECCIÓN Error 1: Verificar $stmt antes de bind_param ***
        if ($stmt) {
              $stmt->bind_param("isi", $target_user_id, $message, $creator_id);
        } else {
             http_response_code(500);
             error_log("Error preparando INSERT de recordatorio: " . $conn->error);
             echo json_encode(['success' => false, 'error' => 'Error interno al preparar la consulta del recordatorio.']);
             $conn->close();
             exit;
        }

    } elseif ($type === 'Asignacion') {
        // Asignación individual
        if ($task_id) { // Re-asignar tarea existente
            $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ?, assigned_to_group = NULL, status = 'Pendiente', priority = ?, start_datetime = ?, end_datetime = ?, created_at = NOW() WHERE id = ?");
             // 7 placeholders -> "issssi"
             if ($stmt) {
                 $stmt->bind_param("issssi", $user_id, $instruction, $priority, $start_datetime, $end_datetime, $task_id);
             }
             $is_update = true;
        } elseif ($alert_id) { // Crear tarea nueva desde alerta
            // Obtener prioridad de la alerta si no se especificó
             $prio_res = $conn->query("SELECT priority FROM alerts WHERE id = " . intval($alert_id));
             $original_priority = $prio_res ? ($prio_res->fetch_assoc()['priority'] ?? 'Media') : 'Media';
             $final_priority = !empty($priority) ? $priority : $original_priority;

            $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type, status, priority, created_by_user_id, created_at) VALUES (?, ?, ?, 'Asignacion', 'Pendiente', ?, ?, NOW())");
             // 6 placeholders -> "iissis"
             if ($stmt) {
                 $stmt->bind_param("iissis", $alert_id, $user_id, $instruction, $final_priority, $creator_id);
             }
        }
    } elseif ($type === 'Manual') {
        // Crear tarea manual individual
        if ($title) {
             $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?)");
             // 8 placeholders -> "sssissii"
             if ($stmt) {
                 $stmt->bind_param("sssissii", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime, $creator_id);
             }
        } else {
             http_response_code(400);
             echo json_encode(['success' => false, 'error' => 'El título es requerido para tareas manuales individuales.']);
             $conn->close();
             exit;
        }
    }

    // Ejecutar la consulta preparada (si se preparó correctamente)
    if ($stmt) {
        if ($stmt->execute()) {
            // Actualizar estado de alerta si fue una asignación desde alerta
            if ($type === 'Asignacion' && $alert_id && !$is_update) {
                 $update_alert_stmt = $conn->prepare("UPDATE alerts SET status = 'Asignada' WHERE id = ?");
                 if ($update_alert_stmt) {
                     $update_alert_stmt->bind_param("i", $alert_id);
                     $update_alert_stmt->execute();
                     $update_alert_stmt->close();
                 } else {
                     error_log("Error preparando update de alerta post-asignación: " . $conn->error);
                 }
            }
            $message = ($type === 'Recordatorio') ? 'Recordatorio creado.' : ($is_update ? 'Tarea reasignada.' : 'Tarea asignada.');
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            http_response_code(500);
            error_log("Error DB ejecutando acción ($type): " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Error al ejecutar la consulta: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        // Este error solo debería ocurrir si $type es inválido o faltan datos cruciales no validados antes
        http_response_code(400);
         error_log("Error preparando statement en alerts_api.php - Tipo: $type, TaskID: $task_id, AlertID: $alert_id, UserID: $user_id");
        echo json_encode(['success' => false, 'error' => 'No se pudo preparar la consulta (Tipo de acción inválido o faltan datos).']);
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
}

$conn->close(); // Asegúrate de cerrar la conexión al final del script
?>