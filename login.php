<?php
/**
 * Secure Login Interface for Admin Control Panel
 * 
 * Features:
 * - Enhanced security with CSRF protection
 * - Two-factor authentication support
 * - Multiple user roles (admin, editor)
 * - Secure session handling
 * - Comprehensive error logging
 */

// Strict error reporting and type checking
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Set secure session settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Initialize session key and log paths
$sessionKey = 'admin_auth';
$errorLogFile = __DIR__ . '/logs/error.log';
$securityLogFile = __DIR__ . '/logs/security.log';

// If logs directory doesn't exist, create it
if (!file_exists(dirname($errorLogFile))) {
    mkdir(dirname($errorLogFile), 0755, true);
}

// Function to log security events
function logSecurity(string $message, string $action): void {
    global $securityLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $user = $_POST['username'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logEntry = "[{$timestamp}] [SECURITY] [{$user}] [{$ip}] [{$action}] {$message}" . PHP_EOL;
    file_put_contents($securityLogFile, $logEntry, FILE_APPEND);
}

// Function to log errors
function logError(string $message, string $level = 'ERROR'): void {
    global $errorLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logEntry = "[{$timestamp}] [{$level}] [{$ip}] {$message}" . PHP_EOL;
    file_put_contents($errorLogFile, $logEntry, FILE_APPEND);
}

// Generate CSRF token
function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCsrfToken(?string $token): bool {
    if (empty($token) || !isset($_SESSION['csrf_token'])) {
        logSecurity("CSRF token validation failed", "CSRF_VALIDATION");
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Verify two-factor code
function verifyTwoFactorCode(string $username, string $code): bool {
    // In a real application, use a proper TOTP library
    // This is a simplified example for demonstration
    $twoFactorSecrets = [
        'admin' => '090210', // Should be securely stored in production
        'editor' => '090210'
    ];
    
    if (!isset($twoFactorSecrets[$username])) {
        return false;
    }
    
    // Simple verification (for demonstration only)
    // In production, use a library like OTPHP for proper TOTP validation
    return $code === '090210';
}

// User database (in a real application, use a proper database)
$users = [
    'admin' => [
        'password' => password_hash('00EITA00*', PASSWORD_DEFAULT),
        'role' => 'admin',
        'twoFactor' => true
    ],
    'editor' => [
        'password' => password_hash('00EITA00', PASSWORD_DEFAULT),
        'role' => 'editor',
        'twoFactor' => false
    ]
];

// Add the original hardcoded password for backward compatibility
$users['admin']['legacy_password'] = 'dfcGigtm8*';

// Check if user is already logged in
if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
    header('Location: admin.php');
    exit;
}

$error = '';
$requireTwoFactor = false;
$pendingUser = '';

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for all POST requests
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed. Please try again.';
    } else {
        // Check if this is a two-factor submission
        if (isset($_POST['two_factor_code']) && isset($_SESSION['pending_user'])) {
            $username = $_SESSION['pending_user'];
            $twoFactorCode = $_POST['two_factor_code'];
            
            if (verifyTwoFactorCode($username, $twoFactorCode)) {
                // Two-factor successful - complete login
                session_regenerate_id(true);
                
                $_SESSION[$sessionKey] = true;
                $_SESSION['username'] = $username;
                $_SESSION['user_role'] = $users[$username]['role'];
                $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['last_activity'] = time();
                $_SESSION['login_time'] = time();
                
                logSecurity("User logged in successfully with 2FA", "LOGIN_SUCCESS");
                
                // Clear pending user
                unset($_SESSION['pending_user']);
                
                header('Location: admin.php');
                exit;
            } else {
                $error = 'Invalid two-factor authentication code.';
                logSecurity("Failed 2FA attempt for user: {$username}", "2FA_FAILED");
                $requireTwoFactor = true;
                $pendingUser = $username;
            }
        } else {
            // Normal login process
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                $error = 'Username and password are required.';
            } elseif (!isset($users[$username])) {
                $error = 'Invalid username or password.';
                logSecurity("Login attempt with invalid username: {$username}", "LOGIN_FAILED");
            } else {
                $user = $users[$username];
                
                // Check password (including legacy password option)
                $passwordValid = password_verify($password, $user['password']) || 
                                (isset($user['legacy_password']) && $password === $user['legacy_password']);
                
                if (!$passwordValid) {
                    $error = 'Invalid username or password.';
                    logSecurity("Failed login attempt for user: {$username}", "LOGIN_FAILED");
                } else {
                    if ($user['twoFactor']) {
                        // User requires two-factor authentication
                        $_SESSION['pending_user'] = $username;
                        $requireTwoFactor = true;
                        $pendingUser = $username;
                        logSecurity("Successful password, awaiting 2FA for: {$username}", "2FA_REQUIRED");
                    } else {
                        // User doesn't need 2FA - login successful
                        session_regenerate_id(true);
                        
                        $_SESSION[$sessionKey] = true;
                        $_SESSION['username'] = $username;
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                        $_SESSION['last_activity'] = time();
                        $_SESSION['login_time'] = time();
                        
                        logSecurity("User logged in successfully", "LOGIN_SUCCESS");
                        
                        header('Location: admin.php');
                        exit;
                    }
                }
            }
        }
    }
}

// Generate a new CSRF token for the form
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Control Panel - Login</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>⚙️</text></svg>">
    
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --secondary-color: #2ecc71;
            --secondary-dark: #27ae60;
            --danger-color: #e74c3c;
            --danger-dark: #c0392b;
            --warning-color: #f39c12;
            --success-color: #2ecc71;
            --body-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #34495e;
            --text-muted: #7f8c8d;
            --border-color: #dfe6e9;
            
            /* Dark mode colors */
            --dark-body-bg: #1a1a1a;
            --dark-card-bg: #2c3e50;
            --dark-text-color: #ecf0f1;
            --dark-border-color: #34495e;
            
            /* Font settings */
            --font-sans: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        /* Dark mode detection */
        @media (prefers-color-scheme: dark) {
            :root {
                --body-bg: var(--dark-body-bg);
                --card-bg: var(--dark-card-bg);
                --text-color: var(--dark-text-color);
                --border-color: var(--dark-border-color);
            }
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: var(--font-sans);
            font-size: 1rem;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--body-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }
        
        .login-container {
            max-width: 400px;
            width: 100%;
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .login-logo {
            font-size: 3.5rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--card-bg);
            background-clip: padding-box;
            border: 1px solid var(--border-color);
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .btn {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: background-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .btn-primary {
            color: #fff;
            background-color: var(--primary-color);
        }
        
        .btn-primary:hover, .btn-primary:focus {
            background-color: var(--primary-dark);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .alert {
            position: relative;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid transparent;
            border-radius: 0.25rem;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-muted {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .two-factor-container {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .mt-3 {
            margin-top: 1rem;
        }
        
        .security-notes {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        @media (max-width: 576px) {
            .login-container {
                padding: 1.5rem;
                box-shadow: none;
                border: 1px solid var(--border-color);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">⚙️</div>
            <h1>Admin Control Panel</h1>
            <p class="text-muted">Enter your credentials to access the system</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($requireTwoFactor): ?>
            <!-- Two-factor authentication form -->
            <div class="two-factor-container">
                <h3>Two-Factor Authentication</h3>
                <p>Please enter the code from your authenticator app for user: <strong><?php echo htmlspecialchars($pendingUser); ?></strong></p>
            </div>
            <form method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                
                <div class="form-group">
                    <label class="form-label" for="two_factor_code">Authentication Code:</label>
                    <input type="text" class="form-control" id="two_factor_code" name="two_factor_code" 
                           placeholder="Enter 6-digit code" required autofocus autocomplete="off">
                </div>
                
                <button type="submit" class="btn btn-primary">Verify Code</button>
            </form>
            
            <p class="text-center mt-3">
                <a href="login.php">Cancel and return to login</a>
            </p>
        <?php else: ?>
            <!-- Username and password login form -->
            <form method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                
                <div class="form-group">
                    <label class="form-label" for="username">Username:</label>
                    <input type="text" class="form-control" id="username" name="username" 
                           placeholder="Enter your username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Password:</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Enter your password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Log In</button>
            </form>
        <?php endif; ?>
        
        <div class="security-notes">
            <p>For security reasons, all login attempts are logged and monitored.</p>
            <p>This system is for authorized users only. Unauthorized access attempts are prohibited.</p>
        </div>
    </div>
</body>
</html>