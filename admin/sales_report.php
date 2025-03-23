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

// Get parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'daily';
$group_by = $_GET['group_by'] ?? 'default'; // New parameter for grouping

// Prepare the SQL query based on grouping preference
switch ($group_by) {
    case 'weekly':
        $group_by_sql = "YEARWEEK(processed_at)";
        $date_format = "CONCAT(
            DATE_FORMAT(
                DATE_SUB(processed_at, INTERVAL WEEKDAY(processed_at) DAY),
                '%M %d'
            ),
            ' - ',
            DATE_FORMAT(
                DATE_ADD(DATE_SUB(processed_at, INTERVAL WEEKDAY(processed_at) DAY), INTERVAL 6 DAY),
                '%M %d, %Y'
            )
        )";
        break;
    case 'monthly':
        $group_by_sql = "DATE_FORMAT(processed_at, '%Y-%m')";
        $date_format = "DATE_FORMAT(processed_at, '%M %Y')";
        break;
    case 'daily':
        $group_by_sql = "DATE(processed_at)";
        $date_format = "DATE_FORMAT(processed_at, '%M %d, %Y')";
        break;
    default:
        // For custom range, decide grouping based on date range
        $days_difference = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24);
        if ($days_difference > 90) {
            $group_by_sql = "DATE_FORMAT(processed_at, '%Y-%m')";
            $date_format = "DATE_FORMAT(processed_at, '%M %Y')";
        } elseif ($days_difference > 31) {
            $group_by_sql = "YEARWEEK(processed_at)";
            $date_format = "CONCAT(
                DATE_FORMAT(
                    DATE_SUB(processed_at, INTERVAL WEEKDAY(processed_at) DAY),
                    '%M %d'
                ),
                ' - ',
                DATE_FORMAT(
                    DATE_ADD(DATE_SUB(processed_at, INTERVAL WEEKDAY(processed_at) DAY), INTERVAL 6 DAY),
                    '%M %d, %Y'
                )
            )";
        } else {
            $group_by_sql = "DATE(processed_at)";
            $date_format = "DATE_FORMAT(processed_at, '%M %d, %Y')";
        }
}

// Fetch sales data
$query = "SELECT 
            {$date_format} as date_label,
            MIN(processed_at) as period_start,
            MAX(processed_at) as period_end,
            COUNT(*) as order_count,
            SUM(total_price) as total_sales,
            AVG(total_price) as average_sale,
            SUM(subtotal_price) as subtotal_sales
          FROM orders 
          WHERE processed_at BETWEEN ? AND ?
          GROUP BY {$group_by_sql}
          ORDER BY period_start ASC";

$stmt = $conn->prepare($query);
$stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
$sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_orders = 0;
$total_sales = 0;
$total_subtotal = 0;
$labels = [];
$sales_values = [];
$order_counts = [];

foreach ($sales_data as $row) {
    $total_orders += $row['order_count'];
    $total_sales += $row['total_sales'];
    $total_subtotal += $row['subtotal_sales'];
    $labels[] = $row['date_label'];
    $sales_values[] = round($row['total_sales'], 2);
    $order_counts[] = $row['order_count'];
}

// Debug output for admin
if (isset($_GET['debug']) && $_GET['debug'] == 1) {
    echo '<pre>';
    print_r($sales_data);
    echo '</pre>';
}
?>

<!-- Begin Page Content -->
<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Heading -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Sales Report</h1>
            <div>
                <button class="btn btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-2"></i>Export to Excel
                </button>
            </div>
        </div>

        <!-- Dashboard Cards -->
        <div class="row">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Orders</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_orders); ?></div>
                                <div class="text-xs text-muted mt-1">For selected period</div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Sales</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">RM <?php echo number_format($total_sales, 2); ?></div>
                                <div class="text-xs text-muted mt-1">Gross revenue</div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Average Order Value</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">RM <?php echo number_format($total_sales / $total_orders, 2); ?></div>
                                <div class="text-xs text-muted mt-1">Per order average</div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Daily Average</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">RM <?php 
                                    $days = ceil((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1;
                                    echo number_format($total_sales / $days, 2); 
                                ?></div>
                                <div class="text-xs text-muted mt-1">Sales per day</div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-calendar fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card shadow mb-4">
            <div class="card-body">
                <form id="reportForm" method="GET" class="row align-items-end">
                    <div class="col-md-3 mb-3">
                        <label for="start_date">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" 
                               value="<?php echo $start_date; ?>" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="end_date">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" 
                               value="<?php echo $end_date; ?>" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="group_by">Group By</label>
                        <select class="form-control" id="group_by" name="group_by">
                            <option value="default" <?php echo $group_by == 'default' ? 'selected' : ''; ?>>Auto (Based on Date Range)</option>
                            <option value="daily" <?php echo $group_by == 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $group_by == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $group_by == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Generate Report
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sales Chart -->
        <div class="row">
            <div class="col-12">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">Sales Overview</h6>
                        <div class="dropdown no-arrow">
                            <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in" aria-labelledby="dropdownMenuLink">
                                <div class="dropdown-header">Export Options:</div>
                                <a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-print fa-sm fa-fw mr-2 text-gray-400"></i>Print Report</a>
                                <a class="dropdown-item" href="#" onclick="exportToExcel()"><i class="fas fa-file-excel fa-sm fa-fw mr-2 text-gray-400"></i>Export to Excel</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-area" style="height: 400px;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary">Detailed Sales Report</h6>
                <div class="dropdown no-arrow">
                    <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in" aria-labelledby="dropdownMenuLink">
                        <div class="dropdown-header">Export Options:</div>
                        <a class="dropdown-item" href="#" onclick="exportToExcel()"><i class="fas fa-file-excel fa-sm fa-fw mr-2 text-gray-400"></i>Export to Excel</a>
                        <a class="dropdown-item" href="#" onclick="toggleFullscreen(this)"><i class="fas fa-expand fa-sm fa-fw mr-2 text-gray-400"></i>Toggle Fullscreen</a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="salesTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Orders</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_data as $row): ?>
                            <tr>
                                <td><?php echo $row['date_label']; ?></td>
                                <td class="text-end"><?php echo number_format($row['order_count']); ?></td>
                                <td class="text-end">RM <?php echo number_format($row['subtotal_sales'], 2); ?></td>
                                <td class="text-end">RM <?php echo number_format($row['total_sales'], 2); ?></td>
                                <td class="text-end">RM <?php echo number_format($row['average_sale'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Totals</th>
                                <th class="text-end"><?php echo number_format($total_orders); ?></th>
                                <th class="text-end">RM <?php echo number_format($total_subtotal, 2); ?></th>
                                <th class="text-end">RM <?php echo number_format($total_sales, 2); ?></th>
                                <th class="text-end">RM <?php echo number_format($total_sales / $total_orders, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page level plugins -->
<script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.24/js/dataTables.bootstrap4.min.js"></script>

<!-- DataTables CSS -->
<link href="https://cdn.datatables.net/1.10.24/css/dataTables.bootstrap4.min.css" rel="stylesheet">

<script>
$(document).ready(function() {
    // Number formatting function
    function number_format(number, decimals = 2) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    $('#salesTable').DataTable({
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        responsive: true,
        language: {
            lengthMenu: "Show _MENU_ entries per page",
            zeroRecords: "No matching records found",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                previous: '<i class="fas fa-angle-left"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                last: '<i class="fas fa-angle-double-right"></i>'
            }
        },
        columnDefs: [
            {
                targets: [1, 2, 3, 4],
                className: 'text-end'
            }
        ],
        footerCallback: function(row, data, start, end, display) {
            var api = this.api();
            
            // Remove the formatting to get numeric data for summation
            var intVal = function(i) {
                if (typeof i === 'string') {
                    return i.replace(/[\$,RM ]/g, '') * 1;
                }
                if (typeof i === 'number') {
                    return i;
                }
                return 0;
            };

            // Calculate page totals
            var pageTotalOrders = api
                .column(1, { page: 'current'})
                .data()
                .reduce(function(a, b) {
                    return intVal(a) + intVal(b);
                }, 0);

            var pageTotalSubtotal = api
                .column(2, { page: 'current'})
                .data()
                .reduce(function(a, b) {
                    return intVal(a) + intVal(b);
                }, 0);

            var pageTotalSales = api
                .column(3, { page: 'current'})
                .data()
                .reduce(function(a, b) {
                    return intVal(a) + intVal(b);
                }, 0);

            // Calculate grand totals
            var grandTotalOrders = api
                .column(1)
                .data()
                .reduce(function(a, b) {
                    return intVal(a) + intVal(b);
                }, 0);

            var grandTotalSubtotal = api
                .column(2)
                .data()
                .reduce(function(a, b) {
                    return intVal(a) + intVal(b);
                }, 0);

            var grandTotalSales = api
                .column(3)
                .data()
                .reduce(function(a, b) {
                    return intVal(a) + intVal(b);
                }, 0);

            // Update footer with page totals and grand totals
            $(api.column(1).footer()).html(
                number_format(pageTotalOrders) + '<br/>' +
                '<small class="text-muted">Total: ' + number_format(grandTotalOrders) + '</small>'
            );
            $(api.column(2).footer()).html(
                'RM ' + number_format(pageTotalSubtotal, 2) + '<br/>' +
                '<small class="text-muted">Total: RM ' + number_format(grandTotalSubtotal, 2) + '</small>'
            );
            $(api.column(3).footer()).html(
                'RM ' + number_format(pageTotalSales, 2) + '<br/>' +
                '<small class="text-muted">Total: RM ' + number_format(grandTotalSales, 2) + '</small>'
            );
            $(api.column(4).footer()).html(
                'RM ' + number_format(pageTotalSales / pageTotalOrders, 2) + '<br/>' +
                '<small class="text-muted">Avg: RM ' + number_format(grandTotalSales / grandTotalOrders, 2) + '</small>'
            );
        }
    });
});
</script>

<style>
.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.25rem 0.5rem;
    margin-left: 2px;
    border-radius: 0.2rem;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #4e73df;
    border-color: #4e73df;
    color: white !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #2e59d9;
    border-color: #2e59d9;
    color: white !important;
}
.dataTables_wrapper .dataTables_length select {
    padding: 0.375rem 1.75rem 0.375rem 0.75rem;
    border-radius: 0.2rem;
}
.dataTables_wrapper .dataTables_filter input {
    padding: 0.375rem 0.75rem;
    border-radius: 0.2rem;
    border: 1px solid #d1d3e2;
}
.dataTables_info {
    padding-top: 0.5rem;
}
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function number_format(number, decimals = 2) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    // Area Chart
    var ctx = document.getElementById('salesChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: "Sales (RM)",
                    lineTension: 0.3,
                    backgroundColor: "rgba(78, 115, 223, 0.05)",
                    borderColor: "rgba(78, 115, 223, 1)",
                    pointRadius: 3,
                    pointBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointBorderColor: "rgba(78, 115, 223, 1)",
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointHoverBorderColor: "rgba(78, 115, 223, 1)",
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    yAxisID: 'y-axis-sales',
                    data: <?php echo json_encode(array_map('floatval', $sales_values)); ?>
                },
                {
                    label: "Orders",
                    lineTension: 0.3,
                    backgroundColor: "rgba(28, 200, 138, 0.05)",
                    borderColor: "rgba(28, 200, 138, 1)",
                    pointRadius: 3,
                    pointBackgroundColor: "rgba(28, 200, 138, 1)",
                    pointBorderColor: "rgba(28, 200, 138, 1)",
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: "rgba(28, 200, 138, 1)",
                    pointHoverBorderColor: "rgba(28, 200, 138, 1)",
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    yAxisID: 'y-axis-orders',
                    data: <?php echo json_encode($order_counts); ?>
                }]
            },
            options: {
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        left: 10,
                        right: 25,
                        top: 25,
                        bottom: 0
                    }
                },
                scales: {
                    xAxes: [{
                        time: {
                            unit: 'date'
                        },
                        gridLines: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            maxTicksLimit: 7,
                            fontFamily: "'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"
                        }
                    }],
                    yAxes: [{
                        id: 'y-axis-sales',
                        position: 'left',
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 10,
                            fontFamily: "'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                            callback: function(value) {
                                return 'RM ' + number_format(value);
                            }
                        },
                        gridLines: {
                            color: "rgb(234, 236, 244)",
                            zeroLineColor: "rgb(234, 236, 244)",
                            drawBorder: false,
                            borderDash: [2],
                            zeroLineBorderDash: [2]
                        }
                    },
                    {
                        id: 'y-axis-orders',
                        position: 'right',
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 10,
                            fontFamily: "'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                            callback: function(value) {
                                return Math.round(value) + ' orders';
                            }
                        },
                        gridLines: {
                            display: false
                        }
                    }]
                },
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltips: {
                    backgroundColor: "rgb(255,255,255)",
                    bodyFontColor: "#858796",
                    titleMarginBottom: 10,
                    titleFontColor: '#6e707e',
                    titleFontSize: 14,
                    bodyFontFamily: "'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    xPadding: 15,
                    yPadding: 15,
                    displayColors: true,
                    intersect: false,
                    mode: 'index',
                    caretPadding: 10,
                    callbacks: {
                        label: function(tooltipItem, chart) {
                            var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                            var value = tooltipItem.yLabel;
                            if (datasetLabel === "Sales (RM)") {
                                return datasetLabel + ': RM ' + number_format(value);
                            } else {
                                return datasetLabel + ': ' + Math.round(value);
                            }
                        }
                    }
                }
            }
        });
    }
});

function toggleFullscreen(el) {
    var element = document.querySelector('.card-body');
    if (!document.fullscreenElement) {
        element.requestFullscreen().catch(err => {
            console.log('Error attempting to enable fullscreen:', err);
        });
        el.innerHTML = '<i class="fas fa-compress fa-sm fa-fw mr-2 text-gray-400"></i>Exit Fullscreen';
    } else {
        document.exitFullscreen();
        el.innerHTML = '<i class="fas fa-expand fa-sm fa-fw mr-2 text-gray-400"></i>Toggle Fullscreen';
    }
}

function exportToExcel() {
    var table = document.getElementById("salesTable");
    var html = table.outerHTML;
    var url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'sales_report.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php include 'includes/footer.php'; ?>
