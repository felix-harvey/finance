<?php
// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';
require_once 'otp_handler.php';

$otpHandler = new OTPHandler();
$showOTPForm = false;
$emailSent = false;
$emailError = false;

if ($_POST) {
    $database = new Database();
    $db = $database->getConnection();
    
    // Step 1: Verify username/password
    if (isset($_POST['username']) && isset($_POST['password']) && !isset($_POST['otp'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $query = "SELECT id, username, password, name, role, email FROM users WHERE username = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Store user data in session for OTP verification
            $_SESSION['pending_user'] = $user;
            $_SESSION['pending_username'] = $username;
            
            // Send OTP via email
            $emailSent = $otpHandler->sendOTPEmail($username, $user['email'], $user['name']);
            
            if ($emailSent) {
                $showOTPForm = true;
                $success = "OTP sent to your email successfully!";
            } else {
                $error = "Failed to send OTP. Please try again.";
                $emailError = true;
            }
        } else {
            $error = "Invalid username or password";
        }
    }
    
    // Step 2: Verify OTP
    if (isset($_POST['otp']) && isset($_SESSION['pending_user'])) {
        $otp = $_POST['otp'];
        $username = $_SESSION['pending_username'];
        
        if ($otpHandler->verifyOTP($username, $otp)) {
            // OTP verified, complete login
            $user = $_SESSION['pending_user'];
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            
            // Clean up
            unset($_SESSION['pending_user']);
            unset($_SESSION['pending_username']);
            
            header("Location: dashboard8.php");
            exit;
        } else {
            $error = "Invalid or expired OTP";
            $showOTPForm = true;
        }
    }
    
    // Resend OTP
    if (isset($_POST['resend_otp']) && isset($_SESSION['pending_user'])) {
        $user = $_SESSION['pending_user'];
        $username = $_SESSION['pending_username'];
        
        $emailSent = $otpHandler->sendOTPEmail($username, $user['email'], $user['name']);
        
        if ($emailSent) {
            $showOTPForm = true;
            $success = "New OTP sent successfully!";
        } else {
            $error = "Failed to resend OTP. Please try again.";
            $emailError = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .password-strength-meter { height: 5px; border-radius: 2px; transition: all 0.3s ease; }
        .shake { animation: shake 0.5s; }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .spinner { border: 2px solid #f3f3f3; border-top: 2px solid #047857; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; display: inline-block; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .input-error { border-color: #EF4444 !important; box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2) !important; }
        
        .otp-input { width: 50px; height: 50px; text-align: center; font-size: 1.5rem; margin: 0 5px; border: 2px solid #d1d5db; border-radius: 8px; }
        .otp-input:focus { border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2); }
    </style>
</head>
<body class="bg-gradient-to-br from-green-50 to-emerald-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-green-600 to-emerald-700 p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Financial Dashboard</h1>
                    <p class="text-green-100 mt-1">Secure OTP Login System</p>
                </div>
                <div class="bg-white/20 p-3 rounded-full">
                    <i class="fas fa-lock text-xl"></i>
                </div>
            </div>
        </div>
        
        <!-- Login Form -->
        <div class="p-6">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo $error; ?>
                    </div>
                    <?php if ($emailError): ?>
                        <p class="text-sm mt-1">Please check your email configuration in mailer.php</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo $success; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$showOTPForm): ?>
            <!-- Username/Password Form -->
            <form id="loginForm" method="POST" class="space-y-5">
                <!-- Username Field -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            id="username" 
                            name="username"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition" 
                            placeholder="Enter your username"
                            required
                            autocomplete="username"
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        >
                    </div>
                    <div id="usernameError" class="text-red-500 text-sm mt-1 hidden">
                        Please enter your username
                    </div>
                </div>
                
                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input 
                            type="password" 
                            id="password" 
                            name="password"
                            class="block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition" 
                            placeholder="••••••••"
                            required
                            minlength="8"
                            autocomplete="current-password"
                        >
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <i class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                        </button>
                    </div>
                    <div class="mt-2">
                        <div class="flex justify-between text-xs text-gray-500 mb-1">
                            <span>Password strength</span>
                            <span id="strengthText">Weak</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                            <div id="strengthMeter" class="password-strength-meter h-1.5 rounded-full bg-red-500 w-1/4"></div>
                        </div>
                    </div>
                    <div id="passwordError" class="text-red-500 text-sm mt-1 hidden">
                        Password must be at least 8 characters
                    </div>
                </div>
                
                <!-- Security Notice -->
                <div class="bg-green-50 p-3 rounded-lg border border-green-200">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-shield-alt text-green-500 mt-0.5"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-green-800">Two-Factor Authentication</h3>
                            <div class="text-sm text-green-700 mt-1">
                                <p>After password verification, you'll receive an OTP via email for additional security.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <button 
                    type="submit" 
                    id="submitBtn"
                    class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition transform hover:scale-[1.02]"
                >
                    <span id="btnText">Continue to OTP Verification</span>
                    <div id="btnSpinner" class="spinner ml-2 hidden"></div>
                </button>
            </form>
            <?php else: ?>
            <!-- OTP Verification Form -->
            <form id="otpForm" method="POST" class="space-y-5">
                <div class="text-center">
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-4">
                        <i class="fas fa-envelope text-blue-500 text-2xl mb-2"></i>
                        <h3 class="text-lg font-medium text-blue-800">Check Your Email</h3>
                        <p class="text-blue-600 mt-1">We've sent a 6-digit OTP to your registered email address</p>
                    </div>
                </div>
                
                <!-- OTP Input -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3 text-center">Enter 6-Digit OTP Code</label>
                    <div class="flex justify-center space-x-2" id="otpContainer">
                        <input type="text" name="otp1" class="otp-input" maxlength="1" data-index="1" autocomplete="off" inputmode="numeric">
                        <input type="text" name="otp2" class="otp-input" maxlength="1" data-index="2" autocomplete="off" inputmode="numeric">
                        <input type="text" name="otp3" class="otp-input" maxlength="1" data-index="3" autocomplete="off" inputmode="numeric">
                        <input type="text" name="otp4" class="otp-input" maxlength="1" data-index="4" autocomplete="off" inputmode="numeric">
                        <input type="text" name="otp5" class="otp-input" maxlength="1" data-index="5" autocomplete="off" inputmode="numeric">
                        <input type="text" name="otp6" class="otp-input" maxlength="1" data-index="6" autocomplete="off" inputmode="numeric">
                    </div>
                    <input type="hidden" name="otp" id="fullOtp">
                    <div id="otpError" class="text-red-500 text-sm mt-2 text-center hidden">
                        Please enter a valid 6-digit OTP
                    </div>
                </div>
                
                <!-- Resend OTP -->
                <div class="text-center">
                    <form method="POST" class="inline">
                        <input type="hidden" name="resend_otp" value="1">
                        <button type="submit" class="text-sm text-green-600 hover:text-green-800 font-medium">
                            <i class="fas fa-redo mr-1"></i>Resend OTP
                        </button>
                    </form>
                    <p class="text-xs text-gray-500 mt-1">OTP expires in 10 minutes</p>
                </div>
                
                <!-- Submit Button - FIXED -->
                <button 
                    type="button"
                    id="verifyOtpBtn"
                    class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition transform hover:scale-[1.02]"
                >
                    <span id="otpBtnText">Verify OTP</span>
                    <div id="otpBtnSpinner" class="spinner ml-2 hidden"></div>
                </button>
                
                <!-- Back to Login -->
                <div class="text-center">
                    <a href="index.php" class="text-sm text-gray-600 hover:text-gray-800">
                        <i class="fas fa-arrow-left mr-1"></i> Back to login
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password form functionality
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                const usernameInput = document.getElementById('username');
                const passwordInput = document.getElementById('password');
                const togglePassword = document.getElementById('togglePassword');
                const usernameError = document.getElementById('usernameError');
                const passwordError = document.getElementById('passwordError');
                const submitBtn = document.getElementById('submitBtn');
                const btnText = document.getElementById('btnText');
                const btnSpinner = document.getElementById('btnSpinner');
                const strengthMeter = document.getElementById('strengthMeter');
                const strengthText = document.getElementById('strengthText');
                
                // Password visibility toggle
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
                
                // Real-time password strength checker
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    let strength = 0;
                    let meterColor = 'bg-red-500';
                    let text = 'Weak';
                    
                    if (password.length >= 8) strength += 25;
                    if (password.match(/[a-z]+/)) strength += 25;
                    if (password.match(/[A-Z]+/)) strength += 25;
                    if (password.match(/[0-9]+/)) strength += 25;
                    
                    strengthMeter.style.width = strength + '%';
                    
                    if (strength >= 75) {
                        meterColor = 'bg-green-500';
                        text = 'Strong';
                    } else if (strength >= 50) {
                        meterColor = 'bg-yellow-500';
                        text = 'Medium';
                    }
                    
                    strengthMeter.className = 'password-strength-meter h-1.5 rounded-full ' + meterColor;
                    strengthText.textContent = text;
                });
                
                // Form validation and submission
                loginForm.addEventListener('submit', function(e) {
                    usernameError.classList.add('hidden');
                    passwordError.classList.add('hidden');
                    loginForm.classList.remove('shake');
                    
                    usernameInput.classList.remove('input-error');
                    passwordInput.classList.remove('input-error');
                    
                    let isValid = true;
                    
                    if (!usernameInput.value.trim()) {
                        usernameError.classList.remove('hidden');
                        usernameInput.classList.add('input-error');
                        isValid = false;
                    }
                    
                    if (passwordInput.value.length < 8) {
                        passwordError.classList.remove('hidden');
                        passwordInput.classList.add('input-error');
                        isValid = false;
                    }
                    
                    if (!isValid) {
                        loginForm.classList.add('shake');
                        e.preventDefault();
                    } else {
                        btnText.textContent = 'Verifying...';
                        btnSpinner.classList.remove('hidden');
                        submitBtn.disabled = true;
                    }
                });
                
                usernameInput.focus();
            }
            
            // OTP form functionality - FIXED VERSION
            const otpForm = document.getElementById('otpForm');
            if (otpForm) {
                const otpInputs = document.querySelectorAll('.otp-input');
                const fullOtpInput = document.getElementById('fullOtp');
                const otpError = document.getElementById('otpError');
                const verifyOtpBtn = document.getElementById('verifyOtpBtn');
                const otpBtnText = document.getElementById('otpBtnText');
                const otpBtnSpinner = document.getElementById('otpBtnSpinner');
                
                // OTP input handling
                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', function(e) {
                        const value = this.value.replace(/\D/g, ''); // Only allow numbers
                        this.value = value;
                        
                        if (value.length === 1) {
                            if (index < otpInputs.length - 1) {
                                otpInputs[index + 1].focus();
                            }
                        }
                        
                        updateFullOtp();
                    });
                    
                    input.addEventListener('keydown', function(e) {
                        // Allow: backspace, delete, tab, escape, enter
                        if ([8, 46, 9, 27, 13].includes(e.keyCode)) {
                            return;
                        }
                        
                        // Ensure it's a number
                        if ((e.keyCode < 48 || e.keyCode > 57) && (e.keyCode < 96 || e.keyCode > 105)) {
                            e.preventDefault();
                        }
                        
                        // Handle backspace
                        if (e.keyCode === 8 && this.value === '' && index > 0) {
                            otpInputs[index - 1].focus();
                        }
                        
                        // Handle enter key to submit form
                        if (e.keyCode === 13) {
                            e.preventDefault();
                            validateAndSubmitOTP();
                        }
                    });
                    
                    input.addEventListener('paste', function(e) {
                        e.preventDefault();
                        const pasteData = e.clipboardData.getData('text').replace(/\D/g, '');
                        if (pasteData.length === 6) {
                            pasteData.split('').forEach((char, idx) => {
                                if (otpInputs[idx]) {
                                    otpInputs[idx].value = char;
                                }
                            });
                            updateFullOtp();
                            otpInputs[5].focus();
                        }
                    });
                    
                    // Add click event to make button work
                    input.addEventListener('click', function() {
                        this.select();
                    });
                });
                
                function updateFullOtp() {
                    let otp = '';
                    otpInputs.forEach(input => {
                        otp += input.value;
                    });
                    fullOtpInput.value = otp;
                    
                    // Auto-submit when all fields are filled
                    if (otp.length === 6) {
                        validateAndSubmitOTP();
                    }
                }
                
                function validateAndSubmitOTP() {
                    const otp = fullOtpInput.value;
                    
                    if (otp.length !== 6 || !/^\d+$/.test(otp)) {
                        otpError.classList.remove('hidden');
                        otpForm.classList.add('shake');
                        setTimeout(() => {
                            otpForm.classList.remove('shake');
                        }, 500);
                    } else {
                        // Show loading state
                        otpBtnText.textContent = 'Verifying...';
                        otpBtnSpinner.classList.remove('hidden');
                        verifyOtpBtn.disabled = true;
                        
                        // Submit the form
                        otpForm.submit();
                    }
                }
                
                // Fix the button click event
                verifyOtpBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    validateAndSubmitOTP();
                });
                
                // Auto-focus first OTP input
                if (otpInputs[0]) {
                    otpInputs[0].focus();
                }
            }
        });
    </script>
</body>
</html>