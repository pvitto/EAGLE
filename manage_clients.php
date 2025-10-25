<?php
// Determine if only content is requested (for AJAX loading)
$isContentOnly = isset($_GET['content_only']) && $_GET['content_only'] == '1';

if (!$isContentOnly) {
    // --- Full Page Load ---
    require 'config.php';
    require 'check_session.php';
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
    <style>
        body { font-family: 'Inter', sans-serif; }
        .client-row:hover { background-color: #f9fafb; cursor: pointer; }
    </style>
</head>
<body class="bg-gray-100 p-8">
    <?php
} else {
    // --- AJAX Content Load ---
    require 'config.php';
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
        echo '<p class="text-red-500 p-4">Acceso no autorizado.</p>';
        exit;
    }
    require 'db_connection.php';
}
?>
    <div id="main-clients-container" class="max-w-6xl mx-auto">
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Gestionar Clientes</h1>
                <?php if (!$isContentOnly): ?>
                    <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
                <?php endif; ?>
            </div>
            <div class="mb-8 p-4 border rounded-lg">
                 <h2 class="text-xl font-semibold mb-4">Agregar Nuevo Cliente</h2>
                 <form id="add-client-form-ajax" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div><label for="client-name-ajax" class="block text-sm font-medium text-gray-700">Nombre</label><input type="text" id="client-name-ajax" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <div><label for="client-nit-ajax" class="block text-sm font-medium text-gray-700">NIT</label><input type="text" id="client-nit-ajax" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <div><label for="client-address-ajax" class="block text-sm font-medium text-gray-700">Dirección</label><input type="text" id="client-address-ajax" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar</button>
                 </form>
            </div>
            <div>
                <h2 class="text-xl font-semibold mb-4">Clientes Existentes</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">NIT</th><th class="px-6 py-3">Dirección</th><th class="px-6 py-3">Creación</th><th class="px-6 py-3 text-center">Acciones</th></tr>
                        </thead>
                        <tbody id="clients-table-body-ajax"><tr><td colspan="5" class="p-4 text-center text-gray-400">Cargando...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="sites-management-panel" class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow-lg hidden">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Gestionar Sedes</h1>
                <p id="sites-client-name" class="text-gray-600"></p>
            </div>
            <button id="back-to-clients-btn" class="text-blue-600 hover:underline">Volver a Clientes</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="p-4 border rounded-lg">
                <h2 class="text-xl font-semibold mb-4">Agregar Nueva Sede</h2>
                <form id="add-site-form" class="space-y-4">
                    <input type="hidden" id="site-client-id">
                    <div><label for="site-name" class="block text-sm font-medium text-gray-700">Nombre de la Sede</label><input type="text" id="site-name" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <div><label for="site-address" class="block text-sm font-medium text-gray-700">Dirección (Opcional)</label><input type="text" id="site-address" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <button type="submit" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-green-700">Agregar Sede</button>
                </form>
            </div>
            <div>
                <h2 class="text-xl font-semibold mb-4">Sedes Existentes</h2>
                <div id="sites-list" class="space-y-2 max-h-96 overflow-y-auto">
                    <p class="text-center text-gray-400">Cargando sedes...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function initializeManageClients() {
            const apiUrlClients = 'api/clients_api.php';
            const apiUrlSites = 'api/sites_api.php';

            const mainContainer = document.getElementById('main-clients-container');
            const sitesContainer = document.getElementById('sites-management-panel');

            const clientsTbody = document.getElementById('clients-table-body-ajax');
            const addClientForm = document.getElementById('add-client-form-ajax');

            const sitesClientName = document.getElementById('sites-client-name');
            const siteClientIdInput = document.getElementById('site-client-id');
            const sitesListDiv = document.getElementById('sites-list');
            const addSiteForm = document.getElementById('add-site-form');
            const backToClientsBtn = document.getElementById('back-to-clients-btn');

            let currentClientId = null;

            async function fetchClientsManage() {
                if (!clientsTbody) return;
                try {
                    const response = await fetch(apiUrlClients);
                    const clients = await response.json();
                    clientsTbody.innerHTML = '';
                    if (clients.length === 0) {
                        clientsTbody.innerHTML = '<tr><td colspan="5" class="text-center p-4 text-gray-500">No hay clientes.</td></tr>';
                    } else {
                        clients.forEach(client => {
                            const row = document.createElement('tr');
                            row.className = 'border-b client-row';
                            row.dataset.clientId = client.id;
                            row.innerHTML = `
                                <td class="px-6 py-4 font-medium">${client.name || ''}</td>
                                <td class="px-6 py-4 font-mono">${client.nit || 'N/A'}</td>
                                <td class="px-6 py-4">${client.address || 'N/A'}</td>
                                <td class="px-6 py-4 text-xs">${client.created_at ? new Date(client.created_at).toLocaleString('es-CO') : ''}</td>
                                <td class="px-6 py-4 text-center">
                                    <button data-client-id="${client.id}" class="delete-client-btn text-red-600 hover:text-red-800 font-semibold">Eliminar</button>
                                </td>
                            `;
                            clientsTbody.appendChild(row);
                        });
                    }
                } catch (error) {
                    console.error('Error fetching clients:', error);
                    clientsTbody.innerHTML = '<tr><td colspan="5" class="text-center p-4 text-red-500">Error al cargar clientes.</td></tr>';
                }
            }
             clientsTbody.addEventListener('click', (e) => {
                if (e.target.classList.contains('delete-client-btn')) {
                    handleDeleteClient(e.target.dataset.clientId);
                } else {
                    const row = e.target.closest('.client-row');
                    if (row) {
                        const clientId = row.dataset.clientId;
                        // Encontrar los datos del cliente para pasarlos
                        fetch(apiUrlClients).then(res => res.json()).then(clients => {
                            const clientData = clients.find(c => c.id == clientId);
                            if (clientData) showSitesPanel(clientData);
                        });
                    }
                }
            });

            async function handleDeleteClient(clientId) {
                if (!confirm('¿Está seguro de que desea eliminar este cliente y todas sus sedes asociadas? Esta acción no se puede deshacer.')) return;
                try {
                    const response = await fetch(apiUrlClients, {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: clientId })
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || `Error ${response.status}`);

                    fetchClientsManage(); // Refresh the client list
                } catch (error) {
                    console.error('Error deleting client:', error);
                    alert(`Error: ${error.message}`);
                }
            }

            function showSitesPanel(client) {
                currentClientId = client.id;
                mainContainer.classList.add('hidden');
                sitesContainer.classList.remove('hidden');
                sitesClientName.textContent = `Para: ${client.name}`;
                siteClientIdInput.value = client.id;
                addSiteForm.reset();
                fetchSitesForClient(client.id);
            }

            function showClientsPanel() {
                currentClientId = null;
                mainContainer.classList.remove('hidden');
                sitesContainer.classList.add('hidden');
            }

            async function fetchSitesForClient(clientId) {
                sitesListDiv.innerHTML = '<p class="text-center text-gray-400">Cargando sedes...</p>';
                try {
                    const response = await fetch(`${apiUrlSites}?client_id=${clientId}`);
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error);

                    sitesListDiv.innerHTML = '';
                    if (data.data.length === 0) {
                        sitesListDiv.innerHTML = '<p class="text-center text-gray-500">Este cliente no tiene sedes registradas.</p>';
                    } else {
                        data.data.forEach(site => {
                            const siteEl = document.createElement('div');
                            siteEl.className = 'p-3 border rounded-md flex justify-between items-center';
                            siteEl.innerHTML = `
                                <div>
                                    <p class="font-semibold">${site.name}</p>
                                    <p class="text-xs text-gray-500">${site.address || 'Sin dirección'}</p>
                                </div>
                                <button data-site-id="${site.id}" class="delete-site-btn text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button>
                            `;
                            sitesListDiv.appendChild(siteEl);
                        });
                        document.querySelectorAll('.delete-site-btn').forEach(btn => {
                            btn.addEventListener('click', handleDeleteSite);
                        });
                    }
                } catch (error) {
                    console.error('Error fetching sites:', error);
                    sitesListDiv.innerHTML = '<p class="text-center text-red-500">Error al cargar las sedes.</p>';
                }
            }

            async function handleAddClient(e) {
                e.preventDefault();
                const name = document.getElementById('client-name-ajax').value;
                const nit = document.getElementById('client-nit-ajax').value;
                const address = document.getElementById('client-address-ajax').value;
                try {
                    const response = await fetch(apiUrlClients, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, nit, address }) });
                    const result = await response.json();
                    if (!result.success) throw new Error(result.error);
                    this.reset();
                    fetchClientsManage();
                } catch (error) {
                    console.error('Error adding client:', error);
                    alert(`Error: ${error.message}`);
                }
            }

            async function handleAddSite(e) {
                e.preventDefault();
                const name = document.getElementById('site-name').value;
                const address = document.getElementById('site-address').value;
                const clientId = siteClientIdInput.value;
                try {
                    const response = await fetch(apiUrlSites, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ client_id: clientId, name, address }) });
                    const result = await response.json();
                    if (!result.success) throw new Error(result.error);
                    this.reset();
                    fetchSitesForClient(clientId);
                } catch (error) {
                    console.error('Error adding site:', error);
                    alert(`Error: ${error.message}`);
                }
            }

            async function handleDeleteSite(e) {
                const siteId = e.target.dataset.siteId;
                if (!confirm('¿Está seguro de que desea eliminar esta sede?')) return;
                try {
                    const response = await fetch(`${apiUrlSites}?site_id=${siteId}`, { method: 'DELETE' });
                    const result = await response.json();
                    if (!result.success) throw new Error(result.error);
                    fetchSitesForClient(currentClientId);
                } catch (error) {
                    console.error('Error deleting site:', error);
                    alert(`Error: ${error.message}`);
                }
            }

            // Attach event listeners
            if (addClientForm && !addClientForm.hasAttribute('data-listener-added')) {
                addClientForm.addEventListener('submit', handleAddClient);
                addClientForm.setAttribute('data-listener-added', 'true');
            }
            if (addSiteForm && !addSiteForm.hasAttribute('data-listener-added')) {
                addSiteForm.addEventListener('submit', handleAddSite);
                addSiteForm.setAttribute('data-listener-added', 'true');
            }
            if (backToClientsBtn && !backToClientsBtn.hasAttribute('data-listener-added')) {
                backToClientsBtn.addEventListener('click', showClientsPanel);
                backToClientsBtn.setAttribute('data-listener-added', 'true');
            }

            fetchClientsManage();
        };

        // Run initialization logic
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
             initializeManageClients();
         } else {
             document.addEventListener('DOMContentLoaded', initializeManageClients);
         }
    </script>
<?php
if (!$isContentOnly) {
    echo '</body></html>';
    if (isset($conn)) $conn->close();
} else {
    if (isset($conn)) $conn->close();
}
?>
