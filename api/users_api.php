<?php
header("Content-Type: application/json; charset=UTF-8");
include '../db_connection.php'; // Incluir el archivo de conexión

$method = $_SERVER['REQUEST_METHOD'];

// Leer el cuerpo de la solicitud (para POST, PUT)
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        // Obtener todos los usuarios
        $result = $conn->query("SELECT * FROM users ORDER BY id");
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        echo json_encode($users);
        break;

    case 'POST':
        // Crear un nuevo usuario
        $name = $input['name'];
        $email = $input['email'];
        $role = $input['role'];

        $stmt = $conn->prepare("INSERT INTO users (name, email, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $email, $role);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
        break;

    case 'PUT':
        // Actualizar un usuario existente
        $id = $input['id'];
        $name = $input['name'];
        $email = $input['email'];
        $role = $input['role'];

        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $email, $role, $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
        break;
        
    case 'DELETE':
        // Eliminar un usuario
        // Obtenemos el ID de la URL, por ej: /api/users_api.php?id=5
        $id = $_GET['id'];
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
        break;

    default:
        // Método no soportado
        header("HTTP/1.0 405 Method Not Allowed");
        break;
}

$conn->close();
?>
