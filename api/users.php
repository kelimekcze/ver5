<?php
/**
 * Users API Endpoint
 * Logistic CRM System
 * 
 * Handles user management operations
 */

// Session and headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Include required files
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/User.php';
    require_once __DIR__ . '/../middleware/auth.php';
    require_once __DIR__ . '/../middleware/license_check.php';
    
    // Authenticate user
    $current_user = authenticate();
    
    // Database connection
    $database = new Database();
    $db = $database->connect();
    $user = new User($db);
    
    // Check license for company users
    if ($current_user['company_id']) {
        licenseRequiredMiddleware($current_user['company_id']);
    }
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetUsers($user, $current_user);
            break;
            
        case 'POST':
            handleCreateUser($user, $current_user);
            break;
            
        case 'PUT':
            handleUpdateUser($user, $current_user);
            break;
            
        case 'DELETE':
            handleDeleteUser($user, $current_user);
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'error' => 'Method not allowed',
                'code' => 'METHOD_NOT_ALLOWED'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("Users API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'code' => 'SERVER_ERROR',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle GET requests - list users
 */
function handleGetUsers($user, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'users', 'read');
        
        // Get query parameters
        $user_type = $_GET['user_type'] ?? null;
        $company_id = $_GET['company_id'] ?? null;
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
        $search = $_GET['search'] ?? '';
        
        // Determine which company's users to show
        if ($current_user['user_type'] === 'super_admin') {
            $target_company_id = $company_id; // Can view any company
        } else {
            $target_company_id = $current_user['company_id']; // Only own company
        }
        
        // Build query
        $where_conditions = [];
        $params = [];
        
        if ($target_company_id) {
            $where_conditions[] = "u.company_id = :company_id";
            $params[':company_id'] = $target_company_id;
        }
        
        if ($user_type) {
            $where_conditions[] = "u.user_type = :user_type";
            $params[':user_type'] = $user_type;
        }
        
        if ($search) {
            $where_conditions[] = "(u.full_name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM users u $where_clause";
        $stmt = $user->db->prepare($count_query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total_count = $stmt->fetchColumn();
        
        // Get users
        $offset = ($page - 1) * $limit;
        $query = "SELECT u.id, u.company_id, u.email, u.full_name, u.phone, u.user_type, 
                        u.is_active, u.avatar_url, u.language, u.timezone, u.last_login,
                        u.driver_license_number, u.driver_license_expires, u.notes,
                        u.created_at, u.updated_at, c.name as company_name
                 FROM users u 
                 LEFT JOIN companies c ON u.company_id = c.id
                 $where_clause
                 ORDER BY u.created_at DESC
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $user->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $users = $stmt->fetchAll();
        
        // Add user statistics
        foreach ($users as &$user_data) {
            $user_data['stats'] = $user->getUserStats($user_data['id']);
        }
        
        echo json_encode([
            'success' => true,
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total_count,
                'pages' => ceil($total_count / $limit)
            ],
            'filters' => [
                'user_type' => $user_type,
                'company_id' => $target_company_id,
                'search' => $search
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Get users error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get users',
            'code' => 'GET_USERS_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle POST requests - create user
 */
function handleCreateUser($user, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'users', 'create');
        
        // Check usage limits
        if ($current_user['company_id']) {
            usageLimitMiddleware($current_user['company_id'], 'users');
        }
        
        // Get and validate input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        // Validate required fields
        $required_fields = ['email', 'password', 'full_name', 'user_type'];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode([
                    'error' => "Field '$field' is required",
                    'code' => 'MISSING_REQUIRED_FIELD'
                ]);
                return;
            }
        }
        
        // Sanitize and validate data
        $data = [
            'email' => trim(strtolower($input['email'])),
            'password' => $input['password'],
            'full_name' => trim($input['full_name']),
            'phone' => trim($input['phone'] ?? ''),
            'user_type' => $input['user_type'],
            'language' => $input['language'] ?? 'cs',
            'timezone' => $input['timezone'] ?? 'Europe/Prague',
            'driver_license_number' => trim($input['driver_license_number'] ?? ''),
            'driver_license_expires' => $input['driver_license_expires'] ?? null,
            'notes' => trim($input['notes'] ?? ''),
            'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : true
        ];
        
        // Set company_id
        if ($current_user['user_type'] === 'super_admin' && isset($input['company_id'])) {
            $data['company_id'] = $input['company_id'];
        } else {
            $data['company_id'] = $current_user['company_id'];
        }
        
        // Validate user type permissions
        $allowed_types = ['driver'];
        if (in_array($current_user['user_type'], ['super_admin', 'admin'])) {
            $allowed_types = ['admin', 'logistics', 'driver'];
        }
        if ($current_user['user_type'] === 'super_admin') {
            $allowed_types[] = 'super_admin';
        }
        
        if (!in_array($data['user_type'], $allowed_types)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'You cannot create users of this type',
                'code' => 'INVALID_USER_TYPE',
                'allowed_types' => $allowed_types
            ]);
            return;
        }
        
        // Create user
        $result = $user->register($data);
        
        if ($result['success']) {
            // Log user creation
            logUserActivity($current_user['user_id'], 'user_created', [
                'new_user_id' => $result['user_id'],
                'user_type' => $data['user_type'],
                'email' => $data['email']
            ]);
            
            echo json_encode([
                'success' => true,
                'user_id' => $result['user_id'],
                'message' => 'User created successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'User creation failed',
                'code' => 'USER_CREATION_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Create user error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create user',
            'code' => 'CREATE_USER_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle PUT requests - update user
 */
function handleUpdateUser($user, $current_user) {
    try {
        // Get and validate input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['user_id'])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'User ID is required',
                'code' => 'MISSING_USER_ID'
            ]);
            return;
        }
        
        $user_id = intval($input['user_id']);
        
        // Get target user
        $target_user = $user->getUserById($user_id);
        if (!$target_user) {
            http_response_code(404);
            echo json_encode([
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ]);
            return;
        }
        
        // Check permissions
        $can_update = false;
        
        if ($current_user['user_type'] === 'super_admin') {
            $can_update = true;
        } elseif ($current_user['user_type'] === 'admin' && $target_user['company_id'] == $current_user['company_id']) {
            $can_update = true;
        } elseif ($current_user['user_id'] == $user_id) {
            // Users can update their own profile
            $can_update = true;
        }
        
        if (!$can_update) {
            http_response_code(403);
            echo json_encode([
                'error' => 'You cannot update this user',
                'code' => 'UPDATE_USER_FORBIDDEN'
            ]);
            return;
        }
        
        // Prepare update data
        $update_data = [];
        $allowed_fields = ['full_name', 'phone', 'language', 'timezone', 'driver_license_number', 'driver_license_expires', 'notes'];
        
        // Admin can update additional fields
        if (in_array($current_user['user_type'], ['super_admin', 'admin'])) {
            $allowed_fields = array_merge($allowed_fields, ['user_type', 'is_active']);
        }
        
        foreach ($allowed_fields as $field) {
            if (isset($input[$field])) {
                $update_data[$field] = $input[$field];
            }
        }
        
        // Validate user type change
        if (isset($update_data['user_type']) && $update_data['user_type'] !== $target_user['user_type']) {
            $allowed_types = ['driver'];
            if (in_array($current_user['user_type'], ['super_admin', 'admin'])) {
                $allowed_types = ['admin', 'logistics', 'driver'];
            }
            if ($current_user['user_type'] === 'super_admin') {
                $allowed_types[] = 'super_admin';
            }
            
            if (!in_array($update_data['user_type'], $allowed_types)) {
                http_response_code(403);
                echo json_encode([
                    'error' => 'You cannot assign this user type',
                    'code' => 'INVALID_USER_TYPE',
                    'allowed_types' => $allowed_types
                ]);
                return;
            }
        }
        
        // Update user
        $result = $user->updateProfile($user_id, $update_data);
        
        if ($result['success']) {
            // Log user update
            logUserActivity($current_user['user_id'], 'user_updated', [
                'updated_user_id' => $user_id,
                'changes' => $update_data
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'User update failed',
                'code' => 'USER_UPDATE_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Update user error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to update user',
            'code' => 'UPDATE_USER_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle DELETE requests - delete user
 */
function handleDeleteUser($user, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'users', 'delete');
        
        // Get user ID from URL
        $user_id = intval($_GET['user_id'] ?? 0);
        if (!$user_id) {
            http_response_code(400);
            echo json_encode([
                'error' => 'User ID is required',
                'code' => 'MISSING_USER_ID'
            ]);
            return;
        }
        
        // Get target user
        $target_user = $user->getUserById($user_id);
        if (!$target_user) {
            http_response_code(404);
            echo json_encode([
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ]);
            return;
        }
        
        // Check company access
        if ($current_user['user_type'] !== 'super_admin') {
            requireCompanyAccess($current_user, $target_user['company_id']);
        }
        
        // Cannot delete self
        if ($current_user['user_id'] == $user_id) {
            http_response_code(400);
            echo json_encode([
                'error' => 'You cannot delete your own account',
                'code' => 'CANNOT_DELETE_SELF'
            ]);
            return;
        }
        
        // Soft delete user (deactivate)
        $query = "UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :user_id";
        $stmt = $user->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        
        if ($stmt->execute()) {
            // Log user deletion
            logUserActivity($current_user['user_id'], 'user_deleted', [
                'deleted_user_id' => $user_id,
                'deleted_user_email' => $target_user['email']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete user');
        }
        
    } catch (Exception $e) {
        error_log("Delete user error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to delete user',
            'code' => 'DELETE_USER_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}