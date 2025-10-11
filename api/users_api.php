<?php
// Inicia la sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 1. Verificación de permisos de Administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

require '../db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Obtener todos los usuarios
        $users = [];
        $result = $conn->query("SELECT id, name, email, role FROM users ORDER BY name ASC");
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
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($role)) {
            echo json_encode(['success' => false, 'error' => 'Nombre, email y rol son requeridos.']);
            exit;
        }

        if ($id) {
            // Actualizar usuario existente
            if (!empty($password)) {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, password = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $email, $role, $password, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->bind_param("sssi", $name, $email, $role, $id);
            }
        } else {
            // Crear nuevo usuario
            if (empty($password)) {
                echo json_encode(['success' => false, 'error' => 'La contraseña es requerida para nuevos usuarios.']);
                exit;
            }
            $stmt = $conn->prepare("INSERT INTO users (name, email, role, password) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $email, $role, $password);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $stmt->error]);
        }
        $stmt->close();
        break;

    case 'DELETE':
        // Eliminar un usuario
        $id = $_GET['id'] ?? null;
        if ($id) {
            // Para evitar errores, también eliminamos tareas asociadas a este usuario
            $conn->query("DELETE FROM tasks WHERE assigned_to_user_id = $id");

            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al eliminar el usuario.']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'error' => 'No se proporcionó ID.']);
        }
        break;
        
    default:
        header('HTTP/1.0 405 Method Not Allowed');
        echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
        break;
}

$conn->close();
?>

