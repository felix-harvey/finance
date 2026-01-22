<?php
// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

// Only include mailer.php if it hasn't been included already
if (!class_exists('Mailer')) {
    require_once 'mailer.php';
}

class OTPHandler {
    private $db;
    private $mailer;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->mailer = new Mailer();
    }
    
    // Generate random 6-digit OTP
    public function generateOTP() {
        return sprintf("%06d", random_int(100000, 999999)); // Better random
    }
    
    // Store OTP in database
    public function storeOTP($username, $otp) {
        try {
            // Use PHP's time instead of MySQL's NOW() to avoid timezone issues
            $created_at = date('Y-m-d H:i:s');
            $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minutes from now
            
            // First, clean any existing OTPs for this user
            $this->cleanUserOTPs($username);
            
            $query = "INSERT INTO user_otps (username, otp, expires_at, created_at) 
                      VALUES (?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([$username, $otp, $expires_at, $created_at]);
            
            // Also store in session for immediate validation
            $_SESSION['otp_data'] = [
                'username' => $username,
                'otp' => $otp,
                'expires_at' => $expires_at
            ];
            
            return $result;
        } catch (Exception $e) {
            error_log("OTP Store Error: " . $e->getMessage());
            return false;
        }
    }
    
    // Verify OTP with both session and database check
    public function verifyOTP($username, $otp) {
        // First check session (fastest)
        if (isset($_SESSION['otp_data'])) {
            $otpData = $_SESSION['otp_data'];
            
            if ($otpData['username'] === $username && 
                $otpData['otp'] === $otp &&
                strtotime($otpData['expires_at']) > time()) {
                
                // Session OTP is valid, clear it
                unset($_SESSION['otp_data']);
                $this->deleteOTP($username);
                return true;
            }
        }
        
        // If session check fails, check database
        try {
            // Use PHP time for consistency
            $current_time = date('Y-m-d H:i:s');
            
            $query = "SELECT * FROM user_otps 
                      WHERE username = ? AND otp = ? AND expires_at > ? 
                      ORDER BY created_at DESC LIMIT 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$username, $otp, $current_time]);
            $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($otpRecord) {
                // Delete used OTP
                $this->deleteOTP($username);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("OTP Verification Error: " . $e->getMessage());
            return false;
        }
    }
    
    // Delete OTP after use
    public function deleteOTP($username) {
        try {
            $query = "DELETE FROM user_otps WHERE username = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$username]);
            
            // Clear session data
            unset($_SESSION['otp_data']);
            
            return true;
        } catch (Exception $e) {
            error_log("OTP Delete Error: " . $e->getMessage());
            return false;
        }
    }
    
    // Clean all OTPs for a user
    private function cleanUserOTPs($username) {
        try {
            $query = "DELETE FROM user_otps WHERE username = ?";
            $stmt = $this->db->prepare($query);
            return $stmt->execute([$username]);
        } catch (Exception $e) {
            error_log("Clean OTPs Error: " . $e->getMessage());
            return false;
        }
    }
    
    // Clean expired OTPs
    public function cleanExpiredOTPs() {
        try {
            $current_time = date('Y-m-d H:i:s');
            $query = "DELETE FROM user_otps WHERE expires_at <= ?";
            $stmt = $this->db->prepare($query);
            return $stmt->execute([$current_time]);
        } catch (Exception $e) {
            error_log("Clean Expired OTPs Error: " . $e->getMessage());
            return false;
        }
    }
    
    // Send OTP via email with rate limiting
    public function sendOTPEmail($username, $userEmail, $userName) {
        try {
            // Rate limiting: Check if OTP was sent recently
            if (isset($_SESSION['last_otp_request'])) {
                $time_since_last = time() - $_SESSION['last_otp_request'];
                if ($time_since_last < 60) { // 60 seconds cooldown
                    throw new Exception("Please wait " . (60 - $time_since_last) . " seconds before requesting a new OTP.");
                }
            }
            
            $otp = $this->generateOTP();
            
            if ($this->storeOTP($username, $otp)) {
                // Store request time for rate limiting
                $_SESSION['last_otp_request'] = time();
                
                // Send email
                $emailResult = $this->mailer->sendOTP($userEmail, $userName, $otp);
                
                if ($emailResult) {
                    return [
                        'success' => true,
                        'message' => 'OTP sent successfully!'
                    ];
                } else {
                    // If email fails, clean up OTP
                    $this->deleteOTP($username);
                    throw new Exception("Failed to send OTP email.");
                }
            } else {
                throw new Exception("Failed to store OTP.");
            }
        } catch (Exception $e) {
            error_log("Send OTP Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Check if OTP is expired
    public function isOTPExpired($username) {
        if (isset($_SESSION['otp_data'])) {
            $otpData = $_SESSION['otp_data'];
            return strtotime($otpData['expires_at']) <= time();
        }
        return true;
    }
}

// Initialize and clean expired OTPs on each load
$otpHandler = new OTPHandler();
$otpHandler->cleanExpiredOTPs();
?>