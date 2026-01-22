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

// Get balance sheet data
function getBalanceSheetData(PDO $db): array {
    $sql = "SELECT 
                account_type,
                account_subtype,
                account_code,
                account_name,
                balance
            FROM chart_of_accounts 
            WHERE statement_type = 'Balance Sheet' 
            AND status = 'Active'
            ORDER BY 
                CASE account_type
                    WHEN 'Asset' THEN 1
                    WHEN 'Liability' THEN 2
                    WHEN 'Equity' THEN 3
                    ELSE 4
                END,
                account_code";
    
    return $db->query($sql)->fetchAll();
}

$balance_data = getBalanceSheetData($db);

// Calculate totals
$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;

foreach ($balance_data as $account) {
    if (in_array($account['account_type'], ['Asset', 'Current Asset', 'Fixed Asset', 'Other Asset'])) {
        $total_assets += $account['balance'];
    } elseif (in_array($account['account_type'], ['Liability', 'Current Liability', 'Long-term Liability'])) {
        $total_liabilities += $account['balance'];
    } else {
        $total_equity += $account['balance'];
    }
}

$total_liabilities_equity = $total_liabilities + $total_equity;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet - Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        /* Include the same styles as chart_of_accounts.php */
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
                <h1 class="text-2xl font-bold mb-6">Balance Sheet</h1>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-6">
                    <!-- Assets -->
                    <div>
                        <h2 class="text-xl font-bold mb-4 text-blue-800">ASSETS</h2>
                        <table class="data-table w-full">
                            <tbody>
                                <?php 
                                $asset_total = 0;
                                foreach ($balance_data as $account): 
                                    if (in_array($account['account_type'], ['Asset', 'Current Asset', 'Fixed Asset', 'Other Asset'])):
                                        $asset_total += $account['balance'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td class="text-right font-semibold">₱<?php echo number_format($account['balance'], 2); ?></td>
                                </tr>
                                <?php endif; endforeach; ?>
                                <tr class="border-t-2">
                                    <td class="font-bold">Total Assets</td>
                                    <td class="text-right font-bold text-blue-800">₱<?php echo number_format($asset_total, 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Liabilities & Equity -->
                    <div>
                        <h2 class="text-xl font-bold mb-4 text-red-800">LIABILITIES & EQUITY</h2>
                        
                        <!-- Liabilities -->
                        <h3 class="font-bold mb-2 text-red-700">Liabilities</h3>
                        <table class="data-table w-full mb-4">
                            <tbody>
                                <?php 
                                $liability_total = 0;
                                foreach ($balance_data as $account): 
                                    if (in_array($account['account_type'], ['Liability', 'Current Liability', 'Long-term Liability'])):
                                        $liability_total += $account['balance'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td class="text-right font-semibold">₱<?php echo number_format($account['balance'], 2); ?></td>
                                </tr>
                                <?php endif; endforeach; ?>
                                <tr>
                                    <td class="font-bold">Total Liabilities</td>
                                    <td class="text-right font-bold text-red-800">₱<?php echo number_format($liability_total, 2); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Equity -->
                        <h3 class="font-bold mb-2 text-purple-700">Equity</h3>
                        <table class="data-table w-full">
                            <tbody>
                                <?php 
                                $equity_total = 0;
                                foreach ($balance_data as $account): 
                                    if (in_array($account['account_type'], ['Equity', 'Retained Earnings'])):
                                        $equity_total += $account['balance'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td class="text-right font-semibold">₱<?php echo number_format($account['balance'], 2); ?></td>
                                </tr>
                                <?php endif; endforeach; ?>
                                <tr>
                                    <td class="font-bold">Total Equity</td>
                                    <td class="text-right font-bold text-purple-800">₱<?php echo number_format($equity_total, 2); ?></td>
                                </tr>
                                <tr class="border-t-2">
                                    <td class="font-bold">Total Liabilities & Equity</td>
                                    <td class="text-right font-bold text-green-800">₱<?php echo number_format($liability_total + $equity_total, 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Balance Check -->
                <div class="mt-6 p-4 <?php echo $total_assets == $total_liabilities_equity ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?> rounded-lg">
                    <p class="text-center font-bold">
                        <?php echo $total_assets == $total_liabilities_equity ? '✓ Balance Sheet is Balanced!' : '✗ Balance Sheet is NOT Balanced!'; ?>
                    </p>
                    <p class="text-center text-sm mt-2">
                        Assets (₱<?php echo number_format($total_assets, 2); ?>) 
                        <?php echo $total_assets == $total_liabilities_equity ? '=' : '≠'; ?> 
                        Liabilities & Equity (₱<?php echo number_format($total_liabilities_equity, 2); ?>)
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>