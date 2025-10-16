<?php
session_start();
require 'check_session.php';
// Solo Admins pueden gestionar clientes
if ($_SESSION['user_role'] !== 'Admin') {
    header('Location: index.php');
    exit;
}
require 'db_connection.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Clientes - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Clientes</h1>
            <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
        </div>
        
        <div class="mb-8 p-4 border rounded-lg">
            <h2 class="text-xl font-semibold mb-4">Agregar Nuevo Cliente</h2>
            <form id="add-client-form" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label for="client-name" class="block text-sm font-medium text-gray-700">Nombre del Cliente</label>
                    <input type="text" id="client-name" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                 <div>
                    <label for="client-nit" class="block text-sm font-medium text-gray-700">NIT</label>
                    <input type="text" id="client-nit" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <div>
                    <label for="client-address" class="block text-sm font-medium text-gray-700">Dirección (Opcional)</label>
                    <input type="text" id="client-address" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar Cliente</button>
            </form>
        </div>

        <div>
            <h2 class="text-xl font-semibold mb-4">Clientes Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left">
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">NIT</th>
                            <th class="px-6 py-3">Dirección</th>
                            <th class="px-6 py-3">Fecha de Creación</th>
                        </tr>
                    </thead>
                    <tbody id="clients-table-body">
                        </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const apiUrl = 'api/clients_api.php';

        async function fetchClients() {
            try {
                const response = await fetch(apiUrl);
                const clients = await response.json();
                const tbody = document.getElementById('clients-table-body');
                tbody.innerHTML = '';
                if (clients.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center p-4">No hay clientes registrados.</td></tr>';
                } else {
                    clients.forEach(client => {
                        const row = `
                            <tr class="border-b">
                                <td class="px-6 py-4 font-medium">${client.name}</td>
                                <td class="px-6 py-4 font-mono">${client.nit || 'N/A'}</td>
                                <td class="px-6 py-4">${client.address || 'N/A'}</td>
                                <td class="px-6 py-4">${new Date(client.created_at).toLocaleString('es-CO')}</td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                }
            } catch (error) {
                console.error('Error fetching clients:', error);
            }
        }

        document.getElementById('add-client-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const name = document.getElementById('client-name').value;
            const nit = document.getElementById('client-nit').value;
            const address = document.getElementById('client-address').value;

            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, nit, address })
                });
                const result = await response.json();
                if (result.success) {
                    this.reset();
                    fetchClients();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error adding client:', error);
                alert('Error de conexión.');
            }
        });

        document.addEventListener('DOMContentLoaded', fetchClients);
    </script>
</body>
</html>