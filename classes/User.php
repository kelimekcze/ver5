<?php
/**
 * User Management Class
 * Logistic CRM System
 * 
 * Handles user authentication, registration, and management
 * with comprehensive security features
 */

class User {
    private $db;
    private $table = 'users';
    
    // User properties
    public $id;
    public $company_id;
    public $email;
    public $password_hash;
    public $full_name;
    public $phone;
    public $user_type;
    public $is_active;
    public $avatar_url;
    public $language;
    public $timezone;
    public $last_login;
    public $driver_license_number;
    public $driver_license_expires;
    public $notes;
    public $created_at;
    public $updated_at;
    
    // Valid user types
    const USER_TYPES = ['super_admin', 'admin', 'logistics', 'driver'];
    
    // Password requirements
    const MIN_PASSWORD_LENGTH = 8;
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_DURATION = 900; // 15 minutes
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * User registration with email verification
     */
    public function register($data) {
        // Validate input data
        $validation = $this->validateRegistrationData($data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        // Check if email already exists
        if ($this->emailExists($data['email'])) {
            return ['success' => false, 'errors' => ['Email je již registrován']];
        }
        
        // Hash password
        $password_hash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        
        // Generate email verification token
        $verification_token = bin2hex(random_bytes(32));
        
        try {
            $this->db->beginTransaction();
            
            // Insert user
            $query = "INSERT INTO {$this->table} (
                        company_id, email, password_hash, full_name, phone, user_type, 
                        language, timezone, email_verification_token, is_active
                      ) VALUES (
                        :company_id, :email, :password_hash, :full_name, :phone, :user_type,
                        :language, :timezone, :email_verification_token, :is_active
                      )";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $data['company_id']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password_hash', $password_hash);
            $stmt->bindParam(':full_name', $data['full_name']);
            $stmt->bindParam(':phone', $data['phone'] ?? null);
            $stmt->bindParam(':user_type', $data['user_type'] ?? 'driver');
            $stmt->bindParam(':language', $data['language'] ?? 'cs');
            $stmt->bindParam(':timezone', $data['timezone'] ?? 'Europe/Prague');
            $stmt->bindParam(':email_verification_token', $verification_token);
            $stmt->bindParam(':is_active', $data['is_active'] ?? 1);
            
            if ($stmt->execute()) {
                $user_id = $this->db->lastInsertId();
                
                // Log registration
                $this->logUserAction($user_id, 'user_registered', [
                    'email' => $data['email'],
                    'user_type' => $data['user_type'] ?? 'driver'
                ]);
                
                // Send verification email (if email service is configured)
                $this->sendVerificationEmail($data['email'], $verification_token);
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'user_id' => $user_id,
                    'message' => 'Registrace byla úspěšná. Zkontrolujte email pro ověření účtu.'
                ];
            }
            
            $this->db->rollBack();
            return ['success' => false, 'errors' => ['Registrace se nezdařila']];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("User registration error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při registraci']];
        }
    }
    
    /**
     * User login with security measures
     */
    public function login($email, $password) {
        // Check rate limiting
        if (!$this->checkRateLimit($email)) {
            return false;
        }
        
        try {
            $query = "SELECT u.*, c.name as company_name, c.status as company_status 
                     FROM {$this->table} u 
                     LEFT JOIN companies c ON u.company_id = c.id 
                     WHERE u.email = :email AND u.is_active = 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->recordFailedLogin($email);
                return false;
            }
            
            // Check if company is active (for non-super-admin users)
            if ($user['company_id'] && $user['company_status'] !== 'active' && $user['user_type'] !== 'super_admin') {
                return false;
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                $this->recordFailedLogin($email);
                return false;
            }
            
            // Check if password needs rehashing (for security upgrades)
            if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
                $this->rehashPassword($user['id'], $password);
            }
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Clear failed login attempts
            $this->clearFailedLogins($email);
            
            // Log successful login
            $this->logUserAction($user['id'], 'user_login', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // Return user data (without password)
            unset($user['password_hash']);
            return $user;
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Logout user and cleanup session
     */
    public function logout($user_id) {
        try {
            // Log logout
            $this->logUserAction($user_id, 'user_logout');
            
            // Clear session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user by ID
     */
    public function getUserById($id) {
        try {
            $query = "SELECT u.*, c.name as company_name 
                     FROM {$this->table} u 
                     LEFT JOIN companies c ON u.company_id = c.id 
                     WHERE u.id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            $user = $stmt->fetch();
            if ($user) {
                unset($user['password_hash']);
            }
            
            return $user;
        } catch (PDOException $e) {
            error_log("Get user error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get users by company and type
     */
    public function getUsersByCompany($company_id, $user_type = null) {
        try {
            $query = "SELECT u.*, c.name as company_name 
                     FROM {$this->table} u 
                     LEFT JOIN companies c ON u.company_id = c.id 
                     WHERE u.company_id = :company_id AND u.is_active = 1";
            
            if ($user_type) {
                $query .= " AND u.user_type = :user_type";
            }
            
            $query .= " ORDER BY u.full_name ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            if ($user_type) {
                $stmt->bindParam(':user_type', $user_type);
            }
            $stmt->execute();
            
            $users = $stmt->fetchAll();
            
            // Remove password hashes
            foreach ($users as &$user) {
                unset($user['password_hash']);
            }
            
            return $users;
        } catch (PDOException $e) {
            error_log("Get users by company error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update user profile
     */
    public function updateProfile($user_id, $data) {
        $validation = $this->validateProfileData($data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        try {
            $query = "UPDATE {$this->table} SET 
                        full_name = :full_name,
                        phone = :phone,
                        language = :language,
                        timezone = :timezone,
                        driver_license_number = :driver_license_number,
                        driver_license_expires = :driver_license_expires,
                        notes = :notes,
                        updated_at = NOW()
                      WHERE id = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':full_name', $data['full_name']);
            $stmt->bindParam(':phone', $data['phone'] ?? null);
            $stmt->bindParam(':language', $data['language'] ?? 'cs');
            $stmt->bindParam(':timezone', $data['timezone'] ?? 'Europe/Prague');
            $stmt->bindParam(':driver_license_number', $data['driver_license_number'] ?? null);
            $stmt->bindParam(':driver_license_expires', $data['driver_license_expires'] ?? null);
            $stmt->bindParam(':notes', $data['notes'] ?? null);
            
            if ($stmt->execute()) {
                $this->logUserAction($user_id, 'profile_updated', $data);
                return ['success' => true, 'message' => 'Profil byl aktualizován'];
            }
            
            return ['success' => false, 'errors' => ['Aktualizace se nezdařila']];
            
        } catch (PDOException $e) {
            error_log("Update profile error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při aktualizaci']];
        }
    }
    
    /**
     * Change user password
     */
    public function changePassword($user_id, $old_password, $new_password) {
        // Get current password hash
        $query = "SELECT password_hash FROM {$this->table} WHERE id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $user = $stmt->fetch();
        if (!$user) {
            return ['success' => false, 'errors' => ['Uživatel nenalezen']];
        }
        
        // Verify old password
        if (!password_verify($old_password, $user['password_hash'])) {
            return ['success' => false, 'errors' => ['Nesprávné aktuální heslo']];
        }
        
        // Validate new password
        $validation = $this->validatePassword($new_password);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        // Hash new password
        $new_hash = password_hash($new_password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        
        try {
            $query = "UPDATE {$this->table} SET 
                        password_hash = :password_hash,
                        updated_at = NOW()
                      WHERE id = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':password_hash', $new_hash);
            
            if ($stmt->execute()) {
                $this->logUserAction($user_id, 'password_changed');
                return ['success' => true, 'message' => 'Heslo bylo změněno'];
            }
            
            return ['success' => false, 'errors' => ['Změna hesla se nezdařila']];
            
        } catch (PDOException $e) {
            error_log("Change password error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při změně hesla']];
        }
    }
    
    /**
     * Password reset request
     */
    public function requestPasswordReset($email) {
        try {
            $query = "SELECT id FROM {$this->table} WHERE email = :email AND is_active = 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            $user = $stmt->fetch();
            if (!$user) {
                // Don't reveal if email exists
                return ['success' => true, 'message' => 'Pokud email existuje, byl odeslán odkaz pro reset hesla'];
            }
            
            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $query = "UPDATE {$this->table} SET 
                        password_reset_token = :token,
                        password_reset_expires = :expires
                      WHERE id = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user['id']);
            $stmt->bindParam(':token', $reset_token);
            $stmt->bindParam(':expires', $expires);
            
            if ($stmt->execute()) {
                // Send reset email
                $this->sendPasswordResetEmail($email, $reset_token);
                
                $this->logUserAction($user['id'], 'password_reset_requested');
                
                return ['success' => true, 'message' => 'Odkaz pro reset hesla byl odeslán'];
            }
            
            return ['success' => false, 'errors' => ['Chyba při generování reset tokenu']];
            
        } catch (PDOException $e) {
            error_log("Password reset request error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při požadavku na reset hesla']];
        }
    }
    
    /**
     * Reset password using token
     */
    public function resetPassword($token, $new_password) {
        try {
            $query = "SELECT id FROM {$this->table} 
                     WHERE password_reset_token = :token 
                     AND password_reset_expires > NOW()
                     AND is_active = 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':token', $token);
            $stmt->execute();
            
            $user = $stmt->fetch();
            if (!$user) {
                return ['success' => false, 'errors' => ['Neplatný nebo expirovaný token']];
            }
            
            // Validate new password
            $validation = $this->validatePassword($new_password);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors']];
            }
            
            // Hash new password
            $password_hash = password_hash($new_password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);
            
            $query = "UPDATE {$this->table} SET 
                        password_hash = :password_hash,
                        password_reset_token = NULL,
                        password_reset_expires = NULL,
                        updated_at = NOW()
                      WHERE id = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user['id']);
            $stmt->bindParam(':password_hash', $password_hash);
            
            if ($stmt->execute()) {
                $this->logUserAction($user['id'], 'password_reset_completed');
                return ['success' => true, 'message' => 'Heslo bylo úspěšně resetováno'];
            }
            
            return ['success' => false, 'errors' => ['Reset hesla se nezdařil']];
            
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při resetování hesla']];
        }
    }
    
    /**
     * Email verification
     */
    public function verifyEmail($token) {
        try {
            $query = "SELECT id FROM {$this->table} 
                     WHERE email_verification_token = :token 
                     AND email_verified = 0";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':token', $token);
            $stmt->execute();
            
            $user = $stmt->fetch();
            if (!$user) {
                return ['success' => false, 'errors' => ['Neplatný verifikační token']];
            }
            
            $query = "UPDATE {$this->table} SET 
                        email_verified = 1,
                        email_verification_token = NULL,
                        updated_at = NOW()
                      WHERE id = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user['id']);
            
            if ($stmt->execute()) {
                $this->logUserAction($user['id'], 'email_verified');
                return ['success' => true, 'message' => 'Email byl úspěšně ověřen'];
            }
            
            return ['success' => false, 'errors' => ['Ověření emailu se nezdařilo']];
            
        } catch (PDOException $e) {
            error_log("Email verification error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při ověřování emailu']];
        }
    }
    
    // Private helper methods
    
    private function validateRegistrationData($data) {
        $errors = [];
        
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Neplatný email';
        }
        
        if (empty($data['password'])) {
            $errors[] = 'Heslo je povinné';
        } else {
            $password_validation = $this->validatePassword($data['password']);
            if (!$password_validation['valid']) {
                $errors = array_merge($errors, $password_validation['errors']);
            }
        }
        
        if (empty($data['full_name'])) {
            $errors[] = 'Jméno je povinné';
        }
        
        if (isset($data['user_type']) && !in_array($data['user_type'], self::USER_TYPES)) {
            $errors[] = 'Neplatný typ uživatele';
        }
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
    
    private function validateProfileData($data) {
        $errors = [];
        
        if (empty($data['full_name'])) {
            $errors[] = 'Jméno je povinné';
        }
        
        if (isset($data['phone']) && !empty($data['phone'])) {
            if (!preg_match('/^[+]?[\d\s\-()]{9,15}$/', $data['phone'])) {
                $errors[] = 'Neplatné telefonní číslo';
            }
        }
        
        if (isset($data['driver_license_expires']) && !empty($data['driver_license_expires'])) {
            if (!DateTime::createFromFormat('Y-m-d', $data['driver_license_expires'])) {
                $errors[] = 'Neplatné datum expirace řidičského průkazu';
            }
        }
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
    
    private function validatePassword($password) {
        $errors = [];
        
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Heslo musí mít minimálně ' . self::MIN_PASSWORD_LENGTH . ' znaků';
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
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
    
    private function emailExists($email) {
        try {
            $query = "SELECT id FROM {$this->table} WHERE email = :email";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Email exists check error: " . $e->getMessage());
            return false;
        }
    }
    
    private function checkRateLimit($email) {
        $cache_key = 'login_attempts_' . md5($email);
        $cache_file = sys_get_temp_dir() . '/' . $cache_key;
        
        if (file_exists($cache_file)) {
            $data = json_decode(file_get_contents($cache_file), true);
            if ($data && $data['attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
                if (time() - $data['last_attempt'] < self::LOCKOUT_DURATION) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    private function recordFailedLogin($email) {
        $cache_key = 'login_attempts_' . md5($email);
        $cache_file = sys_get_temp_dir() . '/' . $cache_key;
        
        $data = ['attempts' => 1, 'last_attempt' => time()];
        
        if (file_exists($cache_file)) {
            $existing = json_decode(file_get_contents($cache_file), true);
            if ($existing) {
                $data['attempts'] = $existing['attempts'] + 1;
            }
        }
        
        file_put_contents($cache_file, json_encode($data));
    }
    
    private function clearFailedLogins($email) {
        $cache_key = 'login_attempts_' . md5($email);
        $cache_file = sys_get_temp_dir() . '/' . $cache_key;
        
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }
    }
    
    private function updateLastLogin($user_id) {
        try {
            $query = "UPDATE {$this->table} SET last_login = NOW() WHERE id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Update last login error: " . $e->getMessage());
        }
    }
    
    private function rehashPassword($user_id, $password) {
        try {
            $new_hash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);
            
            $query = "UPDATE {$this->table} SET password_hash = :password_hash WHERE id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':password_hash', $new_hash);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Rehash password error: " . $e->getMessage());
        }
    }
    
    private function logUserAction($user_id, $action, $data = null) {
        try {
            $query = "INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_values, ip_address, user_agent) 
                     VALUES (:user_id, :action, 'user', :entity_id, :new_values, :ip_address, :user_agent)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':entity_id', $user_id);
            $stmt->bindParam(':new_values', json_encode($data));
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null);
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("User action log error: " . $e->getMessage());
        }
    }
    
    private function sendVerificationEmail($email, $token) {
        // TODO: Implement email sending
        // This would integrate with your email service (SMTP, SendGrid, etc.)
        error_log("Verification email should be sent to: $email with token: $token");
    }
    
    private function sendPasswordResetEmail($email, $token) {
        // TODO: Implement email sending
        // This would integrate with your email service (SMTP, SendGrid, etc.)
        error_log("Password reset email should be sent to: $email with token: $token");
    }
    
    /**
     * Get user permissions based on role
     */
    public function getUserPermissions($user_type) {
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
        
        return $permissions[$user_type] ?? [];
    }
    
    /**
     * Check if user has specific permission
     */
    public function hasPermission($user_type, $entity, $action) {
        $permissions = $this->getUserPermissions($user_type);
        
        return isset($permissions[$entity]) && in_array($action, $permissions[$entity]);
    }
    
    /**
     * Get user statistics
     */
    public function getUserStats($user_id) {
        try {
            $stats = [
                'total_bookings' => 0,
                'completed_bookings' => 0,
                'pending_bookings' => 0,
                'last_booking_date' => null,
                'avg_rating' => 0
            ];
            
            // Get booking statistics
            $query = "SELECT 
                        COUNT(*) as total_bookings,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                        SUM(CASE WHEN status IN ('pending', 'approved', 'confirmed') THEN 1 ELSE 0 END) as pending_bookings,
                        MAX(created_at) as last_booking_date
                      FROM bookings 
                      WHERE driver_id = :user_id OR created_by = :user_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            $result = $stmt->fetch();
            if ($result) {
                $stats = array_merge($stats, $result);
            }
            
            return $stats;
            
        } catch (PDOException $e) {
            error_log("Get user stats error: " . $e->getMessage());
            return [];
        }
    }
}