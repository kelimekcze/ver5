<?php
/**
 * License Check Middleware
 * Logistic CRM System
 * 
 * Validates company licenses and enforces usage limits
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LicenseManager.php';

/**
 * Check license validity for a company
 */
function checkLicenseMiddleware($company_id) {
    if (!$company_id) {
        throw new Exception("Company ID is required for license check");
    }
    
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        // Validate license
        $validation = $licenseManager->validateLicense($company_id);
        
        if (!$validation['valid']) {
            // Log license violation
            error_log("License validation failed for company $company_id: " . json_encode($validation));
            
            // Handle different types of license errors
            switch ($validation['code']) {
                case 'LICENSE_NOT_FOUND':
                    throw new Exception("No valid license found for this company");
                    
                case 'LICENSE_EXPIRED':
                    throw new Exception("License expired on " . $validation['expired_date']);
                    
                case 'COMPANY_INACTIVE':
                    throw new Exception("Company account is inactive");
                    
                case 'PAYMENT_OVERDUE':
                    throw new Exception("License payment is overdue");
                    
                case 'LIMITS_EXCEEDED':
                    $limits = $validation['exceeded_limits'];
                    throw new Exception("License limits exceeded: " . implode(', ', $limits));
                    
                default:
                    throw new Exception("License validation failed: " . $validation['error']);
            }
        }
        
        // Store license info in session for quick access
        $_SESSION['license_info'] = [
            'valid_until' => $validation['license']['valid_until'],
            'license_type' => $validation['license']['license_type'],
            'features' => $validation['features'],
            'usage' => $validation['usage'],
            'expires_in_days' => $validation['expires_in_days']
        ];
        
        // Check if license is expiring soon and log warning
        if ($validation['expires_in_days'] <= 7) {
            error_log("License expiring soon for company $company_id: {$validation['expires_in_days']} days remaining");
        }
        
        return $validation;
        
    } catch (Exception $e) {
        error_log("License check error for company $company_id: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Check if specific feature is available
 */
function checkFeatureAccess($company_id, $feature_name) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        return $licenseManager->hasFeature($company_id, $feature_name);
        
    } catch (Exception $e) {
        error_log("Feature access check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Require specific feature or throw exception
 */
function requireFeatureAccess($company_id, $feature_name) {
    if (!checkFeatureAccess($company_id, $feature_name)) {
        throw new Exception("Feature '$feature_name' is not available in your license");
    }
}

/**
 * Check usage limits before allowing action
 */
function checkUsageLimits($company_id, $limit_type) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        $validation = $licenseManager->validateLicense($company_id);
        
        if (!$validation['valid']) {
            return false;
        }
        
        $license = $validation['license'];
        $usage = $validation['usage'];
        
        switch ($limit_type) {
            case 'users':
                return $usage['users'] < $license['max_users'];
                
            case 'warehouses':
                return $usage['warehouses'] < $license['max_warehouses'];
                
            case 'slots_today':
                return $usage['slots_today'] < $license['max_slots_per_day'];
                
            default:
                return true;
        }
        
    } catch (Exception $e) {
        error_log("Usage limit check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Require usage limit or throw exception
 */
function requireUsageLimit($company_id, $limit_type) {
    if (!checkUsageLimits($company_id, $limit_type)) {
        $limit_names = [
            'users' => 'maximum number of users',
            'warehouses' => 'maximum number of warehouses',
            'slots_today' => 'daily slot limit'
        ];
        
        $limit_name = $limit_names[$limit_type] ?? $limit_type;
        throw new Exception("You have reached the $limit_name for your license");
    }
}

/**
 * Get license information for display
 */
function getLicenseInfo($company_id) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        return $licenseManager->getLicenseInfo($company_id);
        
    } catch (Exception $e) {
        error_log("Get license info error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if company needs license upgrade
 */
function needsLicenseUpgrade($company_id) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        $validation = $licenseManager->validateLicense($company_id);
        
        if (!$validation['valid']) {
            return true;
        }
        
        $license = $validation['license'];
        $usage = $validation['usage'];
        
        // Check if usage is approaching limits (80% threshold)
        $approaching_limits = [];
        
        if ($usage['users'] / $license['max_users'] >= 0.8) {
            $approaching_limits[] = 'users';
        }
        
        if ($usage['warehouses'] / $license['max_warehouses'] >= 0.8) {
            $approaching_limits[] = 'warehouses';
        }
        
        if ($usage['slots_today'] / $license['max_slots_per_day'] >= 0.8) {
            $approaching_limits[] = 'slots_today';
        }
        
        return !empty($approaching_limits);
        
    } catch (Exception $e) {
        error_log("License upgrade check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get license usage statistics
 */
function getLicenseUsage($company_id) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        $validation = $licenseManager->validateLicense($company_id);
        
        if (!$validation['valid']) {
            return null;
        }
        
        $license = $validation['license'];
        $usage = $validation['usage'];
        
        return [
            'users' => [
                'current' => $usage['users'],
                'limit' => $license['max_users'],
                'percentage' => round(($usage['users'] / $license['max_users']) * 100, 2)
            ],
            'warehouses' => [
                'current' => $usage['warehouses'],
                'limit' => $license['max_warehouses'],
                'percentage' => round(($usage['warehouses'] / $license['max_warehouses']) * 100, 2)
            ],
            'slots_today' => [
                'current' => $usage['slots_today'],
                'limit' => $license['max_slots_per_day'],
                'percentage' => round(($usage['slots_today'] / $license['max_slots_per_day']) * 100, 2)
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Get license usage error: " . $e->getMessage());
        return null;
    }
}

/**
 * Offline license validation for when database is not available
 */
function checkLicenseOffline($company_id, $license_key) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        return $licenseManager->validateLicenseOffline($company_id, $license_key);
        
    } catch (Exception $e) {
        error_log("Offline license check error: " . $e->getMessage());
        
        // Try to read from cache file
        $cache_file = sys_get_temp_dir() . '/license_cache_' . md5($company_id . $license_key);
        
        if (file_exists($cache_file)) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            
            if ($cached_data && isset($cached_data['valid_until'])) {
                $cache_valid_until = strtotime($cached_data['valid_until']);
                
                if (time() < $cache_valid_until) {
                    return [
                        'valid' => true,
                        'license' => $cached_data,
                        'offline_mode' => true,
                        'expires_in_days' => ceil(($cache_valid_until - time()) / 86400)
                    ];
                }
            }
        }
        
        return [
            'valid' => false,
            'error' => 'Cannot validate license offline',
            'code' => 'OFFLINE_VALIDATION_FAILED'
        ];
    }
}

/**
 * Auto-check license on API requests
 */
function autoCheckLicense() {
    // Only check for authenticated users with company_id
    if (isset($_SESSION['company_id']) && $_SESSION['company_id']) {
        try {
            checkLicenseMiddleware($_SESSION['company_id']);
        } catch (Exception $e) {
            // Log the error but don't block the request for now
            error_log("Auto license check failed: " . $e->getMessage());
            
            // Uncomment to enforce strict license checking
            /*
            http_response_code(403);
            echo json_encode([
                'error' => 'License validation failed',
                'code' => 'LICENSE_INVALID',
                'message' => $e->getMessage()
            ]);
            exit;
            */
        }
    }
}

/**
 * License warning notifications
 */
function getLicenseWarnings($company_id) {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        $validation = $licenseManager->validateLicense($company_id);
        
        $warnings = [];
        
        if ($validation['valid']) {
            $license = $validation['license'];
            $usage = $validation['usage'];
            $expires_in_days = $validation['expires_in_days'];
            
            // Expiration warnings
            if ($expires_in_days <= 3) {
                $warnings[] = [
                    'type' => 'critical',
                    'message' => "License expires in $expires_in_days days!",
                    'action' => 'renew_license'
                ];
            } elseif ($expires_in_days <= 7) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "License expires in $expires_in_days days",
                    'action' => 'renew_license'
                ];
            } elseif ($expires_in_days <= 30) {
                $warnings[] = [
                    'type' => 'info',
                    'message' => "License expires in $expires_in_days days",
                    'action' => 'renew_license'
                ];
            }
            
            // Usage warnings
            if ($usage['users'] / $license['max_users'] >= 0.9) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "You're using {$usage['users']} of {$license['max_users']} allowed users",
                    'action' => 'upgrade_license'
                ];
            }
            
            if ($usage['warehouses'] / $license['max_warehouses'] >= 0.9) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "You're using {$usage['warehouses']} of {$license['max_warehouses']} allowed warehouses",
                    'action' => 'upgrade_license'
                ];
            }
            
            if ($usage['slots_today'] / $license['max_slots_per_day'] >= 0.9) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => "You're using {$usage['slots_today']} of {$license['max_slots_per_day']} daily slots",
                    'action' => 'upgrade_license'
                ];
            }
        } else {
            // License invalid
            $warnings[] = [
                'type' => 'critical',
                'message' => 'License validation failed: ' . $validation['error'],
                'action' => 'contact_support'
            ];
        }
        
        return $warnings;
        
    } catch (Exception $e) {
        error_log("Get license warnings error: " . $e->getMessage());
        return [[
            'type' => 'error',
            'message' => 'Unable to check license status',
            'action' => 'contact_support'
        ]];
    }
}

/**
 * Middleware for API endpoints that require license validation
 */
function licenseRequiredMiddleware($company_id = null) {
    // Get company ID from session if not provided
    if (!$company_id && isset($_SESSION['company_id'])) {
        $company_id = $_SESSION['company_id'];
    }
    
    if (!$company_id) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Company ID required for license validation',
            'code' => 'COMPANY_ID_REQUIRED'
        ]);
        exit;
    }
    
    try {
        checkLicenseMiddleware($company_id);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode([
            'error' => 'License validation failed',
            'code' => 'LICENSE_INVALID',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/**
 * Middleware for features that require specific license features
 */
function featureRequiredMiddleware($company_id, $feature_name) {
    try {
        requireFeatureAccess($company_id, $feature_name);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Feature not available',
            'code' => 'FEATURE_NOT_AVAILABLE',
            'message' => $e->getMessage(),
            'required_feature' => $feature_name
        ]);
        exit;
    }
}

/**
 * Middleware for actions that require usage limit checks
 */
function usageLimitMiddleware($company_id, $limit_type) {
    try {
        requireUsageLimit($company_id, $limit_type);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Usage limit exceeded',
            'code' => 'USAGE_LIMIT_EXCEEDED',
            'message' => $e->getMessage(),
            'limit_type' => $limit_type
        ]);
        exit;
    }
}

/**
 * Helper function to format license expiration date
 */
function formatLicenseExpiration($date) {
    $expiry = new DateTime($date);
    $now = new DateTime();
    $diff = $now->diff($expiry);
    
    if ($diff->invert) {
        return "Expired " . $diff->days . " days ago";
    } else {
        if ($diff->days == 0) {
            return "Expires today";
        } elseif ($diff->days == 1) {
            return "Expires tomorrow";
        } else {
            return "Expires in " . $diff->days . " days";
        }
    }
}

/**
 * Get license badge color based on status
 */
function getLicenseBadgeColor($expires_in_days) {
    if ($expires_in_days < 0) {
        return 'danger';  // Expired
    } elseif ($expires_in_days <= 3) {
        return 'danger';  // Critical
    } elseif ($expires_in_days <= 7) {
        return 'warning'; // Warning
    } elseif ($expires_in_days <= 30) {
        return 'info';    // Info
    } else {
        return 'success'; // Good
    }
}

/**
 * Generate license renewal URL
 */
function generateLicenseRenewalUrl($company_id, $license_key) {
    $base_url = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    
    return "{$protocol}://{$base_url}/license/renew?company_id={$company_id}&license_key={$license_key}";
}

/**
 * Send license expiration notification
 */
function sendLicenseExpirationNotification($company_id, $expires_in_days) {
    try {
        $database = new Database();
        $db = $database->connect();
        
        // Get company and license info
        $query = "SELECT c.name, c.contact_email, l.license_key, l.valid_until 
                 FROM companies c 
                 JOIN licenses l ON c.id = l.company_id 
                 WHERE c.id = :company_id AND l.is_active = 1 
                 ORDER BY l.valid_until DESC LIMIT 1";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':company_id', $company_id);
        $stmt->execute();
        
        $info = $stmt->fetch();
        
        if ($info && $info['contact_email']) {
            $subject = "License Expiration Notice - " . $info['name'];
            $renewal_url = generateLicenseRenewalUrl($company_id, $info['license_key']);
            
            if ($expires_in_days <= 0) {
                $message = "Your license has expired. Please renew immediately to continue using the service.";
            } else {
                $message = "Your license expires in {$expires_in_days} days. Please renew to avoid service interruption.";
            }
            
            $email_body = "
                <h2>License Expiration Notice</h2>
                <p>Dear {$info['name']},</p>
                <p>{$message}</p>
                <p><strong>License expires:</strong> {$info['valid_until']}</p>
                <p><a href='{$renewal_url}' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Renew License</a></p>
                <p>If you have any questions, please contact our support team.</p>
            ";
            
            // TODO: Implement actual email sending
            error_log("License expiration notification should be sent to: {$info['contact_email']}");
            
            // Log notification
            $query = "INSERT INTO notifications (company_id, type, title, message, action_url) 
                     VALUES (:company_id, 'email', :subject, :message, :action_url)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->bindParam(':subject', $subject);
            $stmt->bindParam(':message', $message);
            $stmt->bindParam(':action_url', $renewal_url);
            $stmt->execute();
        }
        
    } catch (Exception $e) {
        error_log("License expiration notification error: " . $e->getMessage());
    }
}

/**
 * Cron job function to check all licenses and send notifications
 */
function checkAllLicensesAndNotify() {
    try {
        $database = new Database();
        $db = $database->connect();
        $licenseManager = new LicenseManager($db);
        
        // Get licenses expiring in the next 30 days
        $warnings = $licenseManager->getLicenseWarnings(30);
        
        foreach ($warnings as $warning) {
            $company_id = $warning['company_id'];
            $expires_in_days = $warning['days_until_expiry'];
            
            // Send notifications at specific intervals
            if (in_array($expires_in_days, [30, 14, 7, 3, 1, 0])) {
                sendLicenseExpirationNotification($company_id, $expires_in_days);
            }
        }
        
        error_log("License check cron job completed. Processed " . count($warnings) . " licenses.");
        
    } catch (Exception $e) {
        error_log("License check cron job error: " . $e->getMessage());
    }
}

// Auto-initialize license checking for authenticated users
if (isset($_SESSION['company_id']) && $_SESSION['company_id']) {
    // Only auto-check if not already checked in this session
    if (!isset($_SESSION['license_checked']) || (time() - $_SESSION['license_checked']) > 3600) {
        try {
            autoCheckLicense();
            $_SESSION['license_checked'] = time();
        } catch (Exception $e) {
            error_log("Auto license check failed: " . $e->getMessage());
        }
    }
}