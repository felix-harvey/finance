<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    // Database connection
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Authentication check
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: login.php");
    exit;
}

// Get notifications for the user
function getNotifications(PDO $db, int $user_id): array {
    $sql = "SELECT * FROM notifications 
            WHERE user_id = ? OR user_id IS NULL 
            ORDER BY created_at DESC 
            LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Mark notification as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    exit;
}

// Mark all notifications as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? OR user_id IS NULL");
    $stmt->execute([$user_id]);
    exit;
}

// Get chart of accounts for filter
function getChartOfAccounts(PDO $db): array {
    $sql = "SELECT id, account_code, account_name, account_type 
            FROM chart_of_accounts 
            WHERE status = 'Active'
            ORDER BY account_type, account_code";
    return $db->query($sql)->fetchAll();
}

// Get ledger data with filters - UPDATED WITH NULL SAFETY
function getLedgerData(PDO $db, ?int $account_id = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT 
                jel.id,
                COALESCE(je.entry_date, '') as entry_date,
                COALESCE(je.entry_id, '') as entry_id,
                COALESCE(je.description, '') as description,
                COALESCE(coa.account_code, '') as account_code,
                COALESCE(coa.account_name, '') as account_name,
                COALESCE(coa.account_type, '') as account_type,
                COALESCE(jel.debit, 0) as debit,
                COALESCE(jel.credit, 0) as credit,
                (SELECT COALESCE(SUM(debit - credit), 0) 
                 FROM journal_entry_lines jel2 
                 JOIN journal_entries je2 ON jel2.journal_entry_id = je2.id 
                 WHERE jel2.account_id = coa.id 
                 AND je2.entry_date <= je.entry_date
                 AND (je2.entry_date < je.entry_date OR jel2.id <= jel.id)
                ) as running_balance
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE je.status = 'Posted'";
    
    $params = [];
    
    if ($account_id) {
        $sql .= " AND coa.id = ?";
        $params[] = $account_id;
    }
    
    if ($start_date) {
        $sql .= " AND je.entry_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND je.entry_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " ORDER BY coa.account_code, je.entry_date, je.id, jel.id";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll();
    
    // Ensure all values are properly set
    foreach ($result as &$row) {
        $row['entry_date'] = $row['entry_date'] ?? '';
        $row['entry_id'] = $row['entry_id'] ?? '';
        $row['description'] = $row['description'] ?? '';
        $row['account_code'] = $row['account_code'] ?? '';
        $row['account_name'] = $row['account_name'] ?? '';
        $row['account_type'] = $row['account_type'] ?? '';
        $row['debit'] = $row['debit'] ?? 0;
        $row['credit'] = $row['credit'] ?? 0;
        $row['running_balance'] = $row['running_balance'] ?? 0;
    }
    
    return $result;
}

// Get account summary
function getAccountSummary(PDO $db, ?int $account_id = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT 
                coa.id,
                coa.account_code,
                coa.account_name,
                coa.account_type,
                COALESCE(SUM(jel.debit), 0) as total_debit,
                COALESCE(SUM(jel.credit), 0) as total_credit,
                COALESCE(SUM(jel.debit - jel.credit), 0) as net_balance
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id AND je.status = 'Posted'
            WHERE coa.status = 'Active'";
    
    $params = [];
    
    if ($account_id) {
        $sql .= " AND coa.id = ?";
        $params[] = $account_id;
    }
    
    if ($start_date) {
        $sql .= " AND je.entry_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND je.entry_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
              ORDER BY coa.account_type, coa.account_code";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get filter values from request and properly handle types
$account_id = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$chart_accounts = getChartOfAccounts($db);
$ledger_data = getLedgerData($db, $account_id, $start_date, $end_date);
$account_summary = getAccountSummary($db, $account_id, $start_date, $end_date);

// Get notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, function($notification) {
    return !$notification['is_read'];
});
$unread_count = count($unread_notifications);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ledger Table - Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
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
        /* Copy all the CSS styles from journal_entry.php */
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
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #2f855A;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
        
        .status-approved {
            background-color: rgba(104, 211, 145, 0.1);
            color: #68D391;
        }
        
        .status-rejected {
            background-color: rgba(229, 62, 62, 0.1);
            color: #E53E3E;
        }
        
        .status-pending {
            background-color: rgba(251, 191, 36, 0.1);
            color: #F59E0B;
        }
        
        .status-overdue {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
        }
        
        .action-btn {
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            border: 1px solid;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #1D4ED8;
        }
        
        .action-btn.view:hover {
            background-color: #1D4ED8;
            color: white;
        }
        
        .action-btn.export {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.export:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.print {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.print:hover {
            background-color: #0369A1;
            color: white;
        }

        /* Ledger specific styles */
        .debit-amount {
            color: #10B981;
            font-weight: 600;
        }
        
        .credit-amount {
            color: #EF4444;
            font-weight: 600;
        }
        
        .balance-positive {
            color: #10B981;
            font-weight: 600;
        }
        
        .balance-negative {
            color: #EF4444;
            font-weight: 600;
        }
        
        .account-header {
            background-color: #f8fafc !important;
            font-weight: 600;
            border-top: 2px solid #e2e8f0;
        }
        
        .account-total {
            background-color: #f1f5f9 !important;
            font-weight: 600;
            border-top: 2px solid #cbd5e1;
        }
        
        .running-balance {
            font-family: 'Courier New', monospace;
        }

        /* Balance hide/show styles - ADDED FROM REFERENCE */
        .amount-cell {
            display: flex;
            align-items: center;
        }

        .amount-value {
            transition: all 0.3s ease;
        }

        .hidden-amount {
            letter-spacing: 2px;
            font-family: monospace;
        }

        .visibility-toggle {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            margin-left: 0.5rem;
            color: #6b7280;
            transition: color 0.3s ease;
        }

        .visibility-toggle:hover {
            color: #374151;
        }

        .balance-hidden {
            filter: blur(4px);
            user-select: none;
        }
        
        .balance-toggle {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: background-color 0.2s;
        }
        
        .balance-toggle:hover {
            background-color: #f3f4f6;
        }

	/* Notification styles - ADDED FROM REFERENCE */
.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: #EF4444;
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    width: 350px;
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    z-index: 100;
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
    transition: background-color 0.2s;
}

.notification-item:hover {
    background-color: #f9fafb;
}

.notification-item.unread {
    background-color: #f0f9ff;
    border-left: 3px solid #3B82F6;
}

.notification-item.read {
    opacity: 0.7;
}

.notification-time {
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.25rem;
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
                    <h3 class="text-lg font-bold" id="profile-name"><?php echo htmlspecialchars($user['name']); ?></h3>
                    <p class="text-gray-500"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></p>
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
                    <p class="text-xs text-white/90 mt-1">Microfinancial Management System 1</p>
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
                            <div class="submenu active mt-1" id="ledger-submenu">
                                <a href="chart_of_accounts.php" class="submenu-item transition-colors duration-200">Chart of Accounts</a>
                                <a href="journal_entry.php" class="submenu-item transition-colors duration-200">Journal Entry</a>
                                <a href="ledger_table.php" class="submenu-item active transition-colors duration-200">Ledger Table</a>
                                
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
            <!-- Header - UPDATED WITH NOTIFICATION SYSTEM -->
<div class="bg-primary-green text-white p-4 flex justify-between items-center">
    <div class="flex items-center">
        <button id="hamburger-btn" class="mr-4">
            <div class="hamburger-line"></div>
            <div class="hamburger-line"></div>
            <div class="hamburger-line"></div>
        </button>
        <div>
            <h1 class="text-2xl font-bold text-white">Ledger Table</h1>
            <p class="text-sm text-white/90">View account transactions and running balances</p>
        </div>
    </div>
    <div class="flex items-center space-x-4">
        <!-- Balance Toggle Button -->
        <button id="visibility-toggle" class="relative p-2 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
            <i class="fa-solid fa-eye-slash text-xl text-white"></i>
        </button>
        
        <!-- Notification Bell - ADDED FROM REFERENCE -->
        <div class="relative" id="notification-container">
            <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                <i class="fa-solid fa-bell text-xl text-white"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="notification-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </button>
            
            <!-- Notification Dropdown -->
            <div id="notification-dropdown" class="notification-dropdown">
                <div class="p-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Notifications</h3>
                        <?php if ($unread_count > 0): ?>
                            <button id="mark-all-read" class="text-sm text-blue-600 hover:text-blue-800">Mark all as read</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="p-4 text-center text-gray-500">
                            No notifications
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>" data-id="<?php echo $notification['id']; ?>">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800"><?php echo htmlspecialchars($notification['title']); ?></h4>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                                        <div class="notification-time">
                                            <?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?>
                                        </div>
                                    </div>
                                    <?php if (!$notification['is_read']): ?>
                                        <div class="w-2 h-2 bg-blue-500 rounded-full ml-2 mt-2"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
            <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
            <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
            <i class="fa-solid fa-chevron-down text-sm text-white"></i>
        </div>
    </div>
</div>
            
            <div class="p-6 flex-1">
                <!-- Filter Section -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <h3 class="text-lg font-bold text-dark-text mb-4">Filter Ledger</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
                            <label class="form-label">Account</label>
                            <select name="account_id" class="form-input">
                                <option value="">All Accounts</option>
                                <?php foreach ($chart_accounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>" <?php echo ($account_id == $account['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="form-group flex items-end space-x-2">
                            <button type="submit" class="btn btn-primary flex-1">
                                <i class='bx bx-filter-alt mr-2'></i>Apply
                            </button>
                            <a href="ledger_table.php" class="btn btn-secondary">
                                <i class='bx bx-reset mr-2'></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Account Summary - UPDATED WITH BALANCE TOGGLE -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-dark-text">Account Summary</h3>
                        <div class="flex space-x-2">
                            <button class="action-btn export">
                                <i class='bx bx-download mr-1'></i>Export
                            </button>
                            <button class="action-btn print" onclick="window.print()">
                                <i class='bx bx-printer mr-1'></i>Print
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th>Account Type</th>
                                    <th>Total Debit</th>
                                    <th>Total Credit</th>
                                    <th>Net Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($account_summary) > 0): ?>
                                    <?php 
                                    $grand_total_debit = 0;
                                    $grand_total_credit = 0;
                                    $grand_net_balance = 0;
                                    ?>
                                    <?php foreach ($account_summary as $account): 
                                        $grand_total_debit += (float)$account['total_debit'];
                                        $grand_total_credit += (float)$account['total_credit'];
                                        $grand_net_balance += (float)$account['net_balance'];
                                    ?>
                                    <tr>
                                        <td class="font-mono font-medium"><?php echo htmlspecialchars($account['account_code']); ?></td>
                                        <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                        <td>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?php echo $account['account_type'] === 'Asset' ? 'bg-blue-100 text-blue-800' : 
                                                       ($account['account_type'] === 'Liability' ? 'bg-red-100 text-red-800' : 
                                                       ($account['account_type'] === 'Equity' ? 'bg-purple-100 text-purple-800' : 
                                                       ($account['account_type'] === 'Revenue' ? 'bg-green-100 text-green-800' : 
                                                       'bg-yellow-100 text-yellow-800'))); ?>">
                                                <?php echo htmlspecialchars($account['account_type']); ?>
                                            </span>
                                        </td>
                                        <td class="debit-amount">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format((float)$account['total_debit'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="credit-amount">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format((float)$account['total_credit'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="<?php echo (float)$account['net_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format((float)$account['net_balance'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <!-- Grand Total Row -->
                                    <tr class="account-total">
                                        <td colspan="3" class="font-bold">GRAND TOTAL</td>
                                        <td class="debit-amount font-bold">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format($grand_total_debit, 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="credit-amount font-bold">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format($grand_total_credit, 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="<?php echo $grand_net_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?> font-bold">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format($grand_net_balance, 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-gray-500">
                                            No ledger data found for the selected filters.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Detailed Ledger Table - UPDATED TO SHOW ALL ACCOUNTS -->
<div class="bg-white rounded-xl p-6 card-shadow">
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-lg font-bold text-dark-text">Detailed Ledger Transactions</h3>
        <div class="text-sm text-gray-500">
            <?php 
            $total_accounts = count($chart_accounts);
            $accounts_with_transactions = 0;
            if (!empty($ledger_data)) {
                $account_codes = array_filter(array_column($ledger_data, 'account_code'));
                $accounts_with_transactions = count(array_unique($account_codes));
            }
            echo "$accounts_with_transactions accounts with transactions out of $total_accounts total accounts";
            ?>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Entry ID</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th>Debit</th>
                    <th>Credit</th>
                    <th>Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($chart_accounts) > 0): ?>
                    <?php 
                    $current_account = '';
                    $account_running_total = 0;
                    ?>
                    
                    <?php foreach ($chart_accounts as $account): ?>
                        <?php 
                        $account_transactions = [];
                        if (!empty($ledger_data)) {
                            $account_transactions = array_filter($ledger_data, function($t) use ($account) {
                                return isset($t['account_code']) && $t['account_code'] === $account['account_code'];
                            });
                        }
                        ?>
                        
                        <!-- Account Header -->
                        <tr class="account-header">
                            <td colspan="7" class="font-bold py-3 bg-gray-50">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                        <span class="text-sm font-normal text-gray-600">
                                            (<?php echo htmlspecialchars($account['account_type']); ?>)
                                        </span>
                                    </div>
                                    <div class="text-sm font-normal text-gray-600">
                                        <?php echo count($account_transactions); ?> transaction(s)
                                    </div>
                                </div>
                            </td>
                        </tr>
                        
                        <?php if (count($account_transactions) > 0): ?>
                            <?php 
                            $account_running_total = 0;
                            $account_debit_total = 0;
                            $account_credit_total = 0;
                            ?>
                            
                            <?php foreach ($account_transactions as $index => $transaction): ?>
                                <?php 
                                $debit = isset($transaction['debit']) ? (float)$transaction['debit'] : 0;
                                $credit = isset($transaction['credit']) ? (float)$transaction['credit'] : 0;
                                $account_running_total += $debit - $credit;
                                $account_debit_total += $debit;
                                $account_credit_total += $credit;
                                ?>
                                <tr>
                                    <td><?php echo isset($transaction['entry_date']) ? htmlspecialchars($transaction['entry_date']) : ''; ?></td>
                                    <td class="font-mono"><?php echo isset($transaction['entry_id']) ? htmlspecialchars($transaction['entry_id']) : ''; ?></td>
                                    <td class="font-mono"><?php echo isset($transaction['account_code']) ? htmlspecialchars($transaction['account_code']) : ''; ?></td>
                                    <td class="max-w-xs"><?php echo isset($transaction['description']) ? htmlspecialchars($transaction['description']) : ''; ?></td>
                                    <td class="debit-amount">
                                        <?php if ($debit > 0): ?>
                                        <div class="amount-cell">
                                            <span class="amount-value hidden-amount" 
                                                  data-value="₱<?php echo number_format($debit, 2); ?>">
                                                ********
                                            </span>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="credit-amount">
                                        <?php if ($credit > 0): ?>
                                        <div class="amount-cell">
                                            <span class="amount-value hidden-amount" 
                                                  data-value="₱<?php echo number_format($credit, 2); ?>">
                                                ********
                                            </span>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="running-balance <?php echo $account_running_total >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                        <div class="amount-cell">
                                            <span class="amount-value hidden-amount" 
                                                  data-value="₱<?php echo number_format($account_running_total, 2); ?>">
                                                ********
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- Account Total -->
                            <tr class="account-total">
                                <td colspan="4" class="font-bold text-right bg-gray-100">Account Total for <?php echo htmlspecialchars($account['account_code']); ?>:</td>
                                <td class="debit-amount font-bold bg-gray-100">
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format($account_debit_total, 2); ?>">
                                            ********
                                        </span>
                                    </div>
                                </td>
                                <td class="credit-amount font-bold bg-gray-100">
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format($account_credit_total, 2); ?>">
                                            ********
                                        </span>
                                    </div>
                                </td>
                                <td class="running-balance font-bold bg-gray-100 <?php echo $account_running_total >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format($account_running_total, 2); ?>">
                                            ********
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            
                        <?php else: ?>
                            <!-- No Transactions Message -->
                            <tr>
                                <td colspan="7" class="text-center py-6 text-gray-500 bg-gray-50">
                                    <div class="flex flex-col items-center">
                                        <i class='bx bx-file-blank text-4xl text-gray-300 mb-2'></i>
                                        <p class="font-medium">No transactions found</p>
                                        <p class="text-sm">This account has no transactions in the selected period</p>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Empty Account Total -->
                            <tr class="account-total">
                                <td colspan="4" class="font-bold text-right bg-gray-100">Account Total for <?php echo htmlspecialchars($account['account_code']); ?>:</td>
                                <td class="debit-amount font-bold bg-gray-100">
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount" data-value="₱0.00">
                                            ********
                                        </span>
                                    </div>
                                </td>
                                <td class="credit-amount font-bold bg-gray-100">
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount" data-value="₱0.00">
                                            ********
                                        </span>
                                    </div>
                                </td>
                                <td class="running-balance font-bold bg-gray-100 balance-positive">
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount" data-value="₱0.00">
                                            ********
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <!-- Spacer between accounts -->
                        <tr>
                            <td colspan="7" class="py-2 bg-white"></td>
                        </tr>
                        
                    <?php endforeach; ?>
                    
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-8 text-gray-500">
                            <div class="flex flex-col items-center">
                                <i class='bx bx-wallet-alt text-5xl text-gray-300 mb-4'></i>
                                <p class="text-lg font-medium mb-2">No Accounts Found</p>
                                <p class="text-sm">No chart of accounts have been created yet.</p>
                                <a href="chart_of_accounts.php" class="btn btn-primary mt-4">
                                    <i class='bx bx-plus mr-2'></i>Create Your First Account
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>            
            <!-- Footer  -->
<footer class="main-footer">
    <div class="text-center">
        <p class="text-sm">© 2025 Financial Dashboard. All rights reserved.</p>
        <p class="text-xs mt-1 opacity-80">Powered by Microfinancial Management System</p>
    </div>
</footer>

    <script>
    // JavaScript functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Balance visibility toggle functionality - FROM REFERENCE CODE
        let amountsVisible = false;
        
        // Global toggle function
        document.getElementById('visibility-toggle').addEventListener('click', function() {
            const toggleButtons = document.querySelectorAll('.visibility-toggle');
            const globalIcon = this.querySelector('i');
            
            amountsVisible = !amountsVisible;
            
            const amountSpans = document.querySelectorAll('.amount-value');
            amountSpans.forEach(span => {
                if (amountsVisible) {
                    // Show amount
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    // Hide amount
                    span.textContent = '********';
                    span.classList.add('hidden-amount');
                }
            });
            
            // Update global toggle icon
            if (amountsVisible) {
                globalIcon.classList.remove('fa-eye-slash');
                globalIcon.classList.add('fa-eye');
            } else {
                globalIcon.classList.remove('fa-eye');
                globalIcon.classList.add('fa-eye-slash');
            }
        });

        // Initialize all amounts as hidden
        const amountSpans = document.querySelectorAll('.amount-value');
        amountSpans.forEach(span => {
            span.textContent = '********';
            span.classList.add('hidden-amount');
        });

        // Individual toggle function
        function toggleAmountVisibility(button) {
            const amountSpan = button.parentElement.querySelector('.amount-value');
            const icon = button.querySelector('i');
            
            if (amountsVisible) {
                // Hide amount
                amountSpan.textContent = '********';
                amountSpan.classList.add('hidden-amount');
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                // Show amount
                const actualAmount = amountSpan.getAttribute('data-value');
                amountSpan.textContent = actualAmount;
                amountSpan.classList.remove('hidden-amount');
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
            
            amountsVisible = !amountsVisible;
        }

        // Hamburger menu functionality
        const hamburgerBtn = document.getElementById('hamburger-btn');
        const closeSidebar = document.getElementById('close-sidebar');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        const mainContent = document.getElementById('main-content');

        if (hamburgerBtn && sidebar && overlay && closeSidebar && mainContent) {
            function toggleSidebar() {
                if (window.innerWidth < 769) {
                    sidebar.classList.toggle('active');
                    overlay.classList.toggle('active');
                } else {
                    sidebar.classList.toggle('hidden');
                    mainContent.classList.toggle('full-width');
                }
            }

            hamburgerBtn.addEventListener('click', toggleSidebar);
            closeSidebar.addEventListener('click', function() {
                if (window.innerWidth < 769) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                } else {
                    sidebar.classList.add('hidden');
                    mainContent.classList.add('full-width');
                }
            });

            overlay.addEventListener('click', function() {
                if (window.innerWidth < 769) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 769) {
                    // On desktop, ensure overlay is hidden
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
                
                // Toggle the active class on the submenu
                submenu.classList.toggle('active');
                
                // Rotate the arrow
                arrow.classList.toggle('rotate-180');
            });
        });

        // Modal functionality
        const profileBtn = document.getElementById('profile-btn');
        const profileModal = document.getElementById('profile-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function() {
                profileModal.style.display = 'block';
            });
        }
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                profileModal.style.display = 'none';
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
            }
        });
        
        // Logout button functionality
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = '?logout=true';
                }
            });
        }

        // Export functionality
        const exportBtn = document.querySelector('.action-btn.export');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                // Show loading state
                const originalText = this.innerHTML;
                this.innerHTML = '<div class="spinner"></div>Exporting...';
                this.disabled = true;
                
                // Simulate export process
                setTimeout(() => {
                    // Create CSV content
                    let csvContent = "Ledger Report\n\n";
                    csvContent += "Account Summary\n";
                    csvContent += "Account Code,Account Name,Account Type,Total Debit,Total Credit,Net Balance\n";
                    
                    // Add account summary data
                    <?php foreach ($account_summary as $account): ?>
                    csvContent += "<?php echo $account['account_code']; ?>,<?php echo $account['account_name']; ?>,<?php echo $account['account_type']; ?>,<?php echo $account['total_debit']; ?>,<?php echo $account['total_credit']; ?>,<?php echo $account['net_balance']; ?>\n";
                    <?php endforeach; ?>
                    
                    csvContent += "\nDetailed Transactions\n";
                    csvContent += "Date,Entry ID,Account,Description,Debit,Credit,Running Balance\n";
                    
                    // Add transaction data
                    <?php 
                    $current_account = '';
                    $account_running_total = 0;
                    foreach ($ledger_data as $index => $transaction): 
                        if ($current_account !== $transaction['account_code']) {
                            $current_account = $transaction['account_code'];
                            $account_running_total = 0;
                        }
                        $account_running_total += (float)$transaction['debit'] - (float)$transaction['credit'];
                    ?>
                    csvContent += "<?php echo $transaction['entry_date']; ?>,<?php echo $transaction['entry_id']; ?>,<?php echo $transaction['account_code']; ?>,<?php echo addslashes($transaction['description']); ?>,<?php echo $transaction['debit']; ?>,<?php echo $transaction['credit']; ?>,<?php echo $account_running_total; ?>\n";
                    <?php endforeach; ?>
                    
                    // Create and download CSV file
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const url = URL.createObjectURL(blob);
                    link.setAttribute('href', url);
                    link.setAttribute('download', 'ledger_report_<?php echo date('Y-m-d'); ?>.csv');
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // Restore button state
                    this.innerHTML = originalText;
                    this.disabled = false;
                    
                    // Show success message
                    alert('Ledger report exported successfully!');
                }, 1000);
            });
        }

        // Auto-apply date filters to current month if not set
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');
        
        if (startDateInput && !startDateInput.value) {
            const firstDay = new Date();
            firstDay.setDate(1);
            startDateInput.value = firstDay.toISOString().split('T')[0];
        }
        
        if (endDateInput && !endDateInput.value) {
            const lastDay = new Date();
            lastDay.setMonth(lastDay.getMonth() + 1);
            lastDay.setDate(0);
            endDateInput.value = lastDay.toISOString().split('T')[0];
        }

        // Add print styles
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                #sidebar, #hamburger-btn, #profile-btn, .action-btn, .main-footer {
                    display: none !important;
                }
                #main-content {
                    margin-left: 0 !important;
                    width: 100% !important;
                }
                .card-shadow {
                    box-shadow: none !important;
                    border: 1px solid #e5e7eb !important;
                }
                .bg-gray-bg {
                    background-color: white !important;
                }
                .bg-white {
                    background-color: white !important;
                }
            }
        `;
        document.head.appendChild(style);

        // Initialize ledger-specific functionality
        initializeLedgerScripts();
    });

    function initializeLedgerScripts() {
        console.log('Initializing ledger scripts');
        
        // Add search functionality to the ledger table
        const addSearchFunctionality = () => {
            const searchContainer = document.createElement('div');
            searchContainer.className = 'mb-4 flex space-x-4';
            searchContainer.innerHTML = `
                <div class="form-group flex-1">
                    <label class="form-label">Search Transactions</label>
                    <input type="text" id="ledger-search" class="form-input" placeholder="Search by description, account, or entry ID...">
                </div>
                <div class="form-group flex items-end">
                    <button id="clear-search" class="btn btn-secondary">Clear</button>
                </div>
            `;
            
            const ledgerTable = document.querySelector('.bg-white.rounded-xl.p-6.card-shadow:last-child');
            if (ledgerTable) {
                const header = ledgerTable.querySelector('.flex.justify-between.items-center.mb-6');
                if (header) {
                    header.parentNode.insertBefore(searchContainer, header.nextSibling);
                    
                    const searchInput = document.getElementById('ledger-search');
                    const clearButton = document.getElementById('clear-search');
                    const tableBody = ledgerTable.querySelector('tbody');
                    
                    if (searchInput && tableBody) {
                        searchInput.addEventListener('input', function() {
                            const searchTerm = this.value.toLowerCase();
                            const rows = tableBody.querySelectorAll('tr');
                            let visibleRows = 0;
                            
                            rows.forEach(row => {
                                // Skip account header and total rows
                                if (row.classList.contains('account-header') || row.classList.contains('account-total')) {
                                    return;
                                }
                                
                                const text = row.textContent.toLowerCase();
                                if (text.includes(searchTerm)) {
                                    row.style.display = '';
                                    visibleRows++;
                                } else {
                                    row.style.display = 'none';
                                }
                            });
                            
                            // Update transaction count
                            const countElement = ledgerTable.querySelector('.text-sm.text-gray-500');
                            if (countElement) {
                                countElement.textContent = `${visibleRows} transactions found${searchTerm ? ' (filtered)' : ''}`;
                            }
                        });
                        
                        clearButton.addEventListener('click', function() {
                            searchInput.value = '';
                            const rows = tableBody.querySelectorAll('tr');
                            rows.forEach(row => {
                                row.style.display = '';
                            });
                            
                            const countElement = ledgerTable.querySelector('.text-sm.text-gray-500');
                            if (countElement) {
                                countElement.textContent = '<?php echo count($ledger_data); ?> transactions found';
                            }
                        });
                    }
                }
            }
        };
        
        // Add search functionality after a short delay to ensure DOM is ready
        setTimeout(addSearchFunctionality, 100);
    }

    // Individual amount toggle function (for individual eye buttons)
    function toggleAmountVisibility(button) {
        const amountSpan = button.parentElement.querySelector('.amount-value');
        const icon = button.querySelector('i');
        
        if (amountSpan.classList.contains('hidden-amount')) {
            // Show amount
            const actualAmount = amountSpan.getAttribute('data-value');
            amountSpan.textContent = actualAmount;
            amountSpan.classList.remove('hidden-amount');
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        } else {
            // Hide amount
            amountSpan.textContent = '********';
            amountSpan.classList.add('hidden-amount');
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    }

	// Notification functionality - ADDED FROM REFERENCE
const notificationBtn = document.getElementById('notification-btn');
const notificationDropdown = document.getElementById('notification-dropdown');
const notificationItems = document.querySelectorAll('.notification-item');
const markAllReadBtn = document.getElementById('mark-all-read');

// Toggle notification dropdown
if (notificationBtn && notificationDropdown) {
    notificationBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        notificationDropdown.style.display = 
            notificationDropdown.style.display === 'block' ? 'none' : 'block';
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function() {
    if (notificationDropdown) {
        notificationDropdown.style.display = 'none';
    }
});

// Mark notification as read when clicked
notificationItems.forEach(item => {
    item.addEventListener('click', function() {
        const notificationId = this.getAttribute('data-id');
        if (!this.classList.contains('read')) {
            // Mark as read via AJAX
            const formData = new FormData();
            formData.append('action', 'mark_notification_read');
            formData.append('notification_id', notificationId);
            
            fetch('', {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    this.classList.remove('unread');
                    this.classList.add('read');
                    this.querySelector('.bg-blue-500')?.remove();
                    
                    // Update notification count
                    updateNotificationCount();
                }
            });
        }
    });
});

// Mark all as read
if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        
        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        
        fetch('', {
            method: 'POST',
            body: formData
        }).then(response => {
            if (response.ok) {
                // Update all notifications to read
                notificationItems.forEach(item => {
                    item.classList.remove('unread');
                    item.classList.add('read');
                    item.querySelector('.bg-blue-500')?.remove();
                });
                
                // Update notification count
                updateNotificationCount();
                
                // Hide mark all button
                markAllReadBtn.style.display = 'none';
            }
        });
    });
}

function updateNotificationCount() {
    const unreadItems = document.querySelectorAll('.notification-item.unread');
    const notificationBadge = document.querySelector('.notification-badge');
    
    if (unreadItems.length === 0) {
        if (notificationBadge) {
            notificationBadge.remove();
        }
    } else {
        if (!notificationBadge) {
            // Create badge if it doesn't exist
            const badge = document.createElement('span');
            badge.className = 'notification-badge';
            notificationBtn.appendChild(badge);
        }
        document.querySelector('.notification-badge').textContent = unreadItems.length;
    }
}

</script>
</body>
</html>