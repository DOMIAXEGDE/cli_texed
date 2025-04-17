<?php
/**
 * Secure Logout Handler for Admin Control Panel
 * 
 * Features:
 * - Secure session termination
 * - CSRF protection
 * - Comprehensive logout logging
 * - Graceful termination with activity recording
 * - Multiple logout methods supported
 * - Session cleanup
 */

// Strict error reporting and type checking
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Secure session settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Define log paths
$securityLogFile = __DIR__ . '/logs/security.log';
$sessionKey = 'admin_auth';

// Make sure logs directory exists
if (!file_exists(dirname($securityLogFile))) {
    mkdir(dirname($securityLogFile), 0755, true);
}

// Function to log security events
function logSecurity(string $message, string $action): void {
    global $securityLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $user = $_SESSION['username'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $logEntry = "[{$timestamp}] [SECURITY] [{$user}] [{$ip}] [{$action}] {$message} | UA: {$userAgent}" . PHP_EOL;
    file_put_contents($securityLogFile, $logEntry, FILE_APPEND);
}

// Function to get session duration
function getSessionDuration(): string {
    if (!isset($_SESSION['login_time'])) {
        return "Unknown duration";
    }
    
    $duration = time() - $_SESSION['login_time'];
    
    if ($duration < 60) {
        return "{$duration} seconds";
    } elseif ($duration < 3600) {
        $minutes = floor($duration / 60);
        return "{$minutes} minute" . ($minutes == 1 ? '' : 's');
    } else {
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        return "{$hours} hour" . ($hours == 1 ? '' : 's') . 
               " and {$minutes} minute" . ($minutes == 1 ? '' : 's');
    }
}

// Function to perform secure logout
function performLogout(string $reason = "User initiated logout"): void {
    global $sessionKey;
    
    // Log the logout event with session info
    $sessionInfo = [
        'username' => $_SESSION['username'] ?? 'unknown',
        'role' => $_SESSION['user_role'] ?? 'unknown',
        'ip' => $_SESSION['ip_address'] ?? 'unknown',
        'duration' => getSessionDuration()
    ];
    
    logSecurity(
        "User logged out: {$sessionInfo['username']} ({$sessionInfo['role']}) - " . 
        "Session duration: {$sessionInfo['duration']} - Reason: {$reason}",
        "LOGOUT"
    );
    
    // Clear all session variables
    $_SESSION = [];
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params["path"],
                'domain' => $params["domain"],
                'secure' => $params["secure"],
                'httponly' => $params["httponly"],
                'samesite' => 'Strict'
            ]
        );
    }
    
    // Destroy the session
    session_destroy();
}

// Generate CSRF token
function generateCsrfToken(): string {
    if (!isset($_SESSION['logout_csrf_token'])) {
        $_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['logout_csrf_token'];
}

// Validate CSRF token
function validateCsrfToken(?string $token): bool {
    if (empty($token) || !isset($_SESSION['logout_csrf_token'])) {
        logSecurity("CSRF token validation failed during logout attempt", "CSRF_VALIDATION");
        return false;
    }
    
    return hash_equals($_SESSION['logout_csrf_token'], $token);
}

// Default logout settings
$confirmationPage = isset($_GET['confirm']) || (isset($_POST['confirm']) && $_POST['confirm'] === 'true');
$logoutReason = "User initiated logout";
$redirectTo = 'login.php';
$redirectDelay = 3; // seconds
$csrfToken = generateCsrfToken();
$error = '';

// If user isn't logged in, redirect to login page
if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
    header('Location: ' . $redirectTo);
    exit;
}

// Handle direct logout requests
if ((isset($_GET['action']) && $_GET['action'] === 'logout') || isset($_POST['logout'])) {
    
    // For POST requests, validate CSRF token
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $error = 'Security validation failed. Please use the proper logout button.';
            // Don't logout, just show the error on the confirmation page
            $confirmationPage = true;
        } else {
            // CSRF validation passed, proceed with logout
            if (isset($_POST['reason'])) {
                $logoutReason = htmlspecialchars($_POST['reason']);
            }
            
            performLogout($logoutReason);
            
            // If the request was AJAX, return JSON
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Logout successful']);
                exit;
            }
            
            // Otherwise redirect with message
            header('Location: ' . $redirectTo . '?message=logged_out');
            exit;
        }
    } else {
        // GET logout request - must have valid token in URL
        if (!validateCsrfToken($_GET['token'] ?? null)) {
            $error = 'Invalid logout request. Please use the proper logout button.';
            $confirmationPage = true;
        } else {
            // CSRF validation passed, proceed with logout
            if (isset($_GET['reason'])) {
                $logoutReason = htmlspecialchars($_GET['reason']);
            }
            
            performLogout($logoutReason);
            header('Location: ' . $redirectTo . '?message=logged_out');
            exit;
        }
    }
}

// Handle session timeout
if (isset($_GET['timeout']) && $_GET['timeout'] === 'true') {
    $logoutReason = "Session timeout due to inactivity";
    performLogout($logoutReason);
    header('Location: ' . $redirectTo . '?message=session_timeout');
    exit;
}

// Handle security issue logouts
if (isset($_GET['security_issue']) && !empty($_GET['security_issue'])) {
    $securityIssue = htmlspecialchars($_GET['security_issue']);
    $logoutReason = "Security concern: {$securityIssue}";
    performLogout($logoutReason);
    header('Location: ' . $redirectTo . '?message=security_issue&details=' . urlencode($securityIssue));
    exit;
}

// If we reach here, show the confirmation page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Logout - Admin Control Panel</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>⚙️</text></svg>">
    
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --danger-color: #e74c3c;
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
        
        .logout-container {
            max-width: 500px;
            width: 100%;
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .logout-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        
        .logout-title {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .logout-message {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }
        
        .user-info {
            background-color: rgba(0, 0, 0, 0.05);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            text-align: left;
        }
        
        .user-info p {
            margin-bottom: 0.5rem;
        }
        
        .user-info strong {
            font-weight: 600;
        }
        
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .btn {
            flex: 1;
            display: inline-block;
            padding: 0.75rem 1.25rem;
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
        
        .btn-outline {
            color: var(--primary-color);
            background-color: transparent;
            border: 1px solid var(--primary-color);
        }
        
        .btn-outline:hover, .btn-outline:focus {
            background-color: rgba(52, 152, 219, 0.1);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .btn-danger {
            color: #fff;
            background-color: var(--danger-color);
        }
        
        .progress-bar-container {
            width: 100%;
            height: 6px;
            background-color: var(--border-color);
            border-radius: 3px;
            overflow: hidden;
            margin: 1.5rem 0;
        }
        
        .progress-bar {
            height: 100%;
            width: 100%;
            background-color: var(--primary-color);
            border-radius: 3px;
            transition: width 1s linear;
        }
        
        .countdown {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.25rem;
            border: 1px solid transparent;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        @media (max-width: 576px) {
            .logout-container {
                padding: 1.5rem;
                box-shadow: none;
                border: 1px solid var(--border-color);
            }
            
            .btn-group {
                flex-direction: column;
            }
        }
        
        .text-muted {
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="logout-icon">🔒</div>
        <h1 class="logout-title">Logout Confirmation</h1>
        
        <p class="logout-message">
            Are you sure you want to log out of the Admin Control Panel?
        </p>
        
        <div class="user-info">
            <p><strong>Username:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></p>
            <p><strong>Role:</strong> <?php echo htmlspecialchars($_SESSION['user_role'] ?? 'Unknown'); ?></p>
            <p><strong>Session started:</strong> <?php echo isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'Unknown'; ?></p>
            <p><strong>Session duration:</strong> <?php echo getSessionDuration(); ?></p>
            <p><strong>IP address:</strong> <?php echo htmlspecialchars($_SESSION['ip_address'] ?? $_SERVER['REMOTE_ADDR']); ?></p>
        </div>
        
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="confirm" value="true">
            <input type="hidden" name="reason" value="User confirmed logout">
            
            <div class="btn-group">
                <a href="admin.php" class="btn btn-outline">Cancel</a>
                <button type="submit" name="logout" class="btn btn-primary" id="logoutButton">Confirm Logout</button>
            </div>
        </form>
        
        <div class="countdown" id="countdown">
            Automatic logout in <span id="timer"><?php echo $redirectDelay; ?></span> seconds...
        </div>
        
        <div class="progress-bar-container">
            <div class="progress-bar" id="progressBar"></div>
        </div>
        
        <p class="text-muted">
            For security reasons, any active sessions will be terminated and you'll need to login again to access the system.
        </p>
    </div>
    
    <script>
        // Auto-logout countdown timer
        (function() {
            let seconds = <?php echo $redirectDelay; ?>;
            const timerElement = document.getElementById('timer');
            const progressBar = document.getElementById('progressBar');
            const logoutButton = document.getElementById('logoutButton');
            
            progressBar.style.width = '100%';
            
            const countdown = setInterval(function() {
                seconds--;
                timerElement.textContent = seconds;
                
                // Update progress bar
                const percentage = (seconds / <?php echo $redirectDelay; ?>) * 100;
                progressBar.style.width = percentage + '%';
                
                if (seconds <= 0) {
                    clearInterval(countdown);
                    logoutButton.click();
                }
            }, 1000);
            
            // Stop countdown if user interacts with the page
            document.addEventListener('click', function() {
                clearInterval(countdown);
                document.getElementById('countdown').style.display = 'none';
                progressBar.style.display = 'none';
            });
        })();
        
        // Track session activity
        let sessionData = {
            startTime: <?php echo $_SESSION['login_time'] ?? 'Date.now() / 1000'; ?>,
            duration: <?php echo time() - ($_SESSION['login_time'] ?? time()); ?>,
            lastActivity: <?php echo $_SESSION['last_activity'] ?? 'Date.now() / 1000'; ?>
        };
        
        // Add confirmation for the logout button
        document.getElementById('logoutButton').addEventListener('click', function(e) {
            // Prevent form submission for a moment
            e.preventDefault();
            
            // Add loading state to button
            this.innerHTML = 'Logging out...';
            this.disabled = true;
            
            // Submit the form after a brief delay
            setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>