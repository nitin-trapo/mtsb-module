<?php
session_start();

// Debug session data
error_log("Session data: " . print_r($_SESSION, true));

require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../includes/functions.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    error_log("User not logged in or not agent. Session: " . print_r($_SESSION, true));
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get agent details from customers table using email
$stmt = $conn->prepare("SELECT * FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND is_agent = 1");
$stmt->execute([$_SESSION['email']]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {
    error_log("Agent not found in customers table for email: " . $_SESSION['email']);
    // Create customer record if it doesn't exist
    $insert_stmt = $conn->prepare("
        INSERT INTO " . TABLE_CUSTOMERS . " 
        (email, first_name, last_name, is_agent, status, created_at) 
        VALUES (?, '', '', 1, 'active', NOW())
    ");
    $insert_stmt->execute([$_SESSION['email']]);
    
    // Fetch the newly created record
    $stmt->execute([$_SESSION['email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
}

error_log("Agent data retrieved: " . print_r($agent, true));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Debug logging
        error_log("POST Data: " . print_r($_POST, true));
        error_log("Session customer_id: " . $_SESSION['customer_id']);
        
        $stmt = $conn->prepare("
            UPDATE " . TABLE_CUSTOMERS . " 
            SET 
                first_name = :first_name,
                last_name = :last_name,
                phone = :phone,
                bank_name = :bank_name,
                bank_account_number = :bank_account_number,
                bank_account_holder = :bank_account_holder,
                bank_swift_code = :bank_swift_code
            WHERE email = :email AND is_agent = 1
        ");
        
        $params = [
            ':first_name' => $_POST['first_name'],
            ':last_name' => $_POST['last_name'],
            ':phone' => $_POST['phone'],
            ':bank_name' => $_POST['bank_name'],
            ':bank_account_number' => $_POST['bank_account_number'],
            ':bank_account_holder' => $_POST['bank_account_holder'],
            ':bank_swift_code' => $_POST['bank_swift_code'],
            ':email' => $_SESSION['email']
        ];

        // Debug logging
        error_log("SQL Parameters: " . print_r($params, true));
        
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = 'Profile updated successfully!';
        } else {
            error_log("No rows updated. SQL: " . $stmt->queryString);
            $_SESSION['error_message'] = 'No changes were made to the profile.';
        }
        
        header('Location: profile.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Error updating profile: " . $e->getMessage());
        error_log("SQL State: " . $e->errorInfo[0]);
        error_log("Error Code: " . $e->errorInfo[1]);
        error_log("Error Message: " . $e->errorInfo[2]);
        $_SESSION['error_message'] = 'Error updating profile. Please try again.';
        header('Location: profile.php');
        exit;
    }
}

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12 mb-4">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?php 
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Profile Information</h5>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        Edit Profile
                    </button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="mb-3">Personal Information</h6>
                            <table class="table">
                                <tr>
                                    <th width="30%">Name:</th>
                                    <td><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo htmlspecialchars($agent['email']); ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo htmlspecialchars($agent['phone'] ?? 'Not provided'); ?></td>
                                </tr>
                                
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="mb-3">Bank Information</h6>
                            <table class="table">
                                <tr>
                                    <th width="30%">Bank Name:</th>
                                    <td><?php echo htmlspecialchars($agent['bank_name'] ?? 'Not provided'); ?></td>
                                </tr>
                                <tr>
                                    <th>Account Holder:</th>
                                    <td><?php echo htmlspecialchars($agent['bank_account_holder'] ?? 'Not provided'); ?></td>
                                </tr>
                                <tr>
                                    <th>Account Number:</th>
                                    <td><?php echo htmlspecialchars($agent['bank_account_number'] ?? 'Not provided'); ?></td>
                                </tr>
                                <tr>
                                    <th>SWIFT Code:</th>
                                    <td><?php echo htmlspecialchars($agent['bank_swift_code'] ?? 'Not provided'); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="mb-3">Personal Information</h6>
                            <div class="mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($agent['first_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($agent['last_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($agent['phone'] ?? ''); ?>">
                            </div>
                           
                        </div>
                        <div class="col-md-6">
                            <h6 class="mb-3">Bank Information</h6>
                            <div class="mb-3">
                                <label class="form-label">Bank Name</label>
                                <input type="text" class="form-control" name="bank_name" value="<?php echo htmlspecialchars($agent['bank_name'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Account Holder Name</label>
                                <input type="text" class="form-control" name="bank_account_holder" value="<?php echo htmlspecialchars($agent['bank_account_holder'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" class="form-control" name="bank_account_number" value="<?php echo htmlspecialchars($agent['bank_account_number'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">SWIFT Code</label>
                                <input type="text" class="form-control" name="bank_swift_code" value="<?php echo htmlspecialchars($agent['bank_swift_code'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
