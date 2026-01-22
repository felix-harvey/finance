<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

try {
    // --- DB connection ---
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// --- Auth Guard ---
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
if ($user_id <= 0) {
    header("Location: index.php");
    exit;
}

// --- Logout Logic ---
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// --- Load Current User ---
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: login.php");
    exit;
}
// --- Add this block to handle Disbursement Creation ---
    if (isset($_POST['action']) && $_POST['action'] === 'create_disbursement') {
        try {
            // Check if table exists (create if not - safety check)
            $db->query("SELECT 1 FROM disbursement_requests LIMIT 1");
            
            $stmt = $db->prepare("INSERT INTO disbursement_requests (requested_by, department, description, amount, status, date_requested) VALUES (?, ?, ?, ?, 'Pending', NOW())");
            
            // Assuming the 'requested_by' field in form is a name string. 
            // If you want to link to user ID, you might need to adjust logic, but this matches your form.
            $stmt->execute([
                $_POST['requested_by'], 
                $_POST['department'], 
                $_POST['description'], 
                $_POST['amount']
            ]);
            
            // Redirect to avoid resubmission
            header("Location: dashboard8.php?success=1");
            exit;
        } catch (Exception $e) {
            // Table likely doesn't exist or DB error
        }
    }
    // -----------------------------------------------------
// --- Handle AJAX Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle mark all as read
    if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
        // Only run if table exists
        try {
            $stmt = $db->prepare("UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// --- Functions ---

function loadNotificationsFromDatabase(PDO $db, int $user_id): array {
    // Check/Create table logic (Simplified for production speed, keeping your logic)
    try {
        $db->query("SELECT 1 FROM user_notifications LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            notification_type VARCHAR(50),
            title VARCHAR(255),
            message TEXT,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        // Insert defaults if table just created... (omitted for brevity, keeping your existing logic)
    }
    
    $stmt = $db->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $dbNotifications = $stmt->fetchAll();
    
    $notifications = [];
    foreach ($dbNotifications as $notification) {
        $notifications[] = [
            'type' => $notification['notification_type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'time' => getTimeAgo($notification['created_at']),
            'read' => (bool)$notification['is_read'],
            'db_id' => $notification['id']
        ];
    }
    return $notifications;
}

function getTimeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $timeDiff = time() - $time;
    if ($timeDiff < 60) return 'Just now';
    if ($timeDiff < 3600) return floor($timeDiff / 60) . ' mins ago';
    if ($timeDiff < 86400) return floor($timeDiff / 3600) . ' hours ago';
    return floor($timeDiff / 86400) . ' days ago';
}

function getDashboardStats(PDO $db): array {
    // 1. Income (Revenue)
    $rev = $db->query("SELECT COALESCE(SUM(balance),0) as total FROM chart_of_accounts WHERE account_type='Revenue'")->fetch()['total'] ?? 0;
    
    // 2. Expenses
    $exp = $db->query("SELECT COALESCE(SUM(balance),0) as total FROM chart_of_accounts WHERE account_type='Expense'")->fetch()['total'] ?? 0;
    
    // 3. Cash Flow
    $cashFlow = (float)$rev - (float)$exp;
    
    // 4. Upcoming Payments
    try {
        $stmt = $db->query("SELECT COALESCE(SUM(amount),0) as total FROM invoices WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status='Pending' AND type='Payable'");
        $upcoming = $stmt->fetch()['total'] ?? 0;
    } catch (Exception $e) { $upcoming = 0; }

    return [
        'total_income'      => (float)$rev,
        'total_expenses'    => (float)$exp,
        'cash_flow'         => (float)$cashFlow,
        'upcoming_payments' => (float)$upcoming,
    ];
}

function getRecentTransactions(PDO $db, int $limit = 5): array {
    // Check tables exist to prevent crash
    $tables = [];
    foreach(['disbursement_requests', 'payments'] as $t) {
        try { $db->query("SELECT 1 FROM $t LIMIT 1"); $tables[] = $t; } catch(Exception $e){}
    }
    
    $parts = [];
    if(in_array('disbursement_requests', $tables)) {
        $parts[] = "SELECT 'Disbursement' as type, id, request_id as ref, description as name, date_requested as date, amount, status FROM disbursement_requests";
    }
    if(in_array('payments', $tables)) {
        $parts[] = "SELECT 'Payment' as type, id, payment_id as ref, 'Payment' as name, payment_date as date, amount, status FROM payments WHERE type='Receive'"; // Assuming 'Receive' is income
    }
    
    if(empty($parts)) return [];
    
    $sql = implode(" UNION ALL ", $parts) . " ORDER BY date DESC LIMIT " . $limit;
    return $db->query($sql)->fetchAll();
}



// --- CHART DATA QUERIES ---
function getMonthlyChartData(PDO $db) {
    // Initialize 12 months with 0
    $incomeData = array_fill(0, 12, 0);
    $expenseData = array_fill(0, 12, 0);
    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    try {
        // 1. GET INCOME: Sums up payments where type = 'Receive'
        $incStmt = $db->query("SELECT MONTH(payment_date) as m, SUM(amount) as total FROM payments WHERE type='Receive' AND YEAR(payment_date) = YEAR(CURDATE()) GROUP BY m");
        while($row = $incStmt->fetch()) {
            $incomeData[$row['m'] - 1] = (float)$row['total'];
        }
        
        // 2. GET EXPENSES: Sums up payments where type = 'Make'
        $expStmt = $db->query("SELECT MONTH(payment_date) as m, SUM(amount) as total FROM payments WHERE type='Make' AND YEAR(payment_date) = YEAR(CURDATE()) GROUP BY m");
        while($row = $expStmt->fetch()) {
            $expenseData[$row['m'] - 1] = (float)$row['total'];
        }
    } catch (Exception $e) {
        // If tables don't exist yet, just return zeros
    }
    
    return ['labels' => $labels, 'income' => $incomeData, 'expense' => $expenseData];
}

function getBudgetDistribution(PDO $db) {
    $labels = [];
    $data = [];
    // Colors matching your theme
    $colors = ['#2F855A', '#88BE3C', '#68D391', '#3182CE', '#E53E3E', '#F6E05E']; 
    
    try {
        // Sum Approved amounts by Department
        // Note: Change 'Approved' to 'Pending' if you want to see pending requests in the chart too
        $stmt = $db->query("
            SELECT department, SUM(amount) as total 
            FROM disbursement_requests 
            WHERE status IN ('Approved', 'Pending') 
            GROUP BY department
        ");
        
        while($row = $stmt->fetch()) {
            $labels[] = $row['department'];
            $data[] = (float)$row['total'];
        }
    } catch (Exception $e) {
        // Table missing
    }
    
    // Default data if empty (so chart doesn't look broken)
    if (empty($data)) {
        return [
            'labels' => ['No Data'], 
            'data' => [1], 
            'colors' => ['#e5e7eb']
        ];
    }
    
    return ['labels' => $labels, 'data' => $data, 'colors' => array_slice($colors, 0, count($data))];
}

function getUpcomingDueDates(PDO $db) {
    try {
        // Check if table exists
        $db->query("SELECT 1 FROM invoices LIMIT 1");
        
        // Fetch pending payable invoices due in the future, sorted by closest date
        $stmt = $db->query("
            SELECT i.id, i.invoice_number, i.due_date, i.amount, c.name as vendor_name
            FROM invoices i
            LEFT JOIN business_contacts c ON i.contact_id = c.id
            WHERE i.type = 'Payable' 
            AND i.status = 'Pending' 
            AND i.due_date >= CURDATE()
            ORDER BY i.due_date ASC
            LIMIT 5
        ");
        
        $results = $stmt->fetchAll();
        
        // Format for the frontend
        $formatted = [];
        foreach($results as $row) {
            // Display "Vendor Name" or "Invoice #123" if vendor is missing
            $name = $row['vendor_name'] 
                ? $row['vendor_name'] . ' (#' . $row['invoice_number'] . ')' 
                : 'Invoice #' . $row['invoice_number'];
                
            $formatted[] = [
                'id' => $row['id'],
                'name' => $name,
                'date' => $row['due_date'],
                'amount' => (float)$row['amount']
            ];
        }
        return $formatted;
        
    } catch (Exception $e) {
        return []; // Return empty array if table doesn't exist yet
    }
}

// --- Execute Data Fetching ---
$dashboard_stats = getDashboardStats($db);
$show_all_transactions = isset($_GET['view']) && $_GET['view'] === 'all_transactions';
$recent_transactions = getRecentTransactions($db, $show_all_transactions ? 100 : 5);
$notifications = loadNotificationsFromDatabase($db, $user_id);
$chart_data = getMonthlyChartData($db); // Fetch data for charts
$budget_data = getBudgetDistribution($db);
$upcoming_due_dates = getUpcomingDueDates($db);

// Count Unread
$unreadCount = 0;
foreach($notifications as $n) { if(!$n['read']) $unreadCount++; }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $show_all_transactions ? 'All Transactions - Financial Dashboard' : 'Financial Dashboard'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
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
            background-color: rgba(229, 62, 62, 0.1);
            color: #E53E3E;
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
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 25px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .modal-content h2 {
            margin-top: 0;
            padding-right: 30px;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            color: #6b7280;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10;
            background: none;
            border: none;
            padding: 0;
            line-height: 1;
            transition: color 0.3s ease;
        }
        
        .close-modal:hover {
            color: #1f2937;
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
            transition: all 0.3s ease;
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
        
        .action-btn.delete {
            background-color: #FEF2F2;
            color: #EF4444;
            border: 1px solid #EF4444;
        }
        
        .action-btn.approve {
            background-color: #F0FDF4;
            color: #10B981;
            border: 1px solid #10B981;
        }
        
        .action-btn.reject {
            background-color: #FEF2F2;
            color: #EF4444;
            border: 1px solid #EF4444;
        }

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
            user-select: none;
        }

        .transaction-amount.hidden-amount {
            letter-spacing: 2px;
            font-family: monospace;
        }

        .view-transaction-btn {
            background-color: #F0FDF4;
            color: #10B981;
            border: 1px solid #10B981;
            padding: 0.4rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .view-transaction-btn:hover {
            background-color: #10B981;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
        }

        .back-to-dashboard {
            background-color: #6B7280;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .back-to-dashboard:hover {
            background-color: #4B5563;
        }
        
        .bg-white.rounded-xl.p-6.card-shadow {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .bg-white.rounded-xl.p-6.card-shadow:hover {
            transform: translateY(-5px);
            box-shadow: 0px 8px 25px rgba(0,0,0,0.15);
        }
        
        /* Scrollbar styling for modal */
        .modal-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .modal-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .modal-content::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Ensure modal is properly positioned on mobile */
        @media (max-width: 768px) {
            .modal-content {
                margin: 10% auto;
                width: 95%;
                max-height: 85vh;
            }
        }
        
        /* Notification badge */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #EF4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Notification items */
        .notification-item {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 8px;
            background-color: #f8fafc;
            border-left: 4px solid #2F855A;
        }
        
        .notification-item.unread {
            background-color: #f0f9ff;
            border-left-color: #3B82F6;
        }
        
        .notification-item.read {
            opacity: 0.8;
        }
        
        .notification-time {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }
    </style>
</head>
<body class="bg-gray-bg">
    <!-- Overlay for mobile sidebar -->
    <div class="overlay" id="overlay"></div>
    
    <!-- Modal for notifications -->
    <div id="notification-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Notifications</h2>
            <div id="notification-list">
                <!-- Notifications will be loaded here -->
            </div>
        </div>
    </div>
    
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
                    
                    <h4 class="font-medium mb-2">System</h4>
                    
                    <button class="btn btn-secondary w-full" id="logout-btn">Logout</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal for Create Disbursement -->
    <div id="create-disbursement-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Create Disbursement Request</h2>

            <form id="disbursement-form" method="POST">
                <input type="hidden" name="action" value="create_disbursement">

                <div class="form-group">
                    <label class="form-label">Requested By</label>
                    <input type="text" name="requested_by" class="form-input" placeholder="Enter requestor name" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Department</label>
                    <select name="department" class="form-input" required>
                        <option value="">Select Department</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Operations">Operations</option>
                        <option value="IT">IT</option>
                        <option value="HR">HR</option>
                        <option value="Finance">Finance</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Enter description" required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" name="amount" class="form-input" placeholder="Enter amount" step="0.01" min="0" required>
                </div>

                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Submit Request</button>
                </div>
            </form>
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
                                <a href="pending_disbursements.php" class="submenu-item">Pending Disbursements</a>
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
                                
                                
                                <a href="budget_reports.php" class="submenu-item transition-colors duration-200">Budget Reports</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer inside sidebar with same color -->
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
                        <h1 class="text-2xl font-bold text-white">
                            <?php echo $show_all_transactions ? 'All Transactions - Financial Dashboard' : 'Financial With Predictive Budgeting And Cash Flow Forecasting Using Time Series Analysis'; ?>
                        </h1>
                        <p class="text-sm text-white/90">
                            <?php echo $show_all_transactions ? 'Complete transaction history' : 'Welcome back, here\'s your financial overview'; ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <!-- Visibility Toggle Button -->
                    <button id="visibility-toggle" class="relative p-2 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                        <i class="fa-solid fa-eye-slash text-xl text-white"></i>
                    </button>
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                    </button>
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <?php if ($show_all_transactions): ?>
                    <!-- All Transactions View -->
                    <div class="space-y-6">
                        <!-- Header for All Transactions -->
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-dark-text">All Transactions</h2>
                                <p class="text-gray-600">Complete transaction history - Total: <?php echo count($recent_transactions); ?> transactions</p>
                            </div>
                            <div class="flex space-x-3">
                                <button class="back-to-dashboard" onclick="window.location.href='dashboard8.php'">
                                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                                </button>
                                <button id="print-transactions" class="btn btn-primary">
                                    <i class="fas fa-print"></i> Print
                                </button>
                            </div>
                        </div>

                        <!-- Transaction Summary -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-white rounded-lg p-4 card-shadow">
                                <div class="text-sm text-gray-500">Total Transactions</div>
                                <div class="text-2xl font-bold text-primary-green"><?php echo count($recent_transactions); ?></div>
                            </div>
                            <div class="bg-white rounded-lg p-4 card-shadow">
                                <div class="text-sm text-gray-500">Total Amount</div>
                                <div class="text-2xl font-bold text-green-600">
                                    ₱<?php 
                                    $totalAmount = array_sum(array_column($recent_transactions, 'amount'));
                                    echo number_format($totalAmount, 2);
                                    ?>
                                </div>
                            </div>
                            <div class="bg-white rounded-lg p-4 card-shadow">
                                <div class="text-sm text-gray-500">Completed/Approved</div>
                                <div class="text-2xl font-bold text-blue-600">
                                    <?php 
                                    $completed = array_filter($recent_transactions, function($t) {
                                        return in_array($t['status'], ['Completed', 'Approved']);
                                    });
                                    echo count($completed);
                                    ?>
                                </div>
                            </div>
                            <div class="bg-white rounded-lg p-4 card-shadow">
                                <div class="text-sm text-gray-500">Pending</div>
                                <div class="text-2xl font-bold text-yellow-600">
                                    <?php 
                                    $pending = array_filter($recent_transactions, function($t) {
                                        return $t['status'] === 'Pending';
                                    });
                                    echo count($pending);
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- All Transactions Table -->
                        <div class="bg-white rounded-xl p-6 card-shadow">
                            <div class="flex justify-between items-center mb-6">
                                <div class="flex items-center">
                                    <h3 class="text-lg font-bold text-dark-text mr-3">All Transactions</h3>
                                    <button id="all-transactions-visibility-toggle" class="relative p-1 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                                        <i class="fa-solid fa-eye-slash text-lg text-gray-500"></i>
                                    </button>
                                </div>
                                <div class="text-sm text-gray-500">
                                    Showing <?php echo count($recent_transactions); ?> transactions
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="text-left text-sm text-gray-500 border-b border-gray-200">
                                            <th class="pb-3">Transaction</th>
                                            <th class="pb-3">Type</th>
                                            <th class="pb-3">Date</th>
                                            <th class="pb-3">Amount</th>
                                            <th class="pb-3">Status</th>
                                            <th class="pb-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="all-transactions-table-body">
                                        <!-- All transaction rows will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Original Dashboard Content -->
                    <section id="dashboard-content" class="space-y-6 mb-8">
                        <!-- Stats Overview Section -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex items-center">
                                    <div class="p-3 rounded-full bg-green-100 mr-4">
                                        <i class='bx bx-money text-green-600 text-2xl'></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <p class="text-sm text-gray-500">Total Income</p>
                                                <p class="text-2xl font-bold text-dark-text stat-value" data-value="₱<?php echo number_format($dashboard_stats['total_income'], 2); ?>">
                                                    ********
                                                </p>
                                            </div>
                                            <button class="visibility-toggle stat-toggle" data-stat="income">
                                                <i class="fa-solid fa-eye-slash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex items-center">
                                    <div class="p-3 rounded-full bg-red-100 mr-4">
                                        <i class='bx bx-credit-card text-red-600 text-2xl'></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <p class="text-sm text-gray-500">Total Expenses</p>
                                                <p class="text-2xl font-bold text-dark-text stat-value" data-value="₱<?php echo number_format($dashboard_stats['total_expenses'], 2); ?>">
                                                    ********
                                                </p>
                                            </div>
                                            <button class="visibility-toggle stat-toggle" data-stat="expenses">
                                                <i class="fa-solid fa-eye-slash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex items-center">
                                    <div class="p-3 rounded-full bg-yellow-100 mr-4">
                                        <i class='bx bx-wallet text-yellow-600 text-2xl'></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <p class="text-sm text-gray-500">Cash Flow</p>
                                                <p class="text-2xl font-bold text-dark-text stat-value" data-value="₱<?php echo number_format($dashboard_stats['cash_flow'], 2); ?>">
                                                    ********
                                                </p>
                                            </div>
                                            <button class="visibility-toggle stat-toggle" data-stat="cashflow">
                                                <i class="fa-solid fa-eye-slash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex items-center">
                                    <div class="p-3 rounded-full bg-blue-100 mr-4">
                                        <i class='bx bx-calendar text-blue-600 text-2xl'></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <p class="text-sm text-gray-500">Upcoming Payments</p>
                                                <p class="text-2xl font-bold text-dark-text stat-value" data-value="₱<?php echo number_format($dashboard_stats['upcoming_payments'], 2); ?>">
                                                    ********
                                                </p>
                                            </div>
                                            <button class="visibility-toggle stat-toggle" data-stat="payments">
                                                <i class="fa-solid fa-eye-slash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Charts Section -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                            <!-- Income vs Expenses Chart -->
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-lg font-bold text-dark-text">Income vs Expenses</h3>
                                    <div class="flex items-center space-x-2">
                                        <span class="flex items-center">
                                            <span class="w-3 h-3 rounded-full bg-primary-green mr-2"></span>
                                            <span class="text-xs text-gray-500">Income</span>
                                        </span>
                                        <span class="flex items-center">
                                            <span class="w-3 h-3 rounded-full bg-green-600 mr-2"></span>
                                            <span class="text-xs text-gray-500">Expenses</span>
                                        </span>
                                    </div>
                                </div>
                                <div class="h-80">
                                    <canvas id="incomeExpenseChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Budget Distribution Chart -->
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-lg font-bold text-dark-text">Budget Distribution</h3>
                                    <button id="refresh-budget-chart" class="text-xs text-green-600 flex items-center hover:opacity-85">
                                        <i class='bx bx-refresh text-xl'></i>
                                    </button>
                                </div>
                                <div class="h-80">
                                    <canvas id="budgetChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Transactions -->
                        <div class="bg-white rounded-xl p-6 card-shadow mb-8">
                            <div class="flex justify-between items-center mb-6">
                                <div class="flex items-center">
                                    <h3 class="text-lg font-bold text-dark-text mr-3">Recent Transactions</h3>
                                    <button id="transactions-visibility-toggle" class="relative p-1 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                                        <i class="fa-solid fa-eye-slash text-lg text-gray-500"></i>
                                    </button>
                                </div>
                                <button id="view-all-transactions" class="text-xs text-green-600 flex items-center hover:opacity-85">
                                    View all <i class='bx bx-chevron-right text-xl'></i>
                                </button>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="text-left text-sm text-gray-500 border-b border-gray-200">
                                            <th class="pb-3">Transaction</th>
                                            <th class="pb-3">Date</th>
                                            <th class="pb-3">Amount</th>
                                            <th class="pb-3">Status</th>
                                            <th class="pb-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="transactions-table-body">
                                        <!-- Transaction rows will be dynamically loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Upcoming Payments -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Upcoming Due Dates -->
                            <div class="bg-white rounded-xl p-6 card-shadow">
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center">
            <h3 class="text-lg font-bold text-dark-text mr-3">Upcoming Due Dates</h3>
            <button id="due-dates-visibility-toggle" class="relative p-1 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                <i class="fa-solid fa-eye-slash text-lg text-gray-500"></i>
            </button>
        </div>
        <button id="view-all-due-dates" class="text-xs text-green-600 flex items-center hover:opacity-85">
            View all <i class='bx bx-chevron-right text-xl'></i>
        </button>
    </div>
    <div class="space-y-4" id="due-dates-list">
        </div>
</div>
                            
                            <!-- Recent Notifications -->
                            <div class="bg-white rounded-xl p-6 card-shadow">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-lg font-bold text-dark-text">Recent Notifications</h3>
                                    <button id="view-all-notifications" class="text-xs text-green-600 flex items-center hover:opacity-85">
                                        View all <i class='bx bx-chevron-right text-xl'></i>
                                    </button>
                                </div>
                                <div class="space-y-4" id="notifications-list">
                                    <!-- Dynamic notifications will appear here -->
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
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
        const showAllTransactions = <?php echo $show_all_transactions ? 'true' : 'false'; ?>;
        
        if (showAllTransactions) {
            initializeAllTransactionsView();
        } else {
            initializeDashboardView();
        }

        initializeCommonFeatures();
    });

    function initializeAllTransactionsView() {
        // Initialize transaction visibility toggle
        const allTransactionsVisibilityToggle = document.getElementById('all-transactions-visibility-toggle');
        
        if (allTransactionsVisibilityToggle) {
            // Set initial state
            const savedVisibility = localStorage.getItem('transactionsVisible') === 'true';
            const eyeIcon = allTransactionsVisibilityToggle.querySelector('i');
            if (savedVisibility) {
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
                allTransactionsVisibilityToggle.title = "Hide Amounts";
            } else {
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
                allTransactionsVisibilityToggle.title = "Show Amounts";
            }
            
            // Add click event
            allTransactionsVisibilityToggle.addEventListener('click', function() {
                const currentVisibility = localStorage.getItem('transactionsVisible') === 'true';
                const newVisibility = !currentVisibility;
                
                const eyeIcon = this.querySelector('i');
                if (newVisibility) {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                    this.title = "Hide Amounts";
                } else {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                    this.title = "Show Amounts";
                }
                
                // Update all transaction amounts in the all transactions view
                const amountCells = document.querySelectorAll('#all-transactions-table-body .transaction-amount');
                amountCells.forEach(cell => {
                    const actualValue = cell.getAttribute('data-value');
                    if (newVisibility) {
                        cell.textContent = actualValue;
                        cell.classList.remove('hidden-amount');
                    } else {
                        cell.textContent = '********';
                        cell.classList.add('hidden-amount');
                    }
                });
                
                localStorage.setItem('transactionsVisible', newVisibility);
            });
        }

        function loadAllTransactions() {
            const transactions = <?php echo json_encode($recent_transactions); ?>;
            const tableBody = document.getElementById('all-transactions-table-body');
            
            if (tableBody) {
                tableBody.innerHTML = '';
                
                if (!transactions || transactions.length === 0) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="6" class="py-4 text-center text-gray-500">No transactions found</td>
                        </tr>
                    `;
                    return;
                }
                
                const savedVisibility = localStorage.getItem('transactionsVisible') === 'true';
                
                transactions.forEach(transaction => {
                    const statusClass = transaction.status === 'Completed' || transaction.status === 'Approved' ? 'status-completed' : 
                                      transaction.status === 'Rejected' ? 'status-rejected' : 'status-pending';
                    const formattedAmount = `₱${parseFloat(transaction.amount || 0).toLocaleString()}`;
                    const displayAmount = savedVisibility ? formattedAmount : '********';
                    const amountClass = savedVisibility ? '' : 'hidden-amount';
                    
                    const row = document.createElement('tr');
                    row.className = 'border-b border-gray-100';
                    row.innerHTML = `
                        <td class="py-3">
                            <div class="font-medium text-dark-text">${transaction.name || 'N/A'}</div>
                            <div class="text-xs text-gray-500">Ref: ${transaction.reference_id || 'N/A'}</div>
                        </td>
                        <td class="py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                ${transaction.type || 'Transaction'}
                            </span>
                        </td>
                        <td class="py-3 text-gray-500">${transaction.date || 'N/A'}</td>
                        <td class="py-3 font-medium transaction-amount ${amountClass}" data-value="${formattedAmount}">
                            ${displayAmount}
                        </td>
                        <td class="py-3">
                            <span class="status-badge ${statusClass}">${transaction.status || 'Pending'}</span>
                        </td>
                        <td class="py-3">
                            <button class="view-transaction-btn" 
                                    data-id="${transaction.id}" 
                                    data-type="${transaction.type}"
                                    data-status="${transaction.status}">
                                <i class='bx bx-show'></i> View
                            </button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
                
                document.querySelectorAll('.view-transaction-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const transactionId = this.getAttribute('data-id');
                        const transactionType = this.getAttribute('data-type');
                        const transactionStatus = this.getAttribute('data-status');
                        viewTransactionDetails(transactionId, transactionType, transactionStatus);
                    });
                });
            }
        }

        const printBtn = document.getElementById('print-transactions');
        if (printBtn) {
            printBtn.addEventListener('click', function() {
                window.print();
            });
        }

        loadAllTransactions();
    }

    function initializeDashboardView() {
        // Initialize transaction visibility toggle
        const transactionsVisibilityToggle = document.getElementById('transactions-visibility-toggle');
        
        if (transactionsVisibilityToggle) {
            // Set initial state
            const savedVisibility = localStorage.getItem('transactionsVisible') === 'true';
            const eyeIcon = transactionsVisibilityToggle.querySelector('i');
            if (savedVisibility) {
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
                transactionsVisibilityToggle.title = "Hide Amounts";
            } else {
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
                transactionsVisibilityToggle.title = "Show Amounts";
            }
            
            // Add click event
            transactionsVisibilityToggle.addEventListener('click', function() {
                const currentVisibility = localStorage.getItem('transactionsVisible') === 'true';
                const newVisibility = !currentVisibility;
                
                const eyeIcon = this.querySelector('i');
                if (newVisibility) {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                    this.title = "Hide Amounts";
                } else {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                    this.title = "Show Amounts";
                }
                
                // Update all transaction amounts
                const amountCells = document.querySelectorAll('#transactions-table-body .transaction-amount');
                amountCells.forEach(cell => {
                    const actualValue = cell.getAttribute('data-value');
                    if (newVisibility) {
                        cell.textContent = actualValue;
                        cell.classList.remove('hidden-amount');
                    } else {
                        cell.textContent = '********';
                        cell.classList.add('hidden-amount');
                    }
                });
                
                localStorage.setItem('transactionsVisible', newVisibility);
            });
        }

        function loadTransactions() {
            const transactions = <?php echo json_encode($recent_transactions); ?>;
            const tableBody = document.getElementById('transactions-table-body');
            
            if (tableBody) {
                tableBody.innerHTML = '';
                
                if (!transactions || transactions.length === 0) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="5" class="py-4 text-center text-gray-500">No recent transactions found</td>
                        </tr>
                    `;
                    return;
                }
                
                const savedVisibility = localStorage.getItem('transactionsVisible') === 'true';
                
                if (transactionsVisibilityToggle) {
                    const eyeIcon = transactionsVisibilityToggle.querySelector('i');
                    if (savedVisibility) {
                        eyeIcon.classList.remove('fa-eye-slash');
                        eyeIcon.classList.add('fa-eye');
                        transactionsVisibilityToggle.title = "Hide Amounts";
                    } else {
                        eyeIcon.classList.remove('fa-eye');
                        eyeIcon.classList.add('fa-eye-slash');
                        transactionsVisibilityToggle.title = "Show Amounts";
                    }
                }
                
                transactions.forEach(transaction => {
                    const statusClass = transaction.status === 'Completed' || transaction.status === 'Approved' ? 'status-completed' : 
                                      transaction.status === 'Rejected' ? 'status-rejected' : 'status-pending';
                    const formattedAmount = `₱${parseFloat(transaction.amount || 0).toLocaleString()}`;
                    const displayAmount = savedVisibility ? formattedAmount : '********';
                    const amountClass = savedVisibility ? '' : 'hidden-amount';
                    
                    const row = document.createElement('tr');
                    row.className = 'border-b border-gray-100';
                    row.innerHTML = `
                        <td class="py-3">
                            <div class="font-medium text-dark-text">${transaction.name || 'N/A'}</div>
                            <div class="text-xs text-gray-500">${transaction.type || 'Transaction'}</div>
                        </td>
                        <td class="py-3 text-gray-500">${transaction.date || 'N/A'}</td>
                        <td class="py-3 font-medium transaction-amount ${amountClass}" data-value="${formattedAmount}">
                            ${displayAmount}
                        </td>
                        <td class="py-3">
                            <span class="status-badge ${statusClass}">${transaction.status || 'Pending'}</span>
                        </td>
                        <td class="py-3">
                            <button class="view-transaction-btn" 
                                    data-id="${transaction.id}" 
                                    data-type="${transaction.type}"
                                    data-status="${transaction.status}">
                                <i class='bx bx-show'></i> View
                            </button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
                
                document.querySelectorAll('.view-transaction-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const transactionId = this.getAttribute('data-id');
                        const transactionType = this.getAttribute('data-type');
                        const transactionStatus = this.getAttribute('data-status');
                        viewTransactionDetails(transactionId, transactionType, transactionStatus);
                    });
                });
            }
        }

        function setupViewAllTransactions() {
            const viewAllBtn = document.getElementById('view-all-transactions');
            if (viewAllBtn) {
                viewAllBtn.addEventListener('click', function() {
                    window.location.href = 'dashboard8.php?view=all_transactions';
                });
            }
        }

        loadTransactions();
        setupViewAllTransactions();
        
        // Initialize charts and other dashboard components
        initializeCharts();
    }

    function viewTransactionDetails(transactionId, transactionType, transactionStatus = '') {
        let redirectUrl = '';
        let params = `?id=${transactionId}`;
        
        if (transactionStatus) {
            params += `&status=${transactionStatus}`;
        }
        
        switch(transactionType) {
            case 'Disbursement':
                if (transactionStatus === 'Pending') {
                    redirectUrl = `pending_disbursements.php${params}`;
                } else if (transactionStatus === 'Approved') {
                    redirectUrl = `approved_disbursements.php${params}`;
                } else if (transactionStatus === 'Rejected') {
                    redirectUrl = `rejected_disbursements.php${params}`;
                } else {
                    redirectUrl = `disbursement_request.php${params}`;
                }
                break;
            case 'Payment':
                redirectUrl = `payment_entry.php${params}`;
                break;
            case 'Invoice':
                redirectUrl = `invoices.php${params}`;
                break;
            case 'Journal':
                redirectUrl = `journal_entry.php${params}`;
                break;
            case 'Budget':
                redirectUrl = `budget_proposal.php${params}`;
                break;
            default:
                redirectUrl = `dashboard8.php${params}`;
                break;
        }
        
        window.location.href = redirectUrl;
    }

    function initializeCommonFeatures() {
        // Initialize individual stat toggles
        const statToggles = document.querySelectorAll('.stat-toggle');
        const statValues = document.querySelectorAll('.stat-value');
        const visibilityToggle = document.getElementById('visibility-toggle');

        // Initialize each stat individually
        statToggles.forEach(toggle => {
            const statType = toggle.getAttribute('data-stat');
            const savedState = localStorage.getItem(`stat_${statType}_visible`);
            const isVisible = savedState === null ? false : savedState === 'true';
            
            // Find the corresponding stat value
            const statCard = toggle.closest('.bg-white.rounded-xl.p-6');
            let statValue = null;
            if (statCard) {
                statValue = statCard.querySelector('.stat-value');
            }
            
            // Set initial state for this stat
            if (statValue) {
                const actualValue = statValue.getAttribute('data-value');
                if (isVisible) {
                    statValue.textContent = actualValue;
                    statValue.classList.remove('hidden-amount');
                } else {
                    statValue.textContent = '********';
                    statValue.classList.add('hidden-amount');
                }
            }
            
            // Set initial icon for this toggle
            const icon = toggle.querySelector('i');
            if (icon) {
                if (isVisible) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggle.title = "Hide Amount";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggle.title = "Show Amount";
                }
            }
            
            // Add click event for this individual toggle
            toggle.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent triggering parent events
                toggleIndividualStat(this, statType);
            });
        });

        // Main toggle button that controls all stats
        if (visibilityToggle) {
            // Set initial state for main toggle
            const allStatsVisible = checkAllStatsVisible();
            const eyeIcon = visibilityToggle.querySelector('i');
            if (allStatsVisible) {
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
                visibilityToggle.title = "Hide All Amounts";
            } else {
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
                visibilityToggle.title = "Show All Amounts";
            }
            
            visibilityToggle.addEventListener('click', function() {
                toggleAllStats();
            });
        }

        const viewAllDueDatesBtn = document.getElementById('view-all-due-dates');
        if (viewAllDueDatesBtn) {
            viewAllDueDatesBtn.addEventListener('click', function() {
                // Redirect to the relevant page (e.g., invoices or aging reports)
                // You can change 'aging_reports.php' to your preferred file
                window.location.href = 'aging_reports.php'; 
            });
        }

        
        const viewAllNotificationsBtn = document.getElementById('view-all-notifications');
        const notificationModal = document.getElementById('notification-modal');
        
        if (viewAllNotificationsBtn && notificationModal) {
            viewAllNotificationsBtn.addEventListener('click', function() {
                notificationModal.style.display = 'block';
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            });
        }
        
        // Initialize other common features
        initializeSidebar();
        initializeModals();
        loadDueDates();
        loadNotifications();
        setupDueDateToggle();
    }

    function toggleIndividualStat(toggleButton, statType) {
        const statCard = toggleButton.closest('.bg-white.rounded-xl.p-6');
        const statValue = statCard ? statCard.querySelector('.stat-value') : null;
        const icon = toggleButton.querySelector('i');
        
        if (!statValue || !icon) return;
        
        // Get current state for this specific stat
        const currentState = localStorage.getItem(`stat_${statType}_visible`);
        const isVisible = currentState === null ? false : currentState === 'true';
        const newVisibility = !isVisible;
        
        // Toggle this stat
        const actualValue = statValue.getAttribute('data-value');
        if (newVisibility) {
            statValue.textContent = actualValue;
            statValue.classList.remove('hidden-amount');
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            toggleButton.title = "Hide Amount";
        } else {
            statValue.textContent = '********';
            statValue.classList.add('hidden-amount');
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            toggleButton.title = "Show Amount";
        }
        
        // Save state for this specific stat
        localStorage.setItem(`stat_${statType}_visible`, newVisibility);
        
        // Update main toggle button state
        updateMainToggleState();
    }

    function toggleAllStats() {
        const statToggles = document.querySelectorAll('.stat-toggle');
        const visibilityToggle = document.getElementById('visibility-toggle');
        
        if (!visibilityToggle) return;
        
        // Check current overall state
        const allVisible = checkAllStatsVisible();
        const newState = !allVisible;
        
        // Toggle all individual stats
        statToggles.forEach(toggle => {
            const statType = toggle.getAttribute('data-stat');
            const statCard = toggle.closest('.bg-white.rounded-xl.p-6');
            const statValue = statCard ? statCard.querySelector('.stat-value') : null;
            const icon = toggle.querySelector('i');
            
            if (statValue && icon) {
                const actualValue = statValue.getAttribute('data-value');
                if (newState) {
                    statValue.textContent = actualValue;
                    statValue.classList.remove('hidden-amount');
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggle.title = "Hide Amount";
                } else {
                    statValue.textContent = '********';
                    statValue.classList.add('hidden-amount');
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggle.title = "Show Amount";
                }
                
                // Save state for this specific stat
                localStorage.setItem(`stat_${statType}_visible`, newState);
            }
        });
        
        // Update main toggle button
        const eyeIcon = visibilityToggle.querySelector('i');
        if (newState) {
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
            visibilityToggle.title = "Hide All Amounts";
        } else {
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
            visibilityToggle.title = "Show All Amounts";
        }
    }

    function checkAllStatsVisible() {
        const statTypes = ['income', 'expenses', 'cashflow', 'payments'];
        let allVisible = true;
        
        for (const statType of statTypes) {
            const state = localStorage.getItem(`stat_${statType}_visible`);
            if (state === 'false' || state === null) {
                allVisible = false;
                break;
            }
        }
        
        return allVisible;
    }

    function updateMainToggleState() {
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (!visibilityToggle) return;
        
        const allVisible = checkAllStatsVisible();
        const eyeIcon = visibilityToggle.querySelector('i');
        
        if (allVisible) {
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
            visibilityToggle.title = "Hide All Amounts";
        } else {
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
            visibilityToggle.title = "Show All Amounts";
        }
    }

    function initializeCharts() {
        // Income vs Expenses Chart - DYNAMIC DATA
        const incomeExpenseCtx = document.getElementById('incomeExpenseChart');
        if (incomeExpenseCtx) {
            // This line injects the PHP data into JavaScript
            const chartData = <?php echo json_encode($chart_data); ?>;
            
            new Chart(incomeExpenseCtx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [
                        {
                            label: 'Income',
                            data: chartData.income, // Uses database data
                            backgroundColor: '#2F855A',
                            borderRadius: 6,
                        },
                        {
                            label: 'Expenses',
                            data: chartData.expense, // Uses database data
                            backgroundColor: '#88BE3C',
                            borderRadius: 6,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { drawBorder: false },
                            ticks: { callback: function(value) { return '₱' + (value/1000).toFixed(0) + 'K'; } }
                        },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
        
        
        
        // Budget Chart - DYNAMIC
        const budgetCtx = document.getElementById('budgetChart');
        if (budgetCtx) {
            // Inject PHP data
            const budgetData = <?php echo json_encode($budget_data); ?>;
            
            new Chart(budgetCtx, {
                type: 'doughnut',
                data: {
                    labels: budgetData.labels,
                    datasets: [{
                        data: budgetData.data,
                        backgroundColor: budgetData.colors,
                        borderWidth: 0,
                        hoverOffset: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += '₱' + context.parsed.toLocaleString();
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Refresh budget chart button
        const refreshBtn = document.getElementById('refresh-budget-chart');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                this.innerHTML = '<div class="spinner"></div>';
                setTimeout(() => {
                    this.innerHTML = '<i class="bx bx-refresh text-xl"></i>';
                }, 1000);
            });
        }
    }

    function initializeSidebar() {
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
            closeSidebar.addEventListener('click', toggleSidebar);
            overlay.addEventListener('click', toggleSidebar);
            
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 769) {
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
                
                if (submenu) submenu.classList.toggle('active');
                if (arrow) arrow.classList.toggle('rotate-180');
            });
        });
    }

    function initializeModals() {
        const notificationBtn = document.getElementById('notification-btn');
        const profileBtn = document.getElementById('profile-btn');
        const notificationModal = document.getElementById('notification-modal');
        const profileModal = document.getElementById('profile-modal');
        const createDisbursementModal = document.getElementById('create-disbursement-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        const logoutBtn = document.getElementById('logout-btn');

        if (notificationBtn && notificationModal) {
            notificationBtn.addEventListener('click', function() {
                notificationModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            });
        }
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function() {
                profileModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            });
        }
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                if (notificationModal) notificationModal.style.display = 'none';
                if (profileModal) profileModal.style.display = 'none';
                if (createDisbursementModal) createDisbursementModal.style.display = 'none';
                document.body.style.overflow = '';
            });
        });
        
        window.addEventListener('click', function(event) {
            if (event.target === notificationModal) {
                notificationModal.style.display = 'none';
                document.body.style.overflow = '';
            }
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
                document.body.style.overflow = '';
            }
            if (event.target === createDisbursementModal) {
                createDisbursementModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
        
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = '?logout=true';
                }
            });
        }
    }
function setupDueDateToggle() {
        const toggleBtn = document.getElementById('due-dates-visibility-toggle');
        if (toggleBtn) {
            // 1. Set initial icon state based on saved preference
            const savedVisibility = localStorage.getItem('dueDatesVisible') === 'true';
            const eyeIcon = toggleBtn.querySelector('i');
            
            if (savedVisibility) {
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
                toggleBtn.title = "Hide Amounts";
            } else {
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
                toggleBtn.title = "Show Amounts";
            }

            // 2. Add Click Listener
            toggleBtn.addEventListener('click', function() {
                const currentVisibility = localStorage.getItem('dueDatesVisible') === 'true';
                const newVisibility = !currentVisibility;
                localStorage.setItem('dueDatesVisible', newVisibility);
                
                // Update Icon
                const icon = this.querySelector('i');
                if (newVisibility) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    this.title = "Hide Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    this.title = "Show Amounts";
                }
                
                // Reload list to apply masking immediately
                loadDueDates();
            });
        }
    }
    function loadDueDates() {
        // Inject PHP data
        const dueDates = <?php echo json_encode($upcoming_due_dates); ?>;
        const dueDatesList = document.getElementById('due-dates-list');
        
        // CHECK VISIBILITY STATE
        const isVisible = localStorage.getItem('dueDatesVisible') === 'true';
        
        if (dueDatesList) {
            dueDatesList.innerHTML = '';
            
            if (dueDates.length === 0) {
                dueDatesList.innerHTML = `
                    <div class="text-center text-gray-500 py-4 text-sm">
                        <i class='bx bx-check-circle text-2xl mb-1 text-green-500'></i><br>
                        No upcoming payments due
                    </div>
                `;
                return;
            }

            dueDates.forEach(item => {
                const dateObj = new Date(item.date);
                const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                
                // LOGIC TO HIDE/SHOW AMOUNT
                const formattedAmount = '₱' + parseFloat(item.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
                const displayAmount = isVisible ? formattedAmount : '********';
                const hiddenClass = isVisible ? '' : 'hidden-amount';
                
                const dueDate = document.createElement('div');
                dueDate.className = 'flex justify-between items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200';
                dueDate.innerHTML = `
                    <div>
                        <div class="font-medium text-dark-text text-sm">${item.name}</div>
                        <div class="text-xs text-red-500 flex items-center mt-1">
                            <i class='bx bx-time-five mr-1'></i> Due ${dateStr}
                        </div>
                    </div>
                    <div class="font-bold text-dark-text text-sm ${hiddenClass}">${displayAmount}</div>
                `;
                dueDatesList.appendChild(dueDate);
            });
        }
    }

    function loadNotifications() {
        const notifications = <?php echo json_encode($notifications); ?>;
        const notificationsList = document.getElementById('notifications-list');
        const notificationModalList = document.getElementById('notification-list');

        function renderNotifications(container, notifications) {
            if (!container) return;
            
            container.innerHTML = '';
            if (!notifications || notifications.length === 0) {
                container.innerHTML = '<div class="text-center text-gray-500 py-4">No notifications</div>';
                return;
            }

            notifications.forEach(notification => {
                const notificationEl = document.createElement('div');
                notificationEl.className = `notification-item ${notification.read ? 'read' : 'unread'}`;
                notificationEl.innerHTML = `
                    <div class="flex items-start">
                        <div class="mr-3 mt-1">
                            <div class="w-2 h-2 rounded-full ${notification.read ? 'bg-gray-400' : 'bg-green-600'}"></div>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-medium text-dark-text">${notification.title || 'Notification'}</div>
                            <div class="text-xs text-gray-600 mt-1">${notification.message || 'Notification message'}</div>
                            <div class="notification-time">${notification.time || 'Recently'}</div>
                        </div>
                    </div>
                `;
                container.appendChild(notificationEl);
            });
        }

        renderNotifications(notificationsList, notifications);
        renderNotifications(notificationModalList, notifications);
    }
    </script>
</body>
</html>