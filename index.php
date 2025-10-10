<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAGLE 3.0 - Sistema de Alertas</title>
    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .lucide { width: 24px; height: 24px; stroke-width: 2; }
        @keyframes pulse-bg-red { 0%, 100% { background-color: #fee2e2; } 50% { background-color: #fecaca; } }
        .animate-pulse-red { animation: pulse-bg-red 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse-bg-orange { 0%, 100% { background-color: #ffedd5; } 50% { background-color: #fed7aa; } }
        .animate-pulse-orange { animation: pulse-bg-orange 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        .task-form { transition: all 0.3s ease-in-out; max-height: 0; overflow: hidden; padding: 0; margin-top: 0; }
        .task-form.active { max-height: 300px; padding: 1rem; margin-top: 1rem; }
        .nav-tab { cursor: pointer; padding: 0.75rem 1.5rem; font-weight: 500; color: #4b5563; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-tab:hover { color: #111827; }
        .nav-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        #user-modal-overlay { transition: opacity 0.3s ease; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="app" class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">
        <!-- Main Header -->
        <header class="flex flex-col sm:flex-row justify-between sm:items-center mb-6 border-b pb-4">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-900">EAGLE 3.0</h1>
                <p class="text-sm text-gray-500">Sistema Integrado de Operaciones y Alertas</p>
            </div>
             <div class="text-sm text-gray-600 mt-2 sm:mt-0 text-left sm:text-right">
                <p class="font-semibold">Equipo de Operaciones</p>
                <p>Última actualización: <span id="last-updated">justo ahora</span></p>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="mb-8">
            <div class="border-b border-gray-200">
                <div class="-mb-px flex space-x-4" aria-label="Tabs">
                    <button id="tab-operaciones" class="nav-tab active" onclick="switchTab('operaciones')">Panel de Operaciones</button>
                    <button id="tab-roles" class="nav-tab" onclick="switchTab('roles')">Gestión de Roles</button>
                </div>
            </div>
        </nav>

        <!-- Main Content Area -->
        <main>
            <!-- Panel de Operaciones Content (Static for now) -->
            <div id="content-operaciones">
                 <!-- ... (El contenido estático del panel de operaciones se mantiene igual) ... -->
            </div>

            <!-- Gestión de Roles Content (Dynamic) -->
            <div id="content-roles" class="hidden">
                <!-- ... (La sección de tarjetas de roles se mantiene igual) ... -->
                <div>
                    <div class="flex flex-col sm:flex-row justify-between sm:items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-900 flex items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users text-blue-600 mr-2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Gestionar Usuarios</h2>
                        <button id="add-user-btn" class="inline-flex items-center mt-4 sm:mt-0 bg-green-600 text-white font-semibold text-sm px-4 py-2 rounded-lg shadow-sm hover:bg-green-700">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                            Agregar Nuevo Usuario
                        </button>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3">Nombre</th>
                                        <th scope="col" class="px-6 py-3">Email</th>
                                        <th scope="col" class="px-6 py-3">Rol Asignado</th>
                                        <th scope="col" class="px-6 py-3 text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="users-table-body">
                                    <!-- Las filas de usuarios se generarán aquí con JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- User Modal (for Add/Edit) -->
    <div id="user-modal-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-50">
        <div id="user-modal" class="bg-white rounded-lg shadow-xl p-6 w-full max-w-md m-4">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modal-title" class="text-xl font-bold"></h3>
                <button id="close-modal-btn" class="text-gray-500 hover:text-gray-800">&times;</button>
            </div>
            <form id="user-form">
                <input type="hidden" id="user-id" name="id">
                <div class="space-y-4">
                    <div><label for="user-name" class="block text-sm font-medium text-gray-700">Nombre</label><input type="text" id="user-name" name="name" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <div><label for="user-email" class="block text-sm font-medium text-gray-700">Email</label><input type="email" id="user-email" name="email" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                    <div><label for="user-role" class="block text-sm font-medium text-gray-700">Rol</label><select id="user-role" name="role" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"><option value="Admin">Admin</option><option value="Operador">Operador</option><option value="Checkinero">Checkinero</option><option value="Digitador">Digitador</option></select></div>
                </div>
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" id="cancel-modal-btn" class="bg-gray-200 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-300">Cancelar</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Guardar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    // --- USER MANAGEMENT LOGIC (CRUD with PHP API) ---
    document.addEventListener('DOMContentLoaded', function() {
        const usersTableBody = document.getElementById('users-table-body');
        const addUserBtn = document.getElementById('add-user-btn');
        const modalOverlay = document.getElementById('user-modal-overlay');
        const modal = document.getElementById('user-modal');
        const closeModalBtn = document.getElementById('close-modal-btn');
        const cancelModalBtn = document.getElementById('cancel-modal-btn');
        const userForm = document.getElementById('user-form');
        const modalTitle = document.getElementById('modal-title');
        const userIdInput = document.getElementById('user-id');
        const userNameInput = document.getElementById('user-name');
        const userEmailInput = document.getElementById('user-email');
        const userRoleInput = document.getElementById('user-role');

        const API_URL = 'api/users_api.php';

        // --- Fetch and Render Users ---
        async function fetchUsers() {
            try {
                const response = await fetch(API_URL);
                const users = await response.json();
                renderUsersTable(users);
            } catch (error) {
                console.error('Error fetching users:', error);
            }
        }

        function renderUsersTable(users) {
            usersTableBody.innerHTML = '';
            if (users.length === 0) {
                usersTableBody.innerHTML = '<tr><td colspan="4" class="text-center p-6 text-gray-500">No hay usuarios registrados.</td></tr>';
                return;
            }
            users.forEach(user => {
                const roleColors = {
                    Admin: "bg-blue-100 text-blue-800",
                    Operador: "bg-gray-100 text-gray-800",
                    Checkinero: "bg-green-100 text-green-800",
                    Digitador: "bg-yellow-100 text-yellow-800"
                };
                const row = document.createElement('tr');
                row.className = 'bg-white border-b hover:bg-gray-50';
                row.innerHTML = `
                    <td class="px-6 py-4 font-medium text-gray-900">${user.name}</td>
                    <td class="px-6 py-4 text-gray-600">${user.email}</td>
                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${roleColors[user.role] || 'bg-gray-100'}">${user.role}</span></td>
                    <td class="px-6 py-4 text-center space-x-4">
                        <button class="font-medium text-blue-600 hover:underline" onclick="handleEditUser(${user.id}, '${user.name}', '${user.email}', '${user.role}')">Editar</button>
                        <button class="font-medium text-red-600 hover:underline" onclick="handleDeleteUser(${user.id})">Eliminar</button>
                    </td>
                `;
                usersTableBody.appendChild(row);
            });
        }
        
        // --- Modal Handling ---
        function openModal(mode = 'add', user = {}) {
            userForm.reset();
            if (mode === 'add') {
                modalTitle.textContent = 'Agregar Nuevo Usuario';
                userIdInput.value = '';
            } else {
                modalTitle.textContent = 'Editar Usuario';
                userIdInput.value = user.id;
                userNameInput.value = user.name;
                userEmailInput.value = user.email;
                userRoleInput.value = user.role;
            }
            modalOverlay.classList.remove('hidden');
            modalOverlay.classList.add('flex');
        }

        function closeModal() {
            modalOverlay.classList.add('hidden');
            modalOverlay.classList.remove('flex');
        }
        
        addUserBtn.addEventListener('click', () => openModal('add'));
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) closeModal();
        });

        // --- CRUD Operations ---
        userForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = userIdInput.value;
            const userData = {
                name: userNameInput.value,
                email: userEmailInput.value,
                role: userRoleInput.value
            };

            let url = API_URL;
            let method = 'POST';

            if (id) { // If ID exists, it's an update
                userData.id = id;
                method = 'PUT';
            }
            
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(userData)
                });
                const result = await response.json();
                if(result.success) {
                    closeModal();
                    fetchUsers(); // Refresh table
                } else {
                    alert('Error al guardar el usuario: ' + result.error);
                }
            } catch (error) {
                console.error('Error saving user:', error);
            }
        });

        window.handleEditUser = function(id, name, email, role) {
            openModal('edit', { id, name, email, role });
        }

        window.handleDeleteUser = async function(id) {
            if (!confirm('¿Estás seguro de que quieres eliminar a este usuario?')) return;
            
            try {
                const response = await fetch(`${API_URL}?id=${id}`, { method: 'DELETE' });
                const result = await response.json();
                if(result.success) {
                    fetchUsers(); // Refresh table
                } else {
                     alert('Error al eliminar el usuario: ' + result.error);
                }
            } catch (error) {
                console.error('Error deleting user:', error);
            }
        }

        // Initial load
        fetchUsers();
    });
        
    // --- (El resto del script para el panel de operaciones se mantiene igual) ---
    // --- Tab Switching Logic ---
    function switchTab(tabName) { /* ... */ }
    function toggleForm(formId) { /* ... */ }
    function assignTask(alertId) { /* ... */ }
    function setReminder(alertId) { /* ... */ }
    </script>
</body>
</html>
