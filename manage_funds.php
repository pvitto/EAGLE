<?php
session_start();
require 'check_session.php';
if ($_SESSION['user_role'] !== 'Admin') {
    header('Location: index.php');
    exit;
}
require 'db_connection.php';
$all_clients = [];
$clients_result = $conn->query("SELECT id, name, nit FROM clients ORDER BY name ASC");
if ($clients_result) { while ($row = $clients_result->fetch_assoc()) { $all_clients[] = $row; } }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Fondos - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Fondos de Clientes</h1>
            <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
        </div>
        
        <div class="mb-8 p-4 border rounded-lg">
            <h2 class="text-xl font-semibold mb-4">Agregar Nuevo Fondo</h2>
            <form id="add-fund-form" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label for="client-select" class="block text-sm font-medium text-gray-700">Cliente</label>
                    <select id="client-select" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                        <option value="">Seleccione un cliente...</option>
                        <?php foreach($all_clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']) . ' (NIT: ' . htmlspecialchars($client['nit'] ?? 'N/A') . ')'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fund-name" class="block text-sm font-medium text-gray-700">Nombre del Fondo</label>
                    <input type="text" id="fund-name" placeholder="Ej: Fondo C" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar Fondo</button>
            </form>
        </div>

        <div>
            <h2 class="text-xl font-semibold mb-4">Fondos Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left">
                            <th class="px-6 py-3">Nombre del Fondo</th>
                            <th class="px-6 py-3">Cliente Asociado</th>
                            <th class="px-6 py-3">NIT Cliente</th>
                        </tr>
                    </thead>
                    <tbody id="funds-table-body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const apiUrl = 'api/funds_api.php';

        async function fetchFunds() {
            try {
                const response = await fetch(apiUrl);
                const funds = await response.json();
                const tbody = document.getElementById('funds-table-body');
                tbody.innerHTML = '';
                if (funds.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center p-4">No hay fondos registrados.</td></tr>';
                } else {
                    funds.forEach(fund => {
                        const row = `
                            <tr class="border-b">
                                <td class="px-6 py-4 font-medium">${fund.name}</td>
                                <td class="px-6 py-4">${fund.client_name}</td>
                                <td class="px-6 py-4 font-mono">${fund.client_nit || 'N/A'}</td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                }
            } catch (error) { console.error('Error fetching funds:', error); }
        }

        document.getElementById('add-fund-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const name = document.getElementById('fund-name').value;
            const client_id = document.getElementById('client-select').value;
            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, client_id })
                });
                const result = await response.json();
                if (result.success) {
                    this.reset();
                    fetchFunds();
                } else { alert('Error: ' + result.error); }
            } catch (error) { alert('Error de conexión.'); }
        });

        document.addEventListener('DOMContentLoaded', fetchFunds);
    </script>
</body>
</html>