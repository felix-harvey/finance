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

// Initialize data arrays with default values
$budget_summary = [
    'total_proposals' => 0,
    'approved_proposals' => 0,
    'rejected_proposals' => 0,
    'pending_proposals' => 0,
    'total_approved_budget' => 0,
    'avg_approved_budget' => 0,
    'max_approved_budget' => 0,
    'min_approved_budget' => 0
];
$department_reports = [];
$category_reports = [];
$approval_metrics = [];
$monthly_trends = [];
$fiscal_years = [];
$departments = [];

// Handle report filtering - sanitize inputs
$report_type = $_GET['report_type'] ?? 'summary';
$fiscal_year = isset($_GET['fiscal_year']) ? (int)$_GET['fiscal_year'] : (int)date('Y');
$department = $_GET['department'] ?? '';

try {
    // Budget Summary Report - fixed potential division by zero
    $summary_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_proposals,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_proposals,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_proposals,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_proposals,
            COALESCE(SUM(CASE WHEN status = 'Approved' THEN total_amount ELSE 0 END), 0) as total_approved_budget,
            COALESCE(AVG(CASE WHEN status = 'Approved' THEN total_amount ELSE NULL END), 0) as avg_approved_budget,
            COALESCE(MAX(CASE WHEN status = 'Approved' THEN total_amount ELSE 0 END), 0) as max_approved_budget,
            COALESCE(MIN(CASE WHEN status = 'Approved' AND total_amount > 0 THEN total_amount ELSE NULL END), 0) as min_approved_budget
        FROM budget_proposals 
        WHERE fiscal_year = ?
    ");
    $summary_stmt->execute([$fiscal_year]);
    $budget_summary_result = $summary_stmt->fetch();
    if ($budget_summary_result) {
        $budget_summary = array_merge($budget_summary, $budget_summary_result);
    }

    // Department-wise Reports - fixed division by zero
    $dept_stmt = $db->prepare("
        SELECT 
            d.name as department_name,
            COUNT(bp.id) as proposal_count,
            SUM(CASE WHEN bp.status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
            COALESCE(SUM(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE 0 END), 0) as approved_budget,
            SUM(CASE WHEN bp.status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
            COALESCE(AVG(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE NULL END), 0) as avg_budget,
            CASE 
                WHEN (SELECT COALESCE(SUM(total_amount), 0) FROM budget_proposals WHERE status = 'Approved' AND fiscal_year = ?) > 0 
                THEN (COALESCE(SUM(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE 0 END), 0) / 
                     (SELECT SUM(total_amount) FROM budget_proposals WHERE status = 'Approved' AND fiscal_year = ?)) * 100 
                ELSE 0 
            END as budget_percentage
        FROM departments d
        LEFT JOIN budget_proposals bp ON d.id = bp.department AND bp.fiscal_year = ?
        WHERE d.status = 'Active'
        GROUP BY d.id, d.name
        ORDER BY approved_budget DESC
    ");
    $dept_stmt->execute([$fiscal_year, $fiscal_year, $fiscal_year]);
    $department_reports = $dept_stmt->fetchAll();

    // Category-wise Analysis - fixed division by zero and type casting
    $cat_stmt = $db->prepare("
        SELECT 
            bc.name as category_name,
            bc.type as category_type,
            COUNT(bi.id) as item_count,
            COALESCE(SUM(bi.total_cost), 0) as total_budget,
            COALESCE(AVG(bi.total_cost), 0) as avg_cost,
            COALESCE(MAX(bi.total_cost), 0) as max_cost,
            CASE 
                WHEN (SELECT COALESCE(SUM(total_cost), 0) FROM budget_items bi2 
                      JOIN budget_proposals bp2 ON bi2.proposal_id = bp2.id 
                      WHERE bp2.fiscal_year = ? AND bp2.status = 'Approved') > 0
                THEN (COALESCE(SUM(bi.total_cost), 0) / 
                     (SELECT SUM(total_cost) FROM budget_items bi2 
                      JOIN budget_proposals bp2 ON bi2.proposal_id = bp2.id 
                      WHERE bp2.fiscal_year = ? AND bp2.status = 'Approved')) * 100 
                ELSE 0 
            END as percentage
        FROM budget_categories bc
        LEFT JOIN budget_items bi ON bc.name = bi.category
        LEFT JOIN budget_proposals bp ON bi.proposal_id = bp.id AND bp.fiscal_year = ? AND bp.status = 'Approved'
        GROUP BY bc.name, bc.type
        HAVING total_budget > 0
        ORDER BY total_budget DESC
    ");
    $cat_stmt->execute([$fiscal_year, $fiscal_year, $fiscal_year]);
    $category_reports_raw = $cat_stmt->fetchAll();
    
    // Convert percentage values to float to prevent type errors
    $category_reports = array_map(function($category) {
        $category['percentage'] = (float)$category['percentage'];
        $category['total_budget'] = (float)$category['total_budget'];
        $category['avg_cost'] = (float)$category['avg_cost'];
        $category['max_cost'] = (float)$category['max_cost'];
        $category['item_count'] = (int)$category['item_count'];
        return $category;
    }, $category_reports_raw);

    // Approval Metrics - fixed potential null issues
    $approval_stmt = $db->prepare("
        SELECT 
            COALESCE(approver_role, 'Unknown') as approver_role,
            COUNT(*) as decision_count,
            SUM(CASE WHEN action = 'Approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN action = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN action = 'Revision Requested' THEN 1 ELSE 0 END) as revision_count,
            COALESCE(AVG(TIMESTAMPDIFF(HOUR, bp.submitted_date, wa.approved_at)), 0) as avg_approval_time_hours
        FROM workflow_approvals wa
        JOIN budget_proposals bp ON wa.proposal_id = bp.id
        LEFT JOIN workflow_steps ws ON wa.step_completed = ws.step_order AND bp.department = ws.department
        WHERE YEAR(bp.submitted_date) = ?
        GROUP BY approver_role
        ORDER BY decision_count DESC
    ");
    $approval_stmt->execute([$fiscal_year]);
    $approval_metrics = $approval_stmt->fetchAll();

    // Monthly Budget Trends
    $monthly_stmt = $db->prepare("
        SELECT 
            MONTH(submitted_date) as month,
            YEAR(submitted_date) as year,
            COUNT(*) as proposal_count,
            COALESCE(SUM(total_amount), 0) as total_budget,
            COALESCE(AVG(total_amount), 0) as avg_budget
        FROM budget_proposals 
        WHERE YEAR(submitted_date) = ?
        AND status = 'Approved'
        GROUP BY YEAR(submitted_date), MONTH(submitted_date)
        ORDER BY year, month
    ");
    $monthly_stmt->execute([$fiscal_year]);
    $monthly_trends = $monthly_stmt->fetchAll();

    // Get fiscal years for dropdown - IMPROVED QUERY WITH FALLBACK
    $years_stmt = $db->query("
        SELECT DISTINCT fiscal_year 
        FROM budget_proposals 
        WHERE fiscal_year IS NOT NULL 
        AND fiscal_year != ''
        ORDER BY fiscal_year DESC
    ");
    $fiscal_years = $years_stmt ? $years_stmt->fetchAll() : [];

    // If no fiscal years found in database, create default options
    if (empty($fiscal_years)) {
        $current_year = (int)date('Y');
        $fiscal_years = [
            ['fiscal_year' => $current_year],
            ['fiscal_year' => $current_year - 1],
            ['fiscal_year' => $current_year - 2],
            ['fiscal_year' => $current_year - 3]
        ];
    }

    // Get departments for dropdown
    $dept_dropdown_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
    $departments = $dept_dropdown_stmt ? $dept_dropdown_stmt->fetchAll() : [];

} catch (Exception $e) {
    error_log("Budget reports data load error: " . $e->getMessage());
    // Ensure arrays are initialized even on error
    $budget_summary = $budget_summary ?? [];
    $department_reports = $department_reports ?? [];
    $category_reports = $category_reports ?? [];
    $approval_metrics = $approval_metrics ?? [];
    $monthly_trends = $monthly_trends ?? [];
    
    // Create default fiscal years on error
    $current_year = (int)date('Y');
    $fiscal_years = [
        ['fiscal_year' => $current_year],
        ['fiscal_year' => $current_year - 1],
        ['fiscal_year' => $current_year - 2],
        ['fiscal_year' => $current_year - 3]
    ];
    
    $departments = $departments ?? [];
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
    <title>Budget Reports | Financial Dashboard</title>
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
        /* All your existing CSS styles remain the same */
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
            box-shadow: 0px 2px6px rgba(0,0,0,0.08);
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

        button, .btn, .action-btn, .tab {
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        .modal[style*="display: none"] {
            z-index: -1;
        }

        .report-card {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }
        
        .kpi-card {
            background: linear-gradient(135deg, #2f855A 0%, #28644c 100%);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            text-align: center;
        }
        
        .comparison-positive {
            color: #10B981;
        }
        
        .comparison-negative {
            color: #EF4444;
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

        .amount-masked {
            font-family: monospace;
            letter-spacing: 2px;
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
                                
                                
                                <a href="budget_reports.php" class="submenu-item active transition-colors duration-200">Budget Reports</a>
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
                        <h1 class="text-2xl font-bold text-white">Comprehensive Budget Reports</h1>
                        <p class="text-sm text-white/90">Detailed analysis and insights into budget performance</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <!-- Add this eye button -->
                    <button id="toggle-visibility" class="toggle-visibility-btn" title="Show numbers">
                        <i class="fa-solid fa-eye-slash text-white"></i>
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
                            <label class="form-label">Report Type</label>
                            <select name="report_type" class="form-select" onchange="this.form.submit()">
                                <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary Report</option>
                                <option value="department" <?= $report_type === 'department' ? 'selected' : '' ?>>Department Analysis</option>
                                <option value="category" <?= $report_type === 'category' ? 'selected' : '' ?>>Category Analysis</option>
                                <option value="approval" <?= $report_type === 'approval' ? 'selected' : '' ?>>Approval Metrics</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fiscal Year</label>
                            <select name="fiscal_year" class="form-select" onchange="this.form.submit()">
                                <?php if (empty($fiscal_years)): ?>
                                    <!-- Fallback if no fiscal years found -->
                                    <option value="<?= date('Y') ?>" selected><?= date('Y') ?></option>
                                    <option value="<?= date('Y') - 1 ?>"><?= date('Y') - 1 ?></option>
                                    <option value="<?= date('Y') - 2 ?>"><?= date('Y') - 2 ?></option>
                                    <option value="<?= date('Y') - 3 ?>"><?= date('Y') - 3 ?></option>
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
                            <select name="department" class="form-select" onchange="this.form.submit()">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= safe_output($dept['id']) ?>" <?= $department == $dept['id'] ? 'selected' : '' ?>>
                                        <?= safe_output($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group flex items-end">
                            <button type="button" class="btn btn-primary w-full" onclick="exportFullReport()">
                                <i class="fa-solid fa-download mr-2"></i>Export Full Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Summary Report -->
<?php if ($report_type === 'summary'): ?>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <!-- Total Proposals - NOT MASKED -->
        <div class="stat-card">
            <div class="stat-value text-primary-green">
                <?= $budget_summary['total_proposals'] ?>
            </div>
            <div class="stat-label">Total Proposals</div>
        </div>
        
        <!-- Approved Proposals - NOT MASKED -->
        <div class="stat-card">
            <div class="stat-value text-green-600">
                <?= $budget_summary['approved_proposals'] ?? 0 ?>
            </div>
            <div class="stat-label">Approved Proposals</div>
        </div>
        
        <!-- Budget amounts - STILL MASKED -->
        <div class="stat-card">
            <div class="stat-value text-blue-600 amount-masked" data-amount="<?= $budget_summary['total_approved_budget'] ?? 0 ?>">
                ₱<?= str_repeat('*', strlen((string)(int)($budget_summary['total_approved_budget'] ?? 0))) ?>
            </div>
            <div class="stat-label">Total Approved Budget</div>
        </div>
        <div class="stat-card">
            <div class="stat-value text-purple-600 amount-masked" data-amount="<?= $budget_summary['avg_approved_budget'] ?? 0 ?>">
                ₱<?= str_repeat('*', strlen((string)(int)($budget_summary['avg_approved_budget'] ?? 0))) ?>
            </div>
            <div class="stat-label">Average Budget</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="metric-card">
            <h3 class="text-lg font-semibold mb-4">Proposal Status Distribution</h3>
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
        <div class="metric-card">
            <h3 class="text-lg font-semibold mb-4">Monthly Budget Trends</h3>
            <div class="chart-container">
                <canvas id="monthlyTrendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="metric-card">
    <h3 class="text-lg font-semibold mb-4">Budget Performance Summary</h3>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Metric</th>
                    <th>Count</th>
                    <th>Amount</th>
                    <th>Percentage</th>
                    <th>Trend</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_proposals = $budget_summary['total_proposals'];
                $approved_rate = $total_proposals > 0 ? ($budget_summary['approved_proposals'] / $total_proposals * 100) : 0;
                $rejected_rate = $total_proposals > 0 ? ($budget_summary['rejected_proposals'] / $total_proposals * 100) : 0;
                $pending_rate = $total_proposals > 0 ? ($budget_summary['pending_proposals'] / $total_proposals * 100) : 0;
                ?>
                <!-- Total Proposals - NOT MASKED -->
                <tr>
                    <td>Total Proposals</td>
                    <td><?= $budget_summary['total_proposals'] ?></td>
                    <td>-</td>
                    <td>100%</td>
                    <td class="comparison-positive">Base</td>
                </tr>
                
                <!-- Approved Proposals - NOT MASKED -->
<tr>
    <td>Approved Proposals</td>
    <td><?= $budget_summary['approved_proposals'] ?? 0 ?></td>
    <td class="amount-masked" data-amount="<?= $budget_summary['total_approved_budget'] ?? 0 ?>">
        ₱<?= str_repeat('*', strlen((string)(int)($budget_summary['total_approved_budget'] ?? 0))) ?>
    </td>
    <td><?= number_format($approved_rate, 1) ?>%</td>
    <td class="comparison-positive amount-masked" data-amount="<?= $budget_summary['avg_approved_budget'] ?? 0 ?>">
        ₱<?= str_repeat('*', strlen((string)(int)($budget_summary['avg_approved_budget'] ?? 0))) ?> avg
    </td>
</tr>

<!-- Rejected Proposals - NOT MASKED -->
<tr>
    <td>Rejected Proposals</td>
    <td><?= $budget_summary['rejected_proposals'] ?? 0 ?></td>
    <td>-</td>
    <td><?= number_format($rejected_rate, 1) ?>%</td>
    <td class="comparison-negative">Rejected</td>
</tr>

<!-- Pending Proposals - NOT MASKED -->
<tr>
    <td>Pending Proposals</td>
    <td><?= $budget_summary['pending_proposals'] ?? 0 ?></td>
    <td>-</td>
    <td><?= number_format($pending_rate, 1) ?>%</td>
    <td class="comparison-negative">Pending</td>
</tr>
            </tbody>
        </table>
    </div>
</div>

                <!-- Department Analysis -->
                <?php elseif ($report_type === 'department'): ?>
                    <div class="metric-card mb-6">
                        <h3 class="text-lg font-semibold mb-4">Department Budget Analysis</h3>
                        <div class="chart-container">
                            <canvas id="departmentChart"></canvas>
                        </div>
                    </div>

                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Department Performance Details</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Total Proposals</th>
                                        <th>Approved</th>
                                        <th>Rejected</th>
                                        <th>Approved Budget</th>
                                        <th>Average Budget</th>
                                        <th>Budget Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($department_reports)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-gray-500">
                                                No department data available for the selected criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($department_reports as $dept): ?>
                                            <tr>
                                                <td class="font-medium"><?= safe_output($dept['department_name'] ?? 'Unknown') ?></td>
                                                <td class="amount-masked" data-amount="<?= $dept['proposal_count'] ?? 0 ?>">
                                                    <?= str_repeat('*', strlen((string)($dept['proposal_count'] ?? 0))) ?>
                                                </td>
                                                <td class="comparison-positive amount-masked" data-amount="<?= $dept['approved_count'] ?? 0 ?>">
                                                    <?= str_repeat('*', strlen((string)($dept['approved_count'] ?? 0))) ?>
                                                </td>
                                                <td class="comparison-negative amount-masked" data-amount="<?= $dept['rejected_count'] ?? 0 ?>">
                                                    <?= str_repeat('*', strlen((string)($dept['rejected_count'] ?? 0))) ?>
                                                </td>
                                                <td class="font-semibold amount-masked" data-amount="<?= $dept['approved_budget'] ?? 0 ?>">
                                                    ₱<?= str_repeat('*', strlen((string)(int)($dept['approved_budget'] ?? 0))) ?>
                                                </td>
                                                <td class="amount-masked" data-amount="<?= $dept['avg_budget'] ?? 0 ?>">
                                                    ₱<?= str_repeat('*', strlen((string)(int)($dept['avg_budget'] ?? 0))) ?>
                                                </td>
                                                <td>
                                                    <div class="flex items-center">
                                                        <div class="w-20 bg-gray-200 rounded-full h-2 mr-2">
                                                            <div class="h-2 rounded-full bg-primary-green" style="width: <?= $dept['budget_percentage'] ?? 0 ?>%"></div>
                                                        </div>
                                                        <span class="text-sm"><?= number_format((float)($dept['budget_percentage'] ?? 0), 1) ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- Category Analysis -->
                <?php elseif ($report_type === 'category'): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div class="metric-card">
                            <h3 class="text-lg font-semibold mb-4">Budget by Category Type</h3>
                            <div class="chart-container">
                                <canvas id="categoryTypeChart"></canvas>
                            </div>
                        </div>
                        <div class="metric-card">
                            <h3 class="text-lg font-semibold mb-4">Top Budget Categories</h3>
                            <div class="chart-container">
                                <canvas id="topCategoriesChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Category Budget Details</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Item Count</th>
                                        <th>Total Budget</th>
                                        <th>Average Cost</th>
                                        <th>Maximum Cost</th>
                                        <th>Budget Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($category_reports)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-gray-500">
                                                No category data available for the selected criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($category_reports as $category): ?>
                                            <tr>
                                                <td class="font-medium"><?= safe_output($category['category_name']) ?></td>
                                                <td>
                                                    <span class="status-badge <?= $category['category_type'] === 'Revenue' ? 'variance-positive' : 'variance-negative' ?>">
                                                        <?= safe_output($category['category_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="amount-masked" data-amount="<?= $category['item_count'] ?>">
                                                    <?= str_repeat('*', strlen((string)$category['item_count'])) ?>
                                                </td>
                                                <td class="font-semibold amount-masked" data-amount="<?= $category['total_budget'] ?? 0 ?>">
                                                    ₱<?= str_repeat('*', strlen((string)(int)($category['total_budget'] ?? 0))) ?>
                                                </td>
                                                <td class="amount-masked" data-amount="<?= $category['avg_cost'] ?? 0 ?>">
                                                    ₱<?= str_repeat('*', strlen((string)(int)($category['avg_cost'] ?? 0))) ?>
                                                </td>
                                                <td class="amount-masked" data-amount="<?= $category['max_cost'] ?? 0 ?>">
                                                    ₱<?= str_repeat('*', strlen((string)(int)($category['max_cost'] ?? 0))) ?>
                                                </td>
                                                <td><?= number_format((float)$category['percentage'], 1) ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- Approval Metrics -->
                <?php elseif ($report_type === 'approval'): ?>
                    <div class="metric-card mb-6">
                        <h3 class="text-lg font-semibold mb-4">Approval Workflow Performance</h3>
                        <div class="chart-container">
                            <canvas id="approvalMetricsChart"></canvas>
                        </div>
                    </div>

                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Approval Decision Details</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Approver Role</th>
                                        <th>Total Decisions</th>
                                        <th>Approved</th>
                                        <th>Rejected</th>
                                        <th>Revision Requested</th>
                                        <th>Approval Rate</th>
                                        <th>Avg. Decision Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($approval_metrics)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-gray-500">
                                                No approval metrics available for the selected criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($approval_metrics as $metric): 
                                            $approval_rate = $metric['decision_count'] > 0 ? ($metric['approved_count'] / $metric['decision_count'] * 100) : 0;
                                        ?>
                                            <tr>
                                                <td class="font-medium"><?= safe_output($metric['approver_role']) ?></td>
                                                <td class="amount-masked" data-amount="<?= $metric['decision_count'] ?>">
                                                    <?= str_repeat('*', strlen((string)$metric['decision_count'])) ?>
                                                </td>
                                                <td class="comparison-positive amount-masked" data-amount="<?= $metric['approved_count'] ?>">
                                                    <?= str_repeat('*', strlen((string)$metric['approved_count'])) ?>
                                                </td>
                                                <td class="comparison-negative amount-masked" data-amount="<?= $metric['rejected_count'] ?>">
                                                    <?= str_repeat('*', strlen((string)$metric['rejected_count'])) ?>
                                                </td>
                                                <td class="amount-masked" data-amount="<?= $metric['revision_count'] ?>">
                                                    <?= str_repeat('*', strlen((string)$metric['revision_count'])) ?>
                                                </td>
                                                <td>
                                                    <div class="flex items-center">
                                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                                            <div class="h-2 rounded-full bg-primary-green" style="width: <?= $approval_rate ?>%"></div>
                                                        </div>
                                                        <span class="text-sm"><?= number_format((float)$approval_rate, 1) ?>%</span>
                                                    </div>
                                                </td>
                                                <td><?= number_format((float)$metric['avg_approval_time_hours'], 1) ?> hours</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Report Actions -->
                <div class="flex justify-end space-x-2 mt-6">
                    <button class="btn btn-secondary" onclick="printReport()">
                        <i class="fa-solid fa-print mr-2"></i>Print Report
                    </button>
                    <button class="btn btn-secondary" onclick="exportToPDF()">
                        <i class="fa-solid fa-file-pdf mr-2"></i>Export PDF
                    </button>
                    <button class="btn btn-secondary" onclick="exportToExcel()">
                        <i class="fa-solid fa-file-excel mr-2"></i>Export Excel
                    </button>
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
            // Initialize charts based on report type
            const reportType = '<?= $report_type ?>';
            initializeCharts(reportType);
            
            // Common functionality
            initializeCommonFunctionality();
        });

        function initializeCharts(reportType) {
            switch(reportType) {
                case 'summary':
                    initializeSummaryCharts();
                    break;
                case 'department':
                    initializeDepartmentCharts();
                    break;
                case 'category':
                    initializeCategoryCharts();
                    break;
                case 'approval':
                    initializeApprovalCharts();
                    break;
            }
        }

        function initializeSummaryCharts() {
            // Status Distribution Chart - with safety checks
            const statusCtx = document.getElementById('statusChart');
            if (statusCtx) {
                const statusData = {
                    approved: <?= $budget_summary['approved_proposals'] ?? 0 ?>,
                    rejected: <?= $budget_summary['rejected_proposals'] ?? 0 ?>,
                    pending: <?= $budget_summary['pending_proposals'] ?? 0 ?>
                };
                
                // Only create chart if we have data
                if (statusData.approved > 0 || statusData.rejected > 0 || statusData.pending > 0) {
                    new Chart(statusCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: ['Approved', 'Rejected', 'Pending'],
                            datasets: [{
                                data: [statusData.approved, statusData.rejected, statusData.pending],
                                backgroundColor: ['#10B981', '#EF4444', '#F59E0B'],
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
            }

            // Monthly Trend Chart
            const monthlyCtx = document.getElementById('monthlyTrendChart');
            if (monthlyCtx) {
                const monthlyData = <?php echo json_encode($monthly_trends); ?>;
                
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const monthlyBudgets = new Array(12).fill(0);
                const monthlyCounts = new Array(12).fill(0);
                
                monthlyData.forEach(item => {
                    const monthIndex = item.month - 1;
                    monthlyBudgets[monthIndex] = item.total_budget || 0;
                    monthlyCounts[monthIndex] = item.proposal_count || 0;
                });
                
                new Chart(monthlyCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [
                            {
                                label: 'Budget Amount (₱)',
                                data: monthlyBudgets,
                                borderColor: '#2f855A',
                                backgroundColor: 'rgba(47, 133, 90, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Proposal Count',
                                data: monthlyCounts,
                                borderColor: '#3B82F6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
            }
        }

        function initializeDepartmentCharts() {
            const deptCtx = document.getElementById('departmentChart');
            if (deptCtx) {
                const departments = <?php echo json_encode(array_column($department_reports, 'department_name')); ?>;
                const budgets = <?php echo json_encode(array_column($department_reports, 'approved_budget')); ?>;
                
                new Chart(deptCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: departments,
                        datasets: [{
                            label: 'Approved Budget (₱)',
                            data: budgets,
                            backgroundColor: '#2f855A',
                            borderColor: '#28644c',
                            borderWidth: 1
                        }]
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
        }

        function initializeCategoryCharts() {
            // Category Type Chart
            const typeCtx = document.getElementById('categoryTypeChart');
            if (typeCtx) {
                const categories = <?php echo json_encode($category_reports); ?>;
                
                const revenueTotal = categories.filter(c => c.category_type === 'Revenue')
                                             .reduce((sum, c) => sum + parseFloat(c.total_budget || 0), 0);
                const expenseTotal = categories.filter(c => c.category_type === 'Expense')
                                             .reduce((sum, c) => sum + parseFloat(c.total_budget || 0), 0);
                
                if (revenueTotal > 0 || expenseTotal > 0) {
                    new Chart(typeCtx.getContext('2d'), {
                        type: 'pie',
                        data: {
                            labels: ['Revenue', 'Expense'],
                            datasets: [{
                                data: [revenueTotal, expenseTotal],
                                backgroundColor: ['#10B981', '#EF4444'],
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
            }

            // Top Categories Chart
            const topCtx = document.getElementById('topCategoriesChart');
            if (topCtx) {
                const categories = <?php echo json_encode($category_reports); ?>;
                const topCategories = categories.slice(0, 8); // Top 8 categories
                const categoryNames = topCategories.map(c => c.category_name);
                const categoryBudgets = topCategories.map(c => c.total_budget || 0);
                
                if (categoryNames.length > 0) {
                    new Chart(topCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: categoryNames,
                            datasets: [{
                                label: 'Budget (₱)',
                                data: categoryBudgets,
                                backgroundColor: categoryNames.map((_, i) => 
                                    i % 2 === 0 ? '#2f855A' : '#3B82F6'
                                ),
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: 'y',
                            scales: {
                                x: {
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
            }
        }

        function initializeApprovalCharts() {
            const approvalCtx = document.getElementById('approvalMetricsChart');
            if (approvalCtx) {
                const metrics = <?php echo json_encode($approval_metrics); ?>;
                
                const roles = metrics.map(m => m.approver_role);
                const approved = metrics.map(m => m.approved_count || 0);
                const rejected = metrics.map(m => m.rejected_count || 0);
                const revisions = metrics.map(m => m.revision_count || 0);
                
                if (roles.length > 0) {
                    new Chart(approvalCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: roles,
                            datasets: [
                                {
                                    label: 'Approved',
                                    data: approved,
                                    backgroundColor: '#10B981'
                                },
                                {
                                    label: 'Rejected',
                                    data: rejected,
                                    backgroundColor: '#EF4444'
                                },
                                {
                                    label: 'Revision Requested',
                                    data: revisions,
                                    backgroundColor: '#F59E0B'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    stacked: true
                                },
                                x: {
                                    stacked: true
                                }
                            }
                        }
                    });
                }
            }
        }

        function initializeCommonFunctionality() {
            // Number visibility toggle functionality
            const toggleBtn = document.getElementById('toggle-visibility');
            let numbersVisible = false;
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    numbersVisible = !numbersVisible;
                    
                    const maskedElements = document.querySelectorAll('.amount-masked');
                    maskedElements.forEach(element => {
                        const amount = element.getAttribute('data-amount');
                        if (numbersVisible) {
                            // Show actual numbers
                            if (element.textContent.includes('₱')) {
                                // Currency amounts
                                element.textContent = '₱' + parseFloat(amount || 0).toLocaleString('en-PH', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            } else {
                                // Count numbers
                                element.textContent = parseFloat(amount || 0).toLocaleString();
                            }
                        } else {
                            // Show masked numbers
                            if (element.textContent.includes('₱')) {
                                // Currency amounts - mask the integer part
                                const numberStr = (amount || '0').toString();
                                const parts = numberStr.split('.');
                                const integerPart = parts[0];
                                element.textContent = '₱' + '*'.repeat(integerPart.length);
                            } else {
                                // Count numbers
                                const numberStr = (amount || '0').toString();
                                element.textContent = '*'.repeat(numberStr.length);
                            }
                        }
                    });
                    
                    // Update icon
                    const icon = toggleBtn.querySelector('i');
                    if (icon) {
                        icon.className = numbersVisible ? 'fa-solid fa-eye text-white' : 'fa-solid fa-eye-slash text-white';
                    }
                    
                    // Update tooltip
                    toggleBtn.title = numbersVisible ? 'Hide numbers' : 'Show numbers';
                });
            }
            
            // Sidebar functionality
            const hamburgerBtn = document.getElementById('hamburger-btn');
            const closeSidebar = document.getElementById('close-sidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');

            function toggleSidebar() {
                if (window.innerWidth < 769) {
                    sidebar.classList.toggle('active');
                    if (overlay) overlay.classList.toggle('active');
                } else {
                    sidebar.classList.toggle('hidden');
                    const mainContent = document.getElementById('main-content');
                    if (mainContent) mainContent.classList.toggle('full-width');
                }
            }

            if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleSidebar);
            if (closeSidebar) closeSidebar.addEventListener('click', toggleSidebar);
            if (overlay) overlay.addEventListener('click', toggleSidebar);

            // Sidebar dropdowns
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

            // Modal functionality
            const profileBtn = document.getElementById('profile-btn');
            const profileModal = document.getElementById('profile-modal');
            const notificationBtn = document.getElementById('notification-btn');
            const notificationModal = document.getElementById('notification-modal');
            const closeButtons = document.querySelectorAll('.close-modal');
            const logoutBtn = document.getElementById('logout-btn');

            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    profileModal.style.display = 'block';
                });
            }

            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function() {
                    notificationModal.style.display = 'block';
                });
            }

            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    if (profileModal) profileModal.style.display = 'none';
                    if (notificationModal) notificationModal.style.display = 'none';
                });
            });

            window.addEventListener('click', function(event) {
                if (profileModal && event.target === profileModal) {
                    profileModal.style.display = 'none';
                }
                if (notificationModal && event.target === notificationModal) {
                    notificationModal.style.display = 'none';
                }
            });

            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = '?logout=true';
                    }
                });
            }
        }

        function exportFullReport() {
            alert('Full report export functionality would be implemented here');
        }

        function printReport() {
            window.print();
        }

        function exportToPDF() {
            alert('PDF export functionality would be implemented here');
        }

        function exportToExcel() {
            alert('Excel export functionality would be implemented here');
        }
    </script>
</body>
</html>