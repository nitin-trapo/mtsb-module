<?php
session_start();
require_once '../config/database.php';
require_once '../config/shopify_config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Location: login.php');
    exit;
}

$page_title = 'Sync Data';
include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Sync Data with Shopify</h6>
                    <div>
                        <button class="btn btn-success" id="sync-all">
                            <i class="fas fa-sync-alt me-2"></i>Sync All
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-users me-2"></i>Customers
                                        <span class="badge bg-primary float-end" id="customers-count">0</span>
                                    </h5>
                                    <div class="progress mb-3 d-none" id="customers-progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div id="customers-status" class="small mb-3"></div>
                                    <button class="btn btn-primary w-100" id="sync-customers">
                                        <i class="fas fa-sync-alt me-2"></i>Sync Customers
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-shopping-cart me-2"></i>Orders
                                        <span class="badge bg-primary float-end" id="orders-count">0</span>
                                    </h5>
                                    <div class="progress mb-3 d-none" id="orders-progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div id="orders-status" class="small mb-3"></div>
                                    <button class="btn btn-primary w-100" id="sync-orders">
                                        <i class="fas fa-sync-alt me-2"></i>Sync Orders
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-tags me-2"></i>Product Types
                                        <span class="badge bg-primary float-end" id="product_types-count">0</span>
                                    </h5>
                                    <div class="progress mb-3 d-none" id="product_types-progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div id="product_types-status" class="small mb-3"></div>
                                    <button class="btn btn-primary w-100" id="sync-product_types">
                                        <i class="fas fa-sync-alt me-2"></i>Sync Types
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-tags me-2"></i>Product Tags
                                        <span class="badge bg-primary float-end" id="product_tags-count">0</span>
                                    </h5>
                                    <div class="progress mb-3 d-none" id="product_tags-progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div id="product_tags-status" class="small mb-3"></div>
                                    <button class="btn btn-primary w-100" id="sync-product_tags">
                                        <i class="fas fa-sync-alt me-2"></i>Sync Tags
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Recent Sync History</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-items-center mb-0" id="sync-logs" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width:15%">Type</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2" style="width:10%">Status</th>
                                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width:10%">New Items</th>
                                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width:10%">Total</th>
                                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width:15%">Started</th>
                                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width:15%">Completed</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2" style="width:25%">Summary</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const syncLogsTable = $('#sync-logs').DataTable({
        order: [[4, 'desc']], // Order by started_at descending
        ajax: {
            url: 'ajax/get_sync_logs.php',
            dataSrc: ''
        },
        autoWidth: false,
        columns: [
            { 
                data: 'sync_type',
                width: '15%',
                render: function(data) {
                    const typeLabels = {
                        'product_tags': 'Product Tags',
                        'product_types': 'Product Types',
                        'customers': 'Customers',
                        'orders': 'Orders'
                    };
                    return `<div class="d-flex px-2">
                        <div class="d-flex flex-column justify-content-center">
                            <h6 class="mb-0 text-sm">${typeLabels[data] || data}</h6>
                        </div>
                    </div>`;
                }
            },
            { 
                data: 'status',
                width: '10%',
                render: function(data, type, row) {
                    const statusConfig = {
                        'running': {
                            color: 'info',
                            icon: 'fas fa-sync fa-spin',
                            label: 'In Progress',
                            badgeClass: 'bg-gradient-info'
                        },
                        'success': {
                            color: 'success',
                            icon: 'fas fa-check-circle',
                            label: 'Completed',
                            badgeClass: 'bg-success'
                        },
                        'failed': {
                            color: 'danger',
                            icon: 'fas fa-times-circle',
                            label: 'Failed',
                            badgeClass: 'bg-gradient-danger'
                        }
                    };

                    // Convert status to lowercase for case-insensitive comparison
                    const normalizedStatus = (data || '').toLowerCase();
                    
                    const config = statusConfig[normalizedStatus] || {
                        color: 'secondary',
                        icon: 'fas fa-question-circle',
                        label: data,
                        badgeClass: 'bg-gradient-secondary'
                    };

                    return `<div class="text-center">
                        <div class="d-flex align-items-center justify-content-center">
                            <i class="${config.icon} text-${config.color} me-2"></i>
                            <span class="badge badge-sm ${config.badgeClass} text-white" 
                                  style="min-width: 85px; font-weight: 500;">
                                ${config.label}
                            </span>
                        </div>
                    </div>`;
                }
            },
            { 
                data: 'new_items',
                width: '10%',
                className: 'text-center',
                render: function(data) {
                    return `<span class="text-sm font-weight-bold">${data || '0'}</span>`;
                }
            },
            { 
                data: 'total_processed',
                width: '10%',
                className: 'text-center',
                render: function(data) {
                    return `<span class="text-sm font-weight-bold">${data || '0'}</span>`;
                }
            },
            { 
                data: 'started_at',
                width: '15%',
                className: 'text-center',
                render: function(data) {
                    if (!data) return '<span class="text-xs">-</span>';
                    const date = new Date(data);
                    const formattedDate = date.toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    return `<span class="text-xs font-weight-bold">${formattedDate}</span>`;
                }
            },
            { 
                data: 'completed_at',
                width: '15%',
                className: 'text-center',
                render: function(data) {
                    if (!data) return '<span class="text-xs">-</span>';
                    const date = new Date(data);
                    const formattedDate = date.toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    return `<span class="text-xs font-weight-bold">${formattedDate}</span>`;
                }
            },
            { 
                data: 'summary',
                width: '25%',
                render: function(data, type, row) {
                    const normalizedStatus = (row.status || '').toLowerCase();
                    const statusConfig = {
                        'running': {
                            class: 'text-info',
                            icon: 'fas fa-sync fa-spin me-1',
                            defaultText: 'Sync in progress...'
                        },
                        'success': {
                            class: 'text-success',
                            icon: 'fas fa-check-circle me-1',
                            defaultText: 'Sync completed successfully'
                        },
                        'failed': {
                            class: 'text-danger',
                            icon: 'fas fa-exclamation-circle me-1',
                            defaultText: 'Sync failed'
                        }
                    };

                    const config = statusConfig[normalizedStatus] || {
                        class: 'text-secondary',
                        icon: 'fas fa-info-circle me-1',
                        defaultText: ''
                    };

                    return `<div class="px-2">
                        <div class="d-flex align-items-center">
                            <i class="${config.icon} ${config.class}"></i>
                            <span class="text-xs ${config.class}">${data || config.defaultText}</span>
                        </div>
                    </div>`;
                }
            }
        ],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        responsive: true,
        language: {
            search: "",
            searchPlaceholder: "Search logs...",
            lengthMenu: "_MENU_ records per page",
        },
        dom: '<"row align-items-center"<"col-md-6"l><"col-md-6"f>><"table-responsive"t><"row align-items-center"<"col-md-6"i><"col-md-6"p>>',
        drawCallback: function() {
            $('[data-bs-toggle="tooltip"]').tooltip();
        }
    });

    function updateStatus(type, message, isError = false) {
        console.log('Updating status:', type, message, isError); // Debug log
        $(`#${type}-status`).html(`
            <div class="alert alert-${isError ? 'danger' : 'info'} mb-3">
                ${message}
            </div>
        `);
    }

    function updateProgress(type, progress) {
        console.log('Updating progress:', type, progress); // Debug log
        const $progress = $(`#${type}-progress`);
        $progress.removeClass('d-none');
        $progress.find('.progress-bar').css('width', `${progress}%`).attr('aria-valuenow', progress);
    }

    function checkSyncStatus(syncId, type) {
        console.log('Checking sync status:', syncId, type); // Debug log
        $.get('ajax/check_sync_status.php', { sync_id: syncId }, function(response) {
            console.log('Status response:', response); // Debug log
            if (response.error) {
                updateStatus(type, `Error: ${response.error}`, true);
                updateProgress(type, 100);
                $(`#sync-${type}`).prop('disabled', false);
                return;
            }

            if (response.status === 'success') {
                updateStatus(type, `Successfully synced ${response.items_synced} items`);
                updateProgress(type, 100);
                $(`#sync-${type}`).prop('disabled', false);
                syncLogsTable.ajax.reload();
            } else if (response.status === 'failed') {
                updateStatus(type, `Error: ${response.error_message}`, true);
                updateProgress(type, 100);
                $(`#sync-${type}`).prop('disabled', false);
                syncLogsTable.ajax.reload();
            } else {
                updateProgress(type, response.progress);
                setTimeout(() => checkSyncStatus(syncId, type), 2000);
            }
        }).fail(function(jqXHR) {
            console.error('Status check failed:', jqXHR); // Debug log
            let errorMsg = 'Failed to check sync status';
            try {
                const response = JSON.parse(jqXHR.responseText);
                errorMsg = response.error || errorMsg;
            } catch(e) {}
            updateStatus(type, errorMsg, true);
            updateProgress(type, 100);
            $(`#sync-${type}`).prop('disabled', false);
        });
    }

    function startSync(type) {
        console.log('Starting sync:', type); // Debug log
        const $btn = $(`#sync-${type}`);
        $btn.prop('disabled', true);
        updateStatus(type, `Starting ${type} sync...`);
        updateProgress(type, 0);

        $.ajax({
            url: `ajax/sync_${type}.php`,
            method: 'POST',
            success: function(response) {
                console.log('Sync response:', response); // Debug log
                if (response.success && response.sync_id) {
                    checkSyncStatus(response.sync_id, type);
                } else {
                    updateStatus(type, `Error: ${response.error || 'Unknown error'}`, true);
                    updateProgress(type, 100);
                    $btn.prop('disabled', false);
                }
            },
            error: function(xhr) {
                console.error('Sync error:', xhr); // Debug log
                let errorMsg = 'Failed to start sync';
                try {
                    const response = JSON.parse(xhr.responseText);
                    errorMsg = response.error || errorMsg;
                } catch(e) {}
                updateStatus(type, errorMsg, true);
                updateProgress(type, 100);
                $btn.prop('disabled', false);
            }
        });
    }

    // Initialize counts
    function updateCounts() {
        $.get('ajax/get_sync_counts.php', function(response) {
            if (response.success) {
                $('#customers-count').text(response.counts.customers || 0);
                $('#orders-count').text(response.counts.orders || 0);
                $('#product_types-count').text(response.counts.product_types || 0);
                $('#product_tags-count').text(response.counts.product_tags || 0);
            }
        });
    }

    // Call initially and every 30 seconds
    updateCounts();
    setInterval(updateCounts, 30000);

    // Sync All functionality
    $('#sync-all').click(function() {
        if (!confirm('Are you sure you want to sync all data? This may take a while.')) {
            return;
        }

        $(this).prop('disabled', true);
        const button = $(this);
        const originalText = button.html();
        button.html('<i class="fas fa-sync-alt fa-spin me-2"></i>Syncing...');

        // Sync in sequence
        syncInSequence(['customers', 'orders', 'product_types', 'product_tags'])
            .then(() => {
                button.html('<i class="fas fa-check me-2"></i>Completed');
                setTimeout(() => {
                    button.html(originalText);
                    button.prop('disabled', false);
                }, 3000);
            })
            .catch(() => {
                button.html('<i class="fas fa-times me-2"></i>Failed');
                setTimeout(() => {
                    button.html(originalText);
                    button.prop('disabled', false);
                }, 3000);
            });
    });

    async function syncInSequence(types) {
        for (const type of types) {
            await new Promise((resolve, reject) => {
                syncData(type, resolve, reject);
            });
        }
    }

    function syncData(type, resolve, reject) {
        startSync(type);
        const intervalId = setInterval(() => {
            $.get(`ajax/check_sync_status.php?sync_id=${type}`, function(response) {
                if (response.status === 'success' || response.status === 'failed') {
                    clearInterval(intervalId);
                    if (response.status === 'success') {
                        resolve();
                    } else {
                        reject();
                    }
                }
            });
        }, 2000);
    }

    $('#sync-customers').click(() => startSync('customers'));
    $('#sync-orders').click(() => startSync('orders'));
    $('#sync-product_types').click(() => startSync('product_types'));
    $('#sync-product_tags').click(() => startSync('product_tags'));
});
</script>

<style>
.badge.bg-success {
    background: #2dce89 !important;
    border: none;
    font-weight: 500;
}
.badge.bg-success:hover {
    background: #24a46d !important;
}
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<?php include 'includes/footer.php'; ?>
