<?php
// Manual PHPMailer includes - no composer required
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private $mail;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->configureMailer();
    }
    
    private function configureMailer() {
        // Gmail SMTP Configuration
        $this->mail->isSMTP();
        $this->mail->Host = 'smtp.gmail.com';
        $this->mail->SMTPAuth = true;
        $this->mail->Username = 'microfinancial25@gmail.com'; // Your Gmail
        $this->mail->Password = 'grkfauwgdvdwixol'; // ← PASTE YOUR 16-CHARACTER APP PASSWORD HERE
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port = 587;
        
        // Sender info
        $this->mail->setFrom('microfinancial25@gmail.com', 'Financial System');
        $this->mail->isHTML(true);
        
        // Debugging
        $this->mail->SMTPDebug = 0;
        $this->mail->Timeout = 30;
    }
    
    public function sendOTP($toEmail, $toName, $otp) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($toEmail, $toName);
            
            $this->mail->Subject = 'Your OTP Code - Financial System';
            
            $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #10b981, #047857); color: white; padding: 20px; text-align: center; border-radius: 8px; margin-bottom: 20px; }
                    .otp-code { font-size: 32px; font-weight: bold; text-align: center; color: #047857; margin: 20px 0; padding: 15px; background: #f0fdf4; border: 2px dashed #10b981; border-radius: 8px; }
                    .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Financial Dashboard</h1>
                        <p>Secure OTP Verification</p>
                    </div>
                    
                    <h2>Hello {$toName},</h2>
                    <p>Your One-Time Password (OTP) for login verification is:</p>
                    
                    <div class='otp-code'>{$otp}</div>
                    
                    <p>This OTP will expire in <strong>10 minutes</strong>.</p>
                    <p>If you didn't request this code, please ignore this email.</p>
                    
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " Financial System. All rights reserved.</p>
                        <p>This is an automated message, please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $this->mail->Body = $htmlContent;
            
            // Plain text version
            $textContent = "Financial Dashboard - OTP Verification

Hello {$toName},

Your One-Time Password (OTP) for login verification is:

{$otp}

This OTP will expire in 10 minutes.

If you didn't request this code, please ignore this email.

© " . date('Y') . " Financial System. All rights reserved.";
            
            $this->mail->AltBody = $textContent;
            
            if ($this->mail->send()) {
                error_log("OTP email sent successfully to: $toEmail");
                return true;
            } else {
                error_log("Failed to send OTP email to: $toEmail - " . $this->mail->ErrorInfo);
                return false;
            }
        } catch (Exception $e) {
            error_log("Mailer Exception: " . $e->getMessage());
            return false;
        }
    }
}
?>