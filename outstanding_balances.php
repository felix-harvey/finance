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
$outstanding_balances = [];
$aging_summary = [];
$total_outstanding = 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'send_reminder') {
            // Send reminder logic
            $contact_id = $_POST['contact_id'];
            $getContact = $db->prepare("SELECT name, email FROM business_contacts WHERE contact_id = ?");
            $getContact->execute([$contact_id]);
            $contact = $getContact->fetch();
            
            $_SESSION['success_message'] = "Payment reminder sent to " . ($contact['name'] ?? 'customer') . "!";
            
        } elseif ($_POST['action'] === 'apply_payment') {
            // Apply payment to invoice
            $invoice_id = $_POST['invoice_id'];
            $amount = $_POST['amount'];
            
            // Update invoice status
            $updateInvoice = $db->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ?");
            $updateInvoice->execute([$invoice_id]);
            
            $_SESSION['success_message'] = "Payment applied successfully!";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: outstanding_balances.php");
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    try {
        // Fetch data for export
        $export_stmt = $db->query("
            SELECT 
                bc.contact_id,
                bc.name as contact_name,
                bc.email,
                bc.phone,
                SUM(i.amount) as total_balance,
                COUNT(i.id) as invoice_count,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN i.amount ELSE 0 END) as current_0_30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.amount ELSE 0 END) as overdue_31_60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.amount ELSE 0 END) as overdue_61_90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.amount ELSE 0 END) as overdue_90_plus
            FROM business_contacts bc 
            JOIN invoices i ON bc.contact_id = i.contact_id 
            WHERE i.status IN ('Pending', 'Overdue')
            AND i.type = 'Receivable'
            GROUP BY bc.contact_id, bc.name, bc.email, bc.phone
            HAVING total_balance > 0
            ORDER BY total_balance DESC
        ");
        $export_data = $export_stmt->fetchAll();
        
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="outstanding_balances_' . date('Y-m-d') . '.xls"');
        
        // Excel header
        echo "Outstanding Balances Report\t\t\t\t\t\n";
        echo "Generated on: " . date('Y-m-d H:i:s') . "\t\t\t\t\t\n\n";
        
        // Column headers
        echo "Customer Name\tEmail\tPhone\tTotal Balance\tInvoice Count\tCurrent (0-30)\t31-60 Days\t61-90 Days\t90+ Days\n";
        
        // Data rows
        foreach ($export_data as $row) {
            echo $row['contact_name'] . "\t";
            echo $row['email'] . "\t";
            echo $row['phone'] . "\t";
            echo number_format((float)$row['total_balance'], 2) . "\t";
            echo $row['invoice_count'] . "\t";
            echo number_format((float)$row['current_0_30'], 2) . "\t";
            echo number_format((float)$row['overdue_31_60'], 2) . "\t";
            echo number_format((float)$row['overdue_61_90'], 2) . "\t";
            echo number_format((float)$row['overdue_90_plus'], 2) . "\n";
        }
        
        exit;
        
    } catch (Exception $e) {
        error_log("Export error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error generating export file.";
        header("Location: outstanding_balances.php");
        exit;
    }
}

// Fetch outstanding balances data
try {
    // Outstanding balances with aging - UPDATED to use business_contacts and invoices tables
    $balances_stmt = $db->query("
        SELECT 
            bc.contact_id,
            bc.name as contact_name,
            bc.email,
            bc.phone,
            SUM(i.amount) as total_balance,
            COUNT(i.id) as invoice_count,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN i.amount ELSE 0 END) as current_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.amount ELSE 0 END) as overdue_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.amount ELSE 0 END) as overdue_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.amount ELSE 0 END) as overdue_90_plus,
            MAX(i.due_date) as latest_due_date,
            MIN(i.due_date) as oldest_due_date
        FROM business_contacts bc 
        JOIN invoices i ON bc.contact_id = i.contact_id 
        WHERE i.status IN ('Pending', 'Overdue')
        AND i.type = 'Receivable'  -- Only show customer invoices (Accounts Receivable)
        GROUP BY bc.contact_id, bc.name, bc.email, bc.phone
        HAVING total_balance > 0
        ORDER BY total_balance DESC
    ");
    $outstanding_balances = $balances_stmt->fetchAll();
    
    // Calculate total outstanding
    $total_outstanding = array_sum(array_column($outstanding_balances, 'total_balance'));
    
    // Aging summary - UPDATED to use invoices table
    $aging_stmt = $db->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN amount ELSE 0 END) as current_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END) as overdue_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END) as overdue_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END) as overdue_90_plus,
            COUNT(*) as total_invoices
        FROM invoices 
        WHERE status IN ('Pending', 'Overdue')
        AND type = 'Receivable'  -- Only customer invoices
    ");
    $aging_summary = $aging_stmt->fetch();
    
    // Detailed invoices for each customer - UPDATED to use invoices table
    foreach ($outstanding_balances as &$customer) {
        $invoices_stmt = $db->prepare("
            SELECT i.*, 
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i 
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE i.contact_id = ? 
            AND i.status IN ('Pending', 'Overdue')
            AND i.type = 'Receivable'
            GROUP BY i.id
            ORDER BY i.due_date ASC
        ");
        $invoices_stmt->execute([$customer['contact_id']]);
        $customer['invoices'] = $invoices_stmt->fetchAll();
    }
    
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

// Get messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Function to mask numbers with asterisks
function maskNumber($number, $masked = true) {
    if (!$masked) {
        return number_format((float)$number, 0); // Remove decimal places
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part
    return str_repeat('*', strlen($integerPart));
}

// Add session variable to track number visibility (consistent with invoices.php)
if (!isset($_SESSION['show_numbers'])) {
    $_SESSION['show_numbers'] = false;
}

// Toggle number visibility
if (isset($_GET['toggle_numbers'])) {
    $_SESSION['show_numbers'] = !$_SESSION['show_numbers'];
    header("Location: " . str_replace("?toggle_numbers=1", "", $_SERVER['REQUEST_URI']));
    exit;
}

// Function to format numbers with asterisks if hidden (consistent with invoices.php)
function formatNumber($number, $show_numbers = false) {
    // Ensure the input is treated as float
    $number = (float)$number;
    
    if ($show_numbers) {
        return '₱' . number_format($number, 2);
    } else {
        return '₱' . str_repeat('*', max(6, min(12, strlen(number_format($number, 2)))));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Balances | Financial Dashboard</title>
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
        /* All existing CSS styles remain the same */
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
            box-shadow: 0px 2px6px rgba(0,0,0,0.08);
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
            transition: all 0.2s ease-in-out;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
        
        /* Improved action buttons like in invoices.php */
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
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
        
        .action-btn.edit {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.edit:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.remind {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }
        
        .action-btn.remind:hover {
            background-color: #D97706;
            color: white;
        }

        .action-btn.pay {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.pay:hover {
            background-color: #0369A1;
            color: white;
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
        
        .customer-details {
            background-color: #f8fafc;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-top: 0.5rem;
        }
        
        .invoice-row {
            transition: background-color 0.2s ease;
        }
        
        .invoice-row:hover {
            background-color: #f8fafc;
        }
        
        .collapse-toggle {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .collapse-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .collapse-content.active {
            max-height: 1000px;
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
            transition: all 0.2s ease-in-out;
        }
        
        .toggle-visibility-btn:hover {
            background-color: #28644c;
            transform: translateY(-1px);
        }

        /* Add consistent number formatting styles */
        .hidden-numbers {
            letter-spacing: 2px;
            font-family: monospace;
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
    
    <!-- Modal for Apply Payment -->
    <div id="apply-payment-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Apply Payment</h2>
            <form id="apply-payment-form" method="POST">
                <input type="hidden" name="action" value="apply_payment">
                <input type="hidden" name="invoice_id" id="apply-payment-invoice-id">
                
                <div class="form-group">
                    <label class="form-label">Customer</label>
                    <input type="text" id="apply-payment-customer" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" id="apply-payment-invoice" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount Due</label>
                    <input type="text" id="apply-payment-amount-due" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Amount</label>
                    <input type="number" name="amount" class="form-input" step="0.01" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Apply Payment</button>
                </div>
            </form>
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
                            <div class="submenu active" id="collection-submenu">
                                <a href="payment_entry_collection.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="receipt_generation.php" class="submenu-item transition-colors duration-200">Receipt Generation</a>
                                <a href="collection_dashboard.php" class="submenu-item transition-colors duration-200">Collection Dashboard</a>
                                <a href="outstanding_balances.php" class="submenu-item active transition-colors duration-200">Outstanding Balances</a>
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
                        <h1 class="text-2xl font-bold text-white">Outstanding Balances</h1>
                        <p class="text-sm text-white/90">Manage and track customer outstanding balances</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <!-- Number Visibility Toggle Button -->
                    <a href="?toggle_numbers=1" class="toggle-visibility-btn" title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
                        <i class='bx <?php echo $_SESSION['show_numbers'] ? 'bx-hide' : 'bx-show'; ?> text-xl'></i>
                    </a>
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
                <?php if ($success_message): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100 mr-4">
                                <i class='bx bx-error-circle text-red-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Outstanding</p>
                                <p class="text-2xl font-bold text-dark-text <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($total_outstanding, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-orange-100 mr-4">
                                <i class='bx bx-time-five text-orange-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Overdue Invoices</p>
                                <p class="text-2xl font-bold text-dark-text">
                                    <?= $aging_summary['total_invoices'] ?? 0 ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 mr-4">
                                <i class='bx bx-group text-yellow-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Customers with Balances</p>
                                <p class="text-2xl font-bold text-dark-text">
                                    <?= count($outstanding_balances) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 mr-4">
                                <i class='bx bx-calendar-exclamation text-blue-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Average Days Overdue</p>
                                <p class="text-2xl font-bold text-dark-text">
                                    <?php
                                    $total_days = 0;
                                    $count = 0;
                                    foreach ($outstanding_balances as $customer) {
                                        foreach ($customer['invoices'] as $invoice) {
                                            if ($invoice['days_overdue'] > 0) {
                                                $total_days += $invoice['days_overdue'];
                                                $count++;
                                            }
                                        }
                                    }
                                    echo $count > 0 ? round($total_days / $count) : 0;
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Aging Summary -->
                <div class="metric-card mb-6">
                    <h2 class="text-lg font-bold mb-4">Aging Summary</h2>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="text-center p-4 border rounded-lg">
                            <p class="text-sm text-gray-500">Current (0-30 days)</p>
                            <p class="text-xl font-bold text-green-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                <?php echo formatNumber($aging_summary['current_0_30'] ?? 0, $_SESSION['show_numbers']); ?>
                            </p>
                        </div>
                        <div class="text-center p-4 border rounded-lg">
                            <p class="text-sm text-gray-500">31-60 Days</p>
                            <p class="text-xl font-bold text-yellow-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                <?php echo formatNumber($aging_summary['overdue_31_60'] ?? 0, $_SESSION['show_numbers']); ?>
                            </p>
                        </div>
                        <div class="text-center p-4 border rounded-lg">
                            <p class="text-sm text-gray-500">61-90 Days</p>
                            <p class="text-xl font-bold text-orange-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                <?php echo formatNumber($aging_summary['overdue_61_90'] ?? 0, $_SESSION['show_numbers']); ?>
                            </p>
                        </div>
                        <div class="text-center p-4 border rounded-lg">
                            <p class="text-sm text-gray-500">90+ Days</p>
                            <p class="text-xl font-bold text-red-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                <?php echo formatNumber($aging_summary['overdue_90_plus'] ?? 0, $_SESSION['show_numbers']); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Outstanding Balances Table -->
                <div class="metric-card">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-bold">Customer Outstanding Balances</h2>
                        <div class="flex space-x-2">
                            <a href="?export=excel" class="btn btn-secondary">
                                <i class='bx bx-export mr-2'></i> Export to Excel
                            </a>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Contact Info</th>
                                    <th>Total Balance</th>
                                    <th>Invoice Count</th>
                                    <th>Aging Breakdown</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($outstanding_balances)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-gray-500">
                                            No outstanding balances found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($outstanding_balances as $customer): ?>
                                        <tr class="collapse-toggle cursor-pointer" data-customer="<?= $customer['contact_id'] ?>">
                                            <td class="font-medium"><?= htmlspecialchars($customer['contact_name']) ?></td>
                                            <td>
                                                <div class="text-sm">
                                                    <div><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></div>
                                                    <div class="text-gray-500"><?= htmlspecialchars($customer['phone'] ?? 'N/A') ?></div>
                                                </div>
                                            </td>
                                            <td class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($customer['total_balance'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td><?= $customer['invoice_count'] ?></td>
                                            <td>
                                                <div class="text-xs">
                                                    <div class="flex items-center mb-1">
                                                        <span class="w-16 text-gray-500">0-30:</span>
                                                        <span class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                            <?php echo formatNumber($customer['current_0_30'], $_SESSION['show_numbers']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center mb-1">
                                                        <span class="w-16 text-gray-500">31-60:</span>
                                                        <span class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                            <?php echo formatNumber($customer['overdue_31_60'], $_SESSION['show_numbers']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center mb-1">
                                                        <span class="w-16 text-gray-500">61-90:</span>
                                                        <span class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                            <?php echo formatNumber($customer['overdue_61_90'], $_SESSION['show_numbers']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <span class="w-16 text-gray-500">90+:</span>
                                                        <span class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                            <?php echo formatNumber($customer['overdue_90_plus'], $_SESSION['show_numbers']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap gap-2">
                                                    <button class="action-btn remind send-reminder" 
                                                            data-contact-id="<?= $customer['contact_id'] ?>" 
                                                            data-contact-name="<?= htmlspecialchars($customer['contact_name']) ?>">
                                                        <i class='bx bx-bell mr-1'></i> Remind
                                                    </button>
                                                    <a href="invoices.php?contact_id=<?= $customer['contact_id'] ?>&type=Receivable&from_contact=1" 
                                                       class="action-btn view">
                                                        <i class='bx bx-show mr-1'></i> View Invoices
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr class="collapse-content" id="customer-<?= $customer['contact_id'] ?>">
                                            <td colspan="6" class="p-0">
                                                <div class="customer-details">
                                                    <h4 class="font-bold mb-3">Outstanding Invoices for <?= htmlspecialchars($customer['contact_name']) ?></h4>
                                                    <?php if (empty($customer['invoices'])): ?>
                                                        <p class="text-gray-500 text-center py-2">No outstanding invoices</p>
                                                    <?php else: ?>
                                                        <table class="data-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Invoice #</th>
                                                                    <th>Issue Date</th>
                                                                    <th>Due Date</th>
                                                                    <th>Amount</th>
                                                                    <th>Outstanding</th>
                                                                    <th>Days Overdue</th>
                                                                    <th>Status</th>
                                                                    <th>Actions</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($customer['invoices'] as $invoice): ?>
                                                                    <tr class="invoice-row">
                                                                        <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                                                        <td><?= date('M d, Y', strtotime($invoice['issue_date'])) ?></td>
                                                                        <td><?= date('M d, Y', strtotime($invoice['due_date'])) ?></td>
                                                                        <td class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                                            <?php echo formatNumber($invoice['amount'], $_SESSION['show_numbers']); ?>
                                                                        </td>
                                                                        <td class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                                            <?php echo formatNumber($invoice['outstanding_balance'], $_SESSION['show_numbers']); ?>
                                                                        </td>
                                                                        <td>
                                                                            <?php if ($invoice['days_overdue'] > 0): ?>
                                                                                <span class="text-red-600 font-medium"><?= $invoice['days_overdue'] ?> days</span>
                                                                            <?php else: ?>
                                                                                <span class="text-green-600">On time</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td>
                                                                            <span class="status-badge <?= $invoice['status'] === 'Overdue' ? 'status-overdue' : 'status-pending' ?>">
                                                                                <?= htmlspecialchars($invoice['status']) ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <div class="flex flex-wrap gap-2">
                                                                                <a href="invoices.php?contact_id=<?= $customer['contact_id'] ?>&type=Receivable&from_contact=1" 
                                                                                   class="action-btn view">
                                                                                    <i class='bx bx-show mr-1'></i> View
                                                                                </a>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div> </div> </div> <footer class="main-footer">
                <div class="text-center">
                    <p class="text-sm">© 2025 Financial Dashboard. All rights reserved.</p>
                    <p class="text-xs mt-1 opacity-80">Powered by Microfinancial Management System</p>
                </div>
            </footer>

        </div> </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar functionality
        const hamburgerBtn = document.getElementById('hamburger-btn');
        const closeSidebar = document.getElementById('close-sidebar');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        const mainContent = document.getElementById('main-content');
        
        // Function to toggle sidebar (works for both Mobile and Desktop)
        function toggleSidebar() {
            if (window.innerWidth < 769) {
                // Mobile: Toggle 'active' to slide it IN
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            } else {
                // Desktop: Toggle 'hidden' to slide it OUT
                sidebar.classList.toggle('hidden');
                // Expand main content to fill the empty space
                if (mainContent) {
                    mainContent.classList.toggle('full-width');
                }
            }
        }
        
        // Function to explicitly close the sidebar (for the X button)
        function closeSidebarFunc() {
            if (window.innerWidth < 769) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            } else {
                sidebar.classList.add('hidden');
                if (mainContent) {
                    mainContent.classList.add('full-width');
                }
            }
        }
        
        // Add Event Listeners
        if (hamburgerBtn) {
            hamburgerBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent immediate closing if clicking bubbles up
                toggleSidebar();
            });
        }
        
        if (closeSidebar) {
            closeSidebar.addEventListener('click', function(e) {
                e.stopPropagation();
                closeSidebarFunc();
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', closeSidebarFunc);
        }

        // Handle window resize to reset layout if switching between mobile/desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 769) {
                // Remove mobile classes when moving to desktop
                overlay.classList.remove('active');
                sidebar.classList.remove('active');
            } else {
                // Remove desktop hidden classes when moving to mobile
                sidebar.classList.remove('hidden');
                if (mainContent) mainContent.classList.remove('full-width');
            }
        });
            
            // Category toggle functionality
            document.querySelectorAll('.sidebar-category').forEach(category => {
                category.addEventListener('click', function() {
                    const categoryName = this.getAttribute('data-category');
                    const submenu = document.getElementById(`${categoryName}-submenu`);
                    const arrow = this.querySelector('.category-arrow');
                    
                    submenu.classList.toggle('active');
                    arrow.classList.toggle('rotate-180');
                });
            });
            
            // Modal functionality
            const modals = document.querySelectorAll('.modal');
            const closeModalBtns = document.querySelectorAll('.close-modal');
            
            function openModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'block';
                }
            }
            
            function closeModal(modal) {
                modal.style.display = 'none';
            }
            
            // Profile modal
            document.getElementById('profile-btn').addEventListener('click', function() {
                openModal('profile-modal');
            });
            
            // Notification modal
            document.getElementById('notification-btn').addEventListener('click', function() {
                openModal('notification-modal');
            });
            
            // Close modals
            closeModalBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    closeModal(modal);
                });
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                modals.forEach(modal => {
                    if (event.target === modal) {
                        closeModal(modal);
                    }
                });
            });
            
            // Logout functionality
            document.getElementById('logout-btn').addEventListener('click', function() {
                window.location.href = '?logout=true';
            });
            
            // Send reminder functionality
            document.querySelectorAll('.send-reminder').forEach(btn => {
                btn.addEventListener('click', function() {
                    const contactId = this.getAttribute('data-contact-id');
                    const contactName = this.getAttribute('data-contact-name');
                    
                    if (confirm(`Send payment reminder to ${contactName}?`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `
                            <input type="hidden" name="action" value="send_reminder">
                            <input type="hidden" name="contact_id" value="${contactId}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
            
            // Customer details toggle
            document.querySelectorAll('.collapse-toggle').forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const customerId = this.getAttribute('data-customer');
                    const content = document.getElementById(`customer-${customerId}`);
                    content.classList.toggle('active');
                });
            });
            
            // Update notification badge
            const notificationBadge = document.getElementById('notification-badge');
            const unreadCount = <?= count($unread_notifications) ?>;
            if (unreadCount > 0) {
                notificationBadge.textContent = unreadCount;
                notificationBadge.classList.remove('hidden');
            }
            
            // Load notifications
            const notificationList = document.getElementById('notification-list');
            if (unreadCount > 0) {
                notificationList.innerHTML = `
                    <div class="space-y-2">
                        <?php foreach (array_slice($notifications, 0, 5) as $notification): ?>
                            <div class="p-3 border rounded-lg <?= empty($notification['is_read']) ? 'bg-blue-50' : 'bg-white' ?>">
                                <p class="text-sm"><?= htmlspecialchars($notification['message'] ?? 'No message') ?></p>
                                <p class="text-xs text-gray-500 mt-1"><?= date('M d, Y H:i', strtotime($notification['created_at'] ?? 'now')) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                `;
            }
        });
    </script>
</body>
</html>