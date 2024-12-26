$(document).ready(function() {
    // Initialize DataTables
    if ($('.datatable').length > 0) {
        $('.datatable').each(function() {
            // Get configuration from data attributes
            const pageLength = $(this).data('page-length') || 25;
            const orderColumn = $(this).data('order-column') || 0;
            const orderDir = $(this).data('order-dir') || 'desc';
            
            // Initialize DataTable with merged config
            if (!$.fn.DataTable.isDataTable(this)) {
                $(this).DataTable({
                    responsive: true,
                    pageLength: pageLength,
                    order: [[orderColumn, orderDir]],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ orders",
                        infoEmpty: "Showing 0 to 0 of 0 orders",
                        infoFiltered: "(filtered from _MAX_ total orders)"
                    },
                    dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                         '<"row"<"col-sm-12"tr>>' +
                         '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                    columnDefs: [
                        { type: 'num', targets: 0 },                    // Order ID column
                        { className: "text-end", targets: [2, 6] },     // Amount and Actions columns
                        { className: "text-center", targets: [3, 4] },  // Status columns
                        { orderable: false, targets: [6] }              // Actions column
                    ]
                });
            }
        });
    }

    // Initialize Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    // Form Validation
    $('form').on('submit', function(e) {
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        $(this).addClass('was-validated');
    });

    // AJAX Setup
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // Handle AJAX Errors
    $(document).ajaxError(function(event, jqxhr, settings, thrownError) {
        console.error('Ajax error:', thrownError);
        alert('An error occurred. Please try again.');
    });

    // Confirmation Dialog
    $('.confirm-action').on('click', function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });

    // Dynamic Form Updates
    $('.commission-input').on('change', function() {
        updateTotalCommission();
    });

    // Notification Handler
    function showNotification(message, type = 'success') {
        $('.notification')
            .removeClass()
            .addClass('notification alert alert-' + type)
            .text(message)
            .fadeIn()
            .delay(3000)
            .fadeOut();
    }

    // Update Total Commission
    function updateTotalCommission() {
        let total = 0;
        $('.commission-input').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#total-commission').text(total.toFixed(2));
    }
});
