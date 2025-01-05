<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Handle rule creation/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create') {
        $stmt = $conn->prepare("
            INSERT INTO commission_rules 
            (rule_type, rule_value, commission_percentage)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $_POST['rule_type'],
            $_POST['rule_value'],
            $_POST['commission_percentage']
        ]);
    } elseif ($_POST['action'] === 'update') {
        $stmt = $conn->prepare("
            UPDATE commission_rules 
            SET rule_type = ?, rule_value = ?, commission_percentage = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['rule_type'],
            $_POST['rule_value'],
            $_POST['commission_percentage'],
            $_POST['status'],
            $_POST['rule_id']
        ]);
    } elseif ($_POST['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM commission_rules WHERE id = ?");
        $stmt->execute([$_POST['rule_id']]);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Get all commission rules
$stmt = $conn->query("SELECT * FROM commission_rules ORDER BY created_at DESC");
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Commission Rules';
?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2>Commission Rules</h2>
        </div>
        <div class="col text-end">
            <button type="button" class="btn btn-primary" onclick="showCreateModal()">
                Add New Rule
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="rulesTable">
                    <thead>
                        <tr>
                            <th>Rule Type</th>
                            <th>Rule Value</th>
                            <th>Commission %</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td><?php echo ucfirst(str_replace('_', ' ', $rule['rule_type'])); ?></td>
                            <td><?php echo $rule['rule_value']; ?></td>
                            <td><?php echo $rule['commission_percentage']; ?>%</td>
                            <td>
                                <span class="badge bg-<?php echo $rule['status'] === 'active' ? 'success' : 'danger'; ?>">
                                    <?php echo ucfirst($rule['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($rule['created_at'])); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick="editRule(<?php echo htmlspecialchars(json_encode($rule)); ?>)">
                                    Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        onclick="deleteRule(<?php echo $rule['id']; ?>)">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Rule Modal -->
<div class="modal fade" id="ruleModal" tabindex="-1" aria-labelledby="ruleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ruleModalLabel">Add/Edit Commission Rule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="ruleForm">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="rule_id" value="">

                    <div class="mb-3">
                        <label for="ruleType" class="form-label">Rule Type</label>
                        <select name="rule_type" id="ruleType" class="form-select" required>
                            <option value="">Select Rule Type</option>
                            <option value="product_type">Product Type</option>
                            <option value="product_tag">Product Tag</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="ruleValue" class="form-label">Rule Value</label>
                        <div id="ruleValueContainer">
                            <select name="rule_value" id="ruleValue" class="form-select" required disabled>
                                <option value="">Select Rule Value</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="commissionPercentage" class="form-label">Commission Percentage</label>
                        <input type="number" name="commission_percentage" id="commissionPercentage" 
                               class="form-control" step="0.01" min="0" max="100" required>
                    </div>

                    <div class="mb-3">
                        <label for="ruleStatus" class="form-label">Status</label>
                        <select name="status" id="ruleStatus" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRule()">Save</button>
            </div>
        </div>
    </div>
</div>

<style>
    .select2-container {
        width: 100% !important;
    }
    
    .select2-container--default .select2-selection--single {
        height: 38px !important;
        padding: 5px !important;
        border: 1px solid #ced4da !important;
        border-radius: 4px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px !important;
        padding-left: 5px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }
    
    .select2-container--default.select2-container--disabled .select2-selection--single {
        background-color: #e9ecef !important;
        border-color: #ced4da !important;
    }
    
    .select2-container--default .select2-search--dropdown .select2-search__field {
        border: 1px solid #ced4da !important;
        border-radius: 4px !important;
    }
    
    .select2-dropdown {
        border: 1px solid #ced4da !important;
        border-radius: 4px !important;
    }
    
    .select2-results__option {
        padding: 6px 12px !important;
    }
    
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0d6efd !important;
    }
</style>

<script>
    // Define functions globally
    function showCreateModal() {
        $('#ruleForm')[0].reset();
        $('#ruleForm [name="action"]').val('create');
        $('#ruleForm [name="rule_id"]').val('');
        $('#ruleModal .modal-title').text('Add Commission Rule');
        
        // Reset both selects
        $('#ruleType').val('').trigger('change');
        $('#ruleValueContainer').html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
        $('#ruleValue').select2({
            dropdownParent: $('#ruleModal'),
            width: '100%',
            minimumResultsForSearch: -1,
            placeholder: 'Select Rule Value',
            disabled: true
        });
        
        const modal = new bootstrap.Modal(document.getElementById('ruleModal'));
        modal.show();
    }

    function editRule(rule) {
        $('#ruleForm')[0].reset();
        $('#ruleForm [name="action"]').val('update');
        $('#ruleForm [name="rule_id"]').val(rule.id);
        
        // Set rule type and trigger change
        $('#ruleType').val(rule.rule_type).trigger('change');
        
        // Handle rule value based on rule type
        if (rule.rule_type === 'product_type') {
            const container = $('#ruleValueContainer');
            container.html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
            
            const select = $('#ruleValue');
            select.select2({
                dropdownParent: $('#ruleModal'),
                width: '100%',
                minimumResultsForSearch: 0,
                placeholder: 'Select Product Type'
            });
            
            // Show loading state
            select.prop('disabled', true);
            
            // Fetch product types and set the value
            $.get('ajax/get_product_types.php')
                .done(function(response) {
                    if (response.product_types && response.product_types.length > 0) {
                        response.product_types.forEach(function(type) {
                            if (type) {
                                const selected = type === rule.rule_value;
                                select.append(new Option(type, type, selected, selected));
                            }
                        });
                        select.prop('disabled', false).trigger('change');
                    } else {
                        select.html('<option></option>');
                        select.prop('disabled', true).trigger('change');
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    select.html('<option></option>');
                    select.prop('disabled', true).trigger('change');
                    console.error('Failed to fetch product types:', textStatus, errorThrown);
                });
        } else if (rule.rule_type === 'product_tag') {
            const container = $('#ruleValueContainer');
            container.html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
            
            const select = $('#ruleValue');
            select.select2({
                dropdownParent: $('#ruleModal'),
                width: '100%',
                minimumResultsForSearch: 0,
                placeholder: 'Select Product Tag'
            });
            
            // Show loading state
            select.prop('disabled', true);
            
            // Fetch product tags and set the value
            $.get('ajax/get_product_tags.php')
                .done(function(response) {
                    if (response.product_tags && response.product_tags.length > 0) {
                        response.product_tags.forEach(function(tag) {
                            if (tag) {
                                const selected = tag === rule.rule_value;
                                select.append(new Option(tag, tag, selected, selected));
                            }
                        });
                        select.prop('disabled', false).trigger('change');
                    } else {
                        select.html('<option></option>');
                        select.prop('disabled', true).trigger('change');
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    select.html('<option></option>');
                    select.prop('disabled', true).trigger('change');
                    console.error('Failed to fetch product tags:', textStatus, errorThrown);
                });
        } else {
            $('#ruleValueContainer').html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
            $('#ruleValue').select2({
                dropdownParent: $('#ruleModal'),
                width: '100%',
                minimumResultsForSearch: -1,
                placeholder: 'Select Rule Value',
                disabled: true
            });
        }
        
        $('#ruleForm [name="commission_percentage"]').val(rule.commission_percentage);
        $('#ruleForm [name="status"]').val(rule.status);
        $('#ruleModal .modal-title').text('Edit Commission Rule');
        const modal = new bootstrap.Modal(document.getElementById('ruleModal'));
        modal.show();
    }

    function saveRule() {
        const formData = new FormData($('#ruleForm')[0]);
        $.ajax({
            url: 'rules.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error saving rule');
                }
            }
        });
    }

    function deleteRule(ruleId) {
        if (confirm('Are you sure you want to delete this rule?')) {
            $.post('rules.php', {
                action: 'delete',
                rule_id: ruleId
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error deleting rule');
                }
            });
        }
    }

    // jQuery document ready
    $(document).ready(function() {
        // Initialize DataTable
        $('#rulesTable').DataTable({
            responsive: true,
            order: [[4, 'desc']], // Sort by Created date by default
            columnDefs: [
                { orderable: false, targets: 5 } // Disable sorting for Actions column
            ],
            language: {
                search: "Search rules:",
                lengthMenu: "Show _MENU_ rules per page",
                info: "Showing _START_ to _END_ of _TOTAL_ rules",
                infoEmpty: "No rules found",
                emptyTable: "No commission rules available"
            }
        });

        const select2Options = {
            dropdownParent: $('#ruleModal'),
            width: '100%'
        };

        // Initialize rule type select2
        $('#ruleType').select2({
            ...select2Options,
            placeholder: 'Select Rule Type',
            minimumResultsForSearch: -1
        });

        // Initialize rule value select2
        $('#ruleValue').select2({
            ...select2Options,
            placeholder: 'Select Rule Value',
            disabled: true
        });

        // Handle rule type change
        $('#ruleType').on('change', function() {
            const ruleType = $(this).val();
            const container = $('#ruleValueContainer');
            
            if (ruleType === 'product_type') {
                container.html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
                
                const select = $('#ruleValue');
                select.select2({
                    ...select2Options,
                    placeholder: 'Select Product Type',
                    minimumResultsForSearch: 0
                });
                
                // Show loading state
                select.prop('disabled', true);
                
                // Fetch product types from server
                $.get('ajax/get_product_types.php')
                    .done(function(response) {
                        if (response.product_types && response.product_types.length > 0) {
                            response.product_types.forEach(function(type) {
                                if (type) {
                                    select.append(new Option(type, type));
                                }
                            });
                            select.prop('disabled', false).trigger('change');
                        } else {
                            select.html('<option></option>');
                            select.prop('disabled', true).trigger('change');
                        }
                    })
                    .fail(function(jqXHR, textStatus, errorThrown) {
                        select.html('<option></option>');
                        select.prop('disabled', true).trigger('change');
                        console.error('Failed to fetch product types:', textStatus, errorThrown);
                    });
            } else if (ruleType === 'product_tag') {
                container.html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
                
                const select = $('#ruleValue');
                select.select2({
                    ...select2Options,
                    placeholder: 'Select Product Tag',
                    minimumResultsForSearch: 0
                });
                
                // Show loading state
                select.prop('disabled', true);
                
                // Fetch product tags from server
                $.get('ajax/get_product_tags.php')
                    .done(function(response) {
                        if (response.product_tags && response.product_tags.length > 0) {
                            response.product_tags.forEach(function(tag) {
                                if (tag) {
                                    select.append(new Option(tag, tag));
                                }
                            });
                            select.prop('disabled', false).trigger('change');
                        } else {
                            select.html('<option></option>');
                            select.prop('disabled', true).trigger('change');
                        }
                    })
                    .fail(function(jqXHR, textStatus, errorThrown) {
                        select.html('<option></option>');
                        select.prop('disabled', true).trigger('change');
                        console.error('Failed to fetch product tags:', textStatus, errorThrown);
                    });
            } else {
                container.html('<select name="rule_value" id="ruleValue" class="form-select" required><option></option></select>');
                $('#ruleValue').select2({
                    ...select2Options,
                    placeholder: 'Select Rule Value',
                    disabled: true
                });
            }
        });
    });
</script>
