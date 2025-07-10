<?php
/**
 * License Management Class
 * Logistic CRM System
 * 
 * Handles license validation, creation, and management
 * Supports both online and offline validation
 */

class LicenseManager {
    private $db;
    private $table = 'licenses';
    
    // License types
    const LICENSE_TYPES = ['trial', 'monthly', 'yearly'];
    
    // License status
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_SUSPENDED = 'suspended';
    
    // Default limits
    const DEFAULT_LIMITS = [
        'trial' => [
            'max_users' => 3,
            'max_warehouses' => 1,
            'max_slots_per_day' => 20,
            'duration_days' => 30
        ],
        'monthly' => [
            'max_users' => 10,
            'max_warehouses' => 5,
            'max_slots_per_day' => 100,
            'duration_days' => 30
        ],
        'yearly' => [
            'max_users' => 50,
            'max_warehouses' => 20,
            'max_slots_per_day' => 500,
            'duration_days' => 365
        ]
    ];
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Generate new license for company
     */
    public function generateLicense($company_id, $license_type = 'trial', $custom_limits = null) {
        if (!in_array($license_type, self::LICENSE_TYPES)) {
            throw new Exception("Invalid license type: $license_type");
        }
        
        try {
            // Generate unique license key
            $license_key = $this->generateLicenseKey($company_id, $license_type);
            
            // Get license limits
            $limits = $custom_limits ?? self::DEFAULT_LIMITS[$license_type];
            
            // Calculate validity dates
            $valid_from = date('Y-m-d');
            $valid_until = date('Y-m-d', strtotime("+{$limits['duration_days']} days"));
            
            // Prepare features JSON
            $features = [
                'api_access' => true,
                'webhook_support' => $license_type !== 'trial',
                'custom_branding' => $license_type === 'yearly',
                'advanced_reporting' => $license_type !== 'trial',
                'email_notifications' => true,
                'sms_notifications' => $license_type === 'yearly',
                'mobile_app_access' => true,
                'integrations' => $license_type !== 'trial'
            ];
            
            $query = "INSERT INTO {$this->table} (
                        company_id, license_key, license_type, max_users, max_warehouses, 
                        max_slots_per_day, features, valid_from, valid_until, is_active, payment_status
                      ) VALUES (
                        :company_id, :license_key, :license_type, :max_users, :max_warehouses,
                        :max_slots_per_day, :features, :valid_from, :valid_until, 1, :payment_status
                      )";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->bindParam(':license_key', $license_key);
            $stmt->bindParam(':license_type', $license_type);
            $stmt->bindParam(':max_users', $limits['max_users']);
            $stmt->bindParam(':max_warehouses', $limits['max_warehouses']);
            $stmt->bindParam(':max_slots_per_day', $limits['max_slots_per_day']);
            $stmt->bindParam(':features', json_encode($features));
            $stmt->bindParam(':valid_from', $valid_from);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':payment_status', $license_type === 'trial' ? 'paid' : 'pending');
            
            if ($stmt->execute()) {
                $license_id = $this->db->lastInsertId();
                
                // Log license creation
                $this->logLicenseAction($license_id, 'license_created', [
                    'company_id' => $company_id,
                    'license_type' => $license_type,
                    'valid_until' => $valid_until
                ]);
                
                return [
                    'success' => true,
                    'license_id' => $license_id,
                    'license_key' => $license_key,
                    'valid_until' => $valid_until,
                    'limits' => $limits,
                    'features' => $features
                ];
            }
            
            throw new Exception("Failed to create license");
            
        } catch (PDOException $e) {
            error_log("License generation error: " . $e->getMessage());
            throw new Exception("Database error during license generation");
        }
    }
    
    /**
     * Validate license (online mode)
     */
    public function validateLicense($company_id, $license_key = null) {
        try {
            $query = "SELECT l.*, c.name as company_name, c.status as company_status 
                     FROM {$this->table} l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.company_id = :company_id AND l.is_active = 1";
            
            if ($license_key) {
                $query .= " AND l.license_key = :license_key";
            }
            
            $query .= " ORDER BY l.valid_until DESC LIMIT 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            if ($license_key) {
                $stmt->bindParam(':license_key', $license_key);
            }
            $stmt->execute();
            
            $license = $stmt->fetch();
            
            if (!$license) {
                return [
                    'valid' => false,
                    'error' => 'License not found',
                    'code' => 'LICENSE_NOT_FOUND'
                ];
            }
            
            // Check company status
            if ($license['company_status'] !== 'active') {
                return [
                    'valid' => false,
                    'error' => 'Company is not active',
                    'code' => 'COMPANY_INACTIVE'
                ];
            }
            
            // Check expiration
            if (strtotime($license['valid_until']) < time()) {
                // Update license status
                $this->updateLicenseStatus($license['id'], false);
                
                return [
                    'valid' => false,
                    'error' => 'License expired',
                    'code' => 'LICENSE_EXPIRED',
                    'expired_date' => $license['valid_until']
                ];
            }
            
            // Check payment status
            if ($license['payment_status'] === 'overdue') {
                return [
                    'valid' => false,
                    'error' => 'Payment overdue',
                    'code' => 'PAYMENT_OVERDUE'
                ];
            }
            
            // Check usage limits
            $usage = $this->getCurrentUsage($company_id);
            $limits_exceeded = $this->checkLimitsExceeded($license, $usage);
            
            if (!empty($limits_exceeded)) {
                return [
                    'valid' => false,
                    'error' => 'License limits exceeded',
                    'code' => 'LIMITS_EXCEEDED',
                    'exceeded_limits' => $limits_exceeded,
                    'usage' => $usage,
                    'limits' => [
                        'max_users' => $license['max_users'],
                        'max_warehouses' => $license['max_warehouses'],
                        'max_slots_per_day' => $license['max_slots_per_day']
                    ]
                ];
            }
            
            return [
                'valid' => true,
                'license' => $license,
                'usage' => $usage,
                'features' => json_decode($license['features'], true),
                'expires_in_days' => ceil((strtotime($license['valid_until']) - time()) / 86400)
            ];
            
        } catch (PDOException $e) {
            error_log("License validation error: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => 'Database error during validation',
                'code' => 'DATABASE_ERROR'
            ];
        }
    }
    
    /**
     * Validate license (offline mode - cached)
     */
    public function validateLicenseOffline($company_id, $license_key) {
        $cache_file = sys_get_temp_dir() . '/license_cache_' . md5($company_id . $license_key);
        
        if (file_exists($cache_file)) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            
            if ($cached_data && isset($cached_data['valid_until'])) {
                // Check if cache is still valid (allow 24 hours grace period)
                $cache_valid_until = strtotime($cached_data['valid_until']) + 86400;
                
                if (time() < $cache_valid_until) {
                    return [
                        'valid' => true,
                        'license' => $cached_data,
                        'offline_mode' => true,
                        'expires_in_days' => ceil((strtotime($cached_data['valid_until']) - time()) / 86400)
                    ];
                }
            }
        }
        
        // Try online validation and cache result
        $validation = $this->validateLicense($company_id, $license_key);
        
        if ($validation['valid']) {
            file_put_contents($cache_file, json_encode($validation['license']));
        }
        
        return $validation;
    }
    
    /**
     * Extend license validity
     */
    public function extendLicense($company_id, $days, $note = null) {
        try {
            $query = "SELECT id, valid_until FROM {$this->table} 
                     WHERE company_id = :company_id AND is_active = 1 
                     ORDER BY valid_until DESC LIMIT 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();
            
            $license = $stmt->fetch();
            
            if (!$license) {
                throw new Exception("No active license found for company");
            }
            
            // Calculate new expiration date
            $current_expiry = strtotime($license['valid_until']);
            $new_expiry = date('Y-m-d', $current_expiry + ($days * 86400));
            
            $query = "UPDATE {$this->table} SET 
                        valid_until = :valid_until,
                        notes = CONCAT(IFNULL(notes, ''), :note),
                        updated_at = NOW()
                      WHERE id = :license_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':license_id', $license['id']);
            $stmt->bindParam(':valid_until', $new_expiry);
            $stmt->bindParam(':note', "\n" . date('Y-m-d H:i:s') . ": Extended by $days days. " . ($note ?? ''));
            
            if ($stmt->execute()) {
                $this->logLicenseAction($license['id'], 'license_extended', [
                    'days_extended' => $days,
                    'new_expiry' => $new_expiry,
                    'note' => $note
                ]);
                
                return [
                    'success' => true,
                    'new_expiry' => $new_expiry,
                    'message' => "License extended by $days days until $new_expiry"
                ];
            }
            
            throw new Exception("Failed to extend license");
            
        } catch (Exception $e) {
            error_log("License extension error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get license information
     */
    public function getLicenseInfo($company_id) {
        try {
            $query = "SELECT l.*, c.name as company_name 
                     FROM {$this->table} l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.company_id = :company_id 
                     ORDER BY l.created_at DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();
            
            $licenses = $stmt->fetchAll();
            
            if (empty($licenses)) {
                return null;
            }
            
            // Get current usage
            $usage = $this->getCurrentUsage($company_id);
            
            // Process license data
            foreach ($licenses as &$license) {
                $license['features'] = json_decode($license['features'], true);
                $license['is_expired'] = strtotime($license['valid_until']) < time();
                $license['expires_in_days'] = ceil((strtotime($license['valid_until']) - time()) / 86400);
                $license['usage'] = $usage;
            }
            
            return $licenses;
            
        } catch (PDOException $e) {
            error_log("Get license info error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Suspend license
     */
    public function suspendLicense($company_id, $reason = null) {
        try {
            $query = "UPDATE {$this->table} SET 
                        is_active = 0,
                        notes = CONCAT(IFNULL(notes, ''), :suspension_note),
                        updated_at = NOW()
                      WHERE company_id = :company_id AND is_active = 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->bindParam(':suspension_note', "\n" . date('Y-m-d H:i:s') . ": License suspended. " . ($reason ?? ''));
            
            if ($stmt->execute()) {
                $this->logLicenseAction(null, 'license_suspended', [
                    'company_id' => $company_id,
                    'reason' => $reason
                ]);
                
                return ['success' => true, 'message' => 'License suspended'];
            }
            
            throw new Exception("Failed to suspend license");
            
        } catch (Exception $e) {
            error_log("License suspension error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Reactivate license
     */
    public function reactivateLicense($company_id) {
        try {
            $query = "UPDATE {$this->table} SET 
                        is_active = 1,
                        notes = CONCAT(IFNULL(notes, ''), :reactivation_note),
                        updated_at = NOW()
                      WHERE company_id = :company_id AND valid_until > CURDATE()";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->bindParam(':reactivation_note', "\n" . date('Y-m-d H:i:s') . ": License reactivated.");
            
            if ($stmt->execute()) {
                $this->logLicenseAction(null, 'license_reactivated', [
                    'company_id' => $company_id
                ]);
                
                return ['success' => true, 'message' => 'License reactivated'];
            }
            
            throw new Exception("Failed to reactivate license");
            
        } catch (Exception $e) {
            error_log("License reactivation error: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Private helper methods
    
    private function generateLicenseKey($company_id, $license_type) {
        $prefix = strtoupper(substr($license_type, 0, 1));
        $timestamp = time();
        $random = bin2hex(random_bytes(4));
        
        return sprintf('%s-%04d-%08X-%s', $prefix, $company_id, $timestamp, $random);
    }
    
    private function getCurrentUsage($company_id) {
        try {
            $usage = [
                'users' => 0,
                'warehouses' => 0,
                'slots_today' => 0,
                'active_bookings' => 0
            ];
            
            // Count active users
            $query = "SELECT COUNT(*) FROM users WHERE company_id = :company_id AND is_active = 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();
            $usage['users'] = (int)$stmt->fetchColumn();
            
            // Count active warehouses
            $query = "SELECT COUNT(*) FROM warehouses WHERE company_id = :company_id AND is_active = 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();
            $usage['warehouses'] = (int)$stmt->fetchColumn();
            
            // Count today's slots
            $query = "SELECT COUNT(*) FROM time_slots ts 
                     JOIN warehouses w ON ts.warehouse_id = w.id 
                     WHERE w.company_id = :company_id AND ts.slot_date = CURDATE()";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();
            $usage['slots_today'] = (int)$stmt->fetchColumn();
            
            // Count active bookings
            $query = "SELECT COUNT(*) FROM bookings 
                     WHERE company_id = :company_id AND status IN ('pending', 'approved', 'confirmed', 'in_progress')";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();
            $usage['active_bookings'] = (int)$stmt->fetchColumn();
            
            return $usage;
            
        } catch (PDOException $e) {
            error_log("Get current usage error: " . $e->getMessage());
            return [
                'users' => 0,
                'warehouses' => 0,
                'slots_today' => 0,
                'active_bookings' => 0
            ];
        }
    }
    
    private function checkLimitsExceeded($license, $usage) {
        $exceeded = [];
        
        if ($usage['users'] > $license['max_users']) {
            $exceeded[] = 'users';
        }
        
        if ($usage['warehouses'] > $license['max_warehouses']) {
            $exceeded[] = 'warehouses';
        }
        
        if ($usage['slots_today'] > $license['max_slots_per_day']) {
            $exceeded[] = 'slots_per_day';
        }
        
        return $exceeded;
    }
    
    private function updateLicenseStatus($license_id, $is_active) {
        try {
            $query = "UPDATE {$this->table} SET is_active = :is_active WHERE id = :license_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':license_id', $license_id);
            $stmt->bindParam(':is_active', $is_active ? 1 : 0);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Update license status error: " . $e->getMessage());
        }
    }
    
    private function logLicenseAction($license_id, $action, $data = null) {
        try {
            $query = "INSERT INTO audit_log (action, entity_type, entity_id, new_values, ip_address) 
                     VALUES (:action, 'license', :entity_id, :new_values, :ip_address)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':entity_id', $license_id);
            $stmt->bindParam(':new_values', json_encode($data));
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("License action log error: " . $e->getMessage());
        }
    }
    
    /**
     * Get license expiration warnings
     */
    public function getLicenseWarnings($days_ahead = 30) {
        try {
            $query = "SELECT l.*, c.name as company_name, c.contact_email 
                     FROM {$this->table} l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.is_active = 1 
                     AND l.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days_ahead DAY)
                     ORDER BY l.valid_until ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':days_ahead', $days_ahead);
            $stmt->execute();
            
            $warnings = $stmt->fetchAll();
            
            foreach ($warnings as &$warning) {
                $warning['days_until_expiry'] = ceil((strtotime($warning['valid_until']) - time()) / 86400);
                $warning['features'] = json_decode($warning['features'], true);
            }
            
            return $warnings;
            
        } catch (PDOException $e) {
            error_log("Get license warnings error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if feature is available for license
     */
    public function hasFeature($company_id, $feature_name) {
        $validation = $this->validateLicense($company_id);
        
        if (!$validation['valid']) {
            return false;
        }
        
        $features = $validation['features'] ?? [];
        return isset($features[$feature_name]) && $features[$feature_name] === true;
    }
    
    /**
     * Get all licenses (for admin)
     */
    public function getAllLicenses($filters = []) {
        try {
            $query = "SELECT l.*, c.name as company_name, c.contact_email 
                     FROM {$this->table} l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE 1=1";
            
            $params = [];
            
            if (isset($filters['license_type'])) {
                $query .= " AND l.license_type = :license_type";
                $params[':license_type'] = $filters['license_type'];
            }
            
            if (isset($filters['is_active'])) {
                $query .= " AND l.is_active = :is_active";
                $params[':is_active'] = $filters['is_active'];
            }
            
            if (isset($filters['expires_within_days'])) {
                $query .= " AND l.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :expires_within_days DAY)";
                $params[':expires_within_days'] = $filters['expires_within_days'];
            }
            
            $query .= " ORDER BY l.created_at DESC";
            
            if (isset($filters['limit'])) {
                $query .= " LIMIT :limit";
                $params[':limit'] = $filters['limit'];
            }
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value);
            }
            $stmt->execute();
            
            $licenses = $stmt->fetchAll();
            
            foreach ($licenses as &$license) {
                $license['features'] = json_decode($license['features'], true);
                $license['is_expired'] = strtotime($license['valid_until']) < time();
                $license['expires_in_days'] = ceil((strtotime($license['valid_until']) - time()) / 86400);
            }
            
            return $licenses;
            
        } catch (PDOException $e) {
            error_log("Get all licenses error: " . $e->getMessage());
            return [];
        }
    }
}