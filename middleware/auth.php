<?php
/**
 * Authentication Middleware
 * Logistic CRM System
 * 
 * Handles user authentication and authorization
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Authenticate user and return user data
 */
function authenticate() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Neautorizovaný přístup',
            'code' => 'UNAUTHORIZED',
            'message' => 'Přihlaste se prosím'
        ]);
        exit;
    }
    
    // Check session timeout (24 hours)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 86400) {
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'error' => 'Session expired',
            'code' => 'SESSION_EXPIRED',
            'message' => 'Vaše relace vypršela, přihlaste se znovu'
        ]);
        exit;
    }
    
    return [
        'user_id' => $_SESSION['user_id'],
        'user_type' => $_SESSION['user_type'],
        'full_name' => $_SESSION['full_name'] ?? '',
        'company_id' => $_SESSION['company_id'] ?? null
    ];
}

/**
 * Check if user has specific permission
 */
function hasPermission($user_type, $entity, $action) {
    $permissions = [
        'super_admin' => [
            'users' => ['create', 'read', 'update', 'delete'],
            'companies' => ['create', 'read', 'update', 'delete'],
            'licenses' => ['create', 'read', 'update', 'delete'],
            'warehouses' => ['create', 'read', 'update', 'delete'],
            'slots' => ['create', 'read', 'update', 'delete'],
            'bookings' => ['create', 'read', 'update', 'delete'],
            'reports' => ['read', 'export'],
            'settings' => ['read', 'update']
        ],
        'admin' => [
            'users' => ['create', 'read', 'update'],
            'warehouses' => ['create', 'read', 'update'],
            'slots' => ['create', 'read', 'update', 'delete'],
            'bookings' => ['create', 'read', 'update', 'delete'],
            'reports' => ['read', 'export'],
            'settings' => ['read', 'update']
        ],
        'logistics' => [
            'users' => ['read'],
            'warehouses' => ['read'],
            'slots' => ['create', 'read', 'update'],
            'bookings' => ['create', 'read', 'update'],
            'reports' => ['read']
        ],
        'driver' => [
            'bookings' => ['create', 'read', 'update_own'],
            'slots' => ['read'],
            'profile' => ['read', 'update']
        ]
    ];
    
    $user_permissions = $permissions[$user_type] ?? [];
    
    return isset($user_permissions[$entity]) && in_array($action, $user_permissions[$entity]);
}

/**
 * Require specific permission or throw 403
 */
function requirePermission($user_type, $entity, $action) {
    if (!hasPermission($user_type, $entity, $action)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Nedostatečná oprávnění',
            'code' => 'INSUFFICIENT_PERMISSIONS',
            'required_permission' => "$entity:$action",
            'user_type' => $user_type
        ]);
        exit;
    }
}

/**
 * Check if user can access company data
 */
function canAccessCompany($user, $company_id) {
    // Super admin can access all companies
    if ($user['user_type'] === 'super_admin') {
        return true;
    }
    
    // Other users can only access their own company
    return $user['company_id'] == $company_id;
}

/**
 * Require company access or throw 403
 */
function requireCompanyAccess($user, $company_id) {
    if (!canAccessCompany($user, $company_id)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Přístup k datům firmy odepřen',
            'code' => 'COMPANY_ACCESS_DENIED'
        ]);
        exit;
    }
}

/**
 * Check if user can modify specific booking
 */
function canModifyBooking($user, $booking) {
    // Super admin and admin can modify all bookings
    if (in_array($user['user_type'], ['super_admin', 'admin', 'logistics'])) {
        return true;
    }
    
    // Driver can only modify their own bookings
    if ($user['user_type'] === 'driver') {
        return $booking['driver_id'] == $user['user_id'] || $booking['created_by'] == $user['user_id'];
    }
    
    return false;
}

/**
 * Log user activity
 */
function logUserActivity($user_id, $action, $details = null) {
    try {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $user_id,
            'action' => $action,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        // Log to file or database
        error_log('USER_ACTIVITY: ' . json_encode($log_entry));
        
        // If database is available, log to audit table
        if (class_exists('Database')) {
            try {
                $database = new Database();
                $db = $database->connect();
                
                $query = "INSERT INTO audit_log (user_id, action, entity_type, new_values, ip_address, user_agent) 
                         VALUES (:user_id, :action, 'user_activity', :details, :ip_address, :user_agent)";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->bindParam(':action', $action);
                $stmt->bindParam(':details', json_encode($details));
                $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null);
                $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
                $stmt->execute();
            } catch (Exception $e) {
                error_log("Database logging error: " . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
    }
}

/**
 * Rate limiting for API endpoints
 */
function checkRateLimit($user_id, $endpoint, $max_requests = 100, $window_minutes = 60) {
    $cache_key = "rate_limit_{$user_id}_{$endpoint}";
    $cache_file = sys_get_temp_dir() . '/' . md5($cache_key);
    
    $current_time = time();
    $window_start = $current_time - ($window_minutes * 60);
    
    // Load existing requests
    $requests = [];
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        if ($data && is_array($data)) {
            // Filter out old requests
            $requests = array_filter($data, function($timestamp) use ($window_start) {
                return $timestamp > $window_start;
            });
        }
    }
    
    // Check if limit exceeded
    if (count($requests) >= $max_requests) {
        http_response_code(429);
        echo json_encode([
            'error' => 'Rate limit exceeded',
            'code' => 'RATE_LIMIT_EXCEEDED',
            'max_requests' => $max_requests,
            'window_minutes' => $window_minutes,
            'retry_after' => $window_minutes * 60
        ]);
        exit;
    }
    
    // Add current request
    $requests[] = $current_time;
    
    // Save updated requests
    file_put_contents($cache_file, json_encode($requests));
    
    return true;
}

/**
 * CSRF Protection
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'CSRF token validation failed',
            'code' => 'CSRF_TOKEN_INVALID'
        ]);
        exit;
    }
    return true;
}

/**
 * Input validation and sanitization
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    if (is_string($data)) {
        // Remove null bytes
        $data = str_replace("\0", '', $data);
        
        // Trim whitespace
        $data = trim($data);
        
        // Convert special characters to HTML entities
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        
        return $data;
    }
    
    return $data;
}

/**
 * Validate email format
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number
 */
function validatePhone($phone) {
    return preg_match('/^[+]?[\d\s\-()]{9,15}$/', $phone);
}

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate time format
 */
function validateTime($time, $format = 'H:i') {
    $t = DateTime::createFromFormat($format, $time);
    return $t && $t->format($format) === $time;
}

/**
 * Generate secure random string
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Password strength validation
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = 'Heslo musí mít minimálně 8 znaků';
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Heslo musí obsahovat alespoň jedno velké písmeno';
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Heslo musí obsahovat alespoň jedno malé písmeno';
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Heslo musí obsahovat alespoň jednu číslici';
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Heslo musí obsahovat alespoň jeden speciální znak';
    }
    
    return empty($errors) ? true : $errors;
}

/**
 * IP address validation and blocking
 */
function validateIPAddress($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function isIPBlocked($ip) {
    $blocked_ips = [
        // Add blocked IP addresses here
        // '192.168.1.1',
        // '10.0.0.1'
    ];
    
    return in_array($ip, $blocked_ips);
}

/**
 * Check if request is from blocked IP
 */
function checkIPBlocking() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    if (isIPBlocked($ip)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Access denied',
            'code' => 'IP_BLOCKED'
        ]);
        exit;
    }
}

/**
 * Security headers
 */
function setSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: DENY');
    
    // XSS protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Content type sniffing protection
    header('X-Content-Type-Options: nosniff');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';");
}

/**
 * Initialize security middleware
 */
function initializeSecurity() {
    // Set security headers
    setSecurityHeaders();
    
    // Check IP blocking
    checkIPBlocking();
    
    // Generate CSRF token
    generateCSRFToken();
}

// Auto-initialize security when this file is included
initializeSecurity();