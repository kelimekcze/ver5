<?php
/**
 * Slots API Endpoint
 * Logistic CRM System
 * 
 * Handles time slot management operations
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
    require_once __DIR__ . '/../classes/SlotManager.php';
    require_once __DIR__ . '/../middleware/auth.php';
    require_once __DIR__ . '/../middleware/license_check.php';
    
    // Authenticate user
    $current_user = authenticate();
    
    // Database connection
    $database = new Database();
    $db = $database->connect();
    $slotManager = new SlotManager($db);
    
    // Check license for company users
    if ($current_user['company_id']) {
        licenseRequiredMiddleware($current_user['company_id']);
    }
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetSlots($slotManager, $current_user);
            break;
            
        case 'POST':
            handleCreateSlot($slotManager, $current_user);
            break;
            
        case 'PUT':
            handleUpdateSlot($slotManager, $current_user);
            break;
            
        case 'DELETE':
            handleDeleteSlot($slotManager, $current_user);
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
    error_log("Slots API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'code' => 'SERVER_ERROR',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle GET requests - list slots
 */
function handleGetSlots($slotManager, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'slots', 'read');
        
        // Handle special endpoints
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'today':
                    handleTodaysSlots($slotManager, $current_user);
                    return;
                case 'available':
                    handleAvailableSlots($slotManager, $current_user);
                    return;
                case 'statistics':
                    handleSlotStatistics($slotManager, $current_user);
                    return;
            }
        }
        
        // Get query parameters
        $startDate = $_GET['start_date'] ?? date('Y-m-d');
        $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
        $warehouseId = $_GET['warehouse_id'] ?? null;
        
        // Filters
        $filters = [];
        if (isset($_GET['slot_type'])) {
            $filters['slot_type'] = $_GET['slot_type'];
        }
        if (isset($_GET['is_blocked'])) {
            $filters['is_blocked'] = $_GET['is_blocked'] === '1';
        }
        
        // Get slots
        $slots = $slotManager->getSlotsByDateRange($startDate, $endDate, $warehouseId, $filters);
        
        echo json_encode([
            'success' => true,
            'slots' => $slots,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warehouse_id' => $warehouseId,
                'slot_type' => $filters['slot_type'] ?? null,
                'is_blocked' => $filters['is_blocked'] ?? null
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Get slots error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get slots',
            'code' => 'GET_SLOTS_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle today's slots
 */
function handleTodaysSlots($slotManager, $current_user) {
    try {
        $today = date('Y-m-d');
        $warehouseId = $_GET['warehouse_id'] ?? null;
        
        $slots = $slotManager->getSlotsByDateRange($today, $today, $warehouseId);
        
        // Group by time for timeline view
        $timeline = [];
        foreach ($slots as $slot) {
            $time = substr($slot['slot_time_start'], 0, 5);
            if (!isset($timeline[$time])) {
                $timeline[$time] = [];
            }
            $timeline[$time][] = $slot;
        }
        
        echo json_encode([
            'success' => true,
            'date' => $today,
            'slots' => $slots,
            'timeline' => $timeline
        ]);
        
    } catch (Exception $e) {
        error_log("Get today's slots error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get today\'s slots',
            'code' => 'GET_TODAYS_SLOTS_FAILED'
        ]);
    }
}

/**
 * Handle available slots
 */
function handleAvailableSlots($slotManager, $current_user) {
    try {
        $warehouseId = $_GET['warehouse_id'] ?? null;
        $date = $_GET['date'] ?? date('Y-m-d');
        $slotType = $_GET['slot_type'] ?? null;
        
        if (!$warehouseId) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Warehouse ID is required',
                'code' => 'MISSING_WAREHOUSE_ID'
            ]);
            return;
        }
        
        $slots = $slotManager->getAvailableSlots($warehouseId, $date, $slotType);
        
        echo json_encode([
            'success' => true,
            'available_slots' => $slots,
            'warehouse_id' => $warehouseId,
            'date' => $date,
            'slot_type' => $slotType
        ]);
        
    } catch (Exception $e) {
        error_log("Get available slots error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get available slots',
            'code' => 'GET_AVAILABLE_SLOTS_FAILED'
        ]);
    }
}

/**
 * Handle slot statistics
 */
function handleSlotStatistics($slotManager, $current_user) {
    try {
        $warehouseId = $_GET['warehouse_id'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        
        $statistics = $slotManager->getSlotStatistics($warehouseId, $dateFrom, $dateTo);
        
        echo json_encode([
            'success' => true,
            'statistics' => $statistics,
            'filters' => [
                'warehouse_id' => $warehouseId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Get slot statistics error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get slot statistics',
            'code' => 'GET_SLOT_STATISTICS_FAILED'
        ]);
    }
}

/**
 * Handle POST requests - create slot
 */
function handleCreateSlot($slotManager, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'slots', 'create');
        
        // Check usage limits
        if ($current_user['company_id']) {
            usageLimitMiddleware($current_user['company_id'], 'slots_today');
        }
        
        // Get and validate input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        // Validate required fields
        $required_fields = ['warehouse_id', 'slot_date', 'slot_time_start', 'slot_time_end'];
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
        
        // Add created_by
        $input['created_by'] = $current_user['user_id'];
        
        // Create slot
        $result = $slotManager->createSlot($input);
        
        if ($result['success']) {
            // Log slot creation
            logUserActivity($current_user['user_id'], 'slot_created', [
                'slot_id' => $result['slot_id'],
                'warehouse_id' => $input['warehouse_id'],
                'slot_date' => $input['slot_date'],
                'slot_time' => $input['slot_time_start'] . '-' . $input['slot_time_end']
            ]);
            
            echo json_encode([
                'success' => true,
                'slot_id' => $result['slot_id'],
                'message' => 'Slot was created successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Slot creation failed',
                'code' => 'SLOT_CREATION_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Create slot error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create slot',
            'code' => 'CREATE_SLOT_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle PUT requests - update slot
 */
function handleUpdateSlot($slotManager, $current_user) {
    try {
        // Get and validate input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['slot_id'])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Slot ID is required',
                'code' => 'MISSING_SLOT_ID'
            ]);
            return;
        }
        
        $slotId = intval($input['slot_id']);
        
        // Get target slot
        $targetSlot = $slotManager->getSlotById($slotId);
        if (!$targetSlot) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Slot not found',
                'code' => 'SLOT_NOT_FOUND'
            ]);
            return;
        }
        
        // Check permissions
        $can_update = false;
        
        if ($current_user['user_type'] === 'super_admin') {
            $can_update = true;
        } elseif (in_array($current_user['user_type'], ['admin', 'logistics'])) {
            // Check if slot belongs to user's company warehouse
            $warehouse_query = "SELECT company_id FROM warehouses WHERE id = :warehouse_id";
            $warehouse_stmt = $slotManager->db->prepare($warehouse_query);
            $warehouse_stmt->bindParam(':warehouse_id', $targetSlot['warehouse_id']);
            $warehouse_stmt->execute();
            $warehouse = $warehouse_stmt->fetch();
            
            if ($warehouse && $warehouse['company_id'] == $current_user['company_id']) {
                $can_update = true;
            }
        }
        
        if (!$can_update) {
            http_response_code(403);
            echo json_encode([
                'error' => 'You cannot update this slot',
                'code' => 'UPDATE_SLOT_FORBIDDEN'
            ]);
            return;
        }
        
        // Handle special actions
        if (isset($input['action'])) {
            switch ($input['action']) {
                case 'block':
                    $result = $slotManager->blockSlot($slotId, $input['reason'] ?? null);
                    break;
                case 'unblock':
                    $result = $slotManager->unblockSlot($slotId);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Invalid action',
                        'code' => 'INVALID_ACTION'
                    ]);
                    return;
            }
        } else {
            // Regular update
            $result = $slotManager->updateSlot($slotId, $input);
        }
        
        if ($result['success']) {
            // Log slot update
            logUserActivity($current_user['user_id'], 'slot_updated', [
                'slot_id' => $slotId,
                'action' => $input['action'] ?? 'update',
                'changes' => $input
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Slot was updated successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Slot update failed',
                'code' => 'SLOT_UPDATE_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Update slot error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to update slot',
            'code' => 'UPDATE_SLOT_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle DELETE requests - delete slot
 */
function handleDeleteSlot($slotManager, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'slots', 'delete');
        
        // Get slot ID from URL
        $slotId = intval($_GET['slot_id'] ?? 0);
        if (!$slotId) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Slot ID is required',
                'code' => 'MISSING_SLOT_ID'
            ]);
            return;
        }
        
        // Get target slot
        $targetSlot = $slotManager->getSlotById($slotId);
        if (!$targetSlot) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Slot not found',
                'code' => 'SLOT_NOT_FOUND'
            ]);
            return;
        }
        
        // Check company access for non-super-admin users
        if ($current_user['user_type'] !== 'super_admin') {
            $warehouse_query = "SELECT company_id FROM warehouses WHERE id = :warehouse_id";
            $warehouse_stmt = $slotManager->db->prepare($warehouse_query);
            $warehouse_stmt->bindParam(':warehouse_id', $targetSlot['warehouse_id']);
            $warehouse_stmt->execute();
            $warehouse = $warehouse_stmt->fetch();
            
            if (!$warehouse || $warehouse['company_id'] != $current_user['company_id']) {
                http_response_code(403);
                echo json_encode([
                    'error' => 'You cannot delete this slot',
                    'code' => 'DELETE_SLOT_FORBIDDEN'
                ]);
                return;
            }
        }
        
        // Delete slot
        $result = $slotManager->deleteSlot($slotId);
        
        if ($result['success']) {
            // Log slot deletion
            logUserActivity($current_user['user_id'], 'slot_deleted', [
                'slot_id' => $slotId,
                'warehouse_id' => $targetSlot['warehouse_id'],
                'slot_date' => $targetSlot['slot_date']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Slot was deleted successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Slot deletion failed',
                'code' => 'SLOT_DELETION_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Delete slot error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to delete slot',
            'code' => 'DELETE_SLOT_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}