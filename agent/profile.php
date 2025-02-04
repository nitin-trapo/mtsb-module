<?php
session_start();

require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../includes/functions.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get agent details from customers table using email
$stmt = $conn->prepare("SELECT * FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND is_agent = 1");
$stmt->execute([$_SESSION['user_email']]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {
    error_log("Agent not found in customers table for email: " . $_SESSION['user_email']);
    // Create customer record if it doesn't exist
    $insert_stmt = $conn->prepare("
        INSERT INTO " . TABLE_CUSTOMERS . " 
        (email, first_name, last_name, is_agent, status, created_at) 
        VALUES (?, '', '', 1, 'active', NOW())
    ");
    $insert_stmt->execute([$_SESSION['user_email']]);
    
    // Fetch the newly created record
    $stmt->execute([$_SESSION['user_email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
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
        </div>
    </div>

    <!-- Profile Information Section -->
    <div id="bankDetailsContainer">
        <!-- Bank details and basic information will be loaded here -->
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

<script>
// Function to load bank details
function loadBankDetails() {
    fetch('ajax/view_bank_details.php')
        .then(response => {
            // Check if response is JSON (error) or HTML (success)
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return response.json().then(data => {
                    throw new Error(data.error || 'Failed to load bank details');
                });
            }
            return response.text();
        })
        .then(data => {
            document.getElementById('bankDetailsContainer').innerHTML = data;
        })
        .catch(error => {
            console.error('Error loading bank details:', error);
            document.getElementById('bankDetailsContainer').innerHTML = 
                `<div class="alert alert-danger">${error.message || 'Error loading bank details. Please try again later.'}</div>`;
        });
}

// Load bank details when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadBankDetails();
});

// Reload bank details after form submission
document.querySelector('form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadBankDetails(); // Reload bank details after successful update
            alert('Bank details updated successfully!');
        } else {
            alert(data.error || 'Failed to update bank details');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating bank details');
    });
});
</script>

<?php include 'includes/footer.php'; ?>
