<?php
/**
 * Login API Endpoint
 * Logistic CRM System
 * 
 * Handles user authentication with comprehensive security
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable for production

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'code' => 'METHOD_NOT_ALLOWED',
        'allowed_methods' => ['POST']
    ]);
    exit;
}

try {
    // Include required files
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/User.php';
    require_once __DIR__ . '/../middleware/auth.php';
    require_once __DIR__ . '/../middleware/license_check.php';
    
    // Get and validate input
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception('No input data received');
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    // Validate required fields
    if (empty($data['email']) || empty($data['password'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Email a heslo jsou povinné',
            'code' => 'MISSING_CREDENTIALS',
            'required_fields' => ['email', 'password']
        ]);
        exit;
    }
    
    // Sanitize input
    $email = trim(strtolower($data['email']));
    $password = $data['password'];
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Neplatný formát emailu',
            'code' => 'INVALID_EMAIL_FORMAT'
        ]);
        exit;
    }
    
    // Rate limiting
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate_limit_key = 'login_' . $ip;
    
    try {
        checkRateLimit(0, $rate_limit_key, 10, 15); // 10 attempts per 15 minutes
    } catch (Exception $e) {
        // Rate limit exceeded, already handled by the function
        exit;
    }
    
    // Database connection
    $database = new Database();
    $db = $database->connect();
    $user = new User($db);
    
    // Attempt login
    $user_data = $user->login($email, $password);
    
    if (!$user_data) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Nesprávné přihlašovací údaje',
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'Zkontrolujte email a heslo'
        ]);
        exit;
    }
    
    // Check if email is verified
    if (!$user_data['email_verified']) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Email není ověřen',
            'code' => 'EMAIL_NOT_VERIFIED',
            'message' => 'Zkontrolujte email a klikněte na ověřovací odkaz'
        ]);
        exit;
    }
    
    // Check license for company users
    if ($user_data['company_id'] && $user_data['user_type'] !== 'super_admin') {
        try {
            $license_validation = checkLicenseMiddleware($user_data['company_id']);
            
            // Add license info to response
            $user_data['license_info'] = [
                'valid_until' => $license_validation['license']['valid_until'],
                'license_type' => $license_validation['license']['license_type'],
                'expires_in_days' => $license_validation['expires_in_days']
            ];
            
        } catch (Exception $e) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Licence firmy není platná',
                'code' => 'LICENSE_INVALID',
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }
    
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    // Set session variables
    $_SESSION['user_id'] = $user_data['id'];
    $_SESSION['user_type'] = $user_data['user_type'];
    $_SESSION['full_name'] = $user_data['full_name'];
    $_SESSION['email'] = $user_data['email'];
    $_SESSION['company_id'] = $user_data['company_id'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    
    // Generate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    // Log successful login
    logUserActivity($user_data['id'], 'login_success', [
        'ip' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Prepare response data
    $response = [
        'success' => true,
        'message' => 'Přihlášení úspěšné',
        'user' => [
            'id' => $user_data['id'],
            'email' => $user_data['email'],
            'full_name' => $user_data['full_name'],
            'user_type' => $user_data['user_type'],
            'company_id' => $user_data['company_id'],
            'company_name' => $user_data['company_name'] ?? null,
            'language' => $user_data['language'] ?? 'cs',
            'timezone' => $user_data['timezone'] ?? 'Europe/Prague',
            'avatar_url' => $user_data['avatar_url'] ?? null,
            'last_login' => $user_data['last_login']
        ],
        'session' => [
            'csrf_token' => $_SESSION['csrf_token'],
            'expires_at' => date('Y-m-d H:i:s', time() + 86400) // 24 hours
        ],
        'permissions' => $user->getUserPermissions($user_data['user_type']),
        'timestamp' => time()
    ];
    
    // Add license info if available
    if (isset($user_data['license_info'])) {
        $response['license'] = $user_data['license_info'];
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Login database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Chyba databáze',
        'code' => 'DATABASE_ERROR',
        'message' => 'Nelze se připojit k databázi'
    ]);
    
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Chyba serveru',
        'code' => 'SERVER_ERROR',
        'message' => 'Nastala neočekávaná chyba při přihlašování'
    ]);
}