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

// Initialize empty arrays for data
$payments = [];
$customers = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'create_payment') {
            // Validate required fields
            $required = ['contact_id', 'payment_date', 'amount', 'payment_method', 'reference_number'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Validate amount
            $amount = floatval($_POST['amount']);
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than 0");
            }
            
            // Validate OR Number
            if (empty(trim($_POST['reference_number']))) {
                throw new Exception("OR Number is required");
            }
            
            // Insert payment into database - REMOVED created_by column
            $stmt = $db->prepare("
                INSERT INTO payments (contact_id, payment_date, amount, payment_method, reference_number, status, type) 
                VALUES (?, ?, ?, ?, ?, 'Completed', 'Receive')
            ");
            
            $stmt->execute([
                $_POST['contact_id'],
                $_POST['payment_date'],
                $amount,
                $_POST['payment_method'],
                trim($_POST['reference_number'])
            ]);
            
            $payment_id = $db->lastInsertId();
            
            // Create notification for payment
            $getContact = $db->prepare("SELECT name FROM business_contacts WHERE id = ?");
            $getContact->execute([$_POST['contact_id']]);
            $contact = $getContact->fetch();
            
            $notification = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notification->execute([
                $user_id,
                "Payment received from " . ($contact['name'] ?? 'customer') . " - ₱" . number_format($amount, 2)
            ]);
            
            $_SESSION['success_message'] = "Payment recorded successfully! Payment ID: " . $payment_id;
            
        } elseif ($_POST['action'] === 'send_reminder') {
            // Send reminder logic
            $contact_id = $_POST['contact_id'];
            $getContact = $db->prepare("SELECT name, email FROM business_contacts WHERE id = ?");
            $getContact->execute([$contact_id]);
            $contact = $getContact->fetch();
            
            // Create notification
            $notification = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notification->execute([
                $user_id,
                "Payment reminder sent to " . ($contact['name'] ?? 'customer')
            ]);
            
            $_SESSION['success_message'] = "Payment reminder sent to " . ($contact['name'] ?? 'customer') . "!";
            
        } elseif ($_POST['action'] === 'update_payment_status') {
            // Update payment status
            $payment_id = $_POST['payment_id'];
            $status = $_POST['status'];
            
            $valid_statuses = ['Completed', 'Processing', 'Failed', 'Pending'];
            if (!in_array($status, $valid_statuses)) {
                throw new Exception("Invalid status");
            }
            
            $stmt = $db->prepare("UPDATE payments SET status = ? WHERE id = ?");
            $stmt->execute([$status, $payment_id]);
            
            $_SESSION['success_message'] = "Payment status updated successfully!";
            
        } elseif ($_POST['action'] === 'edit_payment') {
            // Edit payment logic
            $payment_id = $_POST['payment_id'];
            $amount = floatval($_POST['amount']);
            $payment_date = $_POST['payment_date'];
            $payment_method = $_POST['payment_method'];
            $reference_number = $_POST['reference_number'] ?? null;
            
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than 0");
            }
            
            // Validate OR Number for edit
            if (empty(trim($reference_number))) {
                throw new Exception("OR Number is required");
            }
            
            $stmt = $db->prepare("
                UPDATE payments 
                SET amount = ?, payment_date = ?, payment_method = ?, reference_number = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $amount,
                $payment_date,
                $payment_method,
                trim($reference_number),
                $payment_id
            ]);
            
            $_SESSION['success_message'] = "Payment updated successfully!";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: payment_entry_collection.php");
    exit;
}

// Handle GET actions (like delete)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    try {
        if ($_GET['action'] === 'delete_payment' && isset($_GET['id'])) {
            $payment_id = (int)$_GET['id'];
            
            // Check if payment exists
            $getPayment = $db->prepare("SELECT id FROM payments WHERE id = ?");
            $getPayment->execute([$payment_id]);
            $payment = $getPayment->fetch();
            
            if ($payment) {
                // Delete the payment
                $deletePayment = $db->prepare("DELETE FROM payments WHERE id = ?");
                $deletePayment->execute([$payment_id]);
                
                $_SESSION['success_message'] = "Payment deleted successfully!";
            }
        } elseif ($_GET['action'] === 'mark_notification_read' && isset($_GET['notification_id'])) {
            $notification_id = (int)$_GET['notification_id'];
            $updateNotification = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $updateNotification->execute([$notification_id]);
            header("Location: payment_entry_collection.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: payment_entry_collection.php");
    exit;
}

// Get messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Fetch data from database
try {
    // Fetch payments - include both direct collections and payments from payment_entry
    $payments_stmt = $db->query("
        SELECT p.*, bc.name as contact_name
        FROM payments p 
        LEFT JOIN business_contacts bc ON p.contact_id = bc.id 
        WHERE p.type = 'Receive' 
        ORDER BY p.payment_date DESC, p.created_at DESC
        LIMIT 100
    ");
    $payments = $payments_stmt->fetchAll();
    
    // Fetch customers
    $customers_stmt = $db->query("SELECT id, name FROM business_contacts WHERE type = 'Customer' AND status = 'Active' ORDER BY name");
    $customers = $customers_stmt->fetchAll();
    
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

// Function to mask numbers with asterisks - HIDE EVERYTHING
function maskNumber($number, $masked = true) {
    // Convert to float to ensure we have a number
    $numericValue = floatval($number);
    
    if (!$masked) {
        return number_format($numericValue, 2); // Show actual number with 2 decimal places
    }
    
    // Count total characters in the formatted number (including decimal point and digits)
    $formattedNumber = number_format($numericValue, 2);
    $totalLength = strlen($formattedNumber);
    
    // Return all asterisks (same length as the formatted number)
    return str_repeat('*', $totalLength);
}

// Calculate totals for stats
$total_collected = array_sum(array_column($payments, 'amount'));
$total_customers = count($customers);
$total_payments = count($payments);

// Check if numbers should be visible by default
$numbersVisible = isset($_GET['toggle_numbers']) && $_GET['toggle_numbers'] === '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Entry - Collection | Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
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
        .status-failed {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
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
            display: inline-block;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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

        .action-btn.delete {
            background-color: #FEF2F2;
            color: #DC2626;
            border-color: #DC2626;
        }

        .action-btn.delete:hover {
            background-color: #DC2626;
            color: white;
        }
        
        .action-btn.receipt {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }

        .action-btn.receipt:hover {
            background-color: #D97706;
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
        }
        
        .toggle-visibility-btn:hover {
            background-color: #28644c;
        }
    </style>
</head>
<body class="bg-gray-bg">
    <!-- Overlay for mobile sidebar -->
    <div class="overlay" id="overlay"></div>
    
    <!-- Modal for Receive Payment -->
    <div id="receive-payment-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Receive Payment</h2>
            <form id="receive-payment-form" method="POST">
                <input type="hidden" name="action" value="create_payment">
                
                <div class="form-group">
                    <label class="form-label">Customer *</label>
                    <select name="contact_id" class="form-input" required>
                        <option value="">Select Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Date *</label>
                    <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount *</label>
                    <input type="number" name="amount" id="amount-input" class="form-input" step="0.01" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <select name="payment_method" class="form-input" required>
                        <option value="Cash">Cash</option>
                        <option value="Check">Check</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Credit Card">Credit Card</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">OR Number *</label>
                    <input type="text" name="reference_number" class="form-input" placeholder="Enter Official Receipt Number" required>
                </div>
                
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal for Edit Payment -->
    <div id="edit-payment-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Edit Payment</h2>
            <form id="edit-payment-form" method="POST">
                <input type="hidden" name="action" value="edit_payment">
                <input type="hidden" name="payment_id" id="edit-payment-id">
                
                <div class="form-group">
                    <label class="form-label">Customer</label>
                    <input type="text" id="edit-customer-name" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Date *</label>
                    <input type="date" name="payment_date" id="edit-payment-date" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount *</label>
                    <input type="number" name="amount" id="edit-amount" class="form-input" step="0.01" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <select name="payment_method" id="edit-payment-method" class="form-input" required>
                        <option value="Cash">Cash</option>
                        <option value="Check">Check</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Credit Card">Credit Card</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">OR Number *</label>
                    <input type="text" name="reference_number" id="edit-reference-number" class="form-input" placeholder="Enter Official Receipt Number" required>
                </div>
                
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Update Payment</button>
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
                                <a href="payment_entry_collection.php" class="submenu-item active transition-colors duration-200">Payment Entry</a>
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
            <!-- Header -->
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Payment Entry - Collection</h1>
                        <p class="text-sm text-white/90">Manage customer payments and collections</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <?php if ($numbersVisible): ?>
                        <!-- Hide button - will refresh the page without toggle_numbers parameter -->
                        <a href="payment_entry_collection.php" class="toggle-visibility-btn" title="Hide numbers">
                            <i class='bx bx-hide text-xl'></i>
                        </a>
                    <?php else: ?>
                        <!-- Show button - will refresh the page with toggle_numbers parameter -->
                        <a href="?toggle_numbers=1" class="toggle-visibility-btn" title="Show numbers">
                            <i class='bx bx-show text-xl'></i>
                        </a>
                    <?php endif; ?>
                    
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center <?php echo count($unread_notifications) > 0 ? '' : 'hidden'; ?>" id="notification-badge">
                            <?php echo count($unread_notifications) > 9 ? '9+' : count($unread_notifications); ?>
                        </span>
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
                
                <!-- Quick Stats -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 mr-4">
                                <i class='bx bx-money text-green-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Collected</p>
                                <p class="text-2xl font-bold text-dark-text amount-masked" data-amount="<?= floatval($total_collected) ?>">
                                    ₱<?= maskNumber($total_collected, !$numbersVisible) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 mr-4">
                                <i class='bx bx-group text-blue-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Customers</p>
                                <p class="text-2xl font-bold text-dark-text"><?= $total_customers ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 mr-4">
                                <i class='bx bx-credit-card text-yellow-600 text-2xl'></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Payments</p>
                                <p class="text-2xl font-bold text-dark-text"><?= $total_payments ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-dark-text">Payment Collection</h2>
                    <button id="receive-payment-btn" class="btn btn-primary">
                        <i class='bx bx-plus mr-2'></i>Receive Payment
                    </button>
                </div>
                
                <!-- Payment Records -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <h3 class="text-lg font-bold text-dark-text mb-4">Payment Records</h3>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>OR No</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-gray-500">No payment records found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td class="font-medium">#<?= $payment['id'] ?? 'N/A' ?></td>
                                            <td><?= htmlspecialchars($payment['contact_name'] ?? 'N/A') ?></td>
                                            <td><?= $payment['payment_date'] ?? 'N/A' ?></td>
                                            <td class="font-semibold amount-masked" data-amount="<?= floatval($payment['amount'] ?? 0) ?>">
                                                ₱<?= maskNumber($payment['amount'] ?? 0, !$numbersVisible) ?>
                                            </td>
                                            <td><?= $payment['payment_method'] ?? 'N/A' ?></td>
                                            <td><?= $payment['reference_number'] ?? '-' ?></td>
                                            <td>
                                                <?php
                                                $status = $payment['status'] ?? 'Completed';
                                                $statusClass = match($status) {
                                                    'Completed' => 'status-completed',
                                                    'Processing' => 'status-processing',
                                                    'Failed' => 'status-failed',
                                                    'Pending' => 'status-pending',
                                                    default => 'status-pending'
                                                };
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>"><?= $status ?></span>
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap gap-2">
                                                    <a href="receipt_generation.php?payment_id=<?= $payment['id'] ?>" class="action-btn receipt" title="Generate Receipt">
                                                        <i class='bx bx-receipt mr-1'></i>Receipt
                                                    </a>
                                                    
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
    console.log('DOM loaded - initializing scripts');
    
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
        console.log('Hamburger button initialized');
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
            
            if (submenu) {
                submenu.classList.toggle('active');
            }
            if (arrow) {
                arrow.classList.toggle('rotate-180');
            }
        });
    });

    // Initialize modals
    initializeModals();
    
    // Initialize action buttons
    initializeActionButtons();
    
    // Load notifications
    loadNotifications();
});

// Modal initialization function
function initializeModals() {
    console.log('Initializing modals...');
    
    const receivePaymentBtn = document.getElementById('receive-payment-btn');
    const receivePaymentModal = document.getElementById('receive-payment-modal');
    const editPaymentModal = document.getElementById('edit-payment-modal');
    const notificationBtn = document.getElementById('notification-btn');
    const notificationModal = document.getElementById('notification-modal');
    const profileBtn = document.getElementById('profile-btn');
    const profileModal = document.getElementById('profile-modal');
    const closeButtons = document.querySelectorAll('.close-modal');
    const logoutBtn = document.getElementById('logout-btn');

    // Function to open modal
    function openModal(modal) {
        if (modal) {
            modal.style.display = 'block';
            console.log('Modal opened:', modal.id);
        }
    }
    
    // Function to close modal
    function closeModal(modal) {
        if (modal) {
            modal.style.display = 'none';
            console.log('Modal closed:', modal.id);
        }
    }
    
    // Close all modals
    function closeAllModals() {
        if (receivePaymentModal) closeModal(receivePaymentModal);
        if (editPaymentModal) closeModal(editPaymentModal);
        if (notificationModal) closeModal(notificationModal);
        if (profileModal) closeModal(profileModal);
    }
    
    // Receive Payment Modal
    if (receivePaymentBtn && receivePaymentModal) {
        receivePaymentBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Receive payment button clicked');
            openModal(receivePaymentModal);
            // Reset form when opening modal
            const paymentForm = document.getElementById('receive-payment-form');
            if (paymentForm) {
                paymentForm.reset();
            }
            const amountInput = document.getElementById('amount-input');
            if (amountInput) {
                amountInput.value = '';
            }
        });
    } else {
        console.log('Receive payment modal not found');
    }
    
    // Notification Modal
    if (notificationBtn && notificationModal) {
        notificationBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Notification button clicked');
            openModal(notificationModal);
            loadNotifications();
        });
        console.log('Notification button initialized');
    } else {
        console.log('Notification button or modal not found:', {
            button: notificationBtn,
            modal: notificationModal
        });
    }
    
    // Profile Modal
    if (profileBtn && profileModal) {
        profileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Profile button clicked');
            openModal(profileModal);
        });
    }
    
    // Close buttons
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            console.log('Close button clicked');
            closeAllModals();
        });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            console.log('Clicked outside modal - closing');
            closeAllModals();
        }
    });

    // Form validation for payment form
    const paymentForm = document.getElementById('receive-payment-form');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            const amountInput = document.getElementById('amount-input');
            const orNumberInput = document.querySelector('input[name="reference_number"]');
            
            if (amountInput) {
                const amount = parseFloat(amountInput.value);
                if (amount <= 0 || isNaN(amount)) {
                    e.preventDefault();
                    alert('Amount must be greater than 0');
                    return false;
                }
            }
            
            if (orNumberInput && !orNumberInput.value.trim()) {
                e.preventDefault();
                alert('OR Number is required');
                return false;
            }
            
            return true;
        });
    }

    // Form validation for edit payment form
    const editPaymentForm = document.getElementById('edit-payment-form');
    if (editPaymentForm) {
        editPaymentForm.addEventListener('submit', function(e) {
            const amountInput = document.getElementById('edit-amount');
            const orNumberInput = document.getElementById('edit-reference-number');
            
            if (amountInput) {
                const amount = parseFloat(amountInput.value);
                if (amount <= 0 || isNaN(amount)) {
                    e.preventDefault();
                    alert('Amount must be greater than 0');
                    return false;
                }
            }
            
            if (orNumberInput && !orNumberInput.value.trim()) {
                e.preventDefault();
                alert('OR Number is required');
                return false;
            }
            
            return true;
        });
    }

    // Logout functionality
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '?logout=true';
            }
        });
    }
}

// Action buttons initialization
function initializeActionButtons() {
    console.log('Initializing action buttons...');
    
    // Edit payment buttons
    document.querySelectorAll('.action-btn.edit').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const paymentId = this.getAttribute('data-payment-id');
            console.log('Edit payment clicked:', paymentId);
            editPayment(paymentId);
        });
    });

    // Delete payment buttons
    document.querySelectorAll('.action-btn.delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const paymentId = this.getAttribute('data-payment-id');
            const paymentNumber = this.closest('tr').querySelector('td:first-child').textContent;
            console.log('Delete payment clicked:', paymentId);
            deletePayment(paymentId, paymentNumber);
        });
    });
}

// Function to load notifications
function loadNotifications() {
    console.log('Loading notifications...');
    const notifications = <?php echo json_encode($notifications); ?>;
    const notificationList = document.getElementById('notification-list');
    const notificationBadge = document.getElementById('notification-badge');
    
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
                    <div class="flex justify-between items-center mt-1">
                        <div class="text-sm text-gray-500">${new Date(notification.created_at).toLocaleDateString()}</div>
                        <button onclick="markNotificationRead(${notification.id})" class="text-xs text-blue-500 hover:text-blue-700">Mark as read</button>
                    </div>
                `;
                notificationList.appendChild(notificationEl);
            });
        }
    }
    
    // Update notification badge
    if (notificationBadge) {
        const unreadCount = notifications.filter(n => !n.is_read).length;
        if (unreadCount > 0) {
            notificationBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
            notificationBadge.classList.remove('hidden');
        } else {
            notificationBadge.classList.add('hidden');
        }
    }
}

// Global functions for payment operations
function editPayment(paymentId) {
    const id = parseInt(paymentId);
    
    // Find the payment row
    const paymentRow = document.querySelector(`[data-payment-id="${paymentId}"]`).closest('tr');
    if (!paymentRow) {
        alert('Payment not found!');
        return;
    }
    
    // Get payment data from the row
    const customerName = paymentRow.cells[1].textContent;
    const paymentDate = paymentRow.cells[2].textContent;
    const amountText = paymentRow.cells[3].textContent.replace('₱', '').trim();
    const paymentMethod = paymentRow.cells[4].textContent;
    const referenceNumber = paymentRow.cells[5].textContent;
    
    // Populate the edit modal
    document.getElementById('edit-payment-id').value = id;
    document.getElementById('edit-customer-name').value = customerName;
    document.getElementById('edit-payment-date').value = paymentDate;
    document.getElementById('edit-amount').value = amountText.replace(/,/g, '');
    document.getElementById('edit-payment-method').value = paymentMethod;
    document.getElementById('edit-reference-number').value = referenceNumber === '-' ? '' : referenceNumber;
    
    // Open the edit modal
    const editModal = document.getElementById('edit-payment-modal');
    if (editModal) {
        editModal.style.display = 'block';
    }
}

function deletePayment(paymentId, paymentNumber) {
    const id = parseInt(paymentId);
    if (confirm(`Are you sure you want to delete payment ${paymentNumber}? This action cannot be undone.`)) {
        window.location.href = `?action=delete_payment&id=${id}`;
    }
}

function markNotificationRead(notificationId) {
    window.location.href = `?action=mark_notification_read&notification_id=${notificationId}`;
}

// Debug function to check what's loaded
function debugPage() {
    console.log('Current page:', window.location.href);
    console.log('Action buttons:', document.querySelectorAll('.action-btn').length);
    console.log('Edit buttons:', document.querySelectorAll('.action-btn.edit').length);
    console.log('Delete buttons:', document.querySelectorAll('.action-btn.delete').length);
}

// Run debug on load
setTimeout(debugPage, 1000);
    </script>
</body>
</html>