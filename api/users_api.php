<?php
// Inicia la sesión de forma segura
require '../config.php';
header('Content-Type: application/json');

// 1. Verificación de permisos de Administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

require '../db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Obtener todos los usuarios
        $users = [];
        // *** MODIFICADO: Incluir 'gender' en la consulta ***
        $result = $conn->query("SELECT id, name, email, role, gender FROM users ORDER BY name ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        echo json_encode($users);
        break;

    case 'POST':
        // Crear o Actualizar un usuario
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';
        // *** NUEVO: Obtener género ***
        $gender = $_POST['gender'] ?? null;

        // Validaciones básicas
        if (empty($name) || empty($email) || empty($role)) {
             http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'error' => 'Nombre, email y rol son requeridos.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
             http_response_code(400);
             echo json_encode(['success' => false, 'error' => 'Formato de email inválido.']);
             exit;
        }
        if (!in_array($role, ['Admin', 'Operador', 'Checkinero', 'Digitador'])) {
             http_response_code(400);
             echo json_encode(['success' => false, 'error' => 'Rol inválido.']);
             exit;
        }
        // *** NUEVO: Validar género ***
        if (!in_array($gender, ['M', 'F'])) {
             http_response_code(400);
             echo json_encode(['success' => false, 'error' => 'Sexo inválido. Debe ser M o F.']);
             exit;
        }


        try {
            if ($id) {
                // Actualizar usuario existente
                if (!empty($password)) {
                    // *** MODIFICADO: Incluir 'gender' y contraseña ***
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, gender = ?, password = ? WHERE id = ?");
                    if (!$stmt) throw new Exception("Error preparando la consulta (update con pass): " . $conn->error);
                    $stmt->bind_param("sssssi", $name, $email, $role, $gender, $password, $id);
                } else {
                    // *** MODIFICADO: Incluir 'gender' sin contraseña ***
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, gender = ? WHERE id = ?");
                     if (!$stmt) throw new Exception("Error preparando la consulta (update sin pass): " . $conn->error);
                    $stmt->bind_param("ssssi", $name, $email, $role, $gender, $id);
                }
            } else {
                // Crear nuevo usuario
                if (empty($password)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'La contraseña es requerida para nuevos usuarios.']);
                    exit;
                }
                 // *** MODIFICADO: Incluir 'gender' al insertar ***
                $stmt = $conn->prepare("INSERT INTO users (name, email, role, gender, password) VALUES (?, ?, ?, ?, ?)");
                 if (!$stmt) throw new Exception("Error preparando la consulta (insert): " . $conn->error);
                $stmt->bind_param("sssss", $name, $email, $role, $gender, $password);
            }

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $id ?: $conn->insert_id]);
            } else {
                 // Capturar errores específicos como email duplicado
                 if ($conn->errno == 1062) { // Código de error para entrada duplicada
                     http_response_code(409); // Conflict
                     echo json_encode(['success' => false, 'error' => 'El email ya está registrado.']);
                 } else {
                     throw new Exception('Error al ejecutar la consulta: ' . $stmt->error);
                 }
            }
            $stmt->close();

        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            error_log("Error en users_api.php (POST): " . $e->getMessage()); // Log del error
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
        }

        break;

    case 'DELETE':
        // Eliminar un usuario
        parse_str(file_get_contents("php://input"), $_DELETE);
        $id = $_DELETE['id'] ?? ($_GET['id'] ?? null); // Aceptar ID por GET o DELETE body

        if ($id && filter_var($id, FILTER_VALIDATE_INT)) {
             if ($id == $_SESSION['user_id']) { // Prevenir autoeliminación
                 http_response_code(403);
                 echo json_encode(['success' => false, 'error' => 'No puedes eliminar tu propio usuario.']);
                 exit;
             }

            $conn->begin_transaction();
            try {
                // Eliminar tareas asignadas al usuario (o reasignarlas si prefieres)
                 $stmt_tasks = $conn->prepare("DELETE FROM tasks WHERE assigned_to_user_id = ?");
                 if (!$stmt_tasks) throw new Exception("Error preparando delete tasks: " . $conn->error);
                 $stmt_tasks->bind_param("i", $id);
                 $stmt_tasks->execute();
                 $stmt_tasks->close();

                 // Eliminar recordatorios para el usuario
                 $stmt_reminders = $conn->prepare("DELETE FROM reminders WHERE user_id = ?");
                 if (!$stmt_reminders) throw new Exception("Error preparando delete reminders: " . $conn->error);
                 $stmt_reminders->bind_param("i", $id);
                 $stmt_reminders->execute();
                 $stmt_reminders->close();

                // Eliminar el usuario
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                if (!$stmt) throw new Exception("Error preparando delete user: " . $conn->error);
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                     if ($stmt->affected_rows > 0) {
                         $conn->commit();
                         echo json_encode(['success' => true, 'message' => 'Usuario y sus tareas/recordatorios asociados eliminados.']);
                     } else {
                         throw new Exception('Usuario no encontrado.');
                     }
                } else {
                    throw new Exception('Error al eliminar el usuario: ' . $stmt->error);
                }
                $stmt->close();

            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                error_log("Error en users_api.php (DELETE): " . $e->getMessage()); // Log del error
                echo json_encode(['success' => false, 'error' => 'Error en la base de datos al eliminar: ' . $e->getMessage()]);
            }

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de usuario inválido o no proporcionado.']);
        }
        break;

    default:
        header('HTTP/1.0 405 Method Not Allowed');
        echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
        break;
}

$conn->close();
?>