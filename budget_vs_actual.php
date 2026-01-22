<?php
declare(strict_types=1);
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', "1");

// Check if user is logged in
if (empty($_SESSION['user_id'] ?? null)) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'user';

// Initialize hide numbers session variable
if (!isset($_SESSION['hide_numbers'])) {
    $_SESSION['hide_numbers'] = false;
}

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
$budget_vs_actual_data = [];
$department_performance = [];
$category_analysis = [];
$variance_analysis = [];
$monthly_trend = [];
$fiscal_years = [];
$departments = [];

// Handle report filtering - sanitize inputs
$fiscal_year = isset($_GET['fiscal_year']) ? (int)$_GET['fiscal_year'] : (int)date('Y');
$department = isset($_GET['department']) ? trim($_GET['department']) : '';
$period = $_GET['period'] ?? 'year';

try {
    // Get approved budget proposals for the selected fiscal year - FIXED SQL
    $budget_sql = "
        SELECT 
            bp.department,
            d.name as department_name,
            SUM(bi.total_cost) as total_budget,
            COUNT(DISTINCT bp.id) as proposal_count
        FROM budget_proposals bp
        JOIN departments d ON bp.department = d.id
        JOIN budget_items bi ON bp.id = bi.proposal_id
        WHERE bp.fiscal_year = ? 
        AND bp.status = 'Approved'
    ";
    
    if (!empty($department)) {
        $budget_sql .= " AND bp.department = ?";
    }
    
    $budget_sql .= " GROUP BY bp.department, d.name ORDER BY total_budget DESC";
    
    $budget_stmt = $db->prepare($budget_sql);
    
    $params = [$fiscal_year];
    if (!empty($department)) {
        $params[] = $department;
    }
    
    $budget_stmt->execute($params);
    $department_budgets = $budget_stmt->fetchAll();

    // Get actual expenses for the same period - FIXED SQL
    $actual_sql = "
        SELECT 
            d.id as department_id,
            d.name as department_name,
            SUM(CASE WHEN p.type = 'Disburse' THEN p.amount ELSE 0 END) as total_expenses,
            SUM(CASE WHEN p.type = 'Receive' THEN p.amount ELSE 0 END) as total_income,
            COUNT(DISTINCT p.id) as transaction_count
        FROM payments p
        JOIN departments d ON p.department_id = d.id
        WHERE YEAR(p.payment_date) = ?
        AND p.status = 'Completed'
    ";
    
    if (!empty($department)) {
        $actual_sql .= " AND p.department_id = ?";
    }
    
    $actual_sql .= " GROUP BY d.id, d.name ORDER BY total_expenses DESC";
    
    $actual_stmt = $db->prepare($actual_sql);
    
    // Use same params for both queries
    $actual_params = [$fiscal_year];
    if (!empty($department)) {
        $actual_params[] = $department;
    }
    
    $actual_stmt->execute($actual_params);
    $department_actuals = $actual_stmt->fetchAll();

    // Combine budget vs actual data with safe calculations
    $budget_vs_actual_data = [];
    foreach ($department_budgets as $budget) {
        $actual = array_filter($department_actuals, function($item) use ($budget) {
            return $item['department_name'] === $budget['department_name'];
        });
        $actual = $actual ? array_values($actual)[0] : null;
        
        $total_budget = (float)($budget['total_budget'] ?? 0);
        $total_expenses = $actual ? (float)($actual['total_expenses'] ?? 0) : 0;
        $variance = $total_budget - $total_expenses;
        
        // Safe division to avoid division by zero
        $variance_percentage = 0;
        if ($total_budget > 0) {
            $variance_percentage = ($variance / $total_budget) * 100;
        }
        
        $budget_vs_actual_data[] = [
            'department' => $budget['department_name'] ?? 'Unknown',
            'total_budget' => $total_budget,
            'total_expenses' => $total_expenses,
            'total_income' => $actual ? (float)($actual['total_income'] ?? 0) : 0,
            'variance' => $variance,
            'variance_percentage' => $variance_percentage,
            'proposal_count' => $budget['proposal_count'] ?? 0,
            'transaction_count' => $actual ? ($actual['transaction_count'] ?? 0) : 0
        ];
    }

    // Get category-wise analysis
    $category_stmt = $db->prepare("
        SELECT 
            bc.name as category_name,
            bc.type as category_type,
            SUM(bi.total_cost) as budget_amount,
            COUNT(bi.id) as item_count
        FROM budget_items bi
        JOIN budget_proposals bp ON bi.proposal_id = bp.id
        JOIN budget_categories bc ON bi.category = bc.name
        WHERE bp.fiscal_year = ? 
        AND bp.status = 'Approved'
        GROUP BY bc.name, bc.type
        ORDER BY budget_amount DESC
    ");
    $category_stmt->execute([$fiscal_year]);
    $category_analysis = $category_stmt->fetchAll();

    // Get variance analysis (top over/under budget)
    $variance_stmt = $db->prepare("
        SELECT 
            bp.title as proposal_title,
            d.name as department_name,
            bp.total_amount as budget_amount,
            (SELECT COALESCE(SUM(p.amount), 0) 
             FROM payments p 
             WHERE p.proposal_id = bp.id 
             AND p.status = 'Completed'
             AND p.type = 'Disburse') as actual_expenses,
            (bp.total_amount - (SELECT COALESCE(SUM(p.amount), 0) 
                              FROM payments p 
                              WHERE p.proposal_id = bp.id 
                              AND p.status = 'Completed'
                              AND p.type = 'Disburse')) as variance,
            CASE 
                WHEN bp.total_amount > 0 THEN 
                    ((bp.total_amount - (SELECT COALESCE(SUM(p.amount), 0) 
                                       FROM payments p 
                                       WHERE p.proposal_id = bp.id 
                                       AND p.status = 'Completed'
                                       AND p.type = 'Disburse')) / bp.total_amount * 100)
                ELSE 0 
            END as variance_percentage
        FROM budget_proposals bp
        JOIN departments d ON bp.department = d.id
        WHERE bp.fiscal_year = ?
        AND bp.status = 'Approved'
        HAVING ABS(variance) > 0
        ORDER BY ABS(variance) DESC
        LIMIT 10
    ");
    $variance_stmt->execute([$fiscal_year]);
    $variance_analysis = $variance_stmt->fetchAll();

    // Get monthly trend data for charts
    $monthly_trend_stmt = $db->prepare("
        SELECT 
            MONTH(p.payment_date) as month,
            YEAR(p.payment_date) as year,
            SUM(CASE WHEN p.type = 'Disburse' THEN p.amount ELSE 0 END) as monthly_expenses,
            SUM(CASE WHEN p.type = 'Receive' THEN p.amount ELSE 0 END) as monthly_income
        FROM payments p
        WHERE YEAR(p.payment_date) = ?
        AND p.status = 'Completed'
        GROUP BY YEAR(p.payment_date), MONTH(p.payment_date)
        ORDER BY year, month
    ");
    $monthly_trend_stmt->execute([$fiscal_year]);
    $monthly_trend = $monthly_trend_stmt->fetchAll();

    // Get fiscal years for dropdown
    $years_stmt = $db->query("
        SELECT DISTINCT fiscal_year 
        FROM budget_proposals 
        WHERE status = 'Approved'
        ORDER BY fiscal_year DESC
    ");
    $fiscal_years = $years_stmt ? $years_stmt->fetchAll() : [];

    // Get departments for dropdown - IMPROVED QUERY
$dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
$departments = $dept_stmt ? $dept_stmt->fetchAll() : [];

// Debug: Check if departments are loading
if (empty($departments)) {
    error_log("No departments found in database");
    // Add default departments for testing
    $departments = [
        ['id' => 1, 'name' => 'Finance'],
        ['id' => 2, 'name' => 'HR'],
        ['id' => 3, 'name' => 'IT'],
        ['id' => 4, 'name' => 'Marketing'],
        ['id' => 5, 'name' => 'Operations']
    ];
}

} catch (Exception $e) {
    error_log("Budget vs Actual data load error: " . $e->getMessage());
    // Initialize empty arrays to prevent undefined variable errors
    $budget_vs_actual_data = [];
    $category_analysis = [];
    $variance_analysis = [];
    $monthly_trend = [];
    $fiscal_years = [];
    $departments = [
        ['id' => 1, 'name' => 'Finance'],
        ['id' => 2, 'name' => 'HR'],
        ['id' => 3, 'name' => 'IT'],
        ['id' => 4, 'name' => 'Marketing'],
        ['id' => 5, 'name' => 'Operations']
    ]; // Fallback with specific departments
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

// Safe output function
function safe_output($value, $default = '') {
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Function to mask numbers with asterisks
function maskNumber($number, $masked = true) {
    if (!$masked) {
        return number_format((float)$number, 2);
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part
    return str_repeat('*', strlen($integerPart));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget vs Actual | Financial Dashboard</title>
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
        /* All the previous CSS styles from budget_proposal.php */
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
        .variance-positive {
            background-color: rgba(34, 197, 94, 0.1);
            color: #16A34A;
        }
        .variance-negative {
            background-color: rgba(239, 68, 68, 0.1);
            color: #DC2626;
        }
        .variance-neutral {
            background-color: rgba(156, 163, 175, 0.1);
            color: #6B7280;
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
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
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
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
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

        /* Budget vs Actual specific styles */
        .budget-actual-row {
            transition: background-color 0.2s;
        }
        
        .budget-actual-row:hover {
            background-color: #f9fafb;
        }
        
        .variance-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .variance-good {
            background-color: #10B981;
        }
        
        .variance-warning {
            background-color: #F59E0B;
        }
        
        .variance-critical {
            background-color: #EF4444;
        }
        
        .utilization-high {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
        }
        
        .utilization-medium {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            color: white;
        }
        
        .utilization-low {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: white;
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
            transition: background-color 0.2s;
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
                                <a href="disbursement_request.php" class="submenu-item transition-colors duration-200">Disbursement Request</a>
                                <a href="pending_disbursements.php" class="submenu-item transition-colors duration-200">Pending Disbursements</a>
                                <a href="approved_disbursements.php" class="submenu-item transition-colors duration-200">Approved Disbursements</a>
                                <a href="rejected_disbursements.php" class="submenu-item transition-colors duration-200">Rejected Disbursements</a>
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
                            <div class="submenu mt-1" id="collection-submenu">
                                <a href="payment_entry_collection.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="receipt_generation.php" class="submenu-item transition-colors duration-200">Receipt Generation</a>
                                <a href="collection_dashboard.php" class="submenu-item transition-colors duration-200">Collection Dashboard</a>
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
                            <div class="submenu mt-1 active" id="budget-submenu">
                                <a href="budget_proposal.php" class="submenu-item transition-colors duration-200">Budget Proposal</a>
                                <a href="approval_workflow.php" class="submenu-item transition-colors duration-200">Approval Workflow</a>
                                <a href="budget_vs_actual.php" class="submenu-item active transition-colors duration-200">Budget vs Actual</a>
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
                        <h1 class="text-2xl font-bold text-white">Budget vs Actual Analysis</h1>
                        <p class="text-sm text-white/90">Compare budgeted amounts with actual expenses and income</p>
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
                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        <?= safe_output($_SESSION['success_message']) ?>
                        <?php unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?= safe_output($_SESSION['error_message']) ?>
                        <?php unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Report Filters -->
                <div class="report-filter">
                    <h3 class="text-lg font-semibold mb-4">Report Parameters</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
    <label class="form-label">Fiscal Year</label>
    <select name="fiscal_year" class="form-select" required>
        <?php if (empty($fiscal_years)): ?>
            <option value="<?= date('Y') ?>"><?= date('Y') ?></option>
        <?php else: ?>
            <?php foreach ($fiscal_years as $year): ?>
                <option value="<?= safe_output($year['fiscal_year']) ?>" 
                    <?= $fiscal_year == $year['fiscal_year'] ? 'selected' : '' ?>>
                    <?= safe_output($year['fiscal_year']) ?>
                </option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
</div>
                        <div class="form-group">
    <label class="form-label">Department</label>
    <select name="department" class="form-select">
        <option value="">All Departments</option>
        <?php foreach ($departments as $dept): ?>
            <option value="<?= safe_output($dept['id']) ?>" 
                <?= (isset($_GET['department']) && $_GET['department'] == $dept['id']) ? 'selected' : '' ?>>
                <?= safe_output($dept['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
                        <div class="form-group">
                            <label class="form-label">Period</label>
                            <select name="period" class="form-select">
                                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Full Year</option>
                                <option value="quarter" <?= $period === 'quarter' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Monthly</option>
                            </select>
                        </div>
                        <div class="form-group flex items-end">
                            <button type="submit" class="btn btn-primary w-full">
                                <i class="fa-solid fa-filter mr-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <?php
                    $total_budget = array_sum(array_column($budget_vs_actual_data, 'total_budget'));
                    $total_expenses = array_sum(array_column($budget_vs_actual_data, 'total_expenses'));
                    $total_income = array_sum(array_column($budget_vs_actual_data, 'total_income'));
                    $net_variance = $total_budget - $total_expenses;
                    $utilization_rate = $total_budget > 0 ? ($total_expenses / $total_budget * 100) : 0;
                    ?>
                    <div class="stat-card">
                        <div class="stat-value text-primary-green amount-masked" data-amount="<?= $total_budget ?>">
                            ₱<?= maskNumber($total_budget) ?>
                        </div>
                        <div class="stat-label">Total Budget</div>
                        <div class="text-sm trend-up">Approved Budget</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-blue-600 amount-masked" data-amount="<?= $total_expenses ?>">
                            ₱<?= maskNumber($total_expenses) ?>
                        </div>
                        <div class="stat-label">Actual Expenses</div>
                        <div class="text-sm">Total Spent</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value <?= $net_variance >= 0 ? 'text-green-600' : 'text-red-600' ?> amount-masked" data-amount="<?= abs($net_variance) ?>">
                            ₱<?= maskNumber(abs($net_variance)) ?>
                        </div>
                        <div class="stat-label">Net Variance</div>
                        <div class="text-sm <?= $net_variance >= 0 ? 'trend-up' : 'trend-down' ?>">
                            <?= $net_variance >= 0 ? 'Under Budget' : 'Over Budget' ?>
                        </div>
                    </div>
                    <div class="stat-card <?= $utilization_rate > 90 ? 'utilization-high' : ($utilization_rate > 70 ? 'utilization-medium' : 'utilization-low') ?>">
                        <div class="stat-value"><?= number_format($utilization_rate, 1) ?>%</div>
                        <div class="stat-label">Budget Utilization</div>
                        <div class="text-sm">Spent vs Budget</div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Budget vs Actual Comparison Chart -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Budget vs Actual by Department</h3>
                        <div class="chart-container">
                            <canvas id="budgetActualChart"></canvas>
                        </div>
                    </div>

                    <!-- Variance Analysis Chart -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Variance Analysis</h3>
                        <div class="chart-container">
                            <canvas id="varianceChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Monthly Trend Chart -->
                <div class="metric-card mb-6">
                    <h3 class="text-lg font-semibold mb-4">Monthly Budget vs Actual Trend</h3>
                    <div class="chart-container">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Detailed Budget vs Actual Table -->
                <div class="metric-card">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Detailed Budget vs Actual Analysis</h3>
                        <div class="flex space-x-2">
                            <button class="btn btn-secondary" onclick="printReport()">
                                <i class="fa-solid fa-print mr-2"></i>Print
                            </button>
                            <button class="btn btn-secondary" onclick="exportToExcel()">
                                <i class="fa-solid fa-file-excel mr-2"></i>Export
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Budget (₱)</th>
                                    <th>Actual (₱)</th>
                                    <th>Variance (₱)</th>
                                    <th>Variance %</th>
                                    <th>Utilization</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($budget_vs_actual_data)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-gray-500">
                                            No budget vs actual data available for the selected criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($budget_vs_actual_data as $data): 
                                        $variance_class = $data['variance'] > 0 ? 'variance-positive' : 
                                                         ($data['variance'] < 0 ? 'variance-negative' : 'variance-neutral');
                                        $utilization = $data['total_budget'] > 0 ? ($data['total_expenses'] / $data['total_budget'] * 100) : 0;
                                        $status_indicator = $utilization > 90 ? 'variance-critical' : 
                                                           ($utilization > 70 ? 'variance-warning' : 'variance-good');
                                    ?>
                                        <tr class="budget-actual-row">
                                            <td class="font-medium"><?= safe_output($data['department']) ?></td>
                                            <td class="font-semibold amount-masked" data-amount="<?= $data['total_budget'] ?>">
                                                ₱<?= maskNumber($data['total_budget']) ?>
                                            </td>
                                            <td class="amount-masked" data-amount="<?= $data['total_expenses'] ?>">
                                                ₱<?= maskNumber($data['total_expenses']) ?>
                                            </td>
                                            <td class="<?= $variance_class ?> font-semibold amount-masked" data-amount="<?= abs($data['variance']) ?>">
                                                ₱<?= maskNumber(abs($data['variance'])) ?>
                                                <?= $data['variance'] > 0 ? 'Under' : ($data['variance'] < 0 ? 'Over' : 'On') ?>
                                            </td>
                                            <td class="<?= $variance_class ?>">
                                                <?= number_format(abs($data['variance_percentage']), 1) ?>%
                                            </td>
                                            <td>
                                                <div class="flex items-center">
                                                    <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                                        <div class="h-2 rounded-full <?= 
                                                            $utilization > 90 ? 'bg-red-500' : 
                                                            ($utilization > 70 ? 'bg-yellow-500' : 'bg-green-500')
                                                        ?>" style="width: <?= min($utilization, 100) ?>%"></div>
                                                    </div>
                                                    <span class="text-sm"><?= number_format($utilization, 1) ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="variance-indicator <?= $status_indicator ?>"></span>
                                                <span class="text-sm">
                                                    <?= $utilization > 90 ? 'High Utilization' : 
                                                         ($utilization > 70 ? 'Medium Utilization' : 'Low Utilization') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Variance Analysis Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    <!-- Top Variances -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Top Variances</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Proposal</th>
                                        <th>Department</th>
                                        <th>Variance (₱)</th>
                                        <th>Variance %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($variance_analysis)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-gray-500">
                                                No significant variances found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($variance_analysis as $variance): 
                                            $variance_class = $variance['variance'] > 0 ? 'variance-positive' : 'variance-negative';
                                        ?>
                                            <tr>
                                                <td class="font-medium"><?= safe_output($variance['proposal_title']) ?></td>
                                                <td><?= safe_output($variance['department_name']) ?></td>
                                                <td class="<?= $variance_class ?> font-semibold amount-masked" data-amount="<?= abs($variance['variance']) ?>">
                                                    ₱<?= maskNumber(abs($variance['variance'])) ?>
                                                </td>
                                                <td class="<?= $variance_class ?>">
                                                    <?= number_format(abs($variance['variance_percentage']), 1) ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Category Analysis -->
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Budget by Category</h3>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
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
        // Clean and working JavaScript - only include this once
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - initializing all functionality');
            
            // 1. Number visibility toggle
            const toggleBtn = document.getElementById('toggle-visibility');
            const visibilityIcon = document.getElementById('visibility-icon');
            let numbersVisible = false;
            
            if (toggleBtn && visibilityIcon) {
                toggleBtn.addEventListener('click', function() {
                    console.log('Toggle visibility clicked');
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
                    visibilityIcon.className = numbersVisible ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
                });
            }

            // 2. Notification functionality
            const notificationBtn = document.getElementById('notification-btn');
            const notificationModal = document.getElementById('notification-modal');
            
            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function() {
                    console.log('Notification button clicked');
                    notificationModal.style.display = 'block';
                    
                    const notificationList = document.getElementById('notification-list');
                    if (notificationList) {
                        notificationList.innerHTML = `
                            <div class="space-y-2">
                                <div class="p-3 border rounded-lg">
                                    <p class="text-sm">Budget proposal #BP-2024-001 has been approved</p>
                                    <p class="text-xs text-gray-500">2 hours ago</p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="text-sm">New disbursement request requires your approval</p>
                                    <p class="text-xs text-gray-500">5 hours ago</p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="text-sm">Monthly financial report is ready for review</p>
                                    <p class="text-xs text-gray-500">1 day ago</p>
                                </div>
                            </div>
                        `;
                    }
                });
            }

            // 3. Profile and logout functionality
            const profileBtn = document.getElementById('profile-btn');
            const profileModal = document.getElementById('profile-modal');
            const logoutBtn = document.getElementById('logout-btn');
            
            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    console.log('Profile button clicked');
                    profileModal.style.display = 'block';
                });
            }
            
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Logout button clicked');
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = '?logout=true';
                    }
                });
            }

            // 4. Modal close functionality
            const closeButtons = document.querySelectorAll('.close-modal');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    if (notificationModal) notificationModal.style.display = 'none';
                    if (profileModal) profileModal.style.display = 'none';
                });
            });

            // 5. Close modals when clicking outside
            window.addEventListener('click', function(event) {
                if (notificationModal && event.target === notificationModal) {
                    notificationModal.style.display = 'none';
                }
                if (profileModal && event.target === profileModal) {
                    profileModal.style.display = 'none';
                }
            });

            // 6. Sidebar functionality
            const hamburgerBtn = document.getElementById('hamburger-btn');
            const closeSidebar = document.getElementById('close-sidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');

            if (hamburgerBtn && sidebar) {
                hamburgerBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    if (overlay) overlay.classList.toggle('active');
                });
            }

            if (closeSidebar && sidebar) {
                closeSidebar.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                });
            }

            if (overlay && sidebar) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                });
            }

            // 7. Sidebar dropdowns
            const categories = document.querySelectorAll('.sidebar-category');
            categories.forEach(category => {
                category.addEventListener('click', function() {
                    const categoryName = this.getAttribute('data-category');
                    const submenu = document.getElementById(categoryName + '-submenu');
                    const arrow = this.querySelector('.category-arrow');
                    
                    if (submenu) submenu.classList.toggle('active');
                    if (arrow) arrow.classList.toggle('rotate-180');
                });
            });

            // 8. Print and export buttons
            document.querySelectorAll('button[onclick*="printReport"]').forEach(btn => {
                btn.addEventListener('click', function() {
                    window.print();
                });
            });

            document.querySelectorAll('button[onclick*="exportToExcel"]').forEach(btn => {
                btn.addEventListener('click', function() {
                    alert('Excel export functionality would be implemented here');
                });
            });

            // 9. Initialize charts
            initializeCharts();
            
            console.log('All functionality initialized successfully');
        });

        function initializeCharts() {
            // Budget vs Actual Comparison Chart
            const budgetActualCtx = document.getElementById('budgetActualChart')?.getContext('2d');
            if (budgetActualCtx) {
                const departments = <?php echo json_encode(array_column($budget_vs_actual_data, 'department')); ?>;
                const budgets = <?php echo json_encode(array_column($budget_vs_actual_data, 'total_budget')); ?>;
                const actuals = <?php echo json_encode(array_column($budget_vs_actual_data, 'total_expenses')); ?>;
                
                new Chart(budgetActualCtx, {
                    type: 'bar',
                    data: {
                        labels: departments,
                        datasets: [
                            {
                                label: 'Budget',
                                data: budgets,
                                backgroundColor: '#2f855A',
                                borderColor: '#28644c',
                                borderWidth: 1
                            },
                            {
                                label: 'Actual',
                                data: actuals,
                                backgroundColor: '#3B82F6',
                                borderColor: '#1D4ED8',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Variance Chart
            const varianceCtx = document.getElementById('varianceChart')?.getContext('2d');
            if (varianceCtx) {
                const departments = <?php echo json_encode(array_column($budget_vs_actual_data, 'department')); ?>;
                const variances = <?php echo json_encode(array_column($budget_vs_actual_data, 'variance')); ?>;
                
                new Chart(varianceCtx, {
                    type: 'bar',
                    data: {
                        labels: departments,
                        datasets: [{
                            label: 'Variance',
                            data: variances,
                            backgroundColor: variances.map(v => v >= 0 ? '#10B981' : '#EF4444'),
                            borderColor: variances.map(v => v >= 0 ? '#059669' : '#DC2626'),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Monthly Trend Chart
            const monthlyCtx = document.getElementById('monthlyTrendChart')?.getContext('2d');
            if (monthlyCtx) {
                const monthlyData = <?php echo json_encode($monthly_trend); ?>;
                
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const monthlyExpenses = new Array(12).fill(0);
                const monthlyIncome = new Array(12).fill(0);
                
                monthlyData.forEach(item => {
                    const monthIndex = item.month - 1;
                    monthlyExpenses[monthIndex] = item.monthly_expenses;
                    monthlyIncome[monthIndex] = item.monthly_income;
                });
                
                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [
                            {
                                label: 'Monthly Expenses',
                                data: monthlyExpenses,
                                borderColor: '#EF4444',
                                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Monthly Income',
                                data: monthlyIncome,
                                borderColor: '#10B981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Category Chart
            const categoryCtx = document.getElementById('categoryChart')?.getContext('2d');
            if (categoryCtx) {
                const categories = <?php echo json_encode(array_column($category_analysis, 'category_name')); ?>;
                const categoryAmounts = <?php echo json_encode(array_column($category_analysis, 'budget_amount')); ?>;
                
                new Chart(categoryCtx, {
                    type: 'doughnut',
                    data: {
                        labels: categories,
                        datasets: [{
                            data: categoryAmounts,
                            backgroundColor: [
                                '#2f855A', '#38A169', '#48BB78', '#68D391', '#9AE6B4',
                                '#3B82F6', '#60A5FA', '#93C5FD', '#BFDBFE', '#DBEAFE'
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
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12
                                }
                            }
                        }
                    }
                });
            }
        }
    </script>
</body>
</html>