THIS SHOULD BE A LINTER ERROR<?php
/**
 * Bookings API Endpoint
 * Logistic CRM System
 * 
 * Handles booking management operations
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
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/BookingManager.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/license_check.php';
    
    // Authenticate user
    $current_user = authenticate();
    
    // Database connection
    $database = new Database();
    $db = $database->connect();
    $bookingManager = new BookingManager($db);
    
    // Check license for company users
    if ($current_user['company_id']) {
        licenseRequiredMiddleware($current_user['company_id']);
    }
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetBookings($bookingManager, $current_user);
            break;
            
        case 'POST':
            handleCreateBooking($bookingManager, $current_user);
            break;
            
        case 'PUT':
            handleUpdateBooking($bookingManager, $current_user);
            break;
            
        case 'DELETE':
            handleDeleteBooking($bookingManager, $current_user);
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
    error_log("Bookings API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'code' => 'SERVER_ERROR',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle GET requests - list bookings
 */
function handleGetBookings($bookingManager, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'bookings', 'read');
        
        // Handle special endpoints
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'upcoming':
                    handleUpcomingBookings($bookingManager, $current_user);
                    return;
                case 'today':
                    handleTodaysBookings($bookingManager, $current_user);
                    return;
                case 'statistics':
                    handleBookingStatistics($bookingManager, $current_user);
                    return;
                case 'checkin':
                    handleCheckIn($bookingManager, $current_user);
                    return;
                case 'checkout':
                    handleCheckOut($bookingManager, $current_user);
                    return;
            }
        }
        
        // Handle single booking request
        if (isset($_GET['booking_id'])) {
            handleSingleBooking($bookingManager, $current_user, $_GET['booking_id']);
            return;
        }
        
        // Get query parameters
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
        
        // Build filters
        $filters = [];
        
        // Status filter
        if (isset($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        
        // Date range filter
        if (isset($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        if (isset($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }
        
        // Warehouse filter
        if (isset($_GET['warehouse_id'])) {
            $filters['warehouse_id'] = $_GET['warehouse_id'];
        }
        
        // Driver filter
        if (isset($_GET['driver_id'])) {
            $filters['driver_id'] = $_GET['driver_id'];
        }
        
        // Search filter
        if (isset($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }
        
        // Company filter (for non-super-admin users)
        if ($current_user['user_type'] !== 'super_admin') {
            $filters['company_id'] = $current_user['company_id'];
        } elseif (isset($_GET['company_id'])) {
            $filters['company_id'] = $_GET['company_id'];
        }
        
        // Driver can only see their own bookings
        if ($current_user['user_type'] === 'driver') {
            $filters['driver_id'] = $current_user['user_id'];
        }
        
        // Get bookings
        $result = $bookingManager->getBookings($filters, $page, $limit);
        
        echo json_encode([
            'success' => true,
            'bookings' => $result['bookings'],
            'pagination' => $result['pagination'],
            'filters' => $filters
        ]);
        
    } catch (Exception $e) {
        error_log("Get bookings error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get bookings',
            'code' => 'GET_BOOKINGS_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle single booking request
 */
function handleSingleBooking($bookingManager, $current_user, $bookingId) {
    try {
        $booking = $bookingManager->getBookingById($bookingId);
        
        if (!$booking) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Booking not found',
                'code' => 'BOOKING_NOT_FOUND'
            ]);
            return;
        }
        
        // Check access permissions
        if (!canModifyBooking($current_user, $booking)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Access denied',
                'code' => 'BOOKING_ACCESS_DENIED'
            ]);
            return;
        }
        
        // Get booking documents
        $documents = $bookingManager->getBookingDocuments($bookingId);
        
        echo json_encode([
            'success' => true,
            'booking' => $booking,
            'documents' => $documents
        ]);
        
    } catch (Exception $e) {
        error_log("Get single booking error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get booking',
            'code' => 'GET_BOOKING_FAILED'
        ]);
    }
}

/**
 * Handle upcoming bookings
 */
function handleUpcomingBookings($bookingManager, $current_user) {
    try {
        $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
        $companyId = $current_user['user_type'] === 'super_admin' ? null : $current_user['company_id'];
        
        $bookings = $bookingManager->getUpcomingBookings($limit, $companyId);
        
        echo json_encode([
            'success' => true,
            'upcoming_bookings' => $bookings,
            'count' => count($bookings)
        ]);
        
    } catch (Exception $e) {
        error_log("Get upcoming bookings error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get upcoming bookings',
            'code' => 'GET_UPCOMING_BOOKINGS_FAILED'
        ]);
    }
}

/**
 * Handle today's bookings
 */
function handleTodaysBookings($bookingManager, $current_user) {
    try {
        $companyId = $current_user['user_type'] === 'super_admin' ? null : $current_user['company_id'];
        
        $bookings = $bookingManager->getTodaysBookings($companyId);
        
        echo json_encode([
            'success' => true,
            'todays_bookings' => $bookings,
            'count' => count($bookings)
        ]);
        
    } catch (Exception $e) {
        error_log("Get today's bookings error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get today\'s bookings',
            'code' => 'GET_TODAYS_BOOKINGS_FAILED'
        ]);
    }
}

/**
 * Handle booking statistics
 */
function handleBookingStatistics($bookingManager, $current_user) {
    try {
        $companyId = $current_user['user_type'] === 'super_admin' ? ($_GET['company_id'] ?? null) : $current_user['company_id'];
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        
        $statistics = $bookingManager->getBookingStatistics($companyId, $dateFrom, $dateTo);
        
        echo json_encode([
            'success' => true,
            'statistics' => $statistics,
            'filters' => [
                'company_id' => $companyId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Get booking statistics error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get booking statistics',
            'code' => 'GET_BOOKING_STATISTICS_FAILED'
        ]);
    }
}

/**
 * Handle check-in
 */
function handleCheckIn($bookingManager, $current_user) {
    try {
        $bookingId = $_GET['booking_id'] ?? null;
        $qrCode = $_GET['qr_code'] ?? null;
        
        if (!$bookingId) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking ID is required',
                'code' => 'MISSING_BOOKING_ID'
            ]);
            return;
        }
        
        $result = $bookingManager->checkIn($bookingId, $qrCode);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Check-in successful'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Check-in failed',
                'code' => 'CHECKIN_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Check-in error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to check in',
            'code' => 'CHECKIN_ERROR'
        ]);
    }
}

/**
 * Handle check-out
 */
function handleCheckOut($bookingManager, $current_user) {
    try {
        $bookingId = $_GET['booking_id'] ?? null;
        $qrCode = $_GET['qr_code'] ?? null;
        
        if (!$bookingId) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking ID is required',
                'code' => 'MISSING_BOOKING_ID'
            ]);
            return;
        }
        
        $result = $bookingManager->checkOut($bookingId, $qrCode);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Check-out successful'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Check-out failed',
                'code' => 'CHECKOUT_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Check-out error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to check out',
            'code' => 'CHECKOUT_ERROR'
        ]);
    }
}

/**
 * Handle POST requests - create booking
 */
function handleCreateBooking($bookingManager, $current_user) {
    try {
        // Check permissions
        requirePermission($current_user['user_type'], 'bookings', 'create');
        
        // Get and validate input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        // Validate required fields
        $required_fields = ['time_slot_id'];
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
        
        // Set company_id and created_by
        if ($current_user['user_type'] === 'super_admin' && isset($input['company_id'])) {
            $input['company_id'] = $input['company_id'];
        } else {
            $input['company_id'] = $current_user['company_id'];
        }
        
        $input['created_by'] = $current_user['user_id'];
        
        // Create booking
        $result = $bookingManager->createBooking($input);
        
        if ($result['success']) {
            // Log booking creation
            logUserActivity($current_user['user_id'], 'booking_created', [
                'booking_id' => $result['booking_id'],
                'booking_number' => $result['booking_number'],
                'time_slot_id' => $input['time_slot_id']
            ]);
            
            echo json_encode([
                'success' => true,
                'booking_id' => $result['booking_id'],
                'booking_number' => $result['booking_number'],
                'qr_code' => $result['qr_code'],
                'requires_approval' => $result['requires_approval'],
                'message' => 'Booking created successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking creation failed',
                'code' => 'BOOKING_CREATION_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Create booking error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create booking',
            'code' => 'CREATE_BOOKING_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle PUT requests - update booking
 */
function handleUpdateBooking($bookingManager, $current_user) {
    try {
        // Get and validate input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['booking_id'])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking ID is required',
                'code' => 'MISSING_BOOKING_ID'
            ]);
            return;
        }
        
        $bookingId = intval($input['booking_id']);
        
        // Get target booking
        $targetBooking = $bookingManager->getBookingById($bookingId);
        if (!$targetBooking) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Booking not found',
                'code' => 'BOOKING_NOT_FOUND'
            ]);
            return;
        }
        
        // Check permissions
        if (!canModifyBooking($current_user, $targetBooking)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'You cannot update this booking',
                'code' => 'UPDATE_BOOKING_FORBIDDEN'
            ]);
            return;
        }
        
        // Handle special actions
        if (isset($input['action'])) {
            switch ($input['action']) {
                case 'approve':
                    if (!hasPermission($current_user['user_type'], 'bookings', 'update')) {
                        http_response_code(403);
                        echo json_encode([
                            'error' => 'Insufficient permissions to approve bookings',
                            'code' => 'APPROVE_BOOKING_FORBIDDEN'
                        ]);
                        return;
                    }
                    $result = $bookingManager->approveBooking($bookingId, $current_user['user_id']);
                    break;
                    
                case 'cancel':
                    $result = $bookingManager->cancelBooking($bookingId, $input['reason'] ?? null);
                    break;
                    
                case 'change_status':
                    if (!hasPermission($current_user['user_type'], 'bookings', 'update')) {
                        http_response_code(403);
                        echo json_encode([
                            'error' => 'Insufficient permissions to change booking status',
                            'code' => 'CHANGE_STATUS_FORBIDDEN'
                        ]);
                        return;
                    }
                    $result = $bookingManager->changeStatus($bookingId, $input['status'], $input['note'] ?? null);
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
            $result = $bookingManager->updateBooking($bookingId, $input);
        }
        
        if ($result['success']) {
            // Log booking update
            logUserActivity($current_user['user_id'], 'booking_updated', [
                'booking_id' => $bookingId,
                'action' => $input['action'] ?? 'update',
                'changes' => $input
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Booking updated successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking update failed',
                'code' => 'BOOKING_UPDATE_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Update booking error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to update booking',
            'code' => 'UPDATE_BOOKING_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle DELETE requests - delete booking
 */
function handleDeleteBooking($bookingManager, $current_user) {
    try {
        // Get booking ID from URL
        $bookingId = intval($_GET['booking_id'] ?? 0);
        if (!$bookingId) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking ID is required',
                'code' => 'MISSING_BOOKING_ID'
            ]);
            return;
        }
        
        // Get target booking
        $targetBooking = $bookingManager->getBookingById($bookingId);
        if (!$targetBooking) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Booking not found',
                'code' => 'BOOKING_NOT_FOUND'
            ]);
            return;
        }
        
        // Check permissions
        if (!canModifyBooking($current_user, $targetBooking)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'You cannot delete this booking',
                'code' => 'DELETE_BOOKING_FORBIDDEN'
            ]);
            return;
        }
        
        // Cancel booking instead of hard delete
        $result = $bookingManager->cancelBooking($bookingId, 'Deleted by user');
        
        if ($result['success']) {
            // Log booking deletion
            logUserActivity($current_user['user_id'], 'booking_deleted', [
                'booking_id' => $bookingId,
                'booking_number' => $targetBooking['booking_number']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Booking deleted successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Booking deletion failed',
                'code' => 'BOOKING_DELETION_FAILED',
                'errors' => $result['errors']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Delete booking error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to delete booking',
            'code' => 'DELETE_BOOKING_FAILED',
            'message' => $e->getMessage()
        ]);
    }
}