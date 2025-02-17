<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/tables.php';
require_once '../../includes/functions.php';

// Debug session data
error_log("Session data in view_bank_details.php: " . print_r($_SESSION, true));

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    error_log("Unauthorized access attempt in view_bank_details.php. Session: " . print_r($_SESSION, true));
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!isset($_SESSION['user_email'])) {
        error_log("User email not set in session");
        throw new Exception("User email not found in session");
    }

    error_log("Attempting to fetch bank details for email: " . $_SESSION['user_email']);

    // Get agent details including basic information
    $stmt = $conn->prepare("
        SELECT 
            first_name,
            last_name,
            email,
            phone,
            bank_name,
            bank_account_number,
            bank_account_header,
            business_registration_number,
            tax_identification_number,
            ic_number,
            created_at
        FROM " . TABLE_CUSTOMERS . "
        WHERE email = ? AND is_agent = 1
    ");
    $stmt->execute([$_SESSION['user_email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Query result for agent: " . print_r($agent, true));

    if (!$agent) {
        error_log("Agent not found for email: " . $_SESSION['user_email']);
        throw new Exception("Agent not found");
    }

    // Parse bank statement header if exists
    $bankStatementInfo = null;
    $fileUrl = '';
    $fileIcon = '';
    
    if (!empty($agent['bank_account_header'])) {
        $bankStatementInfo = json_decode($agent['bank_account_header'], true);
        if ($bankStatementInfo && isset($bankStatementInfo['url'])) {
            $fileUrl = $bankStatementInfo['url'];
            $extension = isset($bankStatementInfo['extension']) ? 
                        $bankStatementInfo['extension'] : 
                        pathinfo($bankStatementInfo['name'], PATHINFO_EXTENSION);
            $fileIcon = getFileIcon($extension);
        }
    }
?>

<div class="row">
    <!-- Basic Information Card -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Basic Information</h5>
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
                        <td><?php echo !empty($agent['phone']) ? htmlspecialchars($agent['phone']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Business Registration No.:</th>
                        <td><?php echo !empty($agent['business_registration_number']) ? htmlspecialchars($agent['business_registration_number']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Tax Identification No. (TIN):</th>
                        <td><?php echo !empty($agent['tax_identification_number']) ? htmlspecialchars($agent['tax_identification_number']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>IC Number:</th>
                        <td><?php echo !empty($agent['ic_number']) ? htmlspecialchars($agent['ic_number']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Joined:</th>
                        <td><?php echo date('F j, Y', strtotime($agent['created_at'])); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Bank Details Card -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Bank Details</h5>
                <table class="table">
                    <tr>
                        <th width="30%">Bank Name:</th>
                        <td><?php echo !empty($agent['bank_name']) ? htmlspecialchars($agent['bank_name']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Account Number:</th>
                        <td><?php echo !empty($agent['bank_account_number']) ? htmlspecialchars($agent['bank_account_number']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Bank Statement:</th>
                        <td>
                            <?php if ($fileUrl): ?>
                                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" 
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="<?php echo $fileIcon; ?> me-2"></i>View Statement 
                                    <?php if (isset($bankStatementInfo['name'])): ?>
                                        (<?php echo htmlspecialchars($bankStatementInfo['name']); ?>)
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                Not uploaded
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
} catch (Exception $e) {
    error_log("Error in view_bank_details.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'An error occurred while fetching bank details: ' . $e->getMessage()]);
    exit;
}

function getFileIcon($extension) {
    return match(strtolower($extension)) {
        'pdf' => 'fas fa-file-pdf',
        'doc', 'docx' => 'fas fa-file-word',
        'xls', 'xlsx' => 'fas fa-file-excel',
        'jpg', 'jpeg', 'png' => 'fas fa-file-image',
        default => 'fas fa-file'
    };
}
?>
