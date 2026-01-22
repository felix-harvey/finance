<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Authentication check
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Load current user
$user_id = (int)$_SESSION['user_id'];
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();

if (!$user) {
    header("Location: index.php");
    exit;
}

// Get income statement data
function getIncomeStatementData(PDO $db): array {
    $sql = "SELECT 
                account_type,
                account_subtype,
                account_code,
                account_name,
                balance
            FROM chart_of_accounts 
            WHERE statement_type = 'Income Statement' 
            AND status = 'Active'
            ORDER BY 
                CASE account_type
                    WHEN 'Revenue' THEN 1
                    WHEN 'Expense' THEN 2
                    ELSE 3
                END,
                account_code";
    
    return $db->query($sql)->fetchAll();
}

$income_data = getIncomeStatementData($db);

// Calculate totals
$total_revenue = 0;
$total_expenses = 0;

foreach ($income_data as $account) {
    if (in_array($account['account_type'], ['Revenue', 'Operating Revenue', 'Other Revenue'])) {
        $total_revenue += $account['balance'];
    } else {
        $total_expenses += $account['balance'];
    }
}

$net_income = $total_revenue - $total_expenses;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Income Statement - Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        /* Include the same styles as chart_of_accounts.php */
        .hamburger-line { width: 24px; height: 3px; background-color: #FFFFFF; margin: 4px 0; transition: all 0.3s; }
        .sidebar-item.active { background-color: rgba(255, 255, 255, 0.2); border-left: 4px solid white; }
        .card-shadow { box-shadow: 0px 2px 6px rgba(0,0,0,0.08); }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        .data-table th { background-color: #f9fafb; font-weight: 500; color: #374151; }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Use the same sidebar structure as chart_of_accounts.php -->
    
    <div class="flex">
        <!-- Sidebar (copy from chart_of_accounts.php) -->
        <div id="sidebar" class="w-64 bg-green-800 min-h-screen">
            <!-- Same sidebar content as chart_of_accounts.php -->
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6">
            <div class="bg-white rounded-xl p-6 card-shadow">
                <h1 class="text-2xl font-bold mb-6">Income Statement</h1>
                <div class="mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h3 class="text-lg font-semibold text-green-800">Total Revenue</h3>
                            <p class="text-2xl font-bold">₱<?php echo number_format($total_revenue, 2); ?></p>
                        </div>
                        <div class="bg-red-50 p-4 rounded-lg">
                            <h3 class="text-lg font-semibold text-red-800">Total Expenses</h3>
                            <p class="text-2xl font-bold">₱<?php echo number_format($total_expenses, 2); ?></p>
                        </div>
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h3 class="text-lg font-semibold text-blue-800">Net Income</h3>
                            <p class="text-2xl font-bold <?php echo $net_income >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                ₱<?php echo number_format($net_income, 2); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $current_type = '';
                        foreach ($income_data as $account): 
                            if ($current_type !== $account['account_type']) {
                                $current_type = $account['account_type'];
                        ?>
                        <tr class="bg-gray-50">
                            <td colspan="4" class="font-bold py-3">
                                <?php echo htmlspecialchars($account['account_type']); ?>
                            </td>
                        </tr>
                        <?php } ?>
                        <tr>
                            <td class="font-mono"><?php echo htmlspecialchars($account['account_code']); ?></td>
                            <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                            <td><?php echo htmlspecialchars($account['account_subtype']); ?></td>
                            <td class="font-semibold <?php echo in_array($account['account_type'], ['Revenue', 'Operating Revenue', 'Other Revenue']) ? 'text-green-600' : 'text-red-600'; ?>">
                                ₱<?php echo number_format($account['balance'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>