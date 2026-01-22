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
$collection_stats = [];
$recent_payments = [];
$overdue_invoices = [];
$aging_summary = [];
$top_customers = [];

// Fetch collection statistics
try {
    // Overall collection stats
    $stats_stmt = $db->query("
        SELECT 
            COUNT(*) as total_payments,
            SUM(amount) as total_collected,
            AVG(amount) as average_payment,
            MAX(amount) as largest_payment,
            COUNT(DISTINCT contact_id) as unique_customers
        FROM payments 
        WHERE status = 'Completed' AND type = 'Receive'
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $collection_stats = $stats_stmt->fetch();
    
    // Recent payments
    $recent_payments_stmt = $db->query("
        SELECT p.*, c.name as contact_name, i.invoice_number 
        FROM payments p 
        LEFT JOIN contacts c ON p.contact_id = c.id 
        LEFT JOIN invoices i ON p.invoice_id = i.id 
        WHERE p.status = 'Completed' AND p.type = 'Receive'
        ORDER BY p.payment_date DESC 
        LIMIT 10
    ");
    $recent_payments = $recent_payments_stmt->fetchAll();
    
    // Overdue invoices
    $overdue_stmt = $db->query("
        SELECT i.*, c.name as contact_name, 
               DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i 
        JOIN contacts c ON i.contact_id = c.id 
        WHERE i.status IN ('Overdue', 'Pending') 
        AND i.due_date < CURDATE()
        ORDER BY i.due_date ASC 
        LIMIT 10
    ");
    $overdue_invoices = $overdue_stmt->fetchAll();
    
    // Aging summary
    $aging_stmt = $db->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN amount ELSE 0 END) as current_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END) as overdue_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END) as overdue_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END) as overdue_90_plus
        FROM invoices 
        WHERE status IN ('Pending', 'Overdue')
    ");
    $aging_summary = $aging_stmt->fetch();
    
    // Top customers by payment amount
    $top_customers_stmt = $db->query("
        SELECT c.name, SUM(p.amount) as total_paid, COUNT(p.id) as payment_count
        FROM payments p 
        JOIN contacts c ON p.contact_id = c.id 
        WHERE p.status = 'Completed' AND p.type = 'Receive'
        AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY c.id, c.name 
        ORDER BY total_paid DESC 
        LIMIT 5
    ");
    $top_customers = $top_customers_stmt->fetchAll();
    
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

// Function to mask numbers with asterisks
function maskNumber($number, $masked = true) {
    if (!$masked) {
        return number_format($number, 0); // Remove decimal places
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
    <title>Collection Dashboard | Financial Dashboard</title>
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
        }
        
        #hamburger-btn {
            display: block !important;
        }
        
        @media (max-width: 768px) {
            #sidebar {
                transform: translateX(-100%);
                position: fixed;
                height: 100%;
                z-index: 40;
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
            z-index: 50;
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
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
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
        }
        
        .btn-primary {
            background-color: #2f855A;
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            background-color: #28644c;
        }
        
        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
            border: none;
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
        }
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #3B82F6;
            border: 1px solid #3B82F6;
        }
        
        .action-btn.edit {
            background-color: #F0FDF4;
            color: #10B981;
            border: 1px solid #10B981;
        }
        
        .action-btn.download {
            background-color: #FEF3C7;
            color: #D97706;
            border: 1px solid #D97706;
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
                    <h3 class="text-lg font-bold" id="profile-name"><?= htmlspecialchars($user['name']) ?></h3>
                    <p class="text-gray-500"><?= ucfirst(htmlspecialchars($user['role'])) ?></p>
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
                <!-- Notifications will be loaded here -->
                <div class="text-center text-gray-500 py-4">No new notifications</div>
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
                                <a href="collection_dashboard.php" class="submenu-item active transition-colors duration-200">Collection Dashboard</a>
                                <a href="outstanding_balances.php" class="submenu-item transition-colors duration-200">Outstanding Balances</a>
                                <a href="collection_reports.php" class="submenu-item transition-colors duration-200">Collection Reports</a>
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
                        <h1 class="text-2xl font-bold text-white">Collection Dashboard</h1>
                        <p class="text-sm text-white/90">Monitor collection performance and metrics</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <button class="toggle-visibility-btn" id="toggle-visibility" title="Toggle number visibility">
                        <i class="fa-solid fa-eye-slash" id="visibility-icon"></i>
                    </button>
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center hidden" id="notification-badge">3</span>
                    </button>
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200 hover:bg-green-600 rounded">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?= htmlspecialchars($user['name']) ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Quick Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 mr-4">
                                <i class='bx bx-money text-green-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Collected (30 days)</p>
                                <p class="text-2xl font-bold text-dark-text amount-masked" data-amount="<?= $collection_stats['total_collected'] ?? 0 ?>">
                                    ₱<?= maskNumber($collection_stats['total_collected'] ?? 0) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 mr-4">
                                <i class='bx bx-user-check text-blue-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Active Customers</p>
                                <p class="text-2xl font-bold text-dark-text">
                                    <?= $collection_stats['unique_customers'] ?? 0 ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100 mr-4">
                                <i class='bx bx-receipt text-purple-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Payments</p>
                                <p class="text-2xl font-bold text-dark-text">
                                    <?= $collection_stats['total_payments'] ?? 0 ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 mr-4">
                                <i class='bx bx-trending-up text-yellow-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Average Payment</p>
                                <p class="text-2xl font-bold text-dark-text amount-masked" data-amount="<?= $collection_stats['average_payment'] ?? 0 ?>">
                                    ₱<?= maskNumber($collection_stats['average_payment'] ?? 0) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Charts and Detailed Metrics -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Collection Trend Chart -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Collection Trend (Last 30 Days)</h3>
                        <div class="chart-container">
                            <canvas id="collectionTrendChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Aging Summary -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Aging Summary</h3>
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>Current (0-30 days)</span>
                                    <span class="font-semibold amount-masked" data-amount="<?= $aging_summary['current_0_30'] ?? 0 ?>">
                                        ₱<?= maskNumber($aging_summary['current_0_30'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-green-500" style="width: 60%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>31-60 Days</span>
                                    <span class="font-semibold amount-masked" data-amount="<?= $aging_summary['overdue_31_60'] ?? 0 ?>">
                                        ₱<?= maskNumber($aging_summary['overdue_31_60'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-yellow-500" style="width: 25%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>61-90 Days</span>
                                    <span class="font-semibold amount-masked" data-amount="<?= $aging_summary['overdue_61_90'] ?? 0 ?>">
                                        ₱<?= maskNumber($aging_summary['overdue_61_90'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-orange-500" style="width: 10%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>90+ Days</span>
                                    <span class="font-semibold amount-masked" data-amount="<?= $aging_summary['overdue_90_plus'] ?? 0 ?>">
                                        ₱<?= maskNumber($aging_summary['overdue_90_plus'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-red-500" style="width: 5%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Recent Payments -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Recent Payments</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_payments)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-gray-500">No recent payments</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_payments as $payment): ?>
                                            <tr>
                                                <td class="font-medium"><?= htmlspecialchars($payment['contact_name'] ?? 'N/A') ?></td>
                                                <td class="font-semibold amount-masked" data-amount="<?= $payment['amount'] ?? 0 ?>">
                                                    ₱<?= maskNumber($payment['amount'] ?? 0) ?>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                                <td>
                                                    <span class="status-badge status-completed">Completed</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Overdue Invoices -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Overdue Invoices</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Due Date</th>
                                        <th>Days Overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($overdue_invoices)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-gray-500">No overdue invoices</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($overdue_invoices as $invoice): ?>
                                            <tr>
                                                <td class="font-medium"><?= htmlspecialchars($invoice['contact_name'] ?? 'N/A') ?></td>
                                                <td class="font-semibold amount-masked" data-amount="<?= $invoice['amount'] ?? 0 ?>">
                                                    ₱<?= maskNumber($invoice['amount'] ?? 0) ?>
                                                </td>
                                                <td class="text-red-600"><?= date('M j, Y', strtotime($invoice['due_date'])) ?></td>
                                                <td>
                                                    <span class="status-badge status-overdue">
                                                        <?= $invoice['days_overdue'] ?? 0 ?> days
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Top Customers -->
                <div class="metric-card mt-6">
                    <h3 class="text-lg font-semibold mb-4">Top Customers (Last 30 Days)</h3>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Total Paid</th>
                                    <th>Payment Count</th>
                                    <th>Average Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_customers)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-gray-500">No customer data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($top_customers as $customer): ?>
                                        <tr>
                                            <td class="font-medium"><?= htmlspecialchars($customer['name']) ?></td>
                                            <td class="font-semibold amount-masked" data-amount="<?= $customer['total_paid'] ?>">
                                                ₱<?= maskNumber($customer['total_paid']) ?>
                                            </td>
                                            <td><?= $customer['payment_count'] ?></td>
                                            <td class="amount-masked" data-amount="<?= $customer['total_paid'] / $customer['payment_count'] ?>">
                                                ₱<?= maskNumber($customer['total_paid'] / $customer['payment_count']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
            // Sidebar functionality
            const hamburgerBtn = document.getElementById('hamburger-btn');
            const closeSidebar = document.getElementById('close-sidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            const mainContent = document.getElementById('main-content');

            function toggleSidebar() {
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
            }

            if (closeSidebar) {
                closeSidebar.addEventListener('click', function() {
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
                    if (window.innerWidth < 769) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    }
                });
            }

            // Sidebar submenu functionality
            const categoryToggles = document.querySelectorAll('.sidebar-category');
            categoryToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const category = this.getAttribute('data-category');
                    const submenu = document.getElementById(`${category}-submenu`);
                    const arrow = document.querySelector(`.category-arrow[data-category="${category}"]`);
                    
                    submenu.classList.toggle('active');
                    arrow.classList.toggle('rotate-180');
                });
            });

            // Number visibility toggle functionality
            const toggleBtn = document.getElementById('toggle-visibility');
            const visibilityIcon = document.getElementById('visibility-icon');
            let numbersVisible = false;

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    numbersVisible = !numbersVisible;
                    toggleNumberVisibility(numbersVisible);
                    
                    if (numbersVisible) {
                        visibilityIcon.className = 'fa-solid fa-eye';
                    } else {
                        visibilityIcon.className = 'fa-solid fa-eye-slash';
                    }
                });
            }

            // Modal functionality
            const notificationBtn = document.getElementById('notification-btn');
            const notificationModal = document.getElementById('notification-modal');
            const profileBtn = document.getElementById('profile-btn');
            const profileModal = document.getElementById('profile-modal');
            const closeButtons = document.querySelectorAll('.close-modal');
            const logoutBtn = document.getElementById('logout-btn');
            
            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function() {
                    notificationModal.style.display = 'block';
                    loadNotifications();
                });
            }
            
            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    profileModal.style.display = 'block';
                });
            }
            
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    notificationModal.style.display = 'none';
                    profileModal.style.display = 'none';
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
                const notifications = <?php echo json_encode($notifications); ?>;
                const notificationList = document.getElementById('notification-list');
                const notificationBadge = document.getElementById('notification-badge');
                
                // Update notification badge
                if (notificationBadge) {
                    const unreadCount = <?php echo count($unread_notifications); ?>;
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
                logoutBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = '?logout=true';
                    }
                });
            }

            // Collection Trend Chart
            const trendCtx = document.getElementById('collectionTrendChart').getContext('2d');
            const collectionTrendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Collections (₱)',
                        data: [25000, 32000, 28000, 41000],
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
        });

        // Toggle number visibility
        function toggleNumberVisibility(visible) {
            const amountElements = document.querySelectorAll('.amount-masked');
            
            amountElements.forEach(element => {
                const amount = element.getAttribute('data-amount');
                if (amount) {
                    if (visible) {
                        // Show actual number without decimal places
                        element.textContent = '₱' + parseInt(amount).toLocaleString('en-US');
                    } else {
                        // Show masked number
                        const numberStr = parseInt(amount).toString();
                        const maskedInteger = '*'.repeat(numberStr.length);
                        element.textContent = '₱' + maskedInteger;
                    }
                }
            });
        }
    </script>
</body>
</html>