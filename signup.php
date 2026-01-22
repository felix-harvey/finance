<?php
session_start();
require_once 'database.php';

if ($_POST) {
    $database = new Database();
    $db = $database->getConnection();
    
    $firstName = $_POST['firstName'];
    $lastName = $_POST['lastName'];
    $email = $_POST['email'];
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'];
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;
    
    // Check if user already exists
    $query = "SELECT id FROM users WHERE username = ? OR email = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$email, $email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        $error = "User with this email already exists";
    } else {
        // Insert new user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $name = $firstName . ' ' . $lastName;
        $role = 'user'; // Default role
        
        $query = "INSERT INTO users (username, password, name, email, phone, role, newsletter) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$email, $hashedPassword, $name, $email, $phone, $role, $newsletter])) {
            // Auto-login after successful registration
            $userId = $db->lastInsertId();
            
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $email;
            $_SESSION['name'] = $name;
            $_SESSION['role'] = $role;
            
            header("Location: dashboard8.php");
            exit;
        } else {
            $error = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Sign up form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#2F855A',
                        'primary-hover': '#276749',
                        'background-light': '#F7FAF5',
                        'text-muted': '#718096',
                        'accent-green': '#68D391',
                        'surface-white': '#FFFFFF',
                        'error-red': '#E53E3E',
                        'summary-brown': '#88BE3C',
                        'text-dark': '#2D3748',
                        'info-blue': '#3182CE',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .transition-standard {
            transition: all 0.3s ease-in-out;
        }
        .transition-fast {
            transition: all 0.2s ease-in-out;
        }
        .transition-slow {
            transition: all 0.5s ease-in-out;
        }
        .scale-hover:hover {
            transform: scale(1.02);
        }
        .shadow-hover:hover {
            box-shadow: 0px 4px 12px rgba(0,0,0,0.12);
        }
        .opacity-hover:hover {
            opacity: 0.85;
        }
        .password-toggle {
            cursor: pointer;
        }
        .input-error {
            border-color: #E53E3E;
        }
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .shake {
            animation: shake 0.5s;
        }
        
        /* Strength bar styling */
        .strength-bar-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        .strength-container {
            display: flex;
            gap: 4px;
        }
        .strength-bar-item {
            height: 4px;
            flex: 1;
            background-color: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-background-light min-h-screen flex items-center justify-center p-4">
    <div class="fixed top-4 right-4 z-50 hidden" id="notification">
        <div class="bg-surface-white rounded-lg shadow-lg p-4 max-w-sm border-l-4 border-error-red transition-standard transform transition-all duration-300">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class='bx bx-error-circle text-error-red text-xl'></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-text-dark" id="notification-title">Error</h3>
                    <p class="mt-1 text-sm text-text-muted" id="notification-message"></p>
                </div>
                <button class="ml-4 flex-shrink-0 opacity-hover" id="close-notification">
                    <i class='bx bx-x text-text-muted'></i>
                </button>
            </div>
        </div>
    </div>

    <div class="bg-surface-white rounded-xl shadow-md p-8 w-full max-w-md transition-standard scale-hover">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary/10 mb-4">
                <i class='bx bx-user-plus text-primary text-3xl'></i>
            </div>
            <h1 class="text-2xl font-bold text-text-dark mb-2">Create Account</h1>
            <p class="text-sm font-medium text-text-muted">Join our community today</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form id="signupForm" method="POST" class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label for="firstName" class="text-sm font-medium text-text-muted">First Name</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="firstName" 
                            name="firstName"
                            class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-green transition-fast peer"
                            placeholder="First name"
                            required
                            value="<?php echo isset($_POST['firstName']) ? htmlspecialchars($_POST['firstName']) : ''; ?>"
                        >
                        <i class='bx bx-user absolute right-3 top-3.5 text-text-muted'></i>
                    </div>
                    <p class="text-xs text-error-red hidden mt-1" id="firstName-error">Please enter your first name</p>
                </div>

                <div class="space-y-2">
                    <label for="lastName" class="text-sm font-medium text-text-muted">Last Name</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="lastName" 
                            name="lastName"
                            class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-green transition-fast peer"
                            placeholder="Last name"
                            required
                            value="<?php echo isset($_POST['lastName']) ? htmlspecialchars($_POST['lastName']) : ''; ?>"
                        >
                    </div>
                    <p class="text-xs text-error-red hidden mt-1" id="lastName-error">Please enter your last name</p>
                </div>
            </div>

            <div class="space-y-2">
                <label for="email" class="text-sm font-medium text-text-muted">Email Address</label>
                <div class="relative">
                    <input 
                        type="email" 
                        id="email" 
                        name="email"
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-green transition-fast peer"
                        placeholder="Enter your email"
                        required
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                    >
                    <i class='bx bx-envelope absolute right-3 top-3.5 text-text-muted'></i>
                </div>
                <p class="text-xs text-error-red hidden mt-1" id="email-error">Please enter a valid email address</p>
            </div>

            <div class="space-y-2">
                <label for="phone" class="text-sm font-medium text-text-muted">Phone Number (Optional)</label>
                <div class="relative">
                    <input 
                        type="tel" 
                        id="phone" 
                        name="phone"
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-green transition-fast peer"
                        placeholder="Enter your phone number"
                        value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                    >
                    <i class='bx bx-phone absolute right-3 top-3.5 text-text-muted'></i>
                </div>
            </div>

            <div class="space-y-2">
                <label for="password" class="text-sm font-medium text-text-muted">Password</label>
                <div class="relative">
                    <input 
                        type="password" 
                        id="password" 
                        name="password"
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-green transition-fast peer"
                        placeholder="Create a password"
                        required
                    >
                    <i class='bx bx-lock-alt absolute right-10 top-3.5 text-text-muted'></i>
                    <i class='bx bx-hide absolute right-3 top-3.5 text-text-muted password-toggle' id="password-toggle"></i>
                </div>
                <p class="text-xs text-error-red hidden mt-1" id="password-error">Password must be at least 8 characters</p>
                
                <div class="mt-2">
                    <div class="strength-container mb-1">
                        <div class="strength-bar-item">
                            <div class="strength-bar-fill" id="strength-bar-1"></div>
                        </div>
                        <div class="strength-bar-item">
                            <div class="strength-bar-fill" id="strength-bar-2"></div>
                        </div>
                        <div class="strength-bar-item">
                            <div class="strength-bar-fill" id="strength-bar-3"></div>
                        </div>
                    </div>
                    <p class="text-xs text-text-muted" id="password-strength-text">Password strength</p>
                </div>

                <ul class="text-xs text-text-muted mt-2 space-y-1">
                    <li class="flex items-center" id="length-requirement">
                        <i class='bx bx-x text-error-red mr-1'></i>
                        <span>At least 8 characters</span>
                    </li>
                    <li class="flex items-center" id="case-requirement">
                        <i class='bx bx-x text-error-red mr-1'></i>
                        <span>Upper and lowercase letters</span>
                    </li>
                    <li class="flex items-center" id="number-requirement">
                        <i class='bx bx-x text-error-red mr-1'></i>
                        <span>At least one number</span>
                    </li>
                </ul>
            </div>

            <div class="space-y-2">
                <label for="confirmPassword" class="text-sm font-medium text-text-muted">Confirm Password</label>
                <div class="relative">
                    <input 
                        type="password" 
                        id="confirmPassword" 
                        name="confirmPassword"
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-green transition-fast peer"
                        placeholder="Confirm your password"
                        required
                    >
                    <i class='bx bx-lock-alt absolute right-10 top-3.5 text-text-muted'></i>
                    <i class='bx bx-hide absolute right-3 top-3.5 text-text-muted password-toggle' id="confirm-password-toggle"></i>
                </div>
                <p class="text-xs text-error-red hidden mt-1" id="confirmPassword-error">Passwords do not match</p>
            </div>

            <div class="flex items-center">
                <div class="relative flex items-center">
                    <input 
                        type="checkbox" 
                        id="terms" 
                        name="terms"
                        class="sr-only"
                        required
                        <?php echo isset($_POST['terms']) ? 'checked' : ''; ?>
                    >
                    <div class="h-4 w-4 border border-gray-300 rounded flex items-center justify-center transition-fast peer focus:outline-none focus:ring-2 focus:ring-accent-green checkbox-div">
                        <i class='bx bx-check text-white text-xs'></i>
                    </div>
                </div>
                <label for="terms" class="ml-2 block text-sm text-text-dark cursor-pointer">
                    I agree to the <a href="#" class="text-primary hover:text-primary-hover transition-fast opacity-hover">Terms of Service</a> and <a href="#" class="text-primary hover:text-primary-hover transition-fast opacity-hover">Privacy Policy</a>
                </label>
            </div>
            <p class="text-xs text-error-red hidden mt-1" id="terms-error">You must accept the terms and conditions</p>

            <div class="flex items-center">
                <div class="relative flex items-center">
                    <input 
                        type="checkbox" 
                        id="newsletter" 
                        name="newsletter"
                        class="sr-only"
                        <?php echo isset($_POST['newsletter']) ? 'checked' : ''; ?>
                    >
                    <div class="h-4 w-4 border border-gray-300 rounded flex items-center justify-center transition-fast peer focus:outline-none focus:ring-2 focus:ring-accent-green newsletter-div">
                        <i class='bx bx-check text-white text-xs'></i>
                    </div>
                </div>
                <label for="newsletter" class="ml-2 block text-sm text-text-dark cursor-pointer">
                    Subscribe to our newsletter
                </label>
            </div>

            <button 
                type="submit" 
                class="w-full bg-primary text-white py-3 px-4 rounded-lg font-medium hover:bg-primary-hover transition-standard shadow-hover flex items-center justify-center"
                id="signup-button"
            >
                <span id="button-text">Create Account</span>
                <div class="loading-spinner ml-2 hidden" id="spinner"></div>
            </button>
        </form>

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-gray-200"></div>
            </div>
            <div class="relative flex justify-center text-sm">
                <span class="px-2 bg-surface-white text-text-muted">Or sign up with</span>
            </div>
        </div>

        <div class="flex justify-center space-x-4">
            <button class="p-2 border border-gray-200 rounded-lg hover:bg-background-light transition-standard shadow-hover flex-1 flex items-center justify-center opacity-hover">
                <i class='bx bxl-google text-xl text-text-muted mr-2'></i>
                <span class="text-sm text-text-muted">Google</span>
            </button>
            <button class="p-2 border border-gray-200 rounded-lg hover:bg-background-light transition-standard shadow-hover flex-1 flex items-center justify-center opacity-hover">
                <i class='bx bxl-facebook text-xl text-text-muted mr-2'></i>
                <span class="text-sm text-text-muted">Facebook</span>
            </button>
        </div>

        <div class="mt-8 text-center">
            <p class="text-sm text-text-muted">
                Already have an account? 
                <a href="index.php" class="text-primary hover:text-primary-hover font-medium ml-1 transition-fast opacity-hover">Sign in</a>
            </p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const signupForm = document.getElementById('signupForm');
            const firstNameInput = document.getElementById('firstName');
            const lastNameInput = document.getElementById('lastName');
            const emailInput = document.getElementById('email');
            const phoneInput = document.getElementById('phone');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordToggle = document.getElementById('password-toggle');
            const confirmPasswordToggle = document.getElementById('confirm-password-toggle');
            const termsCheckbox = document.getElementById('terms');
            const newsletterCheckbox = document.getElementById('newsletter');
            const termsDiv = document.querySelector('.checkbox-div');
            const newsletterDiv = document.querySelector('.newsletter-div');
            const signupButton = document.getElementById('signup-button');
            const buttonText = document.getElementById('button-text');
            const spinner = document.getElementById('spinner');
            const firstNameError = document.getElementById('firstName-error');
            const lastNameError = document.getElementById('lastName-error');
            const emailError = document.getElementById('email-error');
            const passwordError = document.getElementById('password-error');
            const confirmPasswordError = document.getElementById('confirmPassword-error');
            const termsError = document.getElementById('terms-error');
            const notification = document.getElementById('notification');
            const notificationTitle = document.getElementById('notification-title');
            const notificationMessage = document.getElementById('notification-message');
            const closeNotification = document.getElementById('close-notification');
            const strengthText = document.getElementById('password-strength-text');
            const strengthBar1 = document.getElementById('strength-bar-1');
            const strengthBar2 = document.getElementById('strength-bar-2');
            const strengthBar3 = document.getElementById('strength-bar-3');
            const lengthRequirement = document.getElementById('length-requirement');
            const caseRequirement = document.getElementById('case-requirement');
            const numberRequirement = document.getElementById('number-requirement');
            
            // Password visibility toggle
            passwordToggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    passwordToggle.classList.replace('bx-hide', 'bx-show');
                } else {
                    passwordInput.type = 'password';
                    passwordToggle.classList.replace('bx-show', 'bx-hide');
                }
            });
            
            // Confirm password visibility toggle
            confirmPasswordToggle.addEventListener('click', function() {
                if (confirmPasswordInput.type === 'password') {
                    confirmPasswordInput.type = 'text';
                    confirmPasswordToggle.classList.replace('bx-hide', 'bx-show');
                } else {
                    confirmPasswordInput.type = 'password';
                    confirmPasswordToggle.classList.replace('bx-show', 'bx-hide');
                }
            });
            
            // Custom checkboxes
            termsCheckbox.addEventListener('change', function() {
                if (termsCheckbox.checked) {
                    termsDiv.classList.add('bg-primary', 'border-primary');
                } else {
                    termsDiv.classList.remove('bg-primary', 'border-primary');
                }
            });
            
            newsletterCheckbox.addEventListener('change', function() {
                if (newsletterCheckbox.checked) {
                    newsletterDiv.classList.add('bg-primary', 'border-primary');
                } else {
                    newsletterDiv.classList.remove('bg-primary', 'border-primary');
                }
            });
            
            // Password strength indicator
            passwordInput.addEventListener('input', function() {
                checkPasswordStrength(passwordInput.value);
                validatePasswordRequirements(passwordInput.value);
            });
            
            // Confirm password validation
            confirmPasswordInput.addEventListener('input', function() {
                if (confirmPasswordInput.value !== passwordInput.value) {
                    confirmPasswordInput.classList.add('input-error');
                    confirmPasswordError.classList.remove('hidden');
                } else {
                    confirmPasswordInput.classList.remove('input-error');
                    confirmPasswordError.classList.add('hidden');
                }
            });
            
            // Form submission
            signupForm.addEventListener('submit', function(e) {
                const firstName = firstNameInput.value;
                const lastName = lastNameInput.value;
                const email = emailInput.value;
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                const terms = termsCheckbox.checked;
                
                let isValid = true;
                
                // Validate first name
                if (!firstName) {
                    showError(firstNameInput, firstNameError, 'Please enter your first name');
                    shakeElement(firstNameInput);
                    isValid = false;
                } else {
                    clearError(firstNameInput, firstNameError);
                }
                
                // Validate last name
                if (!lastName) {
                    showError(lastNameInput, lastNameError, 'Please enter your last name');
                    shakeElement(lastNameInput);
                    isValid = false;
                } else {
                    clearError(lastNameInput, lastNameError);
                }
                
                // Validate email
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(email)) {
                    showError(emailInput, emailError, 'Please enter a valid email address');
                    shakeElement(emailInput);
                    isValid = false;
                } else {
                    clearError(emailInput, emailError);
                }
                
                // Validate password
                if (password.length < 8) {
                    showError(passwordInput, passwordError, 'Password must be at least 8 characters');
                    shakeElement(passwordInput);
                    isValid = false;
                } else {
                    clearError(passwordInput, passwordError);
                }
                
                // Validate confirm password
                if (password !== confirmPassword) {
                    showError(confirmPasswordInput, confirmPasswordError, 'Passwords do not match');
                    shakeElement(confirmPasswordInput);
                    isValid = false;
                } else {
                    clearError(confirmPasswordInput, confirmPasswordError);
                }
                
                // Validate terms
                if (!terms) {
                    showError(termsDiv, termsError, 'You must accept the terms and conditions');
                    shakeElement(termsDiv);
                    isValid = false;
                } else {
                    clearError(termsDiv, termsError);
                }
                
                if (!isValid) {
                    e.preventDefault();
                    showNotification('Error', 'Please fix the errors in the form', 'error');
                } else {
                    // Show loading state
                    buttonText.textContent = 'Creating Account...';
                    spinner.classList.remove('hidden');
                    signupButton.disabled = true;
                }
            });
            
            // Helper functions
            function showError(inputElement, errorElement, message) {
                inputElement.classList.add('input-error');
                errorElement.textContent = message;
                errorElement.classList.remove('hidden');
            }
            
            function clearError(inputElement, errorElement) {
                inputElement.classList.remove('input-error');
                errorElement.classList.add('hidden');
            }
            
            function shakeElement(element) {
                element.classList.add('shake');
                setTimeout(() => {
                    element.classList.remove('shake');
                }, 500);
            }
            
            function showNotification(title, message, type) {
                notificationTitle.textContent = title;
                notificationMessage.textContent = message;
                
                if (type === 'error') {
                    notification.querySelector('.border-l-4').classList.remove('border-error-red', 'border-accent-green');
                    notification.querySelector('.border-l-4').classList.add('border-error-red');
                    notification.querySelector('i').classList.remove('bx-error-circle', 'bx-check-circle');
                    notification.querySelector('i').classList.add('bx-error-circle');
                    notification.querySelector('i').classList.remove('text-error-red', 'text-accent-green');
                    notification.querySelector('i').classList.add('text-error-red');
                } else {
                    notification.querySelector('.border-l-4').classList.remove('border-error-red', 'border-accent-green');
                    notification.querySelector('.border-l-4').classList.add('border-accent-green');
                    notification.querySelector('i').classList.remove('bx-error-circle', 'bx-check-circle');
                    notification.querySelector('i').classList.add('bx-check-circle');
                    notification.querySelector('i').classList.remove('text-error-red', 'text-accent-green');
                    notification.querySelector('i').classList.add('text-accent-green');
                }
                
                notification.classList.remove('hidden');
                notification.classList.add('opacity-0', 'translate-x-full');
                
                setTimeout(() => {
                    notification.classList.remove('opacity-0', 'translate-x-full');
                    notification.classList.add('opacity-100', 'translate-x-0');
                }, 10);
                
                setTimeout(() => {
                    hideNotification();
                }, 5000);
            }
            
            function hideNotification() {
                notification.classList.remove('opacity-100', 'translate-x-0');
                notification.classList.add('opacity-0', 'translate-x-full');
                
                setTimeout(() => {
                    notification.classList.add('hidden');
                }, 300);
            }
            
            closeNotification.addEventListener('click', hideNotification);
            
            function checkPasswordStrength(password) {
                let strength = 0;
                
                // Length check
                if (password.length >= 8) {
                    strength += 1;
                }
                
                // Case check
                if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
                    strength += 1;
                }
                
                // Number check
                if (/\d/.test(password)) {
                    strength += 1;
                }
                
                // Update strength bars
                strengthBar1.style.width = '0%';
                strengthBar2.style.width = '0%';
                strengthBar3.style.width = '0%';
                
                if (strength >= 1) {
                    strengthBar1.style.width = '100%';
                    strengthBar1.style.backgroundColor = '#E53E3E';
                }
                
                if (strength >= 2) {
                    strengthBar2.style.width = '100%';
                    strengthBar2.style.backgroundColor = '#D69E2E';
                }
                
                if (strength >= 3) {
                    strengthBar3.style.width = '100%';
                    strengthBar3.style.backgroundColor = '#38A169';
                }
                
                // Update strength text
                if (strength === 0) {
                    strengthText.textContent = 'Password strength';
                    strengthText.style.color = '#718096';
                } else if (strength === 1) {
                    strengthText.textContent = 'Weak';
                    strengthText.style.color = '#E53E3E';
                } else if (strength === 2) {
                    strengthText.textContent = 'Medium';
                    strengthText.style.color = '#D69E2E';
                } else {
                    strengthText.textContent = 'Strong';
                    strengthText.style.color = '#38A169';
                }
            }
            
            function validatePasswordRequirements(password) {
                // Length requirement
                if (password.length >= 8) {
                    lengthRequirement.querySelector('i').classList.remove('bx-x', 'text-error-red');
                    lengthRequirement.querySelector('i').classList.add('bx-check', 'text-accent-green');
                } else {
                    lengthRequirement.querySelector('i').classList.remove('bx-check', 'text-accent-green');
                    lengthRequirement.querySelector('i').classList.add('bx-x', 'text-error-red');
                }
                
                // Case requirement
                if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
                    caseRequirement.querySelector('i').classList.remove('bx-x', 'text-error-red');
                    caseRequirement.querySelector('i').classList.add('bx-check', 'text-accent-green');
                } else {
                    caseRequirement.querySelector('i').classList.remove('bx-check', 'text-accent-green');
                    caseRequirement.querySelector('i').classList.add('bx-x', 'text-error-red');
                }
                
                // Number requirement
                if (/\d/.test(password)) {
                    numberRequirement.querySelector('i').classList.remove('bx-x', 'text-error-red');
                    numberRequirement.querySelector('i').classList.add('bx-check', 'text-accent-green');
                } else {
                    numberRequirement.querySelector('i').classList.remove('bx-check', 'text-accent-green');
                    numberRequirement.querySelector('i').classList.add('bx-x', 'text-error-red');
                }
            }
        });
    </script>
</body>
</html>