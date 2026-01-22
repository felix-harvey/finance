<?php
declare(strict_types=1);
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', "1");

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Load current user
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    if (!$user) {
        header("Location: index.php");
        exit;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Initialize data arrays
$report_data = [];
$collection_summary = [];
$payment_methods = [];
$top_performers = [];
$aging_analysis = [];

// Handle report filtering
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
$report_type = $_GET['report_type'] ?? 'summary';

try {
    // Collection Summary Report
    $summary_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_payments,
            SUM(amount) as total_collected,
            AVG(amount) as average_payment,
            MAX(amount) as largest_payment,
            MIN(amount) as smallest_payment,
            COUNT(DISTINCT contact_id) as unique_customers,
            SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN amount ELSE 0 END) as weekly_collection,
            SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as monthly_collection
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date BETWEEN ? AND ?
    ");
    $summary_stmt->execute([$start_date, $end_date]);
    $collection_summary = $summary_stmt->fetch();

    // Payment Methods Breakdown
    $methods_stmt = $db->prepare("
        SELECT 
            payment_method,
            COUNT(*) as payment_count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date BETWEEN ? AND ?
        GROUP BY payment_method 
        ORDER BY total_amount DESC
    ");
    $methods_stmt->execute([$start_date, $end_date]);
    $payment_methods = $methods_stmt->fetchAll();

    // Top Performing Customers
    $top_customers_stmt = $db->prepare("
        SELECT 
            c.name as customer_name,
            COUNT(p.id) as payment_count,
            SUM(p.amount) as total_paid,
            AVG(p.amount) as average_payment,
            MAX(p.payment_date) as last_payment_date
        FROM payments p 
        JOIN contacts c ON p.contact_id = c.id 
        WHERE p.status = 'Completed' 
        AND p.type = 'Receive'
        AND p.payment_date BETWEEN ? AND ?
        GROUP BY c.id, c.name 
        ORDER BY total_paid DESC 
        LIMIT 10
    ");
    $top_customers_stmt->execute([$start_date, $end_date]);
    $top_performers = $top_customers_stmt->fetchAll();

    // Aging Analysis
    $aging_stmt = $db->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN amount ELSE 0 END) as current_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END) as overdue_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END) as overdue_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END) as overdue_90_plus,
            COUNT(*) as total_invoices,
            SUM(amount) as total_outstanding
        FROM invoices 
        WHERE status IN ('Pending', 'Overdue')
    ");
    $aging_analysis = $aging_stmt->fetch();

    // Daily Collection Trend (for charts)
    $daily_trend_stmt = $db->prepare("
        SELECT 
            DATE(payment_date) as collection_date,
            COUNT(*) as payment_count,
            SUM(amount) as daily_total
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY collection_date
    ");
    $daily_trend_stmt->execute([$start_date, $end_date]);
    $daily_trend = $daily_trend_stmt->fetchAll();

    // Monthly Comparison
    $monthly_comparison_stmt = $db->query("
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month_year,
            COUNT(*) as payment_count,
            SUM(amount) as monthly_total
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month_year
    ");
    $monthly_comparison = $monthly_comparison_stmt->fetchAll();

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    // Use empty arrays if database fetch fails
}

// Function to get notifications
function getNotifications(PDO $db, int $user_id): array {
    try {
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL) 
            AND (is_read = 0 OR is_read IS NULL)
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Notifications error: " . $e->getMessage());
        return [];
    }
}

// Fetch notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, fn($n) => empty($n['is_read']));

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

// Safe output function
function safe_output($value, $default = '') {
    if ($value === null) {
        return $default;
    }
    return htmlspecialchars((string)$value);
}

// Function to mask numbers with asterisks
function maskNumber($number, $masked = true) {
    if (!$masked) {
        return number_format($number, 2);
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part
    return str_repeat('*', strlen($integerPart));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Reports | Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary-green': '#28644c',
                        'sidebar-green': '#2f855A',
                        'white': '#ffffff',
                        'gray-bg': '#f3f4f6',
                        'notification-red': '#ef4444',
                        'hover-state': 'rgba(255, 255, 255, 0.3)',
                        'dark-text': '#1f2937',
                    }
                }
            }
        }
    </script>
    <style>
        .hamburger-line {
            width: 24px;
            height: 3px;
            background-color: #FFFFFF;
            margin: 4px 0;
            transition: all 0.3s;
        }
        .sidebar-item.active {
            background-color: rgba(255, 255, 255, 0.2);
            border-left: 4px solid white;
        }
        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .card-shadow {
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
        }
        .status-badge {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 9999px;
        }
        .status-completed {
            background-color: rgba(104, 211, 145, 0.1);
            color: #68D391;
        }
        .status-pending {
            background-color: rgba(251, 191, 36, 0.1);
            color: #F59E0B;
        }
        .status-overdue {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
        }
        .status-processing {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3B82F6;
        }
        #sidebar {
            transition: transform 0.3s ease-in-out;
            background-color: #2f855A;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            z-index: 40;
        }
        
        #hamburger-btn {
            display: block !important;
        }
        
        @media (max-width: 768px) {
            #sidebar {
                transform: translateX(-100%);
                position: fixed;
                height: 100%;
                min-height: 100vh;
            }
            #sidebar.active {
                transform: translateX(0);
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 30;
            }
            .overlay.active {
                display: block;
            }
        }
        
        @media (min-width: 769px) {
            #sidebar {
                transform: translateX(0);
            }
            #sidebar.hidden {
                transform: translateX(-100%);
                position: fixed;
            }
            .overlay {
                display: none;
            }
            #main-content.full-width {
                margin-left: 0;
                width: 100%;
            }
        }
        
        .sidebar-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #2f855A;
        }
        
        .main-footer {
            background-color: #28644c;
            color: white;
            padding: 1.5rem;
            margin-top: auto;
        }

        html, body {
            height: 100%;
        }
        
        .page-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar-content {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .submenu.active {
            max-height: 500px;
        }
        
        .submenu-item {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            display: block;
            text-decoration: none;
        }
        
        .submenu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .submenu-item.active {
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
        }
        
        .rotate-180 {
            transform: rotate(180deg);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
            position: relative;
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 10px;
        }
        
        .close-modal:hover {
            color: black;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #2f855A;
            box-shadow: 0 0 0 3px rgba(47, 133, 90, 0.2);
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background-color: #2f855A;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #28644c;
        }
        
        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background-color: #d1d5db;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th, .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background-color: #f9fafb;
            font-weight: 500;
            color: #374151;
        }
        
        .action-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            cursor: pointer;
            border: 1px solid;
            background: white;
        }
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #3B82F6;
            border-color: #3B82F6;
        }
        
        .action-btn.download {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }
        
        .metric-card {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .report-filter {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }
        
        .tab-container {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            color: #6b7280;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: #2f855A;
        }
        
        .tab.active {
            border-bottom-color: #2f855A;
            color: #2f855A;
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .stat-card {
            text-align: center;
            padding: 1.5rem;
            border-radius: 0.5rem;
            background: white;
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .trend-up {
            color: #10B981;
        }
        
        .trend-down {
            color: #EF4444;
        }

        /* New styles for number masking */
        .amount-masked {
            font-family: monospace;
            letter-spacing: 2px;
        }
        
        .toggle-visibility-btn {
            background-color: #2f855A;
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }
        
        .toggle-visibility-btn:hover {
            background-color: #28644c;
        }

        /* Ensure buttons are clickable */
        button, .btn, .action-btn, .tab {
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        /* Prevent modal from blocking content when hidden */
        .modal[style*="display: none"] {
            z-index: -1;
        }
        
        /* Quick hover effects for charts and cards */
    .bg-white.rounded-xl.p-6.card-shadow {
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .bg-white.rounded-xl.p-6.card-shadow:hover {
        transform: translateY(-5px);
        box-shadow: 0px 10px 30px rgba(0,0,0,0.15);
    }

    /* Apply same effect to metric cards */
    .metric-card {
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .metric-card:hover {
        transform: translateY(-5px);
        box-shadow: 0px 10px 30px rgba(0,0,0,0.15);
    }
        
    </style>
</head>
<body class="bg-gray-bg">
    <!-- Overlay for mobile sidebar -->
    <div class="overlay" id="overlay"></div>
    
    <!-- Modal for user profile -->
    <div id="profile-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">User Profile</h2>
            <div class="flex items-center mb-6">
                <i class="fa-solid fa-user text-[40px] bg-primary-green text-white px-3 py-3 rounded-full"></i>
                <div class="ml-4">
                    <h3 class="text-lg font-bold" id="profile-name"><?= safe_output($user['name']) ?></h3>
                    <p class="text-gray-500"><?= ucfirst(safe_output($user['role'])) ?></p>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <h4 class="font-medium mb-2">Account Settings</h4>
                    <button class="btn btn-secondary w-full mb-2">Edit Profile</button>
                    <button class="btn btn-secondary w-full mb-2">Change Password</button>
                </div>
                <div>
                    <h4 class="font-medium mb-2">System</h4>
                    <button class="btn btn-secondary w-full mb-2">Preferences</button>
                    <button class="btn btn-secondary w-full" id="logout-btn">Logout</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for notifications -->
    <div id="notification-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Notifications</h2>
            <div id="notification-list">
                <div class="text-center text-gray-500 py-4">Loading notifications...</div>
            </div>
        </div>
    </div>

    <!-- Page Container -->
    <div class="page-container">
        <!-- Sidebar -->
        <div id="sidebar" class="w-64 flex flex-col fixed md:relative">
            <div class="sidebar-content">
                <div class="p-6 bg-sidebar-green">
                    <div class="flex justify-between items-center">
                        <h1 class="text-xl font-bold text-white flex items-center">
                            <i class='bx bx-wallet-alt text-white mr-2'></i>
                            Dashboard
                        </h1>
                        <button id="close-sidebar" class="text-white">
                            <i class='bx bx-x text-2xl'></i>
                        </button>
                    </div>
                    <p class="text-xs text-white/90 mt-1">Microfinancial Management System</p>
                </div>
                
                <!-- Navigation -->
                <div class="flex-1 overflow-y-auto px-2 py-4">
                    <div class="space-y-4">
                        <!-- Main Menu Item -->
                        <a href="dashboard8.php" class="sidebar-item py-3 px-4 rounded-lg cursor-pointer mx-2 flex items-center hover:bg-hover-state transition-colors duration-200">
                            <i class='bx bx-home text-white mr-3 text-lg'></i>
                            <span class="text-sm font-medium text-white">FINANCIAL</span>
                        </a>
                     
                        <!-- Disbursement Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="disbursement">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Disbursement</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="disbursement"></i>
                            </div>
                            <div class="submenu mt-1" id="disbursement-submenu">
                                <a href="disbursement_request.php" class="submenu-item transition-colors duration-200">Disbursement History</a>
                                <a href="pending_disbursements.php" class="submenu-item transition-colors duration-200">Pending Disbursements</a>

                                <a href="disbursement_reports.php" class="submenu-item transition-colors duration-200">Disbursement Reports</a>
                            </div>
                        </div>
                        
                        <!-- General Ledger Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ledger">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">General Ledger</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ledger"></i>
                            </div>
                            <div class="submenu mt-1" id="ledger-submenu">
                                <a href="chart_of_accounts.php" class="submenu-item transition-colors duration-200">Chart of Accounts</a>
                                <a href="journal_entry.php" class="submenu-item transition-colors duration-200">Journal Entry</a>
                                <a href="ledger_table.php" class="submenu-item transition-colors duration-200">Ledger Table</a>

                            </div>
                        </div>
                        
                        <!-- AP/AR Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ap-ar">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">AP/AR</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ap-ar"></i>
                            </div>
                            <div class="submenu mt-1" id="ap-ar-submenu">
                                <a href="vendors_customers.php" class="submenu-item transition-colors duration-200">Payable/Receivable</a>
                                <a href="invoices.php" class="submenu-item transition-colors duration-200">Invoices</a>
                                <a href="payment_entry.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="aging_reports.php" class="submenu-item transition-colors duration-200">Aging Reports</a>
                            </div>
                        </div>
                   
                        <!-- Collection Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="collection">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Collection</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="collection"></i>
                            </div>
                            <div class="submenu mt-1 active" id="collection-submenu">
                                <a href="payment_entry_collection.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="receipt_generation.php" class="submenu-item transition-colors duration-200">Receipt Generation</a>
                                <a href="collection_dashboard.php" class="submenu-item transition-colors duration-200">Collection Dashboard</a>
                                <a href="outstanding_balances.php" class="submenu-item transition-colors duration-200">Outstanding Balances</a>
                                <a href="collection_reports.php" class="submenu-item active transition-colors duration-200">Collection Reports</a>
                            </div>
                        </div>

                    <!-- Budget Section -->
        <div class="py-1 mx-2">
            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="budget">
                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Budget Management</h3>
                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="budget"></i>
            </div>
            <div class="submenu mt-1" id="budget-submenu">
                <a href="budget_proposal.php" class="submenu-item transition-colors duration-200">Budget Proposal</a>
                
                
                <a href="budget_reports.php" class="submenu-item transition-colors duration-200">Budget Reports</a>
            </div>
        </div>
    </div>
</div>
                
                <!-- Footer inside sidebar -->
                <div class="p-4 text-center text-xs text-white/80 border-t border-white/10 mt-auto">
                    <p>© 2025 Financial Dashboard. All rights reserved.</p>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div id="main-content" class="flex-1 overflow-y-auto flex flex-col">
            <!-- Header -->
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Collection Reports</h1>
                        <p class="text-sm text-white/90">Comprehensive collection performance analysis and reporting</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <!-- Add the visibility toggle button here -->
                    <button class="toggle-visibility-btn" id="toggle-visibility" title="Toggle number visibility">
                        <i class="fa-solid fa-eye-slash" id="visibility-icon"></i>
                    </button>
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center hidden" id="notification-badge">3</span>
                    </button>
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200 hover:bg-green-600 rounded">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?= safe_output($user['name']) ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Report Filters -->
                <div class="report-filter">
                    <h3 class="text-lg font-semibold mb-4">Report Parameters</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-input" value="<?= $start_date ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-input" value="<?= $end_date ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Report Type</label>
                            <select name="report_type" class="form-input">
                                <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary Report</option>
                                <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed Report</option>
                                <option value="aging" <?= $report_type === 'aging' ? 'selected' : '' ?>>Aging Analysis</option>
                            </select>
                        </div>
                        <div class="form-group flex items-end">
                            <button type="submit" class="btn btn-primary w-full">
                                <i class="fa-solid fa-filter mr-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Report Period Summary -->
                <div class="metric-card mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Report Period: <?= date('F j, Y', strtotime($start_date)) ?> - <?= date('F j, Y', strtotime($end_date)) ?></h3>
                        <div class="flex space-x-2">
                            <button class="btn btn-secondary" onclick="printReport()">
                                <i class="fa-solid fa-print mr-2"></i>Print
                            </button>
                            <button class="btn btn-secondary" onclick="exportToPDF()">
                                <i class="fa-solid fa-file-pdf mr-2"></i>Export PDF
                            </button>
                            <button class="btn btn-secondary" onclick="exportToExcel()">
                                <i class="fa-solid fa-file-excel mr-2"></i>Export Excel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabs for different report views -->
                <div class="tab-container">
                    <div class="tab active" data-tab="summary">Summary</div>
                    <div class="tab" data-tab="performance">Performance</div>
                    <div class="tab" data-tab="aging">Aging Analysis</div>
                    <div class="tab" data-tab="methods">Payment Methods</div>
                </div>

                <!-- Summary Tab -->
                <div class="tab-content active" id="summary-tab">
                    <!-- Key Metrics -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        <div class="stat-card">
                            <div class="stat-value text-primary-green amount-masked" data-amount="<?= $collection_summary['total_collected'] ?? 0 ?>">
                                ₱<?= maskNumber($collection_summary['total_collected'] ?? 0) ?>
                            </div>
                            <div class="stat-label">Total Collected</div>
                            <div class="text-sm trend-up">+12% from previous period</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value text-blue-600"><?= $collection_summary['total_payments'] ?? 0 ?></div>
                            <div class="stat-label">Total Payments</div>
                            <div class="text-sm trend-up">+8% from previous period</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value text-purple-600"><?= $collection_summary['unique_customers'] ?? 0 ?></div>
                            <div class="stat-label">Active Customers</div>
                            <div class="text-sm trend-up">+5% from previous period</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value text-green-600 amount-masked" data-amount="<?= $collection_summary['average_payment'] ?? 0 ?>">
                                ₱<?= maskNumber($collection_summary['average_payment'] ?? 0) ?>
                            </div>
                            <div class="stat-label">Average Payment</div>
                            <div class="text-sm trend-up">+3% from previous period</div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Collection Trend Chart -->
                        <div class="metric-card">
                            <h3 class="text-lg font-semibold mb-4">Collection Trend</h3>
                            <div class="chart-container">
                                <canvas id="collectionTrendChart"></canvas>
                            </div>
                        </div>

                        <!-- Payment Methods Chart -->
                        <div class="metric-card">
                            <h3 class="text-lg font-semibold mb-4">Payment Methods Distribution</h3>
                            <div class="chart-container">
                                <canvas id="paymentMethodsChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Comparison -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Monthly Collection Comparison (Last 6 Months)</h3>
                        <div class="chart-container">
                            <canvas id="monthlyComparisonChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Performance Tab -->
                <div class="tab-content" id="performance-tab">
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Top Performing Customers</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Total Paid</th>
                                        <th>Payment Count</th>
                                        <th>Average Payment</th>
                                        <th>Last Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_performers)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-gray-500">No payment data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($top_performers as $customer): ?>
                                            <tr>
                                                <td class="font-medium"><?= safe_output($customer['customer_name']) ?></td>
                                                <td class="font-semibold text-green-600 amount-masked" data-amount="<?= $customer['total_paid'] ?>">
                                                    ₱<?= maskNumber($customer['total_paid']) ?>
                                                </td>
                                                <td><?= $customer['payment_count'] ?></td>
                                                <td class="amount-masked" data-amount="<?= $customer['average_payment'] ?>">
                                                    ₱<?= maskNumber($customer['average_payment']) ?>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($customer['last_payment_date'])) ?></td>
                                                <td>
                                                    <button class="action-btn view">
                                                        <i class="fa-solid fa-chart-line mr-1"></i>Details
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Aging Analysis Tab -->
                <div class="tab-content" id="aging-tab">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Aging Summary -->
                        <div class="metric-card">
                            <h3 class="text-lg font-semibold mb-4">Aging Analysis Summary</h3>
                            <div class="space-y-4">
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-green-600 font-semibold">Current (0-30 days)</span>
                                        <span class="font-semibold amount-masked" data-amount="<?= $aging_analysis['current_0_30'] ?? 0 ?>">
                                            ₱<?= maskNumber($aging_analysis['current_0_30'] ?? 0) ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill bg-green-500" style="width: <?= ($aging_analysis['total_outstanding'] > 0) ? ($aging_analysis['current_0_30'] / $aging_analysis['total_outstanding'] * 100) : 0 ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-yellow-600 font-semibold">31-60 Days</span>
                                        <span class="font-semibold amount-masked" data-amount="<?= $aging_analysis['overdue_31_60'] ?? 0 ?>">
                                            ₱<?= maskNumber($aging_analysis['overdue_31_60'] ?? 0) ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill bg-yellow-500" style="width: <?= ($aging_analysis['total_outstanding'] > 0) ? ($aging_analysis['overdue_31_60'] / $aging_analysis['total_outstanding'] * 100) : 0 ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-orange-600 font-semibold">61-90 Days</span>
                                        <span class="font-semibold amount-masked" data-amount="<?= $aging_analysis['overdue_61_90'] ?? 0 ?>">
                                            ₱<?= maskNumber($aging_analysis['overdue_61_90'] ?? 0) ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill bg-orange-500" style="width: <?= ($aging_analysis['total_outstanding'] > 0) ? ($aging_analysis['overdue_61_90'] / $aging_analysis['total_outstanding'] * 100) : 0 ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-red-600 font-semibold">90+ Days</span>
                                        <span class="font-semibold amount-masked" data-amount="<?= $aging_analysis['overdue_90_plus'] ?? 0 ?>">
                                            ₱<?= maskNumber($aging_analysis['overdue_90_plus'] ?? 0) ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill bg-red-500" style="width: <?= ($aging_analysis['total_outstanding'] > 0) ? ($aging_analysis['overdue_90_plus'] / $aging_analysis['total_outstanding'] * 100) : 0 ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                                <div class="flex justify-between items-center">
                                    <span class="font-semibold">Total Outstanding:</span>
                                    <span class="text-xl font-bold text-red-600 amount-masked" data-amount="<?= $aging_analysis['total_outstanding'] ?? 0 ?>">
                                        ₱<?= maskNumber($aging_analysis['total_outstanding'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-sm text-gray-600">Total Invoices:</span>
                                    <span class="text-sm font-semibold"><?= $aging_analysis['total_invoices'] ?? 0 ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Aging Chart -->
                        <div class="metric-card">
                            <h3 class="text-lg font-semibold mb-4">Aging Distribution</h3>
                            <div class="chart-container">
                                <canvas id="agingChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Tab -->
                <div class="tab-content" id="methods-tab">
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Payment Methods Analysis</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Payment Method</th>
                                        <th>Payment Count</th>
                                        <th>Total Amount</th>
                                        <th>Average Amount</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($payment_methods)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-gray-500">No payment method data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $total_amount = $collection_summary['total_collected'] ?? 1; // Avoid division by zero
                                        foreach ($payment_methods as $method): 
                                            $percentage = ($method['total_amount'] / $total_amount) * 100;
                                        ?>
                                            <tr>
                                                <td class="font-medium"><?= safe_output($method['payment_method']) ?></td>
                                                <td><?= $method['payment_count'] ?></td>
                                                <td class="font-semibold amount-masked" data-amount="<?= $method['total_amount'] ?>">
                                                    ₱<?= maskNumber($method['total_amount']) ?>
                                                </td>
                                                <td class="amount-masked" data-amount="<?= $method['average_amount'] ?>">
                                                    ₱<?= maskNumber($method['average_amount']) ?>
                                                </td>
                                                <td>
                                                    <div class="flex items-center">
                                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                                            <div class="bg-primary-green h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                                        </div>
                                                        <span class="text-sm"><?= number_format($percentage, 1) ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <footer class="main-footer">
                <div class="text-center">
                    <p class="text-sm">© 2025 Financial Dashboard. All rights reserved.</p>
                    <p class="text-xs mt-1 opacity-80">Powered by Microfinancial Management System</p>
                </div>
            </footer>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded - initializing event listeners');
        
        // Number masking functionality
        let numbersVisible = false;
        const toggleBtn = document.getElementById('toggle-visibility');
        const visibilityIcon = document.getElementById('visibility-icon');
        
        if (toggleBtn && visibilityIcon) {
            toggleBtn.addEventListener('click', function() {
                numbersVisible = !numbersVisible;
                const maskedElements = document.querySelectorAll('.amount-masked');
                
                maskedElements.forEach(element => {
                    const amount = element.getAttribute('data-amount');
                    if (numbersVisible) {
                        // Show actual numbers
                        element.textContent = '₱' + parseFloat(amount || 0).toLocaleString('en-PH', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    } else {
                        // Show masked numbers
                        const numberStr = (amount || '0').toString();
                        const parts = numberStr.split('.');
                        const integerPart = parts[0];
                        element.textContent = '₱' + '*'.repeat(integerPart.length);
                    }
                });
                
                // Update icon
                if (numbersVisible) {
                    visibilityIcon.className = 'fa-solid fa-eye';
                } else {
                    visibilityIcon.className = 'fa-solid fa-eye-slash';
                }
            });
        }

        // Sidebar functionality
        const hamburgerBtn = document.getElementById('hamburger-btn');
        const closeSidebar = document.getElementById('close-sidebar');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        const mainContent = document.getElementById('main-content');

        function toggleSidebar() {
            console.log('Toggle sidebar clicked');
            if (window.innerWidth < 769) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            } else {
                sidebar.classList.toggle('hidden');
                mainContent.classList.toggle('full-width');
            }
        }

        if (hamburgerBtn) {
            hamburgerBtn.addEventListener('click', toggleSidebar);
            console.log('Hamburger button event listener attached');
        } else {
            console.log('Hamburger button not found');
        }

        if (closeSidebar) {
            closeSidebar.addEventListener('click', function() {
                console.log('Close sidebar clicked');
                if (window.innerWidth < 769) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                } else {
                    sidebar.classList.add('hidden');
                    mainContent.classList.add('full-width');
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function() {
                console.log('Overlay clicked');
                if (window.innerWidth < 769) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                }
            });
        }

        // Sidebar submenu functionality
        const categoryToggles = document.querySelectorAll('.sidebar-category');
        console.log('Found', categoryToggles.length, 'sidebar categories');
        
        categoryToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                console.log('Sidebar category clicked:', this.getAttribute('data-category'));
                const category = this.getAttribute('data-category');
                const submenu = document.getElementById(`${category}-submenu`);
                const arrow = document.querySelector(`.category-arrow[data-category="${category}"]`);
                
                if (submenu) {
                    submenu.classList.toggle('active');
                }
                if (arrow) {
                    arrow.classList.toggle('rotate-180');
                }
            });
        });

        // Modal functionality
        const notificationBtn = document.getElementById('notification-btn');
        const notificationModal = document.getElementById('notification-modal');
        const profileBtn = document.getElementById('profile-btn');
        const profileModal = document.getElementById('profile-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        const logoutBtn = document.getElementById('logout-btn');
        
        console.log('Notification button:', notificationBtn);
        console.log('Profile button:', profileBtn);
        
        if (notificationBtn && notificationModal) {
            notificationBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Notification button clicked');
                notificationModal.style.display = 'block';
                loadNotifications();
            });
        }
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Profile button clicked');
                profileModal.style.display = 'block';
            });
        }
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                console.log('Close modal clicked');
                if (notificationModal) {
                    notificationModal.style.display = 'none';
                }
                if (profileModal) {
                    profileModal.style.display = 'none';
                }
            });
        });
        
        window.addEventListener('click', function(event) {
            if (event.target === notificationModal) {
                notificationModal.style.display = 'none';
            }
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
            }
        });

        // Function to load notifications
        function loadNotifications() {
            const notifications = <?php echo json_encode($notifications ?? []); ?>;
            const notificationList = document.getElementById('notification-list');
            const notificationBadge = document.getElementById('notification-badge');
            
            console.log('Loading notifications:', notifications);
            
            // Update notification badge
            if (notificationBadge) {
                const unreadCount = <?php echo count($unread_notifications ?? []); ?>;
                if (unreadCount > 0) {
                    notificationBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                    notificationBadge.classList.remove('hidden');
                } else {
                    notificationBadge.classList.add('hidden');
                }
            }
            
            // Update notification list in modal
            if (notificationList) {
                notificationList.innerHTML = '';
                
                if (notifications.length === 0) {
                    notificationList.innerHTML = '<div class="text-center text-gray-500 py-4">No new notifications</div>';
                } else {
                    notifications.forEach(notification => {
                        const notificationEl = document.createElement('div');
                        notificationEl.className = 'p-3 border-b border-gray-200';
                        notificationEl.innerHTML = `
                            <div class="font-medium">${notification.message || 'Notification'}</div>
                            <div class="text-sm text-gray-500 mt-1">${new Date(notification.created_at).toLocaleDateString()}</div>
                        `;
                        notificationList.appendChild(notificationEl);
                    });
                }
            }
        }
        
        // Load notifications on page load
        loadNotifications();

        // Logout functionality
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Logout button clicked');
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = '?logout=true';
                }
            });
        }

        // Tab functionality
        const tabs = document.querySelectorAll('.tab');
        console.log('Found', tabs.length, 'tabs');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                console.log('Tab clicked:', tabId);
                
                // Remove active class from all tabs and tab contents
                tabs.forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                const tabContent = document.getElementById(`${tabId}-tab`);
                if (tabContent) {
                    tabContent.classList.add('active');
                }
            });
        });

        // Initialize charts only if canvas elements exist
        initializeCharts();
    });

    function initializeCharts() {
        console.log('Initializing charts...');
        
        // Collection Trend Chart
        const trendCanvas = document.getElementById('collectionTrendChart');
        if (trendCanvas) {
            const trendCtx = trendCanvas.getContext('2d');
            const dailyTrendData = <?php 
                if (isset($daily_trend) && is_array($daily_trend)) {
                    echo json_encode($daily_trend);
                } else {
                    echo '[]';
                }
            ?>;
            
            // Prepare chart data
            const trendLabels = dailyTrendData.map(item => item.collection_date || '');
            const trendData = dailyTrendData.map(item => parseFloat(item.daily_total) || 0);
            
            const collectionTrendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Daily Collection (₱)',
                        data: trendData,
                        borderColor: '#2f855A',
                        backgroundColor: 'rgba(47, 133, 90, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Payment Methods Chart
        const methodsCanvas = document.getElementById('paymentMethodsChart');
        if (methodsCanvas) {
            const methodsCtx = methodsCanvas.getContext('2d');
            const paymentMethodsData = <?php 
                if (isset($payment_methods) && is_array($payment_methods)) {
                    echo json_encode($payment_methods);
                } else {
                    echo '[]';
                }
            ?>;
            
            // Prepare chart data
            const methodLabels = paymentMethodsData.map(item => item.payment_method || 'Unknown');
            const methodData = paymentMethodsData.map(item => parseFloat(item.total_amount) || 0);
            
            const paymentMethodsChart = new Chart(methodsCtx, {
                type: 'doughnut',
                data: {
                    labels: methodLabels,
                    datasets: [{
                        data: methodData,
                        backgroundColor: [
                            '#2f855A',
                            '#38A169',
                            '#48BB78',
                            '#68D391',
                            '#9AE6B4',
                            '#C6F6D5'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Monthly Comparison Chart
        const monthlyCanvas = document.getElementById('monthlyComparisonChart');
        if (monthlyCanvas) {
            const monthlyCtx = monthlyCanvas.getContext('2d');
            const monthlyComparisonData = <?php 
                if (isset($monthly_comparison) && is_array($monthly_comparison)) {
                    echo json_encode($monthly_comparison);
                } else {
                    echo '[]';
                }
            ?>;
            
            // Prepare chart data
            const monthlyLabels = monthlyComparisonData.map(item => item.month_year || '');
            const monthlyData = monthlyComparisonData.map(item => parseFloat(item.monthly_total) || 0);
            
            const monthlyComparisonChart = new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Monthly Collection (₱)',
                        data: monthlyData,
                        backgroundColor: '#2f855A',
                        borderColor: '#28644c',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Aging Chart
        const agingCanvas = document.getElementById('agingChart');
        if (agingCanvas) {
            const agingCtx = agingCanvas.getContext('2d');
            const agingData = <?php 
                if (isset($aging_analysis)) {
                    echo json_encode($aging_analysis);
                } else {
                    echo '{"current_0_30":0,"overdue_31_60":0,"overdue_61_90":0,"overdue_90_plus":0}';
                }
            ?>;
            
            const agingChart = new Chart(agingCtx, {
                type: 'bar',
                data: {
                    labels: ['Current (0-30)', '31-60 Days', '61-90 Days', '90+ Days'],
                    datasets: [{
                        label: 'Amount (₱)',
                        data: [
                            parseFloat(agingData.current_0_30) || 0,
                            parseFloat(agingData.overdue_31_60) || 0,
                            parseFloat(agingData.overdue_61_90) || 0,
                            parseFloat(agingData.overdue_90_plus) || 0
                        ],
                        backgroundColor: [
                            '#10B981',
                            '#F59E0B',
                            '#F97316',
                            '#EF4444'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
    }

    // Export functions
    function printReport() {
        console.log('Print report clicked');
        window.print();
    }

    function exportToPDF() {
        console.log('Export to PDF clicked');
        alert('PDF export functionality would be implemented here');
        // In a real implementation, this would generate and download a PDF report
    }

    function exportToExcel() {
        console.log('Export to Excel clicked');
        alert('Excel export functionality would be implemented here');
        // In a real implementation, this would generate and download an Excel report
    }

    // Debug: Log all button clicks
    document.addEventListener('click', function(e) {
        if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
            const button = e.target.closest('button');
            console.log('Button clicked:', button.textContent?.trim() || button.innerHTML?.trim() || 'Unknown button');
        }
    });
</script>
</body>
</html>