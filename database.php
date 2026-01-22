<?php
class Database {
    private $host = "localhost";
    private $db_name = "";
    private $username = ""; 
    private $password = "";
    

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create OTP table if it doesn't exist
            $this->createOTPTable();
        } catch(PDOException $exception) {
            throw new RuntimeException("Database connection failed: " . $exception->getMessage());
        }
        return $this->conn;
    }
    
    private function createOTPTable() {
        $query = "
        CREATE TABLE IF NOT EXISTS user_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            otp VARCHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_username (username),
            INDEX idx_expires (expires_at)
        )";
        
        $this->conn->exec($query);
    }
}
?>