<?php
declare(strict_types=1);

// Enable error reporting
ini_set('display_errors', "1");
ini_set('display_startup_errors', "1");
error_reporting(E_ALL);

session_start();

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
$receipts = [];
$payments = [];
$customers = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'generate_receipt') {
            // Validate required fields
            $required = ['payment_id'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Get payment details
            $payment_stmt = $db->prepare("
                SELECT p.*, c.name as contact_name, c.email, c.phone
                FROM payments p 
                LEFT JOIN business_contacts c ON p.contact_id = c.id 
                WHERE p.id = ?
            ");
            $payment_stmt->execute([$_POST['payment_id']]);
            $payment = $payment_stmt->fetch();
            
            if (!$payment) {
                throw new Exception("Payment not found");
            }
            
            // Generate receipt number - FIXED LINE
            $receipt_number = "RCP-" . date('Ymd') . "-" . str_pad((string)$payment['id'], 4, '0', STR_PAD_LEFT);
            
            // Check if receipts table exists, if not create it
            try {
                $check_table = $db->query("SELECT 1 FROM receipts LIMIT 1");
            } catch (Exception $e) {
                // Create receipts table if it doesn't exist
                $create_table = $db->exec("
                    CREATE TABLE receipts (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        receipt_number VARCHAR(50) NOT NULL UNIQUE,
                        payment_id INT NOT NULL,
                        contact_id INT NOT NULL,
                        receipt_date DATE NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        payment_method VARCHAR(50) NOT NULL,
                        reference_number VARCHAR(100),
                        notes TEXT,
                        created_by INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            }
            
            // Insert receipt into database
            $stmt = $db->prepare("
                INSERT INTO receipts (receipt_number, payment_id, contact_id, receipt_date, amount, 
                                     payment_method, reference_number, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
            $notes = $_POST['notes'] ?? '';
            
            $stmt->execute([
                $receipt_number,
                $_POST['payment_id'],
                $payment['contact_id'],
                $receipt_date,
                $payment['amount'],
                $payment['payment_method'],
                $payment['reference_number'] ?? null,
                $notes,
                $user_id
            ]);
            
            $receipt_id = $db->lastInsertId();
            
            $_SESSION['success_message'] = "Receipt generated successfully!";
            $_SESSION['generated_receipt_id'] = $receipt_id;
            
        } elseif ($_POST['action'] === 'delete_receipt') {
            // Delete receipt logic
            $receipt_id = $_POST['receipt_id'];
            
            // Get receipt details for confirmation message
            $getReceipt = $db->prepare("
                SELECT r.receipt_number 
                FROM receipts r 
                WHERE r.id = ?
            ");
            $getReceipt->execute([$receipt_id]);
            $receipt = $getReceipt->fetch();
            
            if ($receipt) {
                // Delete the receipt
                $delete_stmt = $db->prepare("DELETE FROM receipts WHERE id = ?");
                $delete_stmt->execute([$receipt_id]);
                
                $_SESSION['success_message'] = "Receipt " . $receipt['receipt_number'] . " has been deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Receipt not found";
            }
        } elseif ($_POST['action'] === 'mark_notifications_read') {
            // Mark all notifications as read
            $update_stmt = $db->prepare("
                UPDATE notifications 
                SET is_read = 1
                WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
            ");
            $update_stmt->execute([$user_id]);
            
            $_SESSION['success_message'] = "All notifications marked as read!";
            header("Location: receipt_generation.php");
            exit;
        } elseif ($_POST['action'] === 'mark_single_notification_read') {
            // Mark single notification as read
            $notification_id = $_POST['notification_id'] ?? null;
            if ($notification_id) {
                $update_stmt = $db->prepare("
                    UPDATE notifications 
                    SET is_read = 1
                    WHERE id = ? AND (user_id = ? OR user_id IS NULL)
                ");
                $update_stmt->execute([$notification_id, $user_id]);
                
                // Return JSON response for AJAX
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }
    } catch (Exception $e) {
        if ($_POST['action'] === 'mark_single_notification_read') {
            // Return JSON error for AJAX
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    if (!in_array($_POST['action'], ['mark_single_notification_read'])) {
        header("Location: receipt_generation.php");
        exit;
    }
}

// Handle receipt print
if (isset($_GET['print']) && is_numeric($_GET['print'])) {
    $receipt_id = (int)$_GET['print'];
    
    try {
        // Get receipt details
        $receipt_stmt = $db->prepare("
            SELECT r.*, c.name as contact_name, c.email, c.phone, 
                   p.payment_date
            FROM receipts r 
            JOIN payments p ON r.payment_id = p.id 
            JOIN business_contacts c ON r.contact_id = c.id 
            WHERE r.id = ?
        ");
        $receipt_stmt->execute([$receipt_id]);
        $receipt = $receipt_stmt->fetch();
        
        if ($receipt) {
            // HTML receipt for printing
            echo '<!DOCTYPE html>
            <html>
            <head>
                <title>Receipt ' . $receipt['receipt_number'] . '</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 40px; }
                    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                    .section { margin-bottom: 20px; }
                    .label { font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                    th { background-color: #f5f5f5; }
                    .total { font-weight: bold; font-size: 18px; margin-top: 20px; }
                    .footer { margin-top: 40px; text-align: center; color: #666; }
                    @media print {
                        body { margin: 20px; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>PAYMENT RECEIPT</h1>
                    <div style="font-size: 18px; font-weight: bold;">Receipt No: ' . $receipt['receipt_number'] . '</div>
                </div>

                <div class="section">
                    <div><span class="label">Date:</span> ' . date('F j, Y', strtotime($receipt['receipt_date'])) . '</div>
                </div>

                <div class="section">
                    <div class="label">Received From:</div>
                    <div>' . htmlspecialchars($receipt['contact_name']) . '</div>
                    <div>' . htmlspecialchars($receipt['email'] ?? '') . '</div>
                    <div>' . htmlspecialchars($receipt['phone'] ?? '') . '</div>
                </div>
                            
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Payment Receipt</td>
                            <td>₱' . number_format((float)$receipt['amount'], 2) . '</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="section">
                    <div class="total">Total Amount: ₱' . number_format((float)$receipt['amount'], 2) . '</div>
                    <div><span class="label">Payment Method:</span> ' . $receipt['payment_method'] . '</div>
                    <div><span class="label">OR No:</span> ' . ($receipt['reference_number'] ?? 'N/A') . '</div>
                </div>';
            
            if (!empty($receipt['notes'])) {
                echo '<div class="section">
                    <div class="label">Notes:</div>
                    <div>' . htmlspecialchars($receipt['notes']) . '</div>
                </div>';
            }
            
            echo '
                <div class="footer">
                    <p>Thank you for your payment!</p>
                    <p>Generated on ' . date('F j, Y g:i A') . '</p>
                </div>
                <div class="no-print" style="text-align: center; margin-top: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; background: #2f855A; color: white; border: none; border-radius: 5px; cursor: pointer;">Print Receipt</button>
                    <button onclick="window.close()" style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">Close</button>
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                    };
                </script>
            </body>
            </html>';
            exit;
        } else {
            $_SESSION['error_message'] = "Receipt not found";
            header("Location: receipt_generation.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("Receipt print error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error generating receipt: " . $e->getMessage();
        header("Location: receipt_generation.php");
        exit;
    }
}

// Get messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
$generated_receipt_id = $_SESSION['generated_receipt_id'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['generated_receipt_id']);

// Fetch data from database
try {
    // Check if receipts table exists
    try {
        $receipts_stmt = $db->query("
            SELECT r.*, c.name as contact_name, p.payment_date
            FROM receipts r 
            JOIN payments p ON r.payment_id = p.id 
            JOIN business_contacts c ON r.contact_id = c.id 
            ORDER BY r.receipt_date DESC, r.id DESC
        ");
        $receipts = $receipts_stmt->fetchAll();
    } catch (Exception $e) {
        // If receipts table doesn't exist, use empty array
        $receipts = [];
    }
    
    // Fetch completed payments without receipts
    try {
        // First check if receipts table exists
        $check_receipts = $db->query("SHOW TABLES LIKE 'receipts'");
        $receipts_table_exists = $check_receipts->rowCount() > 0;
        
        if ($receipts_table_exists) {
            $payments_stmt = $db->query("
                SELECT p.*, c.name as contact_name
                FROM payments p 
                LEFT JOIN business_contacts c ON p.contact_id = c.id 
                WHERE p.status = 'Completed' 
                AND NOT EXISTS (
                    SELECT 1 FROM receipts r WHERE r.payment_id = p.id
                )
                ORDER BY p.payment_date DESC
            ");
        } else {
            // If receipts table doesn't exist, get all completed payments
            $payments_stmt = $db->query("
                SELECT p.*, c.name as contact_name
                FROM payments p 
                LEFT JOIN business_contacts c ON p.contact_id = c.id 
                WHERE p.status = 'Completed'
                ORDER BY p.payment_date DESC
            ");
        }
        $payments = $payments_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Payments fetch error: " . $e->getMessage());
        $payments = [];
    }
    
    // Fetch customers
    try {
        $customers_stmt = $db->query("SELECT id, name FROM business_contacts WHERE type = 'Customer' AND status = 'Active' ORDER BY name");
        $customers = $customers_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Customers fetch error: " . $e->getMessage());
        $customers = [];
    }
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    // Use empty arrays if database fetch fails
    $receipts = [];
    $payments = [];
    $customers = [];
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Generation | Financial Dashboard</title>
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
        
        /* MODAL STYLES - FIXED FOR SCROLLING */
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
            padding: 20px 0;
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 25px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            top: 15px;
            right: 20px;
            z-index: 10;
            background: none;
            border: none;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-modal:hover {
            color: #000;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #2f855A;
            box-shadow: 0 0 0 3px rgba(47, 133, 90, 0.2);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-size: 1rem;
        }
        
        .btn-primary {
            background-color: #2f855A;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #28644c;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background-color: #d1d5db;
            transform: translateY(-1px);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .data-table th, .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table tr:hover {
            background-color: #f9fafb;
        }
        
        .action-btn {
            padding: 0.4rem 0.8rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
        }
        
        .action-btn.print {
            background-color: #FEF3C7;
            color: #D97706;
            border: 1px solid #D97706;
        }
        
        .action-btn.print:hover {
            background-color: #FDE68A;
        }
        
        .action-btn.delete {
            background-color: #FEE2E2;
            color: #DC2626;
            border: 1px solid #DC2626;
        }
        
        .action-btn.delete:hover {
            background-color: #FECACA;
        }
        
        /* NEW STYLES FOR COMBINED VIEW */
        .combined-view {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 1024px) {
            .combined-view {
                grid-template-columns: 1fr;
            }
        }
        
        .section-card {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        
        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background-color: #f9fafb;
        }
        
        .section-body {
            padding: 1.5rem;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }
        
        .payment-item:hover {
            background-color: #f9fafb;
        }
        
        .payment-item:last-child {
            border-bottom: none;
        }
        
        .payment-info h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #1f2937;
        }
        
        .payment-info p {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .payment-amount {
            font-weight: 700;
            color: #059669;
            font-size: 1.125rem;
        }
        
        /* Success message styles */
        .alert-success {
            background-color: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .alert-error {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        /* Loading state */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Notification styles */
        .notification-item {
            transition: all 0.3s ease;
        }

        .notification-item.read {
            opacity: 0.6;
            background-color: #f9fafb;
        }

        .notification-item:hover {
            background-color: #f3f4f6;
        }

        .mark-read-btn {
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .notification-item:hover .mark-read-btn {
            opacity: 1;
        }
    </style>
</head>
<body class="bg-gray-bg">
    <!-- Overlay for mobile sidebar -->
    <div class="overlay" id="overlay"></div>
    
    <!-- Modal for Generate Receipt -->
    <div id="generate-receipt-modal" class="modal">
        <div class="modal-content">
            <button type="button" class="close-modal">&times;</button>
            <h2 class="text-xl font-bold mb-6 text-gray-800">Generate Receipt</h2>
            <form id="generate-receipt-form" method="POST">
                <input type="hidden" name="action" value="generate_receipt">
                
                <div class="form-group">
                    <label class="form-label">Payment</label>
                    <select name="payment_id" class="form-select" required id="payment-select">
                        <option value="">Select Payment</option>
                        <?php foreach ($payments as $payment): ?>
                            <option value="<?= $payment['id'] ?>" 
                                    data-contact="<?= htmlspecialchars($payment['contact_name']) ?>"
                                    data-amount="<?= floatval($payment['amount']) ?>"
                                    data-method="<?= htmlspecialchars($payment['payment_method']) ?>"
                                    data-reference="<?= htmlspecialchars($payment['reference_number'] ?? '') ?>">
                                Payment #<?= $payment['id'] ?> - <?= htmlspecialchars($payment['contact_name']) ?> - ₱<?= number_format((float)$payment['amount'], 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Receipt Date</label>
                    <input type="date" name="receipt_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-textarea" rows="3" placeholder="Optional notes for the receipt"></textarea>
                </div>
                
                <!-- Preview Section -->
                <div id="receipt-preview-section" class="mt-6 p-4 border border-gray-200 rounded-lg hidden">
                    <h3 class="font-bold mb-3 text-gray-700">Receipt Preview</h3>
                    <div class="receipt-preview bg-white p-4 rounded border">
                        <div class="receipt-header text-center border-b-2 border-green-600 pb-3 mb-4">
                            <h1 class="text-2xl font-bold text-gray-800">PAYMENT RECEIPT</h1>
                            <div id="preview-receipt-number" class="font-semibold text-gray-600 mt-1">Receipt No: RCP-<?= date('Ymd') ?>-XXXX</div>
                        </div>
                        <div class="receipt-details space-y-2">
                            <div><span class="font-semibold">Date:</span> <span id="preview-date"><?= date('F j, Y') ?></span></div>
                            <div><span class="font-semibold">Received From:</span> <span id="preview-contact">-</span></div>
                        </div>
                        <table class="receipt-table w-full mt-4">
                            <thead>
                                <tr>
                                    <th class="bg-gray-50 p-2">Description</th>
                                    <th class="bg-gray-50 p-2">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="p-2 border">Payment Receipt</td>
                                    <td class="p-2 border" id="preview-amount">₱0.00</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="receipt-details mt-4 space-y-1">
                            <div class="font-semibold text-lg">Total Amount: <span id="preview-total">₱0.00</span></div>
                            <div><span class="font-semibold">Payment Method:</span> <span id="preview-method">-</span></div>
                            <div><span class="font-semibold">OR No:</span> <span id="preview-reference">-</span></div>
                        </div>
                        <div class="receipt-footer mt-6 pt-4 border-t text-center text-gray-500">
                            <p>Thank you for your payment!</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex space-x-4 mt-8">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">X</button>
                    <button type="submit" class="btn btn-primary flex-1" id="generate-receipt-submit">
                        Generate Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal for user profile -->
    <div id="profile-modal" class="modal">
        <div class="modal-content">
            <button type="button" class="close-modal">&times;</button>
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
            <button type="button" class="close-modal">&times;</button>
            <h2 class="text-xl font-bold mb-4">Notifications</h2>
            
            <?php if (!empty($notifications)): ?>
                <div class="mb-4">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="mark_notifications_read">
                        <button type="submit" class="btn btn-secondary text-sm">
                            <i class="fa-solid fa-check-double mr-2"></i>Mark All as Read
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <div id="notification-list">
                <?php if (empty($notifications)): ?>
                    <div class="text-center text-gray-500 py-4">No new notifications</div>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item p-3 border-b border-gray-200" data-notification-id="<?= $notification['id'] ?>">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($notification['message'] ?? 'Notification') ?></div>
                                        <div class="text-sm text-gray-500 mt-1">
                                            <?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?>
                                        </div>
                                    </div>
                                    <button class="mark-read-btn ml-2 text-gray-400 hover:text-green-600 transition-colors" 
                                            data-notification-id="<?= $notification['id'] ?>"
                                            title="Mark as read">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
                        <button id="close-sidebar" class="text-white bg-transparent border-none">
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
                                <a href="receipt_generation.php" class="submenu-item active transition-colors duration-200">Receipt Generation</a>
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
                    <button id="hamburger-btn" class="mr-4 bg-transparent border-none">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Receipt Generation</h1>
                        <p class="text-sm text-white/90">Generate and manage payment receipts</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none bg-transparent border-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center <?= count($unread_notifications) > 0 ? '' : 'hidden' ?>" id="notification-badge">
                            <?= count($unread_notifications) > 0 ? (count($unread_notifications) > 9 ? '9+' : count($unread_notifications)) : '' ?>
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
                    <div class="alert-success">
                        <i class="fa-solid fa-check-circle mr-2"></i><?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert-error">
                        <i class="fa-solid fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-dark-text">Receipt Management</h2>
                    <button id="generate-receipt-btn" class="btn btn-primary">
                        <i class="fa-solid fa-receipt mr-2"></i>Generate New Receipt
                    </button>
                </div>
                
                <!-- Combined View: Receipts and Available Payments -->
                <div class="combined-view">
                    <!-- Recent Receipts Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <h3 class="text-lg font-semibold">Recent Receipts</h3>
                            <p class="text-sm text-gray-500 mt-1">Generated payment receipts</p>
                        </div>
                        <div class="section-body">
                            <?php if (empty($receipts)): ?>
                                <div class="empty-state">
                                    <i class="fa-solid fa-receipt"></i>
                                    <p>No receipts found</p>
                                    <p class="text-sm mt-2">Generate receipts from available payments</p>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Receipt No</th>
                                                <th>Date</th>
                                                <th>Customer</th>
                                                <th>Amount</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($receipts as $receipt): ?>
                                                <tr>
                                                    <td class="font-medium"><?= htmlspecialchars($receipt['receipt_number']) ?></td>
                                                    <td><?= date('M j, Y', strtotime($receipt['receipt_date'])) ?></td>
                                                    <td><?= htmlspecialchars($receipt['contact_name']) ?></td>
                                                    <td class="font-semibold text-green-600">₱<?= number_format((float)$receipt['amount'], 2) ?></td>
                                                    <td>
                                                        <a href="receipt_generation.php?print=<?= $receipt['id'] ?>" target="_blank" class="action-btn print">
                                                            <i class="fa-solid fa-print"></i>Print
                                                        </a>
                                                        
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Available Payments Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <h3 class="text-lg font-semibold">Available Payments</h3>
                            <p class="text-sm text-gray-500 mt-1">Payments ready for receipt generation</p>
                        </div>
                        <div class="section-body">
                            <?php if (empty($payments)): ?>
                                <div class="empty-state">
                                    <i class="fa-solid fa-check-circle"></i>
                                    <p>No payments available for receipt generation</p>
                                    <p class="text-sm mt-2">All completed payments already have receipts</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($payments as $payment): ?>
                                        <div class="payment-item">
                                            <div class="payment-info">
                                                <h4><?= htmlspecialchars($payment['contact_name']) ?></h4>
                                                <p>Payment #<?= $payment['id'] ?> • <?= date('M j, Y', strtotime($payment['payment_date'])) ?></p>
                                                <p class="text-xs"><?= htmlspecialchars($payment['payment_method']) ?> • OR No: <?= htmlspecialchars($payment['reference_number'] ?? 'N/A') ?></p>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <span class="payment-amount">₱<?= number_format((float)$payment['amount'], 2) ?></span>
                                                <button class="action-btn edit generate-payment-receipt" data-payment-id="<?= $payment['id'] ?>">
                                                    <i class="fa-solid fa-receipt"></i>Generate
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Success Message Section (if receipt was just generated) -->
                <?php if ($generated_receipt_id): ?>
                    <div class="mt-6 bg-white rounded-lg shadow-sm p-6 border border-green-200">
                        <h3 class="text-lg font-semibold mb-4 text-green-800">Receipt Generated Successfully!</h3>
                        <p class="text-gray-600 mb-6">Your receipt has been generated. You can download or send it to the customer.</p>
                        
                        <div class="flex space-x-4">
                            <a href="receipt_generation.php?print=<?= $generated_receipt_id ?>" target="_blank" class="btn btn-primary">
                                <i class="fa-solid fa-print mr-2"></i>Print Receipt
                            </a>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this receipt? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete_receipt">
                                <input type="hidden" name="receipt_id" value="<?= $generated_receipt_id ?>">
                                <button type="submit" class="btn btn-secondary">
                                    <i class="fa-solid fa-trash mr-2"></i>Delete Receipt
                                </button>
                            </form>
                            <a href="receipt_generation.php" class="btn btn-secondary">
                                <i class="fa-solid fa-list mr-2"></i>View All Receipts
                            </a>
                        </div>
                    </div>
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
                    document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
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
                        document.body.style.overflow = '';
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
                        document.body.style.overflow = '';
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
            const generateReceiptBtn = document.getElementById('generate-receipt-btn');
            const generateReceiptModal = document.getElementById('generate-receipt-modal');
            const notificationBtn = document.getElementById('notification-btn');
            const notificationModal = document.getElementById('notification-modal');
            const profileBtn = document.getElementById('profile-btn');
            const profileModal = document.getElementById('profile-modal');
            const closeButtons = document.querySelectorAll('.close-modal');
            const logoutBtn = document.getElementById('logout-btn');
            
            function openModal(modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
            
            function closeModal(modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
            
            if (generateReceiptBtn) {
                generateReceiptBtn.addEventListener('click', function() {
                    openModal(generateReceiptModal);
                });
            }
            
            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function() {
                    openModal(notificationModal);
                });
            }
            
            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    openModal(profileModal);
                });
            }
            
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    closeModal(modal);
                });
            });
            
            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeModal(event.target);
                }
            });

            // Mark single notification as read
            document.addEventListener('click', function(e) {
                if (e.target.closest('.mark-read-btn')) {
                    const button = e.target.closest('.mark-read-btn');
                    const notificationId = button.getAttribute('data-notification-id');
                    const notificationItem = button.closest('.notification-item');
                    
                    markNotificationAsRead(notificationId, notificationItem);
                }
            });

            function markNotificationAsRead(notificationId, notificationItem) {
                fetch('receipt_generation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=mark_single_notification_read&notification_id=${notificationId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Mark as read visually
                        notificationItem.classList.add('read');
                        notificationItem.querySelector('.mark-read-btn').remove();
                        
                        // Update notification badge
                        const notificationBadge = document.getElementById('notification-badge');
                        let currentCount = parseInt(notificationBadge.textContent) || 0;
                        currentCount--;
                        
                        if (currentCount <= 0) {
                            notificationBadge.classList.add('hidden');
                            notificationBadge.textContent = '';
                        } else {
                            notificationBadge.textContent = currentCount > 9 ? '9+' : currentCount;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error marking notification as read:', error);
                });
            }

            // Logout functionality
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = '?logout=true';
                    }
                });
            }

            // Generate receipt from payment table
            document.querySelectorAll('.generate-payment-receipt').forEach(button => {
                button.addEventListener('click', function() {
                    const paymentId = this.getAttribute('data-payment-id');
                    const paymentSelect = document.getElementById('payment-select');
                    
                    // Set the payment in the select
                    if (paymentSelect) {
                        paymentSelect.value = paymentId;
                        
                        // Trigger change to update preview
                        const event = new Event('change', { bubbles: true });
                        paymentSelect.dispatchEvent(event);
                        
                        // Open the modal
                        openModal(generateReceiptModal);
                    }
                });
            });
            
            // Update receipt preview when payment is selected
            const paymentSelect = document.getElementById('payment-select');
            if (paymentSelect) {
                paymentSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const previewSection = document.getElementById('receipt-preview-section');
                    
                    if (this.value && selectedOption) {
                        // Show preview section
                        previewSection.classList.remove('hidden');
                        
                        // Update preview content
                        const contact = selectedOption.getAttribute('data-contact') || '-';
                        const amount = parseFloat(selectedOption.getAttribute('data-amount') || 0);
                        const method = selectedOption.getAttribute('data-method') || '-';
                        const reference = selectedOption.getAttribute('data-reference') || 'N/A';
                        
                        document.getElementById('preview-contact').textContent = contact;
                        document.getElementById('preview-amount').textContent = '₱' + amount.toFixed(2);
                        document.getElementById('preview-total').textContent = '₱' + amount.toFixed(2);
                        document.getElementById('preview-method').textContent = method;
                        document.getElementById('preview-reference').textContent = reference;
                        
                        // Update receipt date preview
                        const receiptDateInput = document.querySelector('input[name="receipt_date"]');
                        if (receiptDateInput && receiptDateInput.value) {
                            const receiptDate = new Date(receiptDateInput.value);
                            document.getElementById('preview-date').textContent = receiptDate.toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                            });
                        }
                    } else {
                        // Hide preview section if no payment selected
                        previewSection.classList.add('hidden');
                    }
                });
            }
            
            // Update receipt date preview when date changes
            const receiptDateInput = document.querySelector('input[name="receipt_date"]');
            if (receiptDateInput) {
                receiptDateInput.addEventListener('change', function() {
                    if (paymentSelect && paymentSelect.value) {
                        paymentSelect.dispatchEvent(new Event('change'));
                    }
                });
            }

            // Form submission handling
            const generateReceiptForm = document.getElementById('generate-receipt-form');
            if (generateReceiptForm) {
                generateReceiptForm.addEventListener('submit', function(e) {
                    const submitBtn = document.getElementById('generate-receipt-submit');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Generating...';
                        submitBtn.classList.add('loading');
                    }
                    // Form will submit normally
                });
            }

            // Close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modals = document.querySelectorAll('.modal');
                    modals.forEach(modal => {
                        if (modal.style.display === 'block') {
                            closeModal(modal);
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>