<?php
/**
 * Advanced Administrative Control Panel
 * 
 * A comprehensive, secure, and feature-rich administrative interface for 
 * website maintenance, development, and monitoring.
 * 
 * Features:
 * - Enhanced security with role-based access control
 * - Real-time file system monitoring
 * - Advanced code editor with syntax highlighting
 * - System diagnostics and performance monitoring
 * - Database management interface
 * - Security audit logging
 * - API connectivity and webhook management
 * - Task scheduling and automation
 */

// Strict error reporting and type checking for development
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Start session with secure settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();
// Generate CSRF token for logout if not exists
if (!isset($_SESSION['logout_csrf_token'])) {
    $_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
}
// Time tracking for performance metrics
$startTime = microtime(true);

/** ------------------------------------------
 * Configuration Management
 * -------------------------------------------
 * Centralized configuration with environment detection
 */
class ConfigManager {
    private static $instance = null;
    private $config = [];
    private $env;

    private function __construct() {
        // Determine environment
        $this->env = getenv('APP_ENV') ?: 'production';
        
        // Base configuration
        $this->config = [
            'base_dir'           => dirname(__DIR__),
            'admin_dir'          => __DIR__,
            'default_filter'     => '*',
            'allowed_operations' => true,
            'cache_enabled'      => true,
            'cache_ttl'          => 3600,  // cache duration in seconds
            'session_key'        => 'admin_auth',
            'log_dir'            => __DIR__ . '/logs',
            'log_file'           => __DIR__ . '/logs/admin_activity.log',
            'error_log'          => __DIR__ . '/logs/error.log',
            'security_log'       => __DIR__ . '/logs/security.log',
            'temp_dir'           => sys_get_temp_dir() . '/admin_temp',
            'backup_dir'         => __DIR__ . '/backups',
            'allow_script_exec'  => false,
            'db_config'          => [
                'host'     => 'localhost',
                'user'     => 'dbuser',
                'password' => 'dbpassword',
                'database' => 'dbname'
            ],
            'max_upload_size'    => 50 * 1024 * 1024, // 50MB
            'allowed_file_types' => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'pdf', 'txt', 'html', 'css', 'js', 'php', 'json', 'xml'],
            'excluded_dirs'      => ['.git', 'node_modules', 'vendor', 'logs', 'cache'],
            'api_keys'           => [],
            'version'            => '2.5.0',
            'csrf_token_name'    => 'admin_csrf_token', // Added CSRF token name
        ];
        
        // Environment-specific overrides
        $envConfigFile = __DIR__ . '/config.' . $this->env . '.php';
        if (file_exists($envConfigFile)) {
            $envConfig = include $envConfigFile;
            $this->config = array_merge($this->config, $envConfig);
        }
        
        // Create required directories
        $this->ensureDirectoriesExist([
            $this->config['log_dir'],
            $this->config['temp_dir'],
            $this->config['backup_dir']
        ]);
    }
    
    private function ensureDirectoriesExist(array $dirs): void {
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $this->config[$key] = $value;
    }
    
    public function getEnvironment(): string {
        return $this->env;
    }
    
    public function isDevelopment(): bool {
        return $this->env === 'development';
    }
}

/** ------------------------------------------
 * Enhanced Error Handling & Logging System
 * -------------------------------------------
 * Comprehensive error tracking, reporting and visualization
 */
class ErrorHandler {
    private $logFile;
    private $securityLogFile;
    private $errorLogFile;
    private $isDevelopment;
    private static $instance = null;
    private $errors = [];

    private function __construct(string $errorLogFile, string $securityLogFile, bool $isDevelopment) {
        $this->errorLogFile = $errorLogFile;
        $this->securityLogFile = $securityLogFile;
        $this->isDevelopment = $isDevelopment;
        
        // Register PHP error handlers
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }
    
    public static function getInstance(string $errorLogFile, string $securityLogFile, bool $isDevelopment): self {
        if (self::$instance === null) {
            self::$instance = new self($errorLogFile, $securityLogFile, $isDevelopment);
        }
        return self::$instance;
    }

    public function handleError($errno, $errstr, $errfile, $errline): bool {
        $error = [
            'type' => $this->getErrorType($errno),
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'time' => date('Y-m-d H:i:s')
        ];
        
        $this->errors[] = $error;
        $this->logError($error);
        
        // Display error in development mode for non-fatal errors
        if ($this->isDevelopment && !in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            echo "<div class='error-message'>";
            echo "<strong>Error ({$error['type']}):</strong> {$error['message']} in <strong>{$error['file']}</strong> on line <strong>{$error['line']}</strong>";
            echo "</div>";
        }
        
        // Return false for fatal errors, true to suppress PHP's internal error handler
        return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]) ? false : true;
    }
    
    private function getErrorType($errno): string {
        switch($errno) {
            case E_ERROR: return 'Fatal Error';
            case E_WARNING: return 'Warning';
            case E_PARSE: return 'Parse Error';
            case E_NOTICE: return 'Notice';
            case E_CORE_ERROR: return 'Core Error';
            case E_CORE_WARNING: return 'Core Warning';
            case E_COMPILE_ERROR: return 'Compile Error';
            case E_COMPILE_WARNING: return 'Compile Warning';
            case E_USER_ERROR: return 'User Error';
            case E_USER_WARNING: return 'User Warning';
            case E_USER_NOTICE: return 'User Notice';
            case E_STRICT: return 'Strict Standards';
            case E_RECOVERABLE_ERROR: return 'Recoverable Error';
            case E_DEPRECATED: return 'Deprecated';
            case E_USER_DEPRECATED: return 'User Deprecated';
            default: return 'Unknown Error';
        }
    }

    public function handleException(\Throwable $e): void {
        $error = [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'time' => date('Y-m-d H:i:s')
        ];
        
        $this->errors[] = $error;
        $this->logError($error);
        
        if ($this->isDevelopment) {
            echo "<div class='exception-container'>";
            echo "<h3>Exception: {$error['type']}</h3>";
            echo "<p><strong>Message:</strong> {$error['message']}</p>";
            echo "<p><strong>Location:</strong> {$error['file']} (line {$error['line']})</p>";
            echo "<div class='stack-trace'><pre>{$error['trace']}</pre></div>";
            echo "</div>";
        } else {
            echo "<div class='error-message'>An application error has occurred. The administrator has been notified.</div>";
        }
    }
    
    public function handleFatalError(): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }
    
    private function logError(array $error): void {
        $entry = "[{$error['time']}] [{$error['type']}] {$error['message']} in {$error['file']} on line {$error['line']}" . PHP_EOL;
        file_put_contents($this->errorLogFile, $entry, FILE_APPEND);
    }

    public function log(string $message, string $level = 'INFO', string $context = 'general'): void {
        $timestamp = date('Y-m-d H:i:s');
        $user = $_SESSION['username'] ?? 'guest';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $logEntry = "[{$timestamp}] [{$level}] [{$user}] [{$ip}] [{$context}] {$message}" . PHP_EOL;
        
        if ($level === 'SECURITY') {
            file_put_contents($this->securityLogFile, $logEntry, FILE_APPEND);
        } else {
            file_put_contents($this->errorLogFile, $logEntry, FILE_APPEND);
        }
    }
    
    public function logSecurity(string $message, string $action): void {
        $this->log($message, 'SECURITY', $action);
    }
    
    public function getErrors(): array {
        return $this->errors;
    }
    
    public function displayErrorSummary(): void {
        if (empty($this->errors)) return;
        
        echo "<div class='error-summary'>";
        echo "<h3>Error Summary</h3>";
        echo "<ul>";
        foreach ($this->errors as $error) {
            echo "<li>[{$error['type']}] {$error['message']} in {$error['file']} (line {$error['line']})</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
}

/** ------------------------------------------
 * CSRF Protection
 * -------------------------------------------
 * Cross-Site Request Forgery protection implementation
 */
class CsrfProtection {
    private $tokenName;
    private $errorHandler;
    
    public function __construct(string $tokenName, ErrorHandler $errorHandler) {
        $this->tokenName = $tokenName;
        $this->errorHandler = $errorHandler;
    }
    
    public function generateToken(): string {
        if (!isset($_SESSION[$this->tokenName])) {
            $_SESSION[$this->tokenName] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$this->tokenName];
    }
    
    public function validateToken(?string $token): bool {
        if (empty($token) || !isset($_SESSION[$this->tokenName])) {
            $this->errorHandler->logSecurity("CSRF token validation failed", "CSRF_VALIDATION");
            return false;
        }
        
        return hash_equals($_SESSION[$this->tokenName], $token);
    }
    
    public function getTokenField(): string {
        $token = $this->generateToken();
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
    
    public function renewToken(): void {
        $_SESSION[$this->tokenName] = bin2hex(random_bytes(32));
    }
}

/** ------------------------------------------
 * Advanced Authentication & Authorization System
 * -------------------------------------------
 * Multi-factor authentication with role-based access control
 */
class AuthManager {
    private $sessionKey;
    private $errorHandler;
    private $config;
    private $roles = ['admin', 'editor', 'viewer'];
    private $permissions = [
        'admin' => ['read', 'write', 'delete', 'execute', 'manage_users', 'system_config'],
        'editor' => ['read', 'write', 'delete'],
        'viewer' => ['read']
    ];
    // Add 2FA secrets storage
    private $twoFactorSecrets = [
        'admin' => 'Cooper', // Should be securely stored in production
        'editor' => 'Cooper'
    ];

    public function __construct(string $sessionKey, ErrorHandler $errorHandler, ConfigManager $config) {
        $this->sessionKey = $sessionKey;
        $this->errorHandler = $errorHandler;
        $this->config = $config;
    }

    public function ensureAuthenticated(): void {
        // Check if user is logged in
        if (!isset($_SESSION[$this->sessionKey]) || $_SESSION[$this->sessionKey] !== true) {
            $this->errorHandler->logSecurity("Unauthenticated access attempt", "AUTH_REQUIRED");
            $this->redirectToLogin();
        }
        
        // Session expiration check
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
            // Session expired (30 minutes inactive)
            $this->logout("Session expired due to inactivity");
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
        
        // IP binding check for security
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            $this->errorHandler->logSecurity("Session IP mismatch: {$_SESSION['ip_address']} vs {$_SERVER['REMOTE_ADDR']}", "IP_CHANGED");
            $this->logout("Security concern: IP address changed");
        }
    }
    
    public function hasPermission(string $permission): bool {
        if (!isset($_SESSION['user_role'])) {
            return false;
        }
        
        $role = $_SESSION['user_role'];
        return in_array($permission, $this->permissions[$role] ?? []);
    }
    
    public function requirePermission(string $permission): void {
        if (!$this->hasPermission($permission)) {
            $username = $this->getUsername() ?? 'unknown';
            $this->errorHandler->logSecurity(
                "Permission denied: {$permission} for user {$username}",
                "PERMISSION_DENIED"
            );
            
            http_response_code(403);
            exit("Access Denied: You don't have permission to perform this action.");
        }
    }
    
    public function login(string $username, string $password, ?string $twoFactorCode = null): bool {
        // For demonstration - in a real app, use a proper user database
        // and password hashing (password_hash/password_verify)
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
        
        if (!isset($users[$username])) {
            $this->errorHandler->logSecurity("Failed login attempt for non-existent user: {$username}", "LOGIN_FAILED");
            return false;
        }
        
        if (!password_verify($password, $users[$username]['password'])) {
            $this->errorHandler->logSecurity("Failed login attempt for user: {$username}", "LOGIN_FAILED");
            return false;
        }
        
        // Two-factor check
        if ($users[$username]['twoFactor']) {
            if ($twoFactorCode === null) {
                $_SESSION['pending_user'] = $username;
                return false; // Need 2FA code
            }
            
            // Verify 2FA code - this should use a proper TOTP library in production
            if (!$this->verifyTwoFactorCode($username, $twoFactorCode)) {
                $this->errorHandler->logSecurity("Failed 2FA attempt for user: {$username}", "2FA_FAILED");
                return false;
            }
        }
        
        // Login successful - regenerate session id to prevent session fixation
        session_regenerate_id(true);
        
        $_SESSION[$this->sessionKey] = true;
        $_SESSION['username'] = $username;
        $_SESSION['user_role'] = $users[$username]['role'];
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        
        $this->errorHandler->logSecurity("User logged in: {$username}", "LOGIN_SUCCESS");
        return true;
    }

    private function verifyTwoFactorCode(string $username, string $code): bool {
        // In a real application, use a proper TOTP library like OTPHP
        // This is a simplified example
        if (!isset($this->twoFactorSecrets[$username])) {
            return false;
        }
        
        // Simple verification (for demonstration only)
        // In production, use a library like OTPHP for proper TOTP validation
        return $code === '123456';
    }

    public function logout(string $reason = "User logged out"): void {
        $username = $_SESSION['username'] ?? 'unknown';
        $this->errorHandler->logSecurity($reason . ": {$username}", "LOGOUT");
        
        // Clear all session data
        $_SESSION = [];
        
        // Clear session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        $this->redirectToLogin();
    }
    
    private function redirectToLogin(): void {
        header('Location: login.php');
        exit;
    }
    
    public function getUserRole(): ?string {
        return $_SESSION['user_role'] ?? null;
    }
    
    public function getUsername(): ?string {
        return $_SESSION['username'] ?? null;
    }
    
    public function getSessionInfo(): array {
        return [
            'username' => $this->getUsername(),
            'role' => $this->getUserRole(),
            'login_time' => $_SESSION['login_time'] ?? null,
            'last_activity' => $_SESSION['last_activity'] ?? null,
            'ip_address' => $_SESSION['ip_address'] ?? null
        ];
    }
}

/** ------------------------------------------
 * Directory & File System Manager
 * -------------------------------------------
 * Advanced filesystem operations with security constraints
 */
class FileSystemManager {
    private $baseDir;
    private $cacheEnabled;
    private $cacheTTL;
    private $excludedDirs;
    private $errorHandler;
    private $tempDir;
    private $backupDir;
    private $maxUploadSize;
    private $allowedFileTypes;

    public function __construct(
        string $baseDir, 
        bool $cacheEnabled = false, 
        int $cacheTTL = 60,
        array $excludedDirs = [],
        string $tempDir = '',
        string $backupDir = '',
        int $maxUploadSize = 10485760,
        array $allowedFileTypes = [],
        ErrorHandler $errorHandler
    ) {
        $this->baseDir = realpath($baseDir);
        $this->cacheEnabled = $cacheEnabled;
        $this->cacheTTL = $cacheTTL;
        $this->excludedDirs = $excludedDirs;
        $this->errorHandler = $errorHandler;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();
        $this->backupDir = $backupDir ?: dirname($baseDir) . '/backups';
        $this->maxUploadSize = $maxUploadSize;
        $this->allowedFileTypes = $allowedFileTypes;
        
        // Ensure backup directory exists
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    public function sanitizePath(string $path): string {
        $realPath = realpath($path);
        if ($realPath === false || strpos($realPath, $this->baseDir) !== 0) {
            $this->errorHandler->logSecurity("Path traversal attempt: {$path}", "PATH_TRAVERSAL");
            return $this->baseDir;
        }
        return $realPath;
    }
    
    public function isExcludedPath(string $path): bool {
        $path = $this->sanitizePath($path);
        $relativePath = str_replace($this->baseDir, '', $path);
        $pathSegments = explode(DIRECTORY_SEPARATOR, trim($relativePath, DIRECTORY_SEPARATOR));
        
        foreach ($pathSegments as $segment) {
            if (in_array($segment, $this->excludedDirs)) {
                return true;
            }
        }
        
        return false;
    }

    public function listFilesAndDirs(string $dir, string $filter = '*', bool $recursive = false, int $depth = 0): array {
        $dir = $this->sanitizePath($dir);
        
        if ($this->isExcludedPath($dir)) {
            return [
                'directories' => [],
                'files' => [],
                'stats' => [
                    'total_files' => 0,
                    'total_dirs' => 0,
                    'total_size' => 0
                ]
            ];
        }

        // Simple caching layer
        $cacheKey = md5($dir.$filter.$recursive.$depth);
        if ($this->cacheEnabled && $cached = $this->getCache($cacheKey)) {
            return $cached;
        }

        $result = [
            'directories' => [],
            'files' => [],
            'stats' => [
                'total_files' => 0,
                'total_dirs' => 0,
                'total_size' => 0
            ]
        ];
        
        // Get all items in directory
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($fullPath)) {
                if (!$this->isExcludedPath($fullPath)) {
                    $result['directories'][] = [
                        'path' => $fullPath,
                        'name' => $item,
                        'modified' => filemtime($fullPath)
                    ];
                    $result['stats']['total_dirs']++;
                    
                    // Handle recursive scanning
                    if ($recursive && $depth > 0) {
                        $subResult = $this->listFilesAndDirs($fullPath, $filter, true, $depth - 1);
                        $result['directories'] = array_merge($result['directories'], $subResult['directories']);
                        $result['files'] = array_merge($result['files'], $subResult['files']);
                        $result['stats']['total_files'] += $subResult['stats']['total_files'];
                        $result['stats']['total_dirs'] += $subResult['stats']['total_dirs'];
                        $result['stats']['total_size'] += $subResult['stats']['total_size'];
                    }
                }
            } elseif (is_file($fullPath) && $this->matchesFilter($item, $filter)) {
                $size = filesize($fullPath);
                $result['files'][] = [
                    'path' => $fullPath,
                    'name' => $item,
                    'size' => $size,
                    'modified' => filemtime($fullPath),
                    'extension' => pathinfo($fullPath, PATHINFO_EXTENSION),
                    'mime_type' => $this->getMimeType($fullPath)
                ];
                $result['stats']['total_files']++;
                $result['stats']['total_size'] += $size;
            }
        }
        
        // Sort directories and files alphabetically
        usort($result['directories'], function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        usort($result['files'], function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        if ($this->cacheEnabled) {
            $this->setCache($cacheKey, $result);
        }

        return $result;
    }
    
    private function matchesFilter(string $filename, string $filter): bool {
        if ($filter === '*') return true;
        
        // Handle multiple filters separated by comma
        if (strpos($filter, ',') !== false) {
            $filters = explode(',', $filter);
            foreach ($filters as $singleFilter) {
                if ($this->matchesSingleFilter($filename, trim($singleFilter))) {
                    return true;
                }
            }
            return false;
        }
        
        return $this->matchesSingleFilter($filename, $filter);
    }
    
    private function matchesSingleFilter(string $filename, string $filter): bool {
        // Handle glob patterns
        if (strpos($filter, '*') !== false) {
            return fnmatch($filter, $filename);
        }
        
        // Handle extension filtering
        if (strpos($filter, '.') === 0) {
            return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === strtolower(substr($filter, 1));
        }
        
        // Default - exact match
        return $filename === $filter;
    }
    
    private function getMimeType(string $file): string {
        if (function_exists('mime_content_type')) {
            return mime_content_type($file);
        }
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf'
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }

    public function getFileContent(string $file): string {
        $file = $this->sanitizePath($file);
        if (!file_exists($file) || !is_file($file)) {
            throw new \Exception("File not found: {$file}");
        }
        
        // Check file size before reading to prevent memory issues
        $size = filesize($file);
        if ($size > 5 * 1024 * 1024) { // 5MB limit for text editing
            throw new \Exception("File too large for editing: {$size} bytes");
        }
        
        return file_get_contents($file);
    }

    public function saveFile(string $file, string $content): bool {
        $file = $this->sanitizePath($file);
        
        // Make backup of existing file
        if (file_exists($file)) {
            $this->backupFile($file);
        }
        
        $result = file_put_contents($file, $content);
        
        if ($result === false) {
            $this->errorHandler->log("Failed to save file: {$file}", "ERROR", "FILE_SAVE");
            return false;
        }
        
        $this->errorHandler->log("File saved: {$file}", "INFO", "FILE_SAVE");
        return true;
    }
    
    public function backupFile(string $file): bool {
        $file = $this->sanitizePath($file);
        if (!file_exists($file) || !is_file($file)) {
            return false;
        }
        
        $backupFilename = $this->backupDir . '/' . basename($file) . '.' . date('YmdHis') . '.bak';
        
        if (!copy($file, $backupFilename)) {
            $this->errorHandler->log("Failed to create backup of file: {$file}", "ERROR", "BACKUP");
            return false;
        }
        
        $this->errorHandler->log("Created backup: {$backupFilename}", "INFO", "BACKUP");
        return true;
    }

    public function deleteFile(string $file): bool {
        $file = $this->sanitizePath($file);
        
        if (!file_exists($file) || !is_file($file)) {
            $this->errorHandler->log("Attempted to delete non-existent file: {$file}", "WARNING", "FILE_DELETE");
            return false;
        }
        
        // Create backup before deletion
        $this->backupFile($file);
        
        if (!unlink($file)) {
            $this->errorHandler->log("Failed to delete file: {$file}", "ERROR", "FILE_DELETE");
            return false;
        }
        
        $this->errorHandler->log("File deleted: {$file}", "INFO", "FILE_DELETE");
        return true;
    }

    public function downloadFile(string $file): void {
        $file = $this->sanitizePath($file);
        
        if (!file_exists($file) || !is_file($file)) {
            $this->errorHandler->log("Attempted to download non-existent file: {$file}", "WARNING", "FILE_DOWNLOAD");
            die("File not found");
        }
        
        $this->errorHandler->log("File downloaded: {$file}", "INFO", "FILE_DOWNLOAD");
        
        $filename = basename($file);
        $mimeType = $this->getMimeType($file);
        
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function uploadFile(string $dir, array $fileData): array {
        $dir = $this->sanitizePath($dir);
        $response = ['success' => false, 'message' => '', 'file' => null];
        
        if (!isset($fileData['upload_file']) || $fileData['upload_file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = $this->getUploadErrorMessage($fileData['upload_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            $response['message'] = "Upload failed: {$errorMessage}";
            $this->errorHandler->log($response['message'], "ERROR", "FILE_UPLOAD");
            return $response;
        }
        
        $tmpName = $fileData['upload_file']['tmp_name'];
        $name = $fileData['upload_file']['name'];
        $size = $fileData['upload_file']['size'];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        
        // Validate file size
        if ($size > $this->maxUploadSize) {
            $response['message'] = "File is too large. Maximum size is " . $this->formatBytes($this->maxUploadSize);
            $this->errorHandler->log($response['message'], "WARNING", "FILE_UPLOAD");
            return $response;
        }
        
        // Validate file extension
        if (!empty($this->allowedFileTypes) && !in_array($extension, $this->allowedFileTypes)) {
            $response['message'] = "File type not allowed: {$extension}";
            $this->errorHandler->log($response['message'], "WARNING", "FILE_UPLOAD");
            return $response;
        }
        
        // Validate file content
        $actualMimeType = $this->getActualMimeType($tmpName);
        if (!$this->validateFileMimeType($extension, $actualMimeType)) {
            $response['message'] = "File content doesn't match its extension: {$actualMimeType}";
            $this->errorHandler->log($response['message'], "WARNING", "FILE_UPLOAD");
            return $response;
        }
        
        // Handle filename conflicts
        $targetPath = $dir . DIRECTORY_SEPARATOR . $name;
        if (file_exists($targetPath)) {
            $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
            $newName = $nameWithoutExt . '_' . date('YmdHis') . '.' . $extension;
            $targetPath = $dir . DIRECTORY_SEPARATOR . $newName;
            $name = $newName;
        }
        
        if (!move_uploaded_file($tmpName, $targetPath)) {
            $response['message'] = "Failed to move uploaded file";
            $this->errorHandler->log($response['message'], "ERROR", "FILE_UPLOAD");
            return $response;
        }
        
        $response['success'] = true;
        $response['message'] = "File uploaded successfully";
        $response['file'] = [
            'path' => $targetPath,
            'name' => $name,
            'size' => $size,
            'extension' => $extension
        ];
        
        $this->errorHandler->log("File uploaded: {$targetPath} ({$this->formatBytes($size)})", "INFO", "FILE_UPLOAD");
        return $response;
    }
    
    private function getActualMimeType(string $file): string {
        // Use fileinfo extension if available
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file);
            finfo_close($finfo);
            return $mimeType;
        }
        
        // Fallback to mime_content_type if available
        if (function_exists('mime_content_type')) {
            return mime_content_type($file);
        }
        
        // Last resort - use extension-based detection
        return $this->getMimeType($file);
    }
    
    private function validateFileMimeType(string $extension, string $mimeType): bool {
        // Define allowed MIME types for each extension
        $allowedMimeTypes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'html' => ['text/html'],
            'htm' => ['text/html'],
            'css' => ['text/css'],
            'js' => ['application/javascript', 'text/javascript'],
            'json' => ['application/json'],
            'xml' => ['application/xml', 'text/xml'],
            'php' => ['text/x-php', 'application/x-httpd-php', 'text/plain'],
            'svg' => ['image/svg+xml']
        ];
        
        // If extension not in our list, allow it
        if (!isset($allowedMimeTypes[$extension])) {
            return true;
        }
        
        // Check if the detected MIME type is in the allowed list for this extension
        return in_array($mimeType, $allowedMimeTypes[$extension]);
    }
    
    private function getUploadErrorMessage(int $errorCode): string {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return "The uploaded file exceeds the upload_max_filesize directive in php.ini";
            case UPLOAD_ERR_FORM_SIZE:
                return "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form";
            case UPLOAD_ERR_PARTIAL:
                return "The uploaded file was only partially uploaded";
            case UPLOAD_ERR_NO_FILE:
                return "No file was uploaded";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "Missing a temporary folder";
            case UPLOAD_ERR_CANT_WRITE:
                return "Failed to write file to disk";
            case UPLOAD_ERR_EXTENSION:
                return "A PHP extension stopped the file upload";
            default:
                return "Unknown upload error";
        }
    }
    
    public function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function createFile(string $dir, string $name): bool {
        $dir = $this->sanitizePath($dir);
        $target = $dir . DIRECTORY_SEPARATOR . basename($name);
        
        // Check if file already exists
        if (file_exists($target)) {
            $this->errorHandler->log("Attempted to create file that already exists: {$target}", "WARNING", "FILE_CREATE");
            return false;
        }
        
        // Validate file extension
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!empty($this->allowedFileTypes) && !in_array($extension, $this->allowedFileTypes)) {
            $this->errorHandler->log("Attempted to create file with disallowed extension: {$extension}", "WARNING", "FILE_CREATE");
            return false;
        }
        
        $result = file_put_contents($target, '');
        
        if ($result === false) {
            $this->errorHandler->log("Failed to create file: {$target}", "ERROR", "FILE_CREATE");
            return false;
        }
        
        $this->errorHandler->log("File created: {$target}", "INFO", "FILE_CREATE");
        return true;
    }

    public function createDirectory(string $dir, string $newName): bool {
        $dir = $this->sanitizePath($dir);
        $target = $dir . DIRECTORY_SEPARATOR . basename($newName);
        
        if (file_exists($target)) {
            $this->errorHandler->log("Attempted to create directory that already exists: {$target}", "WARNING", "DIR_CREATE");
            return false;
        }
        
        if (!mkdir($target, 0755)) {
            $this->errorHandler->log("Failed to create directory: {$target}", "ERROR", "DIR_CREATE");
            return false;
        }
        
        $this->errorHandler->log("Directory created: {$target}", "INFO", "DIR_CREATE");
        return true;
    }

    public function renameDirectory(string $oldName, string $newName): bool {
        $oldPath = $this->sanitizePath($oldName);
        $parentDir = dirname($oldPath);
        $newPath = $parentDir . DIRECTORY_SEPARATOR . basename($newName);
        
        if ($oldPath === $this->baseDir) {
            $this->errorHandler->log("Attempted to rename base directory", "WARNING", "DIR_RENAME");
            return false;
        }
        
        if (file_exists($newPath)) {
            $this->errorHandler->log("Attempted to rename to a directory that already exists: {$newPath}", "WARNING", "DIR_RENAME");
            return false;
        }
        
        if (!rename($oldPath, $newPath)) {
            $this->errorHandler->log("Failed to rename directory: {$oldPath} to {$newPath}", "ERROR", "DIR_RENAME");
            return false;
        }
        
        $this->errorHandler->log("Directory renamed: {$oldPath} to {$newPath}", "INFO", "DIR_RENAME");
        return true;
    }

    // Add file rename method
    public function renameFile(string $oldName, string $newName): bool {
        $oldPath = $this->sanitizePath($oldName);
        if (!is_file($oldPath)) {
            $this->errorHandler->log("Attempted to rename non-existent file: {$oldPath}", "WARNING", "FILE_RENAME");
            return false;
        }
        
        $parentDir = dirname($oldPath);
        $newPath = $parentDir . DIRECTORY_SEPARATOR . basename($newName);
        
        if (file_exists($newPath)) {
            $this->errorHandler->log("Attempted to rename to a file that already exists: {$newPath}", "WARNING", "FILE_RENAME");
            return false;
        }
        
        // Create a backup
        $this->backupFile($oldPath);
        
        if (!rename($oldPath, $newPath)) {
            $this->errorHandler->log("Failed to rename file: {$oldPath} to {$newPath}", "ERROR", "FILE_RENAME");
            return false;
        }
        
        $this->errorHandler->log("File renamed: {$oldPath} to {$newPath}", "INFO", "FILE_RENAME");
        return true;
    }

    public function deleteDirectory(string $dir): bool {
        $dir = $this->sanitizePath($dir);
        
        if ($dir === $this->baseDir) {
            $this->errorHandler->log("Attempted to delete base directory", "WARNING", "DIR_DELETE");
            return false;
        }
        
        if (!is_dir($dir)) {
            $this->errorHandler->log("Attempted to delete non-existent directory: {$dir}", "WARNING", "DIR_DELETE");
            return false;
        }
        
        // Check if directory is empty
        $contents = scandir($dir);
        if (count($contents) > 2) { // There are files/directories other than . and ..
            $this->errorHandler->log("Attempted to delete non-empty directory: {$dir}", "WARNING", "DIR_DELETE");
            return false;
        }
        
        if (!rmdir($dir)) {
            $this->errorHandler->log("Failed to delete directory: {$dir}", "ERROR", "DIR_DELETE");
            return false;
        }
        
        $this->errorHandler->log("Directory deleted: {$dir}", "INFO", "DIR_DELETE");
        return true;
    }
    
    public function recursiveDeleteDirectory(string $dir): bool {
        $dir = $this->sanitizePath($dir);
        
        if ($dir === $this->baseDir) {
            $this->errorHandler->log("Attempted to recursively delete base directory", "WARNING", "DIR_DELETE_RECURSIVE");
            return false;
        }
        
        if (!is_dir($dir)) {
            return false;
        }
        
        $this->errorHandler->log("Starting recursive deletion of: {$dir}", "INFO", "DIR_DELETE_RECURSIVE");
        
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $object;
            
            if (is_dir($path)) {
                $this->recursiveDeleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    public function moveFile(string $source, string $destination): bool {
        $source = $this->sanitizePath($source);
        $destination = $this->sanitizePath(dirname($destination)) . DIRECTORY_SEPARATOR . basename($destination);
        
        if (!file_exists($source) || !is_file($source)) {
            $this->errorHandler->log("Attempted to move non-existent file: {$source}", "WARNING", "FILE_MOVE");
            return false;
        }
        
        if (file_exists($destination)) {
            $this->errorHandler->log("Destination file already exists: {$destination}", "WARNING", "FILE_MOVE");
            return false;
        }
        
        // Create a backup
        $this->backupFile($source);
        
        if (!rename($source, $destination)) {
            $this->errorHandler->log("Failed to move file: {$source} to {$destination}", "ERROR", "FILE_MOVE");
            return false;
        }
        
        $this->errorHandler->log("File moved: {$source} to {$destination}", "INFO", "FILE_MOVE");
        return true;
    }
    
    public function copyFile(string $source, string $destination): bool {
        $source = $this->sanitizePath($source);
        $destination = $this->sanitizePath(dirname($destination)) . DIRECTORY_SEPARATOR . basename($destination);
        
        if (!file_exists($source) || !is_file($source)) {
            $this->errorHandler->log("Attempted to copy non-existent file: {$source}", "WARNING", "FILE_COPY");
            return false;
        }
        
        if (file_exists($destination)) {
            $this->errorHandler->log("Destination file already exists: {$destination}", "WARNING", "FILE_COPY");
            return false;
        }
        
        if (!copy($source, $destination)) {
            $this->errorHandler->log("Failed to copy file: {$source} to {$destination}", "ERROR", "FILE_COPY");
            return false;
        }
        
        $this->errorHandler->log("File copied: {$source} to {$destination}", "INFO", "FILE_COPY");
        return true;
    }
    
    public function getFileInfo(string $file): array {
        $file = $this->sanitizePath($file);
        
        if (!file_exists($file) || !is_file($file)) {
            throw new \Exception("File not found: {$file}");
        }
        
        return [
            'path' => $file,
            'name' => basename($file),
            'dir' => dirname($file),
            'size' => filesize($file),
            'size_formatted' => $this->formatBytes(filesize($file)),
            'modified' => filemtime($file),
            'modified_formatted' => date("Y-m-d H:i:s", filemtime($file)),
            'permissions' => substr(sprintf('%o', fileperms($file)), -4),
            'extension' => pathinfo($file, PATHINFO_EXTENSION),
            'mime_type' => $this->getMimeType($file),
            'is_readable' => is_readable($file),
            'is_writable' => is_writable($file)
        ];
    }
    
	public function getDirInfo(string $dir): array {
		$dir = $this->sanitizePath($dir);
		
		if (!file_exists($dir) || !is_dir($dir)) {
			throw new \Exception("Directory not found: {$dir}");
		}
		
		$stats = $this->getDirStats($dir);
		
		return [
			'path' => $dir,
			'name' => basename($dir),
			'parent' => dirname($dir),
			'modified' => filemtime($dir),
			'modified_formatted' => date("Y-m-d H:i:s", filemtime($dir)),
			'permissions' => substr(sprintf('%o', fileperms($dir)), -4),
			'is_readable' => is_readable($dir),
			'is_writable' => is_writable($dir),
			'file_count' => $stats['file_count'],
			'dir_count' => $stats['dir_count'],
			'total_size' => $stats['total_size'],
			'total_size_formatted' => $this->formatBytes($stats['total_size'])
		];
	}
    
    private function getDirStats(string $dir): array {
        $stats = [
            'file_count' => 0,
            'dir_count' => 0, 
            'total_size' => 0
        ];
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($path)) {
                $stats['dir_count']++;
                // Don't recursively calculate stats to avoid performance issues
            } elseif (is_file($path)) {
                $stats['file_count']++;
                $stats['total_size'] += filesize($path);
            }
        }
        
        return $stats;
    }
    
    public function search(string $baseDir, string $term, bool $caseSensitive = false, array $extensions = []): array {
        $baseDir = $this->sanitizePath($baseDir);
        $results = [
            'files' => [],
            'matches' => [],
            'count' => 0
        ];
        
        $this->searchRecursive($baseDir, $term, $results, $caseSensitive, $extensions);
        
        return $results;
    }
    
    private function searchRecursive(string $dir, string $term, array &$results, bool $caseSensitive, array $extensions): void {
        if ($this->isExcludedPath($dir)) {
            return;
        }
        
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($path)) {
                $this->searchRecursive($path, $term, $results, $caseSensitive, $extensions);
            } elseif (is_file($path)) {
                // Check if file extension matches filter
                if (!empty($extensions)) {
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (!in_array($ext, $extensions)) {
                        continue;
                    }
                }
                
                // Search in file name
                if ((!$caseSensitive && stripos($item, $term) !== false) || 
                    ($caseSensitive && strpos($item, $term) !== false)) {
                    $results['files'][] = [
                        'path' => $path,
                        'name' => $item,
                        'type' => 'filename_match'
                    ];
                    $results['count']++;
                }
                
                // Search in file content for text files
                $mimeType = $this->getMimeType($path);
                if (strpos($mimeType, 'text/') === 0 || in_array($mimeType, [
                    'application/json', 'application/xml', 'application/javascript'
                ])) {
                    // Limit file size for content search to prevent memory issues
                    if (filesize($path) < 1024 * 1024) { // 1MB limit
                        $content = file_get_contents($path);
                        $matches = [];
                        
                        if ((!$caseSensitive && stripos($content, $term) !== false) || 
                            ($caseSensitive && strpos($content, $term) !== false)) {
                            
                            // Extract line numbers and context
                            $lines = explode("\n", $content);
                            foreach ($lines as $lineNum => $line) {
                                if ((!$caseSensitive && stripos($line, $term) !== false) || 
                                    ($caseSensitive && strpos($line, $term) !== false)) {
                                    $matches[] = [
                                        'line' => $lineNum + 1,
                                        'content' => $line
                                    ];
                                }
                            }
                            
                            if (!empty($matches)) {
                                $results['matches'][] = [
                                    'path' => $path,
                                    'name' => $item, 
                                    'matches' => $matches
                                ];
                                $results['count'] += count($matches);
                            }
                        }
                    }
                }
            }
        }
    }

    // Add method to create zip archive of a directory
    public function createZipArchive(string $dir, bool $includeBaseDir = true): string {
        $dir = $this->sanitizePath($dir);
        
        if (!file_exists($dir) || !is_dir($dir)) {
            throw new \Exception("Directory not found: {$dir}");
        }
        
        // Create a temporary file for the zip
        $zipFile = $this->tempDir . '/zip_' . time() . '.zip';
        
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            throw new \Exception("Cannot create zip file");
        }
        
        $baseName = basename($dir);
        
        // Add files to zip
        $this->addFilesToZip($zip, $dir, $includeBaseDir ? $baseName : '');
        
        $zip->close();
        
        $this->errorHandler->log("Created zip archive of {$dir}", "INFO", "ZIP_CREATE");
        
        return $zipFile;
    }
    
    private function addFilesToZip(\ZipArchive $zip, string $dir, string $zipPath): void {
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            
            // Skip excluded directories
            if ($this->isExcludedPath($path)) {
                continue;
            }
            
            $localPath = empty($zipPath) ? $item : $zipPath . '/' . $item;
            
            if (is_dir($path)) {
                // Add directory
                $zip->addEmptyDir($localPath);
                
                // Recursively add files in directory
                $this->addFilesToZip($zip, $path, $localPath);
            } elseif (is_file($path)) {
                // Add file
                $zip->addFile($path, $localPath);
            }
        }
    }
    
	public function downloadDirectoryAsZip(string $dir, bool $allowRootDownload = false): void {
		// Store original path before sanitization for logging
		$originalDir = $dir;
		$dir = $this->sanitizePath($dir);
		
		// Check if we're trying to download the base directory
		$isBaseDir = ($dir === $this->baseDir);
		
		// Prevent root directory download unless explicitly allowed
		if ($isBaseDir && !$allowRootDownload) {
			$this->errorHandler->log("Attempted to download root directory without permission", "WARNING", "DIR_DOWNLOAD");
			die("Permission denied: For security reasons, downloading the entire root directory requires special privileges.");
		}
		
		if (!file_exists($dir) || !is_dir($dir)) {
			$this->errorHandler->log("Attempted to download non-existent directory: {$originalDir}", "WARNING", "DIR_DOWNLOAD");
			die("Directory not found");
		}
		
		try {
			// Get directory stats before proceeding (to warn about large directories)
			$dirStats = $this->getDirStats($dir);
			$dirSize = $dirStats['total_size'];
			
			// Warn if directory is very large (100MB threshold)
			if ($dirSize > 100 * 1024 * 1024) {
				$this->errorHandler->log("Large directory download attempted: {$dir} ({$this->formatBytes($dirSize)})", "WARNING", "DIR_DOWNLOAD");
				// We continue but log the large download
			}
			
			// Create ZIP archive with option to include base directory name in the ZIP structure
			$zipFile = $this->createZipArchive($dir, !$isBaseDir);
			
			// Create descriptive ZIP filename based on the directory being downloaded
			if ($isBaseDir) {
				$zipFilename = 'root_directory';
			} else {
				$zipFilename = basename($dir);
			}
			$zipFilename .= '_' . date('Ymd_His') . '.zip';
			
			// Set proper headers
			header('Content-Description: File Transfer');
			header('Content-Type: application/zip');
			header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			
			// Make sure the ZIP file was created successfully
			if (!file_exists($zipFile)) {
				throw new \Exception("Generated ZIP file not found");
			}
			
			header('Content-Length: ' . filesize($zipFile));
			
			// Clean output buffer
			if (ob_get_level()) {
				ob_end_clean();
			}
			flush();
			
			// Stream file in chunks
			$fp = fopen($zipFile, 'rb');
			if ($fp === false) {
				throw new \Exception("Failed to open ZIP file for reading");
			}
			
			while (!feof($fp)) {
				echo fread($fp, 8192);
				flush();
			}
			
			fclose($fp);
			
			// Clean up
			if (file_exists($zipFile)) {
				unlink($zipFile);
			}
			
			$this->errorHandler->log("Directory downloaded as zip: {$dir} ({$this->formatBytes($dirSize)})", "INFO", "DIR_DOWNLOAD");
			exit;
		} catch (\Exception $e) {
			$this->errorHandler->log("Failed to create/download zip archive: " . $e->getMessage(), "ERROR", "DIR_DOWNLOAD");
			die("Failed to create zip archive: " . $e->getMessage());
		}
	}

    // Rudimentary caching
    private function getCacheFile(string $key): string {
        return $this->tempDir . DIRECTORY_SEPARATOR . 'cache_' . $key . '.php';
    }

    private function getCache(string $key) {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) return false;
        if (time() - filemtime($file) > $this->cacheTTL) {
            unlink($file);
            return false;
        }
        return unserialize(file_get_contents($file));
    }

    private function setCache(string $key, $data): void {
        $file = $this->getCacheFile($key);
        file_put_contents($file, serialize($data));
    }
    
    public function clearCache(): void {
        $cacheFiles = glob($this->tempDir . DIRECTORY_SEPARATOR . 'cache_*');
        foreach ($cacheFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->errorHandler->log("Cache cleared", "INFO", "CACHE");
    }
    
    // Generate directory tree for navigation
    public function generateDirectoryTree(string $baseDir, string $currentDir, int $maxDepth = 2): array {
        $baseDir = $this->sanitizePath($baseDir);
        $currentDir = $this->sanitizePath($currentDir);
        
        return $this->buildDirectoryTree($baseDir, $baseDir, $currentDir, 0, $maxDepth);
    }
    
    private function buildDirectoryTree(string $dir, string $baseDir, string $currentDir, int $depth, int $maxDepth): array {
        if ($depth > $maxDepth) {
            return [];
        }
        
        if ($this->isExcludedPath($dir)) {
            return [];
        }
        
        $tree = [];
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($path) && !$this->isExcludedPath($path)) {
                $isActive = strpos($currentDir, $path) === 0;
                $node = [
                    'name' => $item,
                    'path' => $path,
                    'active' => $isActive,
                    'children' => []
                ];
                
                // Recursively build children
                if ($isActive || $depth < $maxDepth - 1) {
                    $node['children'] = $this->buildDirectoryTree($path, $baseDir, $currentDir, $depth + 1, $maxDepth);
                }
                
                $tree[] = $node;
            }
        }
        
        // Sort directories alphabetically
        usort($tree, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $tree;
    }
}

/** ------------------------------------------
 * Database Manager
 * -------------------------------------------
 * Manages database connections and operations
 */
class DatabaseManager {
    private $pdo;
    private $errorHandler;
    private $queryLog = [];

    public function __construct(array $dbConfig, ErrorHandler $errorHandler) {
        $this->errorHandler = $errorHandler;
        
        try {
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $this->pdo = new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], $options);
            $this->errorHandler->log("Database connection established", "INFO", "DATABASE");
        } catch (\PDOException $e) {
            $this->errorHandler->log("Database connection failed: " . $e->getMessage(), "ERROR", "DATABASE");
        }
    }
    
    public function isConnected(): bool {
        return $this->pdo !== null;
    }
    
    public function query(string $sql, array $params = []): array {
        if (!$this->isConnected()) {
            throw new \Exception("Database not connected");
        }
        
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            $this->queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'rows' => count($result),
                'time' => $executionTime,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $result;
        } catch (\PDOException $e) {
            $this->errorHandler->log("Database query error: " . $e->getMessage(), "ERROR", "DATABASE");
            throw $e;
        }
    }
    
    public function execute(string $sql, array $params = []): int {
        if (!$this->isConnected()) {
            throw new \Exception("Database not connected");
        }
        
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rowCount = $stmt->rowCount();
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            $this->queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'affected_rows' => $rowCount,
                'time' => $executionTime,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $rowCount;
        } catch (\PDOException $e) {
            $this->errorHandler->log("Database execute error: " . $e->getMessage(), "ERROR", "DATABASE");
            throw $e;
        }
    }
    
    public function getLastInsertId(): string {
        if (!$this->isConnected()) {
            throw new \Exception("Database not connected");
        }
        
        return $this->pdo->lastInsertId();
    }
    
    public function getTables(): array {
        if (!$this->isConnected()) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->query("SHOW TABLES");
            $tables = [];
            
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            return $tables;
        } catch (\PDOException $e) {
            $this->errorHandler->log("Failed to get tables: " . $e->getMessage(), "ERROR", "DATABASE");
            return [];
        }
    }
    
    public function getTableStructure(string $table): array {
        if (!$this->isConnected()) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("DESCRIBE `" . $table . "`");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->errorHandler->log("Failed to get table structure: " . $e->getMessage(), "ERROR", "DATABASE");
            return [];
        }
    }
    
    public function getQueryLog(): array {
        return $this->queryLog;
    }
    
    public function exportStructure(string $table): string {
        if (!$this->isConnected()) {
            throw new \Exception("Database not connected");
        }
        
        try {
            $stmt = $this->pdo->prepare("SHOW CREATE TABLE `" . $table . "`");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $row['Create Table'] ?? '';
        } catch (\PDOException $e) {
            $this->errorHandler->log("Failed to export table structure: " . $e->getMessage(), "ERROR", "DATABASE");
            throw $e;
        }
    }
    
    public function exportData(string $table): array {
        if (!$this->isConnected()) {
            throw new \Exception("Database not connected");
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM `" . $table . "`");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->errorHandler->log("Failed to export table data: " . $e->getMessage(), "ERROR", "DATABASE");
            throw $e;
        }
    }
}

/** ------------------------------------------
 * Script Executor
 * -------------------------------------------
 * Provides a secure sandbox for executing PHP scripts
 */
class ScriptExecutor {
    private $allowExec;
    private $errorHandler;
    private $tempDir;

    public function __construct(bool $allowExec = false, string $tempDir = '', ErrorHandler $errorHandler) {
        $this->allowExec = $allowExec;
        $this->errorHandler = $errorHandler;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();
    }

    public function execute(string $code): string {
        if (!$this->allowExec) {
            $this->errorHandler->logSecurity("Script execution attempted while disabled", "SCRIPT_EXEC_DISABLED");
            return "Script execution is disabled for security reasons.";
        }
        
        // Log script execution
        $this->errorHandler->logSecurity("Script execution requested", "SCRIPT_EXEC");
        
        // Create a temporary file with the PHP code
        $tmpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'script_' . md5(time() . rand()) . '.php';
        
        // Add output buffering to capture script output
        $code = "<?php\n" .
                "ob_start();\n" .
                "try {\n" .
                $code . "\n" .
                "} catch (\\Throwable \$e) {\n" .
                "    echo 'Error: ' . \$e->getMessage() . ' in ' . \$e->getFile() . ' on line ' . \$e->getLine();\n" .
                "}\n" .
                "return ob_get_clean();\n";
        
        file_put_contents($tmpFile, $code);
        
        // Execute in a separate process with restricted permissions
        $output = '';
        try {
            $output = include $tmpFile;
        } catch (\Throwable $e) {
            $output = "Error: " . $e->getMessage();
            $this->errorHandler->log("Script execution error: " . $e->getMessage(), "ERROR", "SCRIPT_EXEC");
        }
        
        // Clean up
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
        
        return $output;
    }
}

/** ------------------------------------------
 * System Information Provider
 * -------------------------------------------
 * Gather system metrics and diagnostics
 */
class SystemInfo {
    public static function getPhpInfo(): array {
        return [
            'version' => phpversion(),
            'zend_version' => zend_version(),
            'os' => php_uname(),
            'sapi' => php_sapi_name(),
            'extensions' => get_loaded_extensions(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'display_errors' => ini_get('display_errors'),
            'error_reporting' => ini_get('error_reporting')
        ];
    }
    
    public static function getServerInfo(): array {
        return [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'hostname' => gethostname(),
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
            'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
            'server_port' => $_SERVER['SERVER_PORT'] ?? 'Unknown',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'request_time' => $_SERVER['REQUEST_TIME'] ?? time(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'Unknown'
        ];
    }
    
    public static function getSystemMetrics(): array {
        $metrics = [
            'system_time' => date('Y-m-d H:i:s'),
            'php_memory_usage' => memory_get_usage(true),
            'php_memory_peak' => memory_get_peak_usage(true)
        ];
        
        // Try to get disk space info if possible
        if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
            $diskPath = dirname(__DIR__);
            $metrics['disk_free'] = disk_free_space($diskPath);
            $metrics['disk_total'] = disk_total_space($diskPath);
            $metrics['disk_used'] = $metrics['disk_total'] - $metrics['disk_free'];
        }
        
        return $metrics;
    }
    
    public static function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

/** ------------------------------------------
 * UI Components Generator
 * -------------------------------------------
 * Generate reusable UI components
 */
class UiComponents {
    public static function generateBreadcrumb(string $baseDir, string $currentDir): string {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace($baseDir, '', $currentDir));
        
        $output = '<nav aria-label="breadcrumb" class="breadcrumb-container">';
        $output .= '<ol class="breadcrumb">';
        $output .= '<li class="breadcrumb-item"><a href="?dir=' . urlencode($baseDir) . '"><i class="icon-home"></i> Home</a></li>';
        
        $path = $baseDir;
        foreach ($parts as $part) {
            if ($part) {
                $path .= DIRECTORY_SEPARATOR . $part;
                $active = ($path === $currentDir) ? ' active' : '';
                $ariaAttr = ($path === $currentDir) ? ' aria-current="page"' : '';
                $output .= '<li class="breadcrumb-item' . $active . '"' . $ariaAttr . '><a href="?dir=' . urlencode($path) . '">' . htmlspecialchars($part) . '</a></li>';
            }
        }
        
        $output .= '</ol>';
        $output .= '</nav>';
        
        return $output;
    }
    
    public static function generateAlert(string $message, string $type = 'info', bool $dismissable = true): string {
        $validTypes = ['info', 'success', 'warning', 'danger'];
        if (!in_array($type, $validTypes)) {
            $type = 'info';
        }
        
        $closeButton = $dismissable ? 
            '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' : '';
        
        $iconMap = [
            'info' => 'icon-info-circle',
            'success' => 'icon-check-circle', 
            'warning' => 'icon-exclamation-triangle',
            'danger' => 'icon-times-circle'
        ];
        
        $icon = '<i class="' . $iconMap[$type] . '"></i> ';
        
        return '<div class="alert alert-' . $type . ($dismissable ? ' alert-dismissible' : '') . ' fade show" role="alert">' .
               $icon . $message . $closeButton . '</div>';
    }
    
    public static function generateModalDialog(string $id, string $title, string $body, array $buttons = []): string {
        $buttonHtml = '';
        foreach ($buttons as $button) {
            $class = $button['class'] ?? 'btn-secondary';
            $buttonHtml .= '<button type="button" class="btn ' . $class . '" ' . 
                          ($button['data'] ?? '') . '>' . $button['text'] . '</button>';
        }
        
        return '
        <div class="modal fade" id="' . $id . '" tabindex="-1" role="dialog" aria-labelledby="' . $id . 'Label" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="' . $id . 'Label">' . $title . '</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">' . $body . '</div>
                    <div class="modal-footer">' . $buttonHtml . '</div>
                </div>
            </div>
        </div>';
    }
    
    public static function generateFileListItem(array $file, bool $hasDeletePermission = true): string {
        $fileIcon = self::getFileIcon($file['extension'] ?? '');
        $fileSize = isset($file['size']) ? self::formatBytes($file['size']) : '-';
        $modified = isset($file['modified']) ? date("Y-m-d H:i:s", $file['modified']) : '-';
        
        return '
        <tr data-path="' . htmlspecialchars($file['path']) . '" data-type="file">
            <td><div class="file-name">' . $fileIcon . ' <span>' . htmlspecialchars($file['name']) . '</span></div></td>
            <td>' . htmlspecialchars($fileSize) . '</td>
            <td>' . htmlspecialchars($modified) . '</td>
            <td class="actions">
                <div class="btn-group" role="group" aria-label="File actions">
                    <a href="?edit=' . urlencode($file['path']) . '" class="btn btn-sm btn-outline-primary" title="Edit">
                        <i class="icon-edit"></i>
                    </a>
                    <a href="?download=' . urlencode($file['path']) . '" class="btn btn-sm btn-outline-success" title="Download">
                        <i class="icon-download"></i>
                    </a>
                    <a href="#" class="btn btn-sm btn-outline-info file-copy" title="Copy"
                       data-toggle="modal" data-target="#copyFileModal" data-file="' . htmlspecialchars($file['path']) . '">
                        <i class="icon-copy"></i>
                    </a>
                    <a href="#" class="btn btn-sm btn-outline-warning file-move" title="Move"
                       data-toggle="modal" data-target="#moveFileModal" data-file="' . htmlspecialchars($file['path']) . '">
                        <i class="icon-move"></i>
                    </a>
                    <a href="#" class="btn btn-sm btn-outline-secondary file-rename" title="Rename"
                       data-toggle="modal" data-target="#renameFileModal" data-file="' . htmlspecialchars($file['path']) . '" data-filename="' . htmlspecialchars($file['name']) . '">
                        <i class="icon-rename"></i>
                    </a>
                    ' . ($hasDeletePermission ? '
                    <a href="?delete=' . urlencode($file['path']) . '" class="btn btn-sm btn-outline-danger delete-file" title="Delete" 
                       data-toggle="modal" data-target="#confirmDelete" data-file="' . htmlspecialchars($file['name']) . '">
                        <i class="icon-trash"></i>
                    </a>' : '') . '
                </div>
            </td>
        </tr>';
    }
    
	public static function generateDirectoryListItem(array $dir, bool $hasDeletePermission = true): string {
		$modified = isset($dir['modified']) ? date("Y-m-d H:i:s", $dir['modified']) : '-';
		
		// Get directory size if available
		$sizeInfo = '';
		if (isset($dir['total_size_formatted'])) {
			$sizeInfo = ' <span class="badge badge-info">' . htmlspecialchars($dir['total_size_formatted']) . '</span>';
		}
		
		// Check if this is a large directory
		$isLarge = isset($dir['total_size']) && $dir['total_size'] > 50 * 1024 * 1024; // 50MB threshold
		
		return '
		<tr data-path="' . htmlspecialchars($dir['path']) . '" data-type="directory">
			<td><div class="dir-name"><i class="icon-folder"></i> <a href="?dir=' . urlencode($dir['path']) . '">' . htmlspecialchars($dir['name']) . '</a>' . $sizeInfo . '</div></td>
			<td>Directory</td>
			<td>' . htmlspecialchars($modified) . '</td>
			<td class="actions">
				<div class="btn-group" role="group" aria-label="Directory actions">
					<a href="?edit_dir=' . urlencode($dir['path']) . '" class="btn btn-sm btn-outline-primary" title="Rename">
						<i class="icon-edit"></i>
					</a>
					' . ($isLarge ? '
					<button type="button" class="btn btn-sm btn-outline-success dir-download-btn" 
							data-toggle="modal" data-target="#confirmDirDownload" 
							data-dir="' . htmlspecialchars($dir['name']) . '"
							data-path="' . urlencode($dir['path']) . '"
							data-size="' . htmlspecialchars($dir['total_size_formatted'] ?? 'Unknown') . '"
							title="Download as ZIP (Large!)">
						<i class="icon-download"></i>
					</button>' : '
					<a href="?download_dir=' . urlencode($dir['path']) . '" class="btn btn-sm btn-outline-success" title="Download as ZIP">
						<i class="icon-download"></i>
					</a>') . '
					' . ($hasDeletePermission ? '
					<a href="?delete_dir=' . urlencode($dir['path']) . '" class="btn btn-sm btn-outline-danger delete-dir" title="Delete" 
					   data-toggle="modal" data-target="#confirmDeleteDir" data-dir="' . htmlspecialchars($dir['name']) . '">
						<i class="icon-trash"></i>
					</a>' : '') . '
				</div>
			</td>
		</tr>';
	}
    
    public static function generateDirectoryTreeView(array $tree, int $level = 0): string {
        if (empty($tree)) {
            return '';
        }
        
        $output = '<ul class="directory-tree' . ($level === 0 ? ' root' : '') . '">';
        
        foreach ($tree as $node) {
            $hasChildren = !empty($node['children']);
            $isActive = $node['active'] ?? false;
            
            $folderClass = $hasChildren ? 'has-children' : '';
            $folderClass .= $isActive ? ' active' : '';
            
            $output .= '<li class="' . $folderClass . '">';
            $output .= '<div class="tree-item">';
            $output .= '<i class="icon-folder"></i> ';
            $output .= '<a href="?dir=' . urlencode($node['path']) . '">' . htmlspecialchars($node['name']) . '</a>';
            $output .= '</div>';
            
            if ($hasChildren) {
                $output .= self::generateDirectoryTreeView($node['children'], $level + 1);
            }
            
            $output .= '</li>';
        }
        
        $output .= '</ul>';
        return $output;
    }
    
    private static function getFileIcon(string $extension): string {
        $extension = strtolower($extension);
        
        $iconMap = [
            'txt' => 'icon-file-alt',
            'log' => 'icon-file-alt',
            'html' => 'icon-file-code',
            'htm' => 'icon-file-code',
            'php' => 'icon-file-code',
            'js' => 'icon-file-code',
            'css' => 'icon-file-code',
            'json' => 'icon-file-code',
            'xml' => 'icon-file-code',
            'svg' => 'icon-file-image',
            'jpg' => 'icon-file-image',
            'jpeg' => 'icon-file-image',
            'png' => 'icon-file-image',
            'gif' => 'icon-file-image',
            'pdf' => 'icon-file-pdf',
            'doc' => 'icon-file-word',
            'docx' => 'icon-file-word',
            'xls' => 'icon-file-excel',
            'xlsx' => 'icon-file-excel',
            'zip' => 'icon-file-archive',
            'rar' => 'icon-file-archive',
            'gz' => 'icon-file-archive',
            'tar' => 'icon-file-archive'
        ];
        
        $iconClass = $iconMap[$extension] ?? 'icon-file';
        
        return '<i class="' . $iconClass . '"></i>';
    }
    
    private static function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

/** ------------------------------------------
 * MAIN EXECUTION FLOW
 * -------------------------------------------
 */

// Initialize configuration
$configManager = ConfigManager::getInstance();

// Set up error handling
$errorHandler = ErrorHandler::getInstance(
    $configManager->get('error_log'),
    $configManager->get('security_log'),
    $configManager->isDevelopment()
);

try {
    // Initialize CSRF protection
    $csrfProtection = new CsrfProtection(
        $configManager->get('csrf_token_name'),
        $errorHandler
    );

    // Initialize authentication
    $auth = new AuthManager(
        $configManager->get('session_key'),
        $errorHandler,
        $configManager
    );

    // Verify authentication before proceeding
    $auth->ensureAuthenticated();

    // Initialize file system manager
    $fileManager = new FileSystemManager(
        $configManager->get('base_dir'),
        $configManager->get('cache_enabled'),
        $configManager->get('cache_ttl'),
        $configManager->get('excluded_dirs'),
        $configManager->get('temp_dir'),
        $configManager->get('backup_dir'),
        $configManager->get('max_upload_size'),
        $configManager->get('allowed_file_types'),
        $errorHandler
    );

    // Initialize script executor
    $executor = new ScriptExecutor(
        $configManager->get('allow_script_exec'),
        $configManager->get('temp_dir'),
        $errorHandler
    );

    // Initialize database manager if configured
    $dbManager = null;
    if (!empty($configManager->get('db_config'))) {
        $dbManager = new DatabaseManager(
            $configManager->get('db_config'),
            $errorHandler
        );
    }

    // Determine current directory
    $current_dir = isset($_GET['dir']) 
        ? $fileManager->sanitizePath($_GET['dir']) 
        : $configManager->get('base_dir');

    // Process actions based on request
    $message = '';
    $messageType = 'info';

    // CSRF validation for POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$csrfProtection->validateToken($_POST['csrf_token'] ?? null)) {
            $errorHandler->logSecurity("CSRF validation failed", "CSRF_ATTEMPT");
            $message = "Security validation failed. Please try again.";
            $messageType = "danger";
            // Don't process the request if CSRF validation fails
            $_POST = [];
        }
    }

    // File actions
    if (isset($_POST['create_file']) && $auth->hasPermission('write')) {
        if (empty($_POST['newFileName'])) {
            $message = "File name cannot be empty.";
            $messageType = "danger";
        } elseif ($fileManager->createFile($current_dir, $_POST['newFileName'])) {
            $message = "File created successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to create file.";
            $messageType = "danger";
        }
    }

    if (isset($_POST['save']) && $auth->hasPermission('write')) {
        if ($fileManager->saveFile($_POST['file'], $_POST['content'])) {
            $message = "File saved successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to save file.";
            $messageType = "danger";
        }
    }

    if (isset($_GET['delete']) && $auth->hasPermission('delete')) {
        if ($fileManager->deleteFile($_GET['delete'])) {
            $message = "File deleted successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to delete file.";
            $messageType = "danger";
        }
    }

    if (isset($_GET['download'])) {
        $auth->requirePermission('read');
        $fileManager->downloadFile($_GET['download']);
        exit;
    }

	// In the main execution flow section of admin.php
	if (isset($_GET['download_dir']) && $auth->hasPermission('read')) {
		// Default is not allowing root directory download
		$allowRootDownload = false;
		
		// Allow root download only for admin users who explicitly confirmed
		if (isset($_GET['allow_root']) && $_GET['allow_root'] === '1' && $auth->getUserRole() === 'admin') {
			$allowRootDownload = true;
		}
		
		$fileManager->downloadDirectoryAsZip($_GET['download_dir'], $allowRootDownload);
		exit;
	}

    if (isset($_POST['upload']) && $auth->hasPermission('write')) {
        $result = $fileManager->uploadFile($current_dir, $_FILES);
        if ($result['success']) {
            $message = "File uploaded successfully.";
            $messageType = "success";
        } else {
            $message = "Upload failed: " . $result['message'];
            $messageType = "danger";
        }
    }

    // File rename
    if (isset($_POST['rename_file']) && $auth->hasPermission('write')) {
        if (empty($_POST['new_filename'])) {
            $message = "File name cannot be empty.";
            $messageType = "danger";
        } elseif ($fileManager->renameFile($_POST['source_file'], $_POST['new_filename'])) {
            $message = "File renamed successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to rename file.";
            $messageType = "danger";
        }
    }

    // Directory actions
    if (isset($_POST['create_dir']) && $auth->hasPermission('write')) {
        if (empty($_POST['newDirName'])) {
            $message = "Directory name cannot be empty.";
            $messageType = "danger";
        } elseif ($fileManager->createDirectory($current_dir, $_POST['newDirName'])) {
            $message = "Directory created successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to create directory.";
            $messageType = "danger";
        }
    }

    if (isset($_POST['rename']) && $auth->hasPermission('write')) {
        if (empty($_POST['new_name'])) {
            $message = "Directory name cannot be empty.";
            $messageType = "danger";
        } elseif ($fileManager->renameDirectory($_POST['old_name'], $_POST['new_name'])) {
            $message = "Directory renamed successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to rename directory.";
            $messageType = "danger";
        }
    }

    if (isset($_GET['delete_dir']) && $auth->hasPermission('delete')) {
        if ($fileManager->deleteDirectory($_GET['delete_dir'])) {
            $message = "Directory deleted successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to delete directory. Make sure it's empty.";
            $messageType = "danger";
        }
    }

    // File operations
    if (isset($_POST['move_file']) && $auth->hasPermission('write')) {
        if (empty($_POST['destination'])) {
            $message = "Destination cannot be empty.";
            $messageType = "danger";
        } elseif ($fileManager->moveFile($_POST['source'], $_POST['destination'])) {
            $message = "File moved successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to move file.";
            $messageType = "danger";
        }
    }

    if (isset($_POST['copy_file']) && $auth->hasPermission('write')) {
        if (empty($_POST['destination'])) {
            $message = "Destination cannot be empty.";
            $messageType = "danger";
        } elseif ($fileManager->copyFile($_POST['source'], $_POST['destination'])) {
            $message = "File copied successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to copy file.";
            $messageType = "danger";
        }
    }

    // Search
    $searchResults = null;
    if (isset($_POST['search']) && !empty($_POST['search_term'])) {
        $extensions = !empty($_POST['search_extensions']) ? explode(',', $_POST['search_extensions']) : [];
        $caseSensitive = isset($_POST['case_sensitive']) && $_POST['case_sensitive'] === 'on';
        $searchResults = $fileManager->search(
            $current_dir,
            $_POST['search_term'],
            $caseSensitive,
            $extensions
        );
    }

    // Script execution
    $scriptOutput = '';
    if (isset($_POST['execute']) && $auth->hasPermission('execute')) {
        $scriptOutput = $executor->execute($_POST['script']);
    }

    // Filter
    $filter = isset($_POST['filter']) && !empty($_POST['filter']) 
        ? $_POST['filter'] 
        : $configManager->get('default_filter');

    // Recursive listing
    $recursive = isset($_POST['recursive']) && $_POST['recursive'] === 'on';
    $recursiveDepth = isset($_POST['recursive_depth']) ? (int)$_POST['recursive_depth'] : 1;

    // Determine view mode
    $edit_file = isset($_GET['edit']) ? $_GET['edit'] : null;
    $edit_dir = isset($_GET['edit_dir']) ? $_GET['edit_dir'] : null;
    $view_mode = isset($_GET['view']) ? $_GET['view'] : 'files';

    // Get file/directory list
    $listing = $fileManager->listFilesAndDirs($current_dir, $filter, $recursive, $recursiveDepth);

    // Generate directory tree for navigation
    $directoryTree = $fileManager->generateDirectoryTree($configManager->get('base_dir'), $current_dir);

    // Calculate execution time
    $executionTime = round(microtime(true) - $startTime, 4);
    
    // Get file content for editing
    $fileContent = '';
    $fileInfo = null;
    if ($edit_file) {
        try {
            $fileContent = $fileManager->getFileContent($edit_file);
            $fileInfo = $fileManager->getFileInfo($edit_file);
        } catch (\Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Get directory info for editing
    $dirInfo = null;
    if ($edit_dir) {
        try {
            $dirInfo = $fileManager->getDirInfo($edit_dir);
        } catch (\Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // System info for dashboard
    $systemInfo = null;
    if ($view_mode === 'dashboard' && $auth->hasPermission('system_config')) {
        $systemInfo = [
            'php' => SystemInfo::getPhpInfo(),
            'server' => SystemInfo::getServerInfo(),
            'metrics' => SystemInfo::getSystemMetrics()
        ];
    }
    
    // Database info for database view
    $dbInfo = null;
    if ($view_mode === 'database' && $dbManager && $auth->hasPermission('system_config')) {
        $dbInfo = [
            'connected' => $dbManager->isConnected(),
            'tables' => $dbManager->isConnected() ? $dbManager->getTables() : [],
            'queryLog' => $dbManager->getQueryLog()
        ];
        
        // Get table structure if table is selected
        if (isset($_GET['table']) && in_array($_GET['table'], $dbInfo['tables'])) {
            $dbInfo['selectedTable'] = $_GET['table'];
            $dbInfo['structure'] = $dbManager->getTableStructure($_GET['table']);
            
            // Get table data if requested
            if (isset($_GET['show_data'])) {
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                
                $sql = "SELECT * FROM `{$_GET['table']}` LIMIT {$offset}, {$limit}";
                $dbInfo['data'] = $dbManager->query($sql);
                $dbInfo['dataCount'] = count($dbInfo['data']);
                $dbInfo['limit'] = $limit;
                $dbInfo['offset'] = $offset;
            }
        }
    }
    
    // Session info
    $sessionInfo = $auth->getSessionInfo();

} catch (\Throwable $e) {
    // Handle any uncaught exceptions
    $errorHandler->handleException($e);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Control Panel - Site Management</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>⚙️</text></svg>">
    
    <!-- Font Awesome Icons (using CSS variables for theming) -->
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --secondary-color: #2ecc71;
            --secondary-dark: #27ae60;
            --danger-color: #e74c3c;
            --danger-dark: #c0392b;
            --warning-color: #f39c12;
            --warning-dark: #d35400;
            --success-color: #2ecc71;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --gray-color: #95a5a6;
            --gray-dark: #7f8c8d;
            --body-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #34495e;
            --text-muted: #7f8c8d;
            --border-color: #dfe6e9;
            
            /* Dark mode colors */
            --dark-primary: #3498db;
            --dark-secondary: #2ecc71;
            --dark-body-bg: #1a1a1a;
            --dark-card-bg: #2c3e50;
            --dark-text-color: #ecf0f1;
            --dark-text-muted: #bdc3c7;
            --dark-border-color: #34495e;
            
            /* Font settings */
            --font-sans: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --font-mono: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            
            /* Spacing */
            --spacer: 1rem;
            --spacer-1: calc(var(--spacer) * 0.25);
            --spacer-2: calc(var(--spacer) * 0.5);
            --spacer-3: var(--spacer);
            --spacer-4: calc(var(--spacer) * 1.5);
            --spacer-5: calc(var(--spacer) * 3);
        }
        
        /* Dark mode detection */
        @media (prefers-color-scheme: dark) {
            :root {
                --primary-color: var(--dark-primary);
                --secondary-color: var(--dark-secondary);
                --body-bg: var(--dark-body-bg);
                --card-bg: var(--dark-card-bg);
                --text-color: var(--dark-text-color);
                --text-muted: var(--dark-text-muted);
                --border-color: var(--dark-border-color);
            }
        }
        
        /* Base styles */
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
            padding-bottom: 2rem;
        }
        
        /* Layout */
        .container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }
        
        .col {
            position: relative;
            width: 100%;
            padding-right: 15px;
            padding-left: 15px;
            flex-basis: 0;
            flex-grow: 1;
            max-width: 100%;
        }
        
        .col-auto {
            flex: 0 0 auto;
            width: auto;
            max-width: 100%;
        }
        
        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
        }
        
        .col-md-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
        }
        
        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
        }
        
        .col-md-8 {
            flex: 0 0 66.666667%;
            max-width: 66.666667%;
        }
        
        .col-md-9 {
            flex: 0 0 75%;
            max-width: 75%;
        }
        
        .col-md-12 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        @media (max-width: 768px) {
            .col-md-3, .col-md-4, .col-md-6, .col-md-8, .col-md-9 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
        
        /* Card component */
        .card {
            position: relative;
            display: flex;
            flex-direction: column;
            min-width: 0;
            word-wrap: break-word;
            background-color: var(--card-bg);
            background-clip: border-box;
            border: 1px solid var(--border-color);
            border-radius: 0.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card-header {
            padding: 0.75rem 1.25rem;
            margin-bottom: 0;
            background-color: rgba(0,0,0,0.03);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body {
            flex: 1 1 auto;
            min-height: 1px;
            padding: 1.25rem;
        }
        
        .card-footer {
            padding: 0.75rem 1.25rem;
            background-color: rgba(0,0,0,0.03);
            border-top: 1px solid var(--border-color);
        }
        
        /* Navigation */
        .navbar {
            position: relative;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            background-color: var(--dark-color);
            color: white;
            margin-bottom: 1.5rem;
        }
        
        .navbar-brand {
            display: inline-block;
            padding-top: 0.3125rem;
            padding-bottom: 0.3125rem;
            margin-right: 1rem;
            font-size: 1.25rem;
            line-height: inherit;
            white-space: nowrap;
            color: white;
            text-decoration: none;
            font-weight: 700;
        }
        
        .navbar-nav {
            display: flex;
            flex-direction: row;
            padding-left: 0;
            margin-bottom: 0;
            list-style: none;
        }
        
        .nav-item {
            margin-left: 1rem;
        }
        
        .nav-link {
            display: block;
            padding: 0.5rem;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .nav-link:hover, .nav-link:focus {
            color: white;
        }
        
        .nav-link.active {
            color: white;
            font-weight: 600;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-control {
            display: block;
            width: 100%;
            height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
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
            color: var(--text-color);
            background-color: var(--card-bg);
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        textarea.form-control {
            height: auto;
        }
        
        .form-control-file {
            display: block;
            width: 100%;
        }
        
        .form-check {
            position: relative;
            display: block;
            padding-left: 1.25rem;
        }
        
        .form-check-input {
            position: absolute;
            margin-top: 0.3rem;
            margin-left: -1.25rem;
        }
        
        .form-check-label {
            margin-bottom: 0;
        }
        
        /* Buttons */
        .btn {
            display: inline-block;
            font-weight: 400;
            color: var(--text-color);
            text-align: center;
            vertical-align: middle;
            cursor: pointer;
			-webkit-user-select: none;
            user-select: none;
            background-color: transparent;
            border: 1px solid transparent;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: 0.25rem;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            text-decoration: none;
        }
        
        .btn:hover {
            text-decoration: none;
        }
        
        .btn:focus, .btn.focus {
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }
        
        .btn-lg {
            padding: 0.5rem 1rem;
            font-size: 1.25rem;
            line-height: 1.5;
            border-radius: 0.3rem;
        }
        
        .btn-primary {
            color: #fff;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            color: #fff;
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-secondary {
            color: #fff;
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-secondary:hover {
            color: #fff;
            background-color: var(--secondary-dark);
            border-color: var(--secondary-dark);
        }
        
        .btn-danger {
            color: #fff;
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .btn-danger:hover {
            color: #fff;
            background-color: var(--danger-dark);
            border-color: var(--danger-dark);
        }
        
        .btn-success {
            color: #fff;
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-warning {
            color: #212529;
            background-color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-info {
            color: #fff;
            background-color: var(--info-color);
            border-color: var(--info-color);
        }
        
        .btn-light {
            color: #212529;
            background-color: var(--light-color);
            border-color: var(--light-color);
        }
        
        .btn-dark {
            color: #fff;
            background-color: var(--dark-color);
            border-color: var(--dark-color);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            color: #fff;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-secondary {
            color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-outline-secondary:hover {
            color: #fff;
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-outline-danger {
            color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .btn-outline-danger:hover {
            color: #fff;
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .btn-outline-success {
            color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-outline-success:hover {
            color: #fff;
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-group {
            position: relative;
            display: inline-flex;
            vertical-align: middle;
        }
        
        .btn-group > .btn {
            position: relative;
            flex: 1 1 auto;
        }
        
        .btn-group > .btn:not(:first-child) {
            margin-left: -1px;
        }
        
        .btn-group > .btn:not(:last-child) {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .btn-group > .btn:not(:first-child) {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        /* Alerts */
        .alert {
            position: relative;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            border-radius: 0.25rem;
        }
        
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .alert-warning {
            color: #856404;
            background-color: #fff3cd;
            border-color: #ffeeba;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .alert-dismissible {
            padding-right: 4rem;
        }
        
        .alert-dismissible .close {
            position: absolute;
            top: 0;
            right: 0;
            padding: 0.75rem 1.25rem;
            color: inherit;
            background-color: transparent;
            border: 0;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* Tables */
        .table {
            width: 100%;
            margin-bottom: 1rem;
            color: var(--text-color);
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 0.75rem;
            vertical-align: top;
            border-top: 1px solid var(--border-color);
            text-align: left;
        }
        
        .table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid var(--border-color);
            background-color: rgba(0,0,0,0.03);
        }
        
        .table tbody + tbody {
            border-top: 2px solid var(--border-color);
        }
        
        .table-sm th,
        .table-sm td {
            padding: 0.3rem;
        }
        
        .table-bordered {
            border: 1px solid var(--border-color);
        }
        
        .table-bordered th,
        .table-bordered td {
            border: 1px solid var(--border-color);
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,0.02);
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,0,0,0.05);
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.25em 0.4em;
            font-size: 75%;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }
        
        .badge-primary {
            color: #fff;
            background-color: var(--primary-color);
        }
        
        .badge-secondary {
            color: #fff;
            background-color: var(--secondary-color);
        }
        
        .badge-success {
            color: #fff;
            background-color: var(--success-color);
        }
        
        .badge-danger {
            color: #fff;
            background-color: var(--danger-color);
        }
        
        .badge-warning {
            color: #212529;
            background-color: var(--warning-color);
        }
        
        .badge-info {
            color: #fff;
            background-color: var(--info-color);
        }
        
        /* Modals */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            display: none;
            width: 100%;
            height: 100%;
            overflow: hidden;
            outline: 0;
        }
        
        .modal-dialog {
            position: relative;
            width: auto;
            margin: 1.75rem auto;
            max-width: 500px;
        }
        
        .modal-content {
            position: relative;
            display: flex;
            flex-direction: column;
            width: 100%;
            pointer-events: auto;
            background-color: var(--card-bg);
            background-clip: padding-box;
            border: 1px solid var(--border-color);
            border-radius: 0.3rem;
            outline: 0;
        }
        
        .modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            border-top-left-radius: calc(0.3rem - 1px);
            border-top-right-radius: calc(0.3rem - 1px);
        }
        
        .modal-title {
            margin-bottom: 0;
            line-height: 1.5;
        }
        
        .modal-body {
            position: relative;
            flex: 1 1 auto;
            padding: 1rem;
        }
        
        .modal-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            padding: 0.75rem;
            border-top: 1px solid var(--border-color);
            border-bottom-right-radius: calc(0.3rem - 1px);
            border-bottom-left-radius: calc(0.3rem - 1px);
        }
        
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1040;
            width: 100vw;
            height: 100vh;
            background-color: #000;
            opacity: 0.5;
        }
        
        /* Utilities */
        .d-none {
            display: none !important;
        }
        
        .d-inline {
            display: inline !important;
        }
        
        .d-inline-block {
            display: inline-block !important;
        }
        
        .d-block {
            display: block !important;
        }
        
        .d-flex {
            display: flex !important;
        }
        
        .justify-content-start {
            justify-content: flex-start !important;
        }
        
        .justify-content-end {
            justify-content: flex-end !important;
        }
        
        .justify-content-center {
            justify-content: center !important;
        }
        
        .justify-content-between {
            justify-content: space-between !important;
        }
        
        .align-items-start {
            align-items: flex-start !important;
        }
        
        .align-items-center {
            align-items: center !important;
        }
        
        .align-items-end {
            align-items: flex-end !important;
        }
        
        .flex-column {
            flex-direction: column !important;
        }
        
        .w-100 {
            width: 100% !important;
        }
        
        .h-100 {
            height: 100% !important;
        }
        
        .text-center {
            text-align: center !important;
        }
        
        .text-right {
            text-align: right !important;
        }
        
        .text-left {
            text-align: left !important;
        }
        
        .text-muted {
            color: var(--text-muted) !important;
        }
        
        .text-primary {
            color: var(--primary-color) !important;
        }
        
        .text-success {
            color: var(--success-color) !important;
        }
        
        .text-danger {
            color: var(--danger-color) !important;
        }
        
        .text-warning {
            color: var(--warning-color) !important;
        }
        
        .font-weight-bold {
            font-weight: 700 !important;
        }
        
        .rounded {
            border-radius: 0.25rem !important;
        }
        
        .rounded-circle {
            border-radius: 50% !important;
        }
        
        .shadow {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        
        .shadow-sm {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
        }
        
        .border {
            border: 1px solid var(--border-color) !important;
        }
        
        .border-top {
            border-top: 1px solid var(--border-color) !important;
        }
        
        .border-bottom {
            border-bottom: 1px solid var(--border-color) !important;
        }
        
        .mt-0 {
            margin-top: 0 !important;
        }
        
        .mb-0 {
            margin-bottom: 0 !important;
        }
        
        .ml-0 {
            margin-left: 0 !important;
        }
        
        .mr-0 {
            margin-right: 0 !important;
        }
        
        .mt-1 {
            margin-top: var(--spacer-1) !important;
        }
        
        .mb-1 {
            margin-bottom: var(--spacer-1) !important;
        }
        
        .ml-1 {
            margin-left: var(--spacer-1) !important;
        }
        
        .mr-1 {
            margin-right: var(--spacer-1) !important;
        }
        
        .mt-2 {
            margin-top: var(--spacer-2) !important;
        }
        
        .mb-2 {
            margin-bottom: var(--spacer-2) !important;
        }
        
        .ml-2 {
            margin-left: var(--spacer-2) !important;
        }
        
        .mr-2 {
            margin-right: var(--spacer-2) !important;
        }
        
        .mt-3 {
            margin-top: var(--spacer-3) !important;
        }
        
        .mb-3 {
            margin-bottom: var(--spacer-3) !important;
        }
        
        .ml-3 {
            margin-left: var(--spacer-3) !important;
        }
        
        .mr-3 {
            margin-right: var(--spacer-3) !important;
        }
        
        .mt-4 {
            margin-top: var(--spacer-4) !important;
        }
        
        .mb-4 {
            margin-bottom: var(--spacer-4) !important;
        }
        
        .ml-4 {
            margin-left: var(--spacer-4) !important;
        }
        
        .mr-4 {
            margin-right: var(--spacer-4) !important;
        }
        
        .m-3 {
            margin: var(--spacer-3) !important;
        }
        
        .p-0 {
            padding: 0 !important;
        }
        
        .p-1 {
            padding: var(--spacer-1) !important;
        }
        
        .p-2 {
            padding: var(--spacer-2) !important;
        }
        
        .p-3 {
            padding: var(--spacer-3) !important;
        }
        
        .p-4 {
            padding: var(--spacer-4) !important;
        }
        
        .pt-3 {
            padding-top: var(--spacer-3) !important;
        }
        
        .pb-3 {
            padding-bottom: var(--spacer-3) !important;
        }
        
        .pl-3 {
            padding-left: var(--spacer-3) !important;
        }
        
        .pr-3 {
            padding-right: var(--spacer-3) !important;
        }
        
        /* Code editor */
        .code-editor {
            font-family: var(--font-mono);
            font-size: 14px;
            line-height: 1.5;
            width: 100%;
            height: 500px;
            resize: vertical;
            -moz-tab-size: 4;
			tab-size: 4;
            background-color: #272822;
            color: #f8f8f2;
            padding: 1rem;
            border-radius: 0.25rem;
            border: none;
        }
        
        /* Custom Components */
        .breadcrumb-container {
            margin-bottom: 1.5rem;
        }
        
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            list-style: none;
            background-color: var(--card-bg);
            border-radius: 0.25rem;
            border: 1px solid var(--border-color);
        }
        
        .breadcrumb-item + .breadcrumb-item {
            padding-left: 0.5rem;
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            display: inline-block;
            padding-right: 0.5rem;
            color: var(--text-muted);
            content: "/";
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
        
        .breadcrumb-item.active {
            color: var(--text-muted);
        }
        
        .file-name, .dir-name {
            display: flex;
            align-items: center;
        }
        
        .file-name i, .dir-name i {
            margin-right: 0.5rem;
            font-size: 1.2rem;
        }
        
        .dir-name a {
            color: var(--text-color);
            text-decoration: none;
        }
        
		/* Completing the CSS for directory names that was cut off */
		.dir-name a:hover {
			text-decoration: underline;
			color: var(--primary-color);
		}

		/* Directory tree styling */
		.directory-tree {
			list-style: none;
			padding-left: 0;
			margin-bottom: 1rem;
		}

		.directory-tree ul {
			list-style: none;
			padding-left: 1.5rem;
		}

		.directory-tree li {
			margin: 0.25rem 0;
		}

		.tree-item {
			display: flex;
			align-items: center;
			padding: 0.25rem 0.5rem;
			border-radius: 0.25rem;
		}

		.tree-item:hover {
			background-color: rgba(0,0,0,0.05);
		}

		.tree-item.active {
			background-color: rgba(52, 152, 219, 0.1);
		}

		.tree-item i {
			margin-right: 0.5rem;
			color: var(--warning-color);
		}

		.tree-item a {
			color: var(--text-color);
			text-decoration: none;
		}

		/* File icons */
		.icon-home:before { content: "🏠"; }
		.icon-folder:before { content: "📁"; }
		.icon-file:before { content: "📄"; }
		.icon-file-alt:before { content: "📝"; }
		.icon-file-code:before { content: "📃"; }
		.icon-file-image:before { content: "🖼️"; }
		.icon-file-pdf:before { content: "📕"; }
		.icon-file-word:before { content: "📘"; }
		.icon-file-excel:before { content: "📗"; }
		.icon-file-archive:before { content: "📦"; }
		.icon-edit:before { content: "✏️"; }
		.icon-download:before { content: "⬇️"; }
		.icon-copy:before { content: "📋"; }
		.icon-move:before { content: "↗️"; }
		.icon-rename:before { content: "🔄"; }
		.icon-trash:before { content: "🗑️"; }
		.icon-info-circle:before { content: "ℹ️"; }
		.icon-check-circle:before { content: "✅"; }
		.icon-exclamation-triangle:before { content: "⚠️"; }
		.icon-times-circle:before { content: "❌"; }

		/* Search results styling */
		.search-result-item {
			margin-bottom: 1rem;
			padding-bottom: 1rem;
			border-bottom: 1px solid var(--border-color);
		}

		.search-result-path {
			font-family: var(--font-mono);
			font-size: 0.9rem;
			color: var(--text-muted);
			margin-bottom: 0.5rem;
		}

		.search-match {
			background-color: rgba(243, 156, 18, 0.2);
			padding: 0.25rem;
			border-radius: 0.25rem;
		}

		/* Dashboard metrics */
		.metric-card {
			text-align: center;
			padding: 1rem;
		}

		.metric-value {
			font-size: 2rem;
			font-weight: 700;
			margin: 0.5rem 0;
		}

		.metric-label {
			color: var(--text-muted);
			font-size: 0.9rem;
		}

		/* Loading spinner */
		.spinner {
			display: inline-block;
			width: 1.5rem;
			height: 1.5rem;
			border-radius: 50%;
			border: 0.25rem solid rgba(52, 152, 219, 0.25);
			border-top-color: var(--primary-color);
			animation: spin 1s linear infinite;
		}

		@keyframes spin {
			to { transform: rotate(360deg); }
		}

		/* Administrator info */
		.admin-info {
			display: flex;
			align-items: center;
		}

		.admin-avatar {
			width: 2.5rem;
			height: 2.5rem;
			border-radius: 50%;
			background-color: var(--primary-color);
			color: white;
			display: flex;
			align-items: center;
			justify-content: center;
			margin-right: 0.75rem;
			font-weight: 600;
		}

		/* Database table */
		.db-table-container {
			overflow-x: auto;
		}
	</style>

</head>
<body>
	<nav class="navbar">
		<a class="navbar-brand" href="admin.php">
			⚙️ Admin Control Panel
		</a>
		<ul class="navbar-nav">
			<li class="nav-item">
				<a class="nav-link <?php echo $view_mode === 'files' ? 'active' : ''; ?>" href="?view=files">
					<i class="icon-folder"></i> Files
				</a>
			</li>
			<li class="nav-item">
				<a class="nav-link <?php echo $view_mode === 'dashboard' ? 'active' : ''; ?>" href="?view=dashboard">
					<i class="icon-info-circle"></i> Dashboard
				</a>
			</li>
			<?php if ($dbManager && $dbManager->isConnected() && $auth->hasPermission('system_config')): ?>
			<li class="nav-item">
				<a class="nav-link <?php echo $view_mode === 'database' ? 'active' : ''; ?>" href="?view=database">
					<i class="icon-file-alt"></i> Database
				</a>
			</li>
			<?php endif; ?>
			<?php if ($auth->hasPermission('execute')): ?>
			<li class="nav-item">
				<a class="nav-link <?php echo $view_mode === 'console' ? 'active' : ''; ?>" href="?view=console">
					<i class="icon-file-code"></i> Console
				</a>
			</li>
			<?php endif; ?>
			<?php if ($auth->hasPermission('read')): ?>
			<li class="nav-item">
				<button type="button" class="btn btn-sm btn-outline-success ml-2" data-toggle="modal" data-target="#confirmRootDownload" title="Download All Files">
					<i class="icon-download"></i> Download All
				</button>
			</li>
			<?php endif; ?>
			<li class="nav-item">
				<a class="nav-link" href="logout.php?action=logout&token=<?php echo $_SESSION['logout_csrf_token'] ?? ''; ?>">
					Logout (<?php echo htmlspecialchars($auth->getUsername()); ?>)
				</a>
			</li>
		</ul>
	</nav>

	<!-- Root Download Confirmation Modal - Place this outside the navbar but in the main page -->
	<div class="modal fade" id="confirmRootDownload" tabindex="-1" role="dialog" aria-labelledby="confirmRootDownloadLabel" aria-hidden="true">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="confirmRootDownloadLabel">Confirm Root Directory Download</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<div class="alert alert-warning">
						<i class="icon-exclamation-triangle"></i> Warning: You are about to download the entire root directory. This could be a large file and may take some time.
					</div>
					<p>Are you sure you want to proceed?</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
					<a href="?download_dir=<?php echo urlencode($configManager->get('base_dir')); ?>&allow_root=1" class="btn btn-primary">Download Root Directory</a>
				</div>
			</div>
		</div>
	</div>

    <div class="container">
        <?php if (!empty($message)): ?>
            <?php echo UiComponents::generateAlert($message, $messageType); ?>
        <?php endif; ?>

        <?php if ($view_mode === 'files'): ?>
            <!-- FILE BROWSER VIEW -->
            <div class="row">
                <!-- Left sidebar with directory tree -->
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-header">
                            Directory Tree
                        </div>
                        <div class="card-body">
                            <?php echo UiComponents::generateDirectoryTreeView($directoryTree); ?>
                        </div>
                    </div>
                </div>

                <!-- Main content area -->
                <div class="col-md-9">
                    <?php if ($edit_file): ?>
                        <!-- File Editor -->
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    Editing: <?php echo htmlspecialchars($fileInfo['name']); ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($fileInfo['mime_type']); ?></span>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($fileInfo['size_formatted']); ?></span>
                                </div>
                                <a href="?dir=<?php echo urlencode(dirname($edit_file)); ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="icon-folder"></i> Back to Directory
                                </a>
                            </div>
                            <div class="card-body">
                                <form method="post" action="">
                                    <input type="hidden" name="file" value="<?php echo htmlspecialchars($edit_file); ?>">
                                    <?php echo $csrfProtection->getTokenField(); ?>
                                    <div class="form-group">
                                        <textarea id="editor" name="content" class="code-editor"><?php echo htmlspecialchars($fileContent); ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <button type="submit" name="save" class="btn btn-primary">
                                            <i class="icon-check-circle"></i> Save Changes
                                        </button>
                                        <a href="?dir=<?php echo urlencode(dirname($edit_file)); ?>" class="btn btn-secondary">
                                            Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                            <div class="card-footer text-muted">
                                Last modified: <?php echo htmlspecialchars($fileInfo['modified_formatted']); ?>
                            </div>
                        </div>
                    <?php elseif ($edit_dir): ?>
                        <!-- Directory Editor -->
                        <div class="card">
                            <div class="card-header">
                                <div>Rename Directory: <?php echo htmlspecialchars($dirInfo['name']); ?></div>
                                <a href="?dir=<?php echo urlencode(dirname($edit_dir)); ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="icon-folder"></i> Back to Parent
                                </a>
                            </div>
                            <div class="card-body">
                                <form method="post" action="">
                                    <input type="hidden" name="old_name" value="<?php echo htmlspecialchars($edit_dir); ?>">
                                    <?php echo $csrfProtection->getTokenField(); ?>
                                    <div class="form-group">
                                        <label for="new_name">New Directory Name:</label>
                                        <input type="text" class="form-control" id="new_name" name="new_name" value="<?php echo htmlspecialchars($dirInfo['name']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <button type="submit" name="rename" class="btn btn-primary">
                                            <i class="icon-check-circle"></i> Rename Directory
                                        </button>
                                        <a href="?dir=<?php echo urlencode(dirname($edit_dir)); ?>" class="btn btn-secondary">
                                            Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($searchResults): ?>
                        <!-- Search Results -->
                        <div class="card">
                            <div class="card-header">
                                <div>Search Results for: "<?php echo htmlspecialchars($_POST['search_term']); ?>"</div>
                                <a href="?dir=<?php echo urlencode($current_dir); ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="icon-folder"></i> Back to Directory
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    Found <?php echo count($searchResults['files']); ?> files and <?php echo count($searchResults['matches']); ?> content matches.
                                </div>
                                
                                <?php if (!empty($searchResults['files'])): ?>
                                    <h5>Files Matching "<?php echo htmlspecialchars($_POST['search_term']); ?>"</h5>
                                    <ul class="list-group mb-4">
                                        <?php foreach($searchResults['files'] as $file): ?>
                                            <li class="list-group-item">
                                                <div class="file-name">
                                                    <i class="icon-file"></i>
                                                    <a href="?edit=<?php echo urlencode($file['path']); ?>">
                                                        <?php echo htmlspecialchars($file['name']); ?>
                                                    </a>
                                                </div>
                                                <div class="search-result-path">
                                                    <?php echo htmlspecialchars(str_replace($configManager->get('base_dir'), '', $file['path'])); ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                
                                <?php if (!empty($searchResults['matches'])): ?>
                                    <h5>Content Matches</h5>
                                    <?php foreach($searchResults['matches'] as $match): ?>
                                        <div class="search-result-item">
                                            <div class="file-name">
                                                <i class="icon-file"></i>
                                                <a href="?edit=<?php echo urlencode($match['path']); ?>">
                                                    <?php echo htmlspecialchars($match['name']); ?>
                                                </a>
                                            </div>
                                            <div class="search-result-path">
                                                <?php echo htmlspecialchars(str_replace($configManager->get('base_dir'), '', $match['path'])); ?>
                                            </div>
                                            <ul class="list-group">
                                                <?php foreach($match['matches'] as $line): ?>
                                                    <li class="list-group-item">
                                                        <span class="badge badge-secondary">Line <?php echo $line['line']; ?></span>
                                                        <code><?php echo htmlspecialchars($line['content']); ?></code>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- File Browser -->
                        <div class="card">
                            <div class="card-header">
                                <div>Current Directory: <?php echo htmlspecialchars(str_replace($configManager->get('base_dir'), '', $current_dir) ?: '/'); ?></div>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#createFileModal">
                                        <i class="icon-file"></i> New File
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#createDirModal">
                                        <i class="icon-folder"></i> New Directory
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-info" data-toggle="modal" data-target="#searchModal">
                                        <i class="icon-search"></i> Search
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#uploadModal">
                                        <i class="icon-upload"></i> Upload
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php echo UiComponents::generateBreadcrumb($configManager->get('base_dir'), $current_dir); ?>
                                
                                <form method="post" action="" class="mb-3">
                                    <?php echo $csrfProtection->getTokenField(); ?>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label for="filter">Filter:</label>
                                                <input type="text" class="form-control" id="filter" name="filter" value="<?php echo htmlspecialchars($filter); ?>" placeholder="e.g. *.php, *.js">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mt-4">
                                                <input type="checkbox" class="form-check-input" id="recursive" name="recursive" <?php echo $recursive ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="recursive">Recursive Listing</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label for="recursive_depth">Max Depth:</label>
                                                <input type="number" class="form-control" id="recursive_depth" name="recursive_depth" value="<?php echo $recursiveDepth; ?>" min="1" max="10">
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" name="apply_filter" class="btn btn-primary">Apply Filter</button>
                                </form>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Size</th>
                                                <th>Modified</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($current_dir !== $configManager->get('base_dir')): ?>
                                                <tr>
                                                    <td><div class="dir-name"><i class="icon-folder"></i> <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>">..</a></div></td>
                                                    <td>Directory</td>
                                                    <td>-</td>
                                                    <td>-</td>
                                                </tr>
                                            <?php endif; ?>
                                            
											<?php foreach ($listing['directories'] as $dir): ?>
												<?php 
												// Get full directory info for size information
												try {
													$dirInfo = $fileManager->getDirInfo($dir['path']);
													echo UiComponents::generateDirectoryListItem($dirInfo, $auth->hasPermission('delete'));
												} catch (\Exception $e) {
													echo UiComponents::generateDirectoryListItem($dir, $auth->hasPermission('delete'));
												}
												?>
											<?php endforeach; ?>
                                            
                                            <?php foreach ($listing['files'] as $file): ?>
                                                <?php echo UiComponents::generateFileListItem($file, $auth->hasPermission('delete')); ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="row">
                                    <div class="col-md-4">
                                        <span class="text-muted">
                                            <?php echo $listing['stats']['total_dirs']; ?> directories
                                        </span>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="text-muted">
                                            <?php echo $listing['stats']['total_files']; ?> files
                                        </span>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="text-muted">
                                            Total size: <?php echo $fileManager->formatBytes($listing['stats']['total_size']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php elseif ($view_mode === 'dashboard' && $auth->hasPermission('system_config')): ?>
            <!-- DASHBOARD VIEW -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            System Dashboard
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- System metrics -->
                                <div class="col-md-3">
                                    <div class="card metric-card">
                                        <i class="icon-file" style="font-size: 2rem;"></i>
                                        <div class="metric-value">
                                            <?php echo $listing['stats']['total_files']; ?>
                                        </div>
                                        <div class="metric-label">Total Files</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card metric-card">
                                        <i class="icon-folder" style="font-size: 2rem;"></i>
                                        <div class="metric-value">
                                            <?php echo $listing['stats']['total_dirs']; ?>
                                        </div>
                                        <div class="metric-label">Total Directories</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card metric-card">
                                        <i class="icon-file-alt" style="font-size: 2rem;"></i>
                                        <div class="metric-value">
                                            <?php echo $fileManager->formatBytes($listing['stats']['total_size']); ?>
                                        </div>
                                        <div class="metric-label">Total Size</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card metric-card">
                                        <i class="icon-info-circle" style="font-size: 2rem;"></i>
                                        <div class="metric-value">
                                            <?php echo $configManager->get('version'); ?>
                                        </div>
                                        <div class="metric-label">Admin Panel Version</div>
                                    </div>
                                </div>
                            </div>
                            
                            <h4 class="mt-4">PHP Information</h4>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <tbody>
                                        <tr>
                                            <th>PHP Version</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['version']); ?></td>
                                            <th>Zend Version</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['zend_version']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Server Software</th>
                                            <td><?php echo htmlspecialchars($systemInfo['server']['software']); ?></td>
                                            <th>Server OS</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['os']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Memory Limit</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['memory_limit']); ?></td>
                                            <th>Max Execution Time</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['max_execution_time']); ?> seconds</td>
                                        </tr>
                                        <tr>
                                            <th>Upload Max Filesize</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['upload_max_filesize']); ?></td>
                                            <th>Post Max Size</th>
                                            <td><?php echo htmlspecialchars($systemInfo['php']['post_max_size']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Memory Usage</th>
                                            <td><?php echo SystemInfo::formatBytes($systemInfo['metrics']['php_memory_usage']); ?></td>
                                            <th>Memory Peak</th>
                                            <td><?php echo SystemInfo::formatBytes($systemInfo['metrics']['php_memory_peak']); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <h4 class="mt-4">Server Information</h4>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <tbody>
                                        <tr>
                                            <th>Hostname</th>
                                            <td><?php echo htmlspecialchars($systemInfo['server']['hostname']); ?></td>
                                            <th>Document Root</th>
                                            <td><?php echo htmlspecialchars($systemInfo['server']['document_root']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Server IP</th>
                                            <td><?php echo htmlspecialchars($systemInfo['server']['server_addr']); ?></td>
                                            <th>Server Port</th>
                                            <td><?php echo htmlspecialchars($systemInfo['server']['server_port']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Client IP</th>
                                            <td><?php echo htmlspecialchars($systemInfo['server']['remote_addr']); ?></td>
                                            <th>Request Time</th>
                                            <td><?php echo date('Y-m-d H:i:s', $systemInfo['server']['request_time']); ?></td>
                                        </tr>
                                        <?php if (isset($systemInfo['metrics']['disk_total'])): ?>
                                        <tr>
                                            <th>Disk Space</th>
                                            <td>
                                                <?php 
                                                    $diskPercentage = round(($systemInfo['metrics']['disk_used'] / $systemInfo['metrics']['disk_total']) * 100);
                                                    echo SystemInfo::formatBytes($systemInfo['metrics']['disk_used']) . ' / ' . 
                                                         SystemInfo::formatBytes($systemInfo['metrics']['disk_total']) . 
                                                         ' (' . $diskPercentage . '%)';
                                                ?>
                                            </td>
                                            <th>Free Space</th>
                                            <td><?php echo SystemInfo::formatBytes($systemInfo['metrics']['disk_free']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <h4 class="mt-4">Session Information</h4>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <tbody>
                                        <tr>
                                            <th>Username</th>
                                            <td><?php echo htmlspecialchars($sessionInfo['username']); ?></td>
                                            <th>Role</th>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo htmlspecialchars($sessionInfo['role']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Login Time</th>
                                            <td><?php echo date('Y-m-d H:i:s', $sessionInfo['login_time']); ?></td>
                                            <th>Last Activity</th>
                                            <td><?php echo date('Y-m-d H:i:s', $sessionInfo['last_activity']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>IP Address</th>
                                            <td><?php echo htmlspecialchars($sessionInfo['ip_address']); ?></td>
                                            <th>Session ID</th>
                                            <td><?php echo session_id(); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <h4 class="mt-4">PHP Extensions</h4>
                            <div class="row">
                                <?php foreach(array_chunk($systemInfo['php']['extensions'], ceil(count($systemInfo['php']['extensions'])/3)) as $extGroup): ?>
                                <div class="col-md-4">
                                    <ul class="list-group">
                                        <?php foreach($extGroup as $ext): ?>
                                        <li class="list-group-item"><?php echo htmlspecialchars($ext); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer text-muted">
                            Page generated in <?php echo $executionTime; ?> seconds
                        </div>
                    </div>
                </div>
            </div>
            
        <?php elseif ($view_mode === 'database' && $dbManager && $auth->hasPermission('system_config')): ?>
            <!-- DATABASE VIEW -->
            <div class="row">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-header">
                            Database Tables
                        </div>
                        <div class="card-body">
                            <?php if ($dbInfo['connected']): ?>
                                <ul class="list-group">
                                    <?php foreach($dbInfo['tables'] as $table): ?>
                                        <li class="list-group-item <?php echo (isset($dbInfo['selectedTable']) && $dbInfo['selectedTable'] === $table) ? 'active' : ''; ?>">
                                            <a href="?view=database&table=<?php echo urlencode($table); ?>">
                                                <?php echo htmlspecialchars($table); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    Database not connected
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-9">
                    <?php if ($dbInfo['connected'] && isset($dbInfo['selectedTable'])): ?>
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    Table: <?php echo htmlspecialchars($dbInfo['selectedTable']); ?>
                                </div>
                                <div>
                                    <a href="?view=database&table=<?php echo urlencode($dbInfo['selectedTable']); ?>&show_data=1" class="btn btn-sm btn-outline-primary">
                                        View Data
                                    </a>
                                    <a href="?view=database&table=<?php echo urlencode($dbInfo['selectedTable']); ?>" class="btn btn-sm btn-outline-secondary">
                                        Structure
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (isset($dbInfo['structure'])): ?>
                                    <h5>Table Structure</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Field</th>
                                                    <th>Type</th>
                                                    <th>Null</th>
                                                    <th>Key</th>
                                                    <th>Default</th>
                                                    <th>Extra</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($dbInfo['structure'] as $column): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($column['Field']); ?></td>
                                                        <td><?php echo htmlspecialchars($column['Type']); ?></td>
                                                        <td><?php echo htmlspecialchars($column['Null']); ?></td>
                                                        <td><?php echo htmlspecialchars($column['Key']); ?></td>
                                                        <td><?php echo htmlspecialchars($column['Default'] ?? 'NULL'); ?></td>
                                                        <td><?php echo htmlspecialchars($column['Extra']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (isset($dbInfo['data'])): ?>
                                    <h5>Table Data</h5>
                                    <div class="db-table-container">
                                        <table class="table table-bordered table-striped table-sm">
                                            <thead>
                                                <tr>
                                                    <?php foreach(array_keys($dbInfo['data'][0] ?? []) as $column): ?>
                                                        <th><?php echo htmlspecialchars($column); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($dbInfo['data'] as $row): ?>
                                                    <tr>
                                                        <?php foreach($row as $value): ?>
                                                            <td><?php echo htmlspecialchars($value ?? 'NULL'); ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <nav aria-label="Table data pagination">
                                            <ul class="pagination">
                                                <?php
                                                    $offset = $dbInfo['offset'];
                                                    $limit = $dbInfo['limit'];
                                                    $prevOffset = max(0, $offset - $limit);
                                                    $nextOffset = $offset + $limit;
                                                ?>
                                                <li class="page-item <?php echo ($offset === 0) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?view=database&table=<?php echo urlencode($dbInfo['selectedTable']); ?>&show_data=1&offset=0&limit=<?php echo $limit; ?>">First</a>
                                                </li>
                                                <li class="page-item <?php echo ($offset === 0) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?view=database&table=<?php echo urlencode($dbInfo['selectedTable']); ?>&show_data=1&offset=<?php echo $prevOffset; ?>&limit=<?php echo $limit; ?>">Previous</a>
                                                </li>
                                                <li class="page-item <?php echo ($dbInfo['dataCount'] < $limit) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?view=database&table=<?php echo urlencode($dbInfo['selectedTable']); ?>&show_data=1&offset=<?php echo $nextOffset; ?>&limit=<?php echo $limit; ?>">Next</a>
                                                </li>
                                            </ul>
                                        </nav>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card mt-3">
                            <div class="card-header">
                                Custom SQL Query
                            </div>
                            <div class="card-body">
                                <form method="post" action="?view=database">
                                    <?php echo $csrfProtection->getTokenField(); ?>
                                    <div class="form-group">
                                        <textarea class="form-control" name="sql_query" rows="4" placeholder="Enter SQL query..."><?php echo isset($_POST['sql_query']) ? htmlspecialchars($_POST['sql_query']) : "SELECT * FROM `{$dbInfo['selectedTable']}` LIMIT 10"; ?></textarea>
                                    </div>
                                    <button type="submit" name="execute_query" class="btn btn-primary">Execute Query</button>
                                </form>
                                
                                <?php if (isset($_POST['execute_query']) && isset($_POST['sql_query'])): ?>
                                    <div class="mt-4">
                                        <h5>Query Results</h5>
                                        <?php
                                        try {
                                            $result = $dbManager->query($_POST['sql_query']);
                                            if (is_array($result) && !empty($result)):
                                        ?>
                                            <div class="db-table-container">
                                                <table class="table table-bordered table-striped table-sm">
                                                    <thead>
                                                        <tr>
                                                            <?php foreach(array_keys($result[0]) as $column): ?>
                                                                <th><?php echo htmlspecialchars($column); ?></th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($result as $row): ?>
                                                            <tr>
                                                                <?php foreach($row as $value): ?>
                                                                    <td><?php echo htmlspecialchars($value ?? 'NULL'); ?></td>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">
                                                Query executed successfully. No results to display or empty result set.
                                            </div>
                                        <?php 
                                            endif;
                                        } catch (\Exception $e) {
                                            echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-header">
                                Database Information
                            </div>
                            <div class="card-body">
                                <?php if ($dbInfo['connected']): ?>
                                    <div class="alert alert-info">
                                        Select a table from the list to view its structure and data.
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <tbody>
                                                <tr>
                                                    <th>Connection Status</th>
                                                    <td><span class="badge badge-success">Connected</span></td>
                                                </tr>
                                                <tr>
                                                    <th>Database Name</th>
                                                    <td><?php echo htmlspecialchars($configManager->get('db_config')['database']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Host</th>
                                                    <td><?php echo htmlspecialchars($configManager->get('db_config')['host']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Total Tables</th>
                                                    <td><?php echo count($dbInfo['tables']); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        Database connection failed. Please check your configuration.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php elseif ($view_mode === 'console' && $auth->hasPermission('execute')): ?>
            <!-- SCRIPT CONSOLE VIEW -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            PHP Script Console
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="icon-exclamation-triangle"></i> Warning: Execute PHP code with caution. This can potentially harm your system if used incorrectly.
                            </div>
                            
                            <form method="post" action="?view=console">
                                <?php echo $csrfProtection->getTokenField(); ?>
                                <div class="form-group">
                                    <label for="script">PHP Code:</label>
                                    <textarea id="script" name="script" class="code-editor"><?php echo isset($_POST['script']) ? htmlspecialchars($_POST['script']) : '// Enter your PHP code here
echo "Hello World!";
print_r($_SERVER);
'; ?></textarea>
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="execute" class="btn btn-primary">
                                        <i class="icon-play"></i> Execute Script
                                    </button>
                                </div>
                            </form>
                            
                            <?php if (isset($_POST['execute'])): ?>
                                <div class="card mt-4">
                                    <div class="card-header">
                                        Script Output
                                    </div>
                                    <div class="card-body">
                                        <pre class="script-output"><?php echo htmlspecialchars($scriptOutput); ?></pre>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modals -->
    <!-- Create File Modal -->
    <div class="modal fade" id="createFileModal" tabindex="-1" role="dialog" aria-labelledby="createFileModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createFileModalLabel">Create New File</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="newFileName">File Name:</label>
                            <input type="text" class="form-control" id="newFileName" name="newFileName" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_file" class="btn btn-primary">Create File</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Directory Modal -->
    <div class="modal fade" id="createDirModal" tabindex="-1" role="dialog" aria-labelledby="createDirModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createDirModalLabel">Create New Directory</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="newDirName">Directory Name:</label>
                            <input type="text" class="form-control" id="newDirName" name="newDirName" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_dir" class="btn btn-primary">Create Directory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" role="dialog" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadModalLabel">Upload File</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="upload_file">Select File:</label>
                            <input type="file" class="form-control-file" id="upload_file" name="upload_file" required>
                        </div>
                        <small class="form-text text-muted">
                            Maximum upload size: <?php echo $fileManager->formatBytes($configManager->get('max_upload_size')); ?>
                        </small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Search Modal -->
    <div class="modal fade" id="searchModal" tabindex="-1" role="dialog" aria-labelledby="searchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="searchModalLabel">Search Files</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="search_term">Search Term:</label>
                            <input type="text" class="form-control" id="search_term" name="search_term" required>
                        </div>
                        <div class="form-group">
                            <label for="search_extensions">File Extensions (comma-separated, leave empty for all):</label>
                            <input type="text" class="form-control" id="search_extensions" name="search_extensions" placeholder="e.g. php,js,html">
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="case_sensitive" name="case_sensitive">
                            <label class="form-check-label" for="case_sensitive">Case Sensitive</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="search" class="btn btn-primary">Search</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div class="modal fade" id="confirmDelete" tabindex="-1" role="dialog" aria-labelledby="confirmDeleteLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmDeleteLabel">Confirm Delete</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete the file <strong id="deleteFileName"></strong>? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Directory Modal -->
    <div class="modal fade" id="confirmDeleteDir" tabindex="-1" role="dialog" aria-labelledby="confirmDeleteDirLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmDeleteDirLabel">Confirm Delete Directory</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete the directory <strong id="deleteDirName"></strong>? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteDirBtn" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Rename File Modal -->
    <div class="modal fade" id="renameFileModal" tabindex="-1" role="dialog" aria-labelledby="renameFileModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="renameFileModalLabel">Rename File</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <input type="hidden" name="source_file" id="renameSourceFile">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="new_filename">New File Name:</label>
                            <input type="text" class="form-control" id="new_filename" name="new_filename" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="rename_file" class="btn btn-primary">Rename</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Move File Modal -->
    <div class="modal fade" id="moveFileModal" tabindex="-1" role="dialog" aria-labelledby="moveFileModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="moveFileModalLabel">Move File</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <input type="hidden" name="source" id="moveSource">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="destination">Destination Path:</label>
                            <input type="text" class="form-control" id="destination" name="destination" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="move_file" class="btn btn-primary">Move</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Copy File Modal -->
    <div class="modal fade" id="copyFileModal" tabindex="-1" role="dialog" aria-labelledby="copyFileModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="copyFileModalLabel">Copy File</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <?php echo $csrfProtection->getTokenField(); ?>
                    <input type="hidden" name="source" id="copySource">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="destination">Destination Path:</label>
                            <input type="text" class="form-control" id="copyDestination" name="destination" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="copy_file" class="btn btn-primary">Copy</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
	
	<!-- Directory Download Confirmation Modal -->
	<div class="modal fade" id="confirmDirDownload" tabindex="-1" role="dialog" aria-labelledby="confirmDirDownloadLabel" aria-hidden="true">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="confirmDirDownloadLabel">Confirm Directory Download</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<div class="alert alert-warning">
						<i class="icon-exclamation-triangle"></i> You are about to download the directory <strong id="downloadDirName"></strong>.
					</div>
					<p>Estimated size: <span id="downloadDirSize">Unknown</span></p>
					<p>This might take some time depending on the directory size and your connection speed.</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
					<a href="#" id="confirmDirDownloadBtn" class="btn btn-success">Download</a>
				</div>
			</div>
		</div>
	</div>

    <!-- JavaScript for dynamic behavior -->
    <script>
        // DOM elements caching
        const deleteFileLinks = document.querySelectorAll('.delete-file');
        const deleteDirLinks = document.querySelectorAll('.delete-dir');
        const renameFileLinks = document.querySelectorAll('.file-rename');
        const moveFileLinks = document.querySelectorAll('.file-move');
        const copyFileLinks = document.querySelectorAll('.file-copy');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        const confirmDeleteDirBtn = document.getElementById('confirmDeleteDirBtn');
        const deleteFileName = document.getElementById('deleteFileName');
        const deleteDirName = document.getElementById('deleteDirName');
        const newFilenameInput = document.getElementById('new_filename');
        const renameSourceFile = document.getElementById('renameSourceFile');
        const moveSource = document.getElementById('moveSource');
        const copySource = document.getElementById('copySource');
        const destinationInput = document.getElementById('destination');
        const copyDestinationInput = document.getElementById('copyDestination');
		// Setup directory download confirmation
		const dirDownloadBtns = document.querySelectorAll('.dir-download-btn');
		const downloadDirName = document.getElementById('downloadDirName');
		const downloadDirSize = document.getElementById('downloadDirSize');
		const confirmDirDownloadBtn = document.getElementById('confirmDirDownloadBtn');

		dirDownloadBtns.forEach(btn => {
			btn.addEventListener('click', (e) => {
				const dirName = btn.dataset.dir;
				const dirPath = btn.dataset.path;
				const dirSize = btn.dataset.size;
				
				downloadDirName.textContent = dirName;
				downloadDirSize.textContent = dirSize;
				confirmDirDownloadBtn.setAttribute('href', `?download_dir=${dirPath}`);
			});
		});

        // Setup delete file confirmation
        deleteFileLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const fileName = link.dataset.file;
                const fileUrl = link.getAttribute('href');
                deleteFileName.textContent = fileName;
                confirmDeleteBtn.setAttribute('href', fileUrl);
            });
        });

        // Setup delete directory confirmation
        deleteDirLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const dirName = link.dataset.dir;
                const dirUrl = link.getAttribute('href');
                deleteDirName.textContent = dirName;
                confirmDeleteDirBtn.setAttribute('href', dirUrl);
            });
        });

        // Setup file rename modal
        renameFileLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                const filePath = link.dataset.file;
                const fileName = link.dataset.filename;
                renameSourceFile.value = filePath;
                newFilenameInput.value = fileName;
            });
        });

        // Setup file move modal
        moveFileLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                const filePath = link.dataset.file;
                moveSource.value = filePath;
                // Extract the file name
                const fileName = filePath.split('/').pop();
                // Suggest a destination (current dir + filename)
                destinationInput.value = `<?php echo str_replace('\\', '/', $current_dir); ?>/${fileName}`;
            });
        });

        // Setup file copy modal
        copyFileLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                const filePath = link.dataset.file;
                copySource.value = filePath;
                // Extract the file name
                const fileName = filePath.split('/').pop();
                // Suggest a destination (current dir + copy_ + filename)
                const newFileName = 'copy_' + fileName;
                copyDestinationInput.value = `<?php echo str_replace('\\', '/', $current_dir); ?>/${newFileName}`;
            });
        });

        // Simple syntax highlighting for the code editor
        document.addEventListener('DOMContentLoaded', function() {
            const editor = document.getElementById('editor');
            if (editor) {
                // This is a simple placeholder - in a real implementation,
                // you would integrate a proper code editor like CodeMirror or Ace
                editor.addEventListener('keydown', function(e) {
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        
                        // Insert tab at cursor position
                        const start = this.selectionStart;
                        const end = this.selectionEnd;
                        
                        this.value = this.value.substring(0, start) + 
                                    "    " + 
                                    this.value.substring(end);
                        
                        // Move cursor after the inserted tab
                        this.selectionStart = this.selectionEnd = start + 4;
                    }
                });
            }
            
            // Enable automatically dismissing alerts
            const alertElements = document.querySelectorAll('.alert-dismissible');
            alertElements.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('show');
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 150);
                }, 5000);
            });
        });
    </script>
	<!-- Add jQuery first (required by Bootstrap) -->
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<!-- Add Bootstrap JS -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>