<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $result = $conn->query("SELECT id, name, nit, address, created_at FROM clients ORDER BY name ASC");
        $clients = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $clients[] = $row;
            }
        }
        echo json_encode($clients);
        break;

    case 'POST':
        if ($_SESSION['user_role'] !== 'Admin') {
            echo json_encode(['success' => false, 'error' => 'Solo los administradores pueden crear clientes.']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        $nit = $data['nit'] ?? '';
        $address = $data['address'] ?? null;

        if (empty($name) || empty($nit)) {
            echo json_encode(['success' => false, 'error' => 'El nombre del cliente y el NIT son requeridos.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO clients (name, nit, address) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $nit, $address);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            // Error común: NIT duplicado
            if ($conn->errno == 1062) {
                 echo json_encode(['success' => false, 'error' => 'El NIT ingresado ya existe.']);
            } else {
                 echo json_encode(['success' => false, 'error' => 'Error al crear el cliente: ' . $stmt->error]);
            }
        }
        $stmt->close();
        break;

    case 'DELETE':
        if ($_SESSION['user_role'] !== 'Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Solo los administradores pueden eliminar clientes.']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de cliente requerido.']);
            exit;
        }

        $conn->begin_transaction();

        try {
            // Primero, eliminar sedes asociadas
            $stmt_sites = $conn->prepare("DELETE FROM client_sites WHERE client_id = ?");
            $stmt_sites->bind_param("i", $id);
            $stmt_sites->execute();
            $stmt_sites->close();

            // Luego, eliminar el cliente
            $stmt_client = $conn->prepare("DELETE FROM clients WHERE id = ?");
            $stmt_client->bind_param("i", $id);
            $stmt_client->execute();

            if ($stmt_client->affected_rows > 0) {
                $conn->commit();
                echo json_encode(['success' => true]);
            } else {
                $conn->rollback();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Cliente no encontrado.']);
            }
            $stmt_client->close();
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error en la transacción: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
        break;
}

$conn->close();
?>