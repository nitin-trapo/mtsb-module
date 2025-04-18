<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'includes/header.php';
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$conn = $database->getConnection();

// Define available pages for permissions
$available_pages = [
    'dashboard' => 'Dashboard',
    'users' => 'Users Management',
    'orders' => 'Orders',
    'agents' => 'Agents',
    'commissions' => 'Commissions List',
    'bulk_commissions' => 'Bulk Commissions',
    'rules' => 'Commission Rules',
    'sync' => 'Sync Data',
    'products' => 'Products',
    'settings' => 'Settings',
    'profile' => 'Profile',
    'sales_report' => 'Sales Report'
];

// Function to generate random password
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}

// Initialize variables
$error = '';
$success = '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle delete action
if ($action === 'delete' && $user_id > 0) {
    // Don't allow users to delete themselves
    if ($user_id != $_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            $success = "User deleted successfully!";
        } else {
            $error = "Error deleting user: " . $stmt->errorInfo()[2];
        }
    } else {
        $error = "You cannot delete your own account!";
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_user']) || isset($_POST['update_user'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = 'admin';
        $status = isset($_POST['status']) ? 'active' : 'inactive';
        
        if (empty($name) || empty($email)) {
            $error = "Name and email are required fields.";
        } else {
            if (isset($_POST['add_user'])) {
                // Generate random password for new user
                $password = generateRandomPassword();
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                
                if ($stmt->execute([$name, $email, $hashed_password, $role, $status])) {
                    $new_user_id = $conn->lastInsertId();
                    
                    // Insert permissions for the new user
                    $page_permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
                    $perm_stmt = $conn->prepare("INSERT INTO user_permissions (user_id, page_name, has_access) VALUES (?, ?, ?)");
                    
                    foreach ($available_pages as $page => $label) {
                        $has_access = in_array($page, $page_permissions) ? 1 : 0;
                        $perm_stmt->execute([$new_user_id, $page, $has_access]);
                    }
                    
                    $success = "User added successfully! Temporary password: " . $password;
                } else {
                    $error = "Error adding user: " . $stmt->errorInfo()[2];
                }
            } else {
                $update_sql = "UPDATE users SET name = ?, email = ?, status = ? WHERE id = ?";
                $params = array($name, $email, $status, $_POST['user_id']);
                
                $stmt = $conn->prepare($update_sql);
                
                if ($stmt->execute($params)) {
                    // Update permissions
                    $page_permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
                    
                    // First, ensure permissions exist for all pages
                    foreach ($available_pages as $page => $label) {
                        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND page_name = ?");
                        $check_stmt->execute([$_POST['user_id'], $page]);
                        $exists = $check_stmt->fetchColumn();
                        
                        if ($exists == 0) {
                            // Insert if doesn't exist
                            $insert_stmt = $conn->prepare("INSERT INTO user_permissions (user_id, page_name, has_access) VALUES (?, ?, ?)");
                            $has_access = in_array($page, $page_permissions) ? 1 : 0;
                            $insert_stmt->execute([$_POST['user_id'], $page, $has_access]);
                        } else {
                            // Update if exists
                            $update_stmt = $conn->prepare("UPDATE user_permissions SET has_access = ? WHERE user_id = ? AND page_name = ?");
                            $has_access = in_array($page, $page_permissions) ? 1 : 0;
                            $update_stmt->execute([$has_access, $_POST['user_id'], $page]);
                        }
                    }
                    
                    $success = "User updated successfully!";
                } else {
                    $error = "Error updating user: " . $stmt->errorInfo()[2];
                }
            }
        }
    }
}

// Fetch all users with their permissions
$stmt = $conn->query("
    SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
           GROUP_CONCAT(CASE WHEN up.has_access = 1 THEN up.page_name ELSE NULL END) as permissions
    FROM users u
    LEFT JOIN user_permissions up ON u.id = up.user_id
    WHERE u.role = 'admin'
    GROUP BY u.id
    ORDER BY u.id DESC
");
$users = $stmt;
?>

<!-- Begin Page Content -->
<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Heading -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Manage Users</h1>
            <button type="button" class="d-none d-sm-inline-block btn btn-sm btn-success shadow-sm add-user-btn" data-bs-toggle="modal" data-bs-target="#userModal">
                <i class="fas fa-plus fa-sm"></i> Add New User
            </button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Users Table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Users List</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="usersTable" class="table table-bordered" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Permissions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = $users->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr data-permissions="<?php echo $user['permissions']; ?>">
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="toggle_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $user['status']; ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-sm <?php echo $user['status'] == 'active' ? 'btn-success' : 'btn-warning'; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php if ($user['permissions']): ?>
                                            <?php foreach (explode(',', $user['permissions']) as $permission): ?>
                                                <span class="badge bg-success me-1"><?php echo $permission; ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-primary btn-sm" 
                                                    onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', 
                                                                     '<?php echo htmlspecialchars($user['email']); ?>', '<?php echo $user['status']; ?>', '<?php echo $user['permissions']; ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" 
                                                    onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalLabel">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="userForm" method="post">
                <div class="modal-body">
                    <input type="hidden" id="user_id" name="user_id" value="">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="status" name="status" checked>
                            <label class="form-check-label" for="status">Active</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Page Permissions</label>
                        <div class="row">
                            <?php foreach ($available_pages as $page => $label): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input permission-checkbox" type="checkbox" 
                                           name="permissions[]" value="<?php echo $page; ?>" 
                                           id="perm_<?php echo $page; ?>">
                                    <label class="form-check-label" for="perm_<?php echo $page; ?>">
                                        <?php echo $label; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn" name="add_user">
                        <i class="fas fa-save"></i> Save User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#usersTable').DataTable({
        "order": [[0, "desc"]],
        "pageLength": 25,
        "responsive": true,
        "language": {
            "search": "Search users:",
            "lengthMenu": "Show _MENU_ users per page",
            "info": "Showing _START_ to _END_ of _TOTAL_ users",
            "infoEmpty": "Showing 0 to 0 of 0 users",
            "zeroRecords": "No matching users found"
        },
        "dom": "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        "drawCallback": function(settings) {
            // Re-initialize tooltips after DataTable updates
            $('[data-bs-toggle="tooltip"]').tooltip();
        }
    });

    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Reset form when modal is closed
    $('#userModal').on('hidden.bs.modal', function () {
        $('#userForm')[0].reset();
        $('#userModalLabel').text('Add New User');
        $('#submitBtn').attr('name', 'add_user').html('<i class="fas fa-save"></i> Save User');
        $('#user_id').val('');
    });

    // Handle add new user button click
    $('.add-user-btn').click(function() {
        $('#userForm')[0].reset();
        $('#userModalLabel').text('Add New User');
        $('#submitBtn').attr('name', 'add_user').html('<i class="fas fa-save"></i> Save User');
        $('#user_id').val('');
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
});

function editUser(id, name, email, status, permissions = '') {
    document.getElementById('userModalLabel').textContent = 'Edit User';
    document.getElementById('user_id').value = id;
    document.getElementById('name').value = name;
    document.getElementById('email').value = email;
    document.getElementById('status').checked = status === 'active';
    
    // Reset all permission checkboxes
    document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Set the permissions
    if (permissions) {
        const permissionArray = permissions.split(',');
        permissionArray.forEach(page => {
            const checkbox = document.getElementById('perm_' + page.trim());
            if (checkbox) checkbox.checked = true;
        });
    }
    
    document.getElementById('userForm').querySelector('button[type="submit"]').name = 'update_user';
    var modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
}

// Update the table row to show permissions
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('usersTable');
    if (table) {
        table.querySelectorAll('tbody tr').forEach(row => {
            const permissions = row.getAttribute('data-permissions');
            if (permissions) {
                const permCell = row.querySelector('td:nth-last-child(2)'); // Get the permissions cell
                if (permCell) {
                    permCell.innerHTML = permissions.split(',').map(p => 
                        `<span class="badge bg-success me-1">${p}</span>`
                    ).join('');
                }
            }
        });
    }
});

// Delete user confirmation
function deleteUser(id, name) {
    if (confirm('Are you sure you want to delete user "' + name + '"? This action cannot be undone.')) {
        window.location.href = 'users.php?action=delete&id=' + id;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
