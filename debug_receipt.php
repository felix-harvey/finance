<?php
declare(strict_types=1);

// Enable maximum error reporting
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

echo "Debug started...<br>";

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    echo "User not logged in, redirecting...<br>";
    header("Location: login.php");
    exit;
}

echo "User is logged in<br>";

$user_id = (int)$_SESSION['user_id'];

try {
    echo "Connecting to database...<br>";
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    echo "Database connected successfully<br>";
    
    // Load current user
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    if (!$user) {
        echo "User not found in database<br>";
        header("Location: login.php");
        exit;
    }
    
    echo "User loaded: " . htmlspecialchars($user['name']) . "<br>";
    
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "<br>";
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

echo "All checks passed, page should load normally...<br>";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Debug Page</title>
</head>
<body>
    <h1>Debug Page Loaded Successfully</h1>
    <p>If you can see this, the basic PHP is working.</p>
</body>
</html>