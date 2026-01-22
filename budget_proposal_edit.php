<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'] ?? null)) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$proposal_id = (int)($_GET['proposal_id'] ?? 0);

if (!$proposal_id) {
    die("Proposal ID is required");
}

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    // Load proposal data
    $stmt = $db->prepare("
        SELECT bp.*, d.name as department_name, u.name as submitter_name
        FROM budget_proposals bp
        LEFT JOIN departments d ON bp.department = d.id
        LEFT JOIN users u ON bp.submitted_by = u.id
        WHERE bp.id = ? AND bp.submitted_by = ?
    ");
    $stmt->execute([$proposal_id, $user_id]);
    $proposal = $stmt->fetch();
    
    if (!$proposal) {
        die("Proposal not found or access denied");
    }
    
    // Load budget items
    $items_stmt = $db->prepare("SELECT * FROM budget_items WHERE proposal_id = ? ORDER BY id");
    $items_stmt->execute([$proposal_id]);
    $budget_items = $items_stmt->fetchAll();
    
    // Load categories and departments for dropdowns
    $categories_stmt = $db->query("SELECT id, name, type FROM budget_categories WHERE status = 'Active' ORDER BY type, name");
    $categories = $categories_stmt->fetchAll();
    
    $dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
    $departments = $dept_stmt->fetchAll();
    
} catch (Exception $e) {
    die("Error loading proposal: " . $e->getMessage());
}

function safe_output($value, $default = '') {
    if ($value === null) return $default;
    return htmlspecialchars((string)$value);
}
?>

<!-- Edit Proposal Form HTML -->
<div class="p-6">
    <h2 class="text-2xl font-bold mb-4">Edit Budget Proposal</h2>
    
    <!-- Basic Proposal Info -->
    <div class="metric-card mb-6">
        <h3 class="text-lg font-semibold mb-4">Proposal Details</h3>
        <form method="POST" action="budget_proposal.php">
            <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="form-group">
                    <label class="form-label">Proposal Title*</label>
                    <input type="text" name="title" class="form-input" value="<?= safe_output($proposal['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-input" value="<?= safe_output($proposal['department_name']) ?>" readonly>
                </div>
            </div>
            
            <div class="form-group mb-4">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-textarea" rows="3"><?= safe_output($proposal['description']) ?></textarea>
            </div>
            
            <div class="flex space-x-2">
                <button type="submit" name="update_proposal" class="btn btn-primary">Update Proposal</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('edit-proposal-modal')">Cancel</button>
            </div>
        </form>
    </div>
    
    <!-- Budget Items Section -->
    <div class="metric-card">
        <h3 class="text-lg font-semibold mb-4">Budget Items</h3>
        
        <!-- Existing Items -->
<div class="mb-6">
    <h4 class="font-medium mb-3">Current Budget Items</h4>
    <?php if (empty($budget_items)): ?>
        <p class="text-gray-500">No budget items added yet.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Unit Cost</th>
                        <th>Total Cost</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($budget_items as $item): ?>
                        <tr>
                            <td><?= safe_output($item['category']) ?></td>
                            <td><?= safe_output($item['description']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td>₱<?= number_format((float)$item['unit_cost'], 2) ?></td>
                            <td class="font-semibold">₱<?= number_format((float)$item['total_cost'], 2) ?></td>
                            <td>
                                <form method="POST" action="budget_proposal.php" class="inline">
                                    <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button type="submit" name="delete_budget_item" class="action-btn danger" 
                                            onclick="return confirm('Delete this budget item?')">
                                        <i class="fa-solid fa-trash mr-1"></i>Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
        
        <!-- Add New Item Form -->
        <div class="border-t pt-4">
            <h4 class="font-medium mb-3">Add New Budget Item</h4>
            <form method="POST" action="budget_proposal.php">
                <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-3">
                    <div class="form-group">
                        <label class="form-label">Category*</label>
                        <select name="category" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= safe_output($category['name']) ?>">
                                    <?= safe_output($category['name']) ?> (<?= safe_output($category['type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description*</label>
                        <input type="text" name="item_description" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity*</label>
                        <input type="number" name="quantity" class="form-input" value="1" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit Cost (₱)*</label>
                        <input type="number" name="unit_cost" class="form-input" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Justification</label>
                    <textarea name="justification" class="form-textarea" rows="2"></textarea>
                </div>
                
                <button type="submit" name="add_budget_item" class="btn btn-primary">
                    <i class="fa-solid fa-plus mr-2"></i>Add Budget Item
                </button>
            </form>
        </div>
    </div>
    
    <!-- Proposal Actions -->
    <?php if ($proposal['status'] === 'Draft'): ?>
        <div class="metric-card mt-6">
            <h3 class="text-lg font-semibold mb-4">Proposal Actions</h3>
            <div class="flex space-x-2">
                <?php if (!empty($budget_items)): ?>
                    <form method="POST" action="budget_proposal.php" class="inline">
                        <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">
                        <button type="submit" name="submit_for_approval" class="btn btn-success" 
                                onclick="return confirm('Submit this proposal for approval?')">
                            <i class="fa-solid fa-paper-plane mr-2"></i>Submit for Approval
                        </button>
                    </form>
                <?php endif; ?>
                
                <form method="POST" action="budget_proposal.php" class="inline" 
                      onsubmit="return confirm('Are you sure you want to delete this proposal? This action cannot be undone.')">
                    <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">
                    <button type="submit" name="delete_proposal" class="btn btn-danger">
                        <i class="fa-solid fa-trash mr-2"></i>Delete Proposal
                    </button>
                </form>
            </div>
        </div>
    <?php elseif ($proposal['status'] === 'Rejected'): ?>
        <div class="metric-card mt-6">
            <h3 class="text-lg font-semibold mb-4">Proposal Revision</h3>
            <form method="POST" action="budget_proposal.php">
                <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">
                <button type="submit" name="revise_proposal" class="btn btn-warning">
                    <i class="fa-solid fa-edit mr-2"></i>Revise Proposal
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>