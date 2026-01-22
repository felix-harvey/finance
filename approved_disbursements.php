<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

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
    header("Location: index.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle mark all as read
    if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
        if (isset($_SESSION['notifications'])) {
            foreach ($_SESSION['notifications'] as &$notification) {
                $notification['read'] = true;
            }
        }
        $_SESSION['notifications_read'] = true;
        $_SESSION['notifications_read_time'] = time();
        echo json_encode(['success' => true]);
        exit;
    }
}

// Initialize notifications array if not set
if (!isset($_SESSION['notifications'])) {
    $_SESSION['notifications'] = [
        [
            'type' => 'success',
            'title' => 'Request Approved',
            'message' => 'Disbursement request DISB-20250128-0001 has been approved.',
            'time' => '2 hours ago',
            'read' => false
        ],
        [
            'type' => 'info',
            'title' => 'New Approval',
            'message' => 'A new disbursement request requires your approval.',
            'time' => '5 hours ago',
            'read' => false
        ],
        [
            'type' => 'info',
            'title' => 'System Update',
            'message' => 'New features added to the disbursement module.',
            'time' => '1 day ago',
            'read' => false
        ],
        
    ];
}

// Get approved disbursements data
function getApprovedDisbursements(PDO $db): array {
    $sql = "SELECT dr.*, dr.requested_by_name
            FROM disbursement_requests dr
            WHERE dr.status = 'Approved'
            ORDER BY dr.date_approved DESC, dr.id DESC";
    return $db->query($sql)->fetchAll();
}

$approved_disbursements = getApprovedDisbursements($db);

// Calculate unread notification count
$unreadCount = 0;
if (isset($_SESSION['notifications'])) {
    foreach ($_SESSION['notifications'] as $notification) {
        if (!$notification['read']) {
            $unreadCount++;
        }
    }
}

// Check if all notifications are read
$allNotificationsRead = true;
if (isset($_SESSION['notifications'])) {
    foreach ($_SESSION['notifications'] as $notification) {
        if (!$notification['read']) {
            $allNotificationsRead = false;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approved Disbursements - Financial Dashboard</title>
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
        
        .action-btn.delete {
            background-color: #FEF2F2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.delete:hover {
            background-color: #DC2626;
            color: white;
        }

        /* Number visibility toggle styles */
        .stat-value {
            transition: all 0.3s ease;
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
        
        .hidden-amount {
            letter-spacing: 2px;
            font-family: monospace;
        }
        
        .amount-cell {
            display: flex;
            align-items: center;
        }
        
        /* Notification styles */
        .notification-item.unread {
            background-color: #f8fafc;
            border-left: 3px solid #3b82f6;
        }
        
        .notification-item.read {
            background-color: white;
            border-left: 3px solid transparent;
        }
        
        .notification-dot {
            transition: all 0.3s ease;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
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

    <!-- Modal for Notifications -->
    <div id="notification-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Notifications</h2>
            <div class="space-y-4 max-h-96 overflow-y-auto" id="notification-list">
                <!-- Dynamic notifications from session -->
                <?php if (isset($_SESSION['notifications']) && !empty($_SESSION['notifications'])): ?>
                    <?php foreach ($_SESSION['notifications'] as $index => $notification): ?>
                        <div class="p-3 border border-gray-200 rounded-lg notification-item <?php echo $notification['read'] ? 'read' : 'unread'; ?>" data-index="<?php echo $index; ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <h4 class="font-medium text-<?php 
                                        echo $notification['type'] === 'success' ? 'green' : 
                                             ($notification['type'] === 'warning' ? 'yellow' : 
                                             ($notification['type'] === 'error' ? 'red' : 'blue')); ?>-600">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                    </h4>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($notification['time']); ?></p>
                                </div>
                                <span class="w-2 h-2 <?php echo $notification['read'] ? 'bg-gray-400' : (
                                    $notification['type'] === 'success' ? 'bg-green-500' : 
                                    ($notification['type'] === 'warning' ? 'bg-yellow-500' : 
                                    ($notification['type'] === 'error' ? 'bg-red-500' : 'bg-blue-500'))
                                ); ?> rounded-full mt-2 notification-dot"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-gray-500">
                        No notifications found.
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-6 pt-4 border-t border-gray-200">
                <button id="mark-all-read" class="btn btn-secondary w-full" <?php echo $allNotificationsRead ? 'disabled' : ''; ?>>
                    <?php echo $allNotificationsRead ? 'All Notifications Read' : 'Mark All as Read'; ?>
                </button>
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
                                <a href="disbursement_request.php" class="submenu-item transition-colors duration-200">Disbursement Request</a>
                                <a href="pending_disbursements.php" class="submenu-item transition-colors duration-200">Pending Disbursements</a>
                                <a href="approved_disbursements.php" class="submenu-item active transition-colors duration-200">Approved Disbursements</a>
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
                            <div class="submenu mt-1" id="budget-submenu">
                                <a href="budget_proposal.php" class="submenu-item transition-colors duration-200">Budget Proposal</a>
                                <a href="approval_workflow.php" class="submenu-item transition-colors duration-200">Approval Workflow</a>
                                <a href="budget_vs_actual.php" class="submenu-item transition-colors duration-200">Budget vs Actual</a>
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
                        <h1 class="text-2xl font-bold text-white">Approved Disbursements</h1>
                        <p class="text-sm text-white/90">View all approved disbursement requests</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <!-- Visibility Toggle Button -->
                    <button id="visibility-toggle" class="relative p-2 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                        <i class="fa-solid fa-eye-slash text-xl text-white"></i>
                    </button>
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-badge" id="notification-badge"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Approved Disbursements Content -->
                <div class="bg-white rounded-xl p-6 card-shadow">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-dark-text">Approved Disbursements</h2>
                        <div class="text-sm text-gray-500">
                            Total: <?php echo count($approved_disbursements); ?> approved requests
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Description</th>
                                    <th>Requested By</th>
                                    <th>Amount</th>
                                    <th>Date Approved</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($approved_disbursements) > 0): ?>
                                    <?php foreach ($approved_disbursements as $request): ?>
                                    <tr>
                                        <td class="font-medium"><?php echo htmlspecialchars($request['request_id']); ?></td>
                                        <td><?php echo htmlspecialchars($request['description'] ?? 'No description'); ?></td>
                                        <td><?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?></td>
                                        <td class="font-semibold">
    <div class="amount-cell">
        <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)$request['amount'], 2); ?>">
            ********
        </span>
        <button class="visibility-toggle" onclick="toggleAmountVisibility(this)">
            <i class="fa-solid fa-eye-slash"></i>
        </button>
    </div>
</td>
                                        <td><?php echo !empty($request['date_approved']) ? date('M j, Y', strtotime($request['date_approved'])) : 'Not set'; ?></td>
                                        <td>
                                            <span class="status-badge status-approved">Approved</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-gray-500">
                                            No approved disbursement requests found.
                                        </td>
                                    </tr>
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
        // JavaScript functionality
        document.addEventListener('DOMContentLoaded', function() {
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

            // Modal functionality
            const profileBtn = document.getElementById('profile-btn');
            const notificationBtn = document.getElementById('notification-btn');
            const profileModal = document.getElementById('profile-modal');
            const notificationModal = document.getElementById('notification-modal');
            const closeButtons = document.querySelectorAll('.close-modal');

            // Profile button click
            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    profileModal.style.display = 'block';
                });
            }

            // Notification button click
            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function() {
                    notificationModal.style.display = 'block';
                });
            }

            // Close buttons functionality
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    profileModal.style.display = 'none';
                    notificationModal.style.display = 'none';
                });
            });

            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === profileModal) {
                    profileModal.style.display = 'none';
                }
                if (event.target === notificationModal) {
                    notificationModal.style.display = 'none';
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

            // Mark All as Read functionality with AJAX
            const markAllReadBtn = document.getElementById('mark-all-read');
            
            if (markAllReadBtn && !markAllReadBtn.disabled) {
                markAllReadBtn.addEventListener('click', function() {
                    // Send AJAX request to mark all as read
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_all_read'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI
                            const notificationItems = document.querySelectorAll('.notification-item');
                            notificationItems.forEach(item => {
                                item.classList.remove('unread');
                                item.classList.add('read');
                                
                                // Change dot color to gray
                                const dot = item.querySelector('.notification-dot');
                                if (dot) {
                                    dot.className = 'w-2 h-2 bg-gray-400 rounded-full mt-2 notification-dot';
                                }
                            });
                            
                            // Update button
                            markAllReadBtn.textContent = 'All Notifications Read';
                            markAllReadBtn.disabled = true;
                            
                            // Remove notification badge
                            const notificationBadge = document.getElementById('notification-badge');
                            if (notificationBadge) {
                                notificationBadge.remove();
                            }
                            
                            // Show confirmation
                            showNotification('All notifications marked as read', 'success');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Error marking notifications as read', 'error');
                    });
                });
            }
            
            function showNotification(message, type = 'info') {
                // Create toast notification
                const toast = document.createElement('div');
                toast.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
                    type === 'success' ? 'bg-green-500 text-white' : 
                    type === 'error' ? 'bg-red-500 text-white' : 
                    'bg-blue-500 text-white'
                }`;
                toast.textContent = message;
                
                document.body.appendChild(toast);
                
                // Remove toast after 3 seconds
                setTimeout(() => {
                    toast.remove();
                }, 3000);
            }

            // Highlight current page in sidebar
            const currentPage = window.location.pathname.split('/').pop();
            const menuItems = document.querySelectorAll('.submenu-item');
            
            menuItems.forEach(item => {
                if (item.getAttribute('href') === currentPage) {
                    item.classList.add('active');
                    // Also open the parent submenu
                    const category = item.closest('.submenu').id.replace('-submenu', '');
                    const submenu = document.getElementById(`${category}-submenu`);
                    const arrow = document.querySelector(`.category-arrow[data-category="${category}"]`);
                    
                    if (submenu && arrow) {
                        submenu.classList.add('active');
                        arrow.classList.add('rotate-180');
                    }
                }
            });

            // Initialize all amounts as hidden
            const amountSpans = document.querySelectorAll('.amount-value');
            amountSpans.forEach(span => {
                span.textContent = '********';
                span.classList.add('hidden-amount');
            });
        });

        // Amount visibility toggle functionality
        let amountsVisible = false;
        
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

        // Global visibility toggle
        document.getElementById('visibility-toggle').addEventListener('click', function() {
            const toggleButtons = document.querySelectorAll('.visibility-toggle');
            const globalIcon = this.querySelector('i');
            
            amountsVisible = !amountsVisible;
            
            toggleButtons.forEach(button => {
                const amountSpan = button.parentElement.querySelector('.amount-value');
                const icon = button.querySelector('i');
                
                if (amountsVisible) {
                    // Show all amounts
                    const actualAmount = amountSpan.getAttribute('data-value');
                    amountSpan.textContent = actualAmount;
                    amountSpan.classList.remove('hidden-amount');
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                } else {
                    // Hide all amounts
                    amountSpan.textContent = '********';
                    amountSpan.classList.add('hidden-amount');
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
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
    </script>
</body>
</html>