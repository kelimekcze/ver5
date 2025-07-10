<?php
/**
 * Booking Management Class
 * Logistic CRM System
 * 
 * Handles booking creation, management, and status tracking
 */

class BookingManager {
    private $db;
    
    // Booking statuses
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CHECKED_IN = 'checked_in';
    const STATUS_CHECKED_OUT = 'checked_out';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_DELAYED = 'delayed';
    const STATUS_RESCHEDULED = 'rescheduled';
    
    // Booking types
    const TYPE_LOADING = 'loading';
    const TYPE_UNLOADING = 'unloading';
    const TYPE_UNIVERSAL = 'universal';
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Get bookings with filters and pagination
     */
    public function getBookings($filters = [], $page = 1, $limit = 20) {
        try {
            $offset = ($page - 1) * $limit;
            
            $query = "SELECT b.*, 
                            ts.slot_date, ts.slot_time_start, ts.slot_time_end,
                            w.name as warehouse_name, wz.name as zone_name,
                            u.full_name as driver_name, u.phone as driver_phone,
                            v.license_plate as vehicle_license,
                            c.name as company_name,
                            creator.full_name as created_by_name
                     FROM bookings b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN warehouse_zones wz ON ts.zone_id = wz.id
                     LEFT JOIN users u ON b.driver_id = u.id
                     LEFT JOIN vehicles v ON b.vehicle_id = v.id
                     LEFT JOIN companies c ON b.company_id = c.id
                     LEFT JOIN users creator ON b.created_by = creator.id
                     WHERE 1=1";
            
            $params = [];
            
            // Apply filters
            if (isset($filters['status'])) {
                $query .= " AND b.status = :status";
                $params[':status'] = $filters['status'];
            }
            
            if (isset($filters['company_id'])) {
                $query .= " AND b.company_id = :company_id";
                $params[':company_id'] = $filters['company_id'];
            }
            
            if (isset($filters['warehouse_id'])) {
                $query .= " AND ts.warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $filters['warehouse_id'];
            }
            
            if (isset($filters['driver_id'])) {
                $query .= " AND b.driver_id = :driver_id";
                $params[':driver_id'] = $filters['driver_id'];
            }
            
            if (isset($filters['date_from'])) {
                $query .= " AND ts.slot_date >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }
            
            if (isset($filters['date_to'])) {
                $query .= " AND ts.slot_date <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }
            
            if (isset($filters['search'])) {
                $query .= " AND (b.booking_number LIKE :search 
                           OR b.reference_number LIKE :search 
                           OR u.full_name LIKE :search 
                           OR c.name LIKE :search)";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            // Get total count
            $countQuery = str_replace('SELECT b.*, ts.slot_date, ts.slot_time_start, ts.slot_time_end, w.name as warehouse_name, wz.name as zone_name, u.full_name as driver_name, u.phone as driver_phone, v.license_plate as vehicle_license, c.name as company_name, creator.full_name as created_by_name', 'SELECT COUNT(*)', $query);
            
            $countStmt = $this->db->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();
            
            // Add ordering and pagination
            $query .= " ORDER BY ts.slot_date DESC, ts.slot_time_start DESC, b.created_at DESC";
            $query .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $bookings = $stmt->fetchAll();
            
            // Calculate pagination
            $pages = ceil($total / $limit);
            
            return [
                'bookings' => $bookings,
                'pagination' => [
                    'page' => $page,
                    'pages' => $pages,
                    'limit' => $limit,
                    'total' => $total
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Get bookings error: " . $e->getMessage());
            return [
                'bookings' => [],
                'pagination' => ['page' => 1, 'pages' => 0, 'limit' => $limit, 'total' => 0]
            ];
        }
    }
    
    /**
     * Get booking by ID
     */
    public function getBookingById($bookingId) {
        try {
            $query = "SELECT b.*, 
                            ts.slot_date, ts.slot_time_start, ts.slot_time_end,
                            w.name as warehouse_name, wz.name as zone_name,
                            u.full_name as driver_name, u.phone as driver_phone, u.email as driver_email,
                            v.license_plate as vehicle_license, v.type as vehicle_type,
                            c.name as company_name, c.address as company_address,
                            creator.full_name as created_by_name
                     FROM bookings b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN warehouse_zones wz ON ts.zone_id = wz.id
                     LEFT JOIN users u ON b.driver_id = u.id
                     LEFT JOIN vehicles v ON b.vehicle_id = v.id
                     LEFT JOIN companies c ON b.company_id = c.id
                     LEFT JOIN users creator ON b.created_by = creator.id
                     WHERE b.id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Get booking by ID error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get booking documents
     */
    public function getBookingDocuments($bookingId) {
        try {
            $query = "SELECT * FROM booking_documents 
                     WHERE booking_id = :booking_id 
                     ORDER BY created_at DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get booking documents error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get upcoming bookings
     */
    public function getUpcomingBookings($limit = 10, $companyId = null) {
        try {
            $query = "SELECT b.*, 
                            ts.slot_date, ts.slot_time_start, ts.slot_time_end,
                            w.name as warehouse_name,
                            u.full_name as driver_name,
                            c.name as company_name
                     FROM bookings b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN users u ON b.driver_id = u.id
                     LEFT JOIN companies c ON b.company_id = c.id
                     WHERE ts.slot_date >= CURDATE()
                     AND b.status IN ('confirmed', 'approved')";
            
            $params = [];
            
            if ($companyId) {
                $query .= " AND b.company_id = :company_id";
                $params[':company_id'] = $companyId;
            }
            
            $query .= " ORDER BY ts.slot_date, ts.slot_time_start LIMIT :limit";
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get upcoming bookings error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get today's bookings
     */
    public function getTodaysBookings($companyId = null) {
        try {
            $query = "SELECT b.*, 
                            ts.slot_date, ts.slot_time_start, ts.slot_time_end,
                            w.name as warehouse_name,
                            u.full_name as driver_name,
                            c.name as company_name
                     FROM bookings b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN users u ON b.driver_id = u.id
                     LEFT JOIN companies c ON b.company_id = c.id
                     WHERE ts.slot_date = CURDATE()";
            
            $params = [];
            
            if ($companyId) {
                $query .= " AND b.company_id = :company_id";
                $params[':company_id'] = $companyId;
            }
            
            $query .= " ORDER BY ts.slot_time_start";
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get today's bookings error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get booking statistics
     */
    public function getBookingStatistics($companyId = null, $dateFrom = null, $dateTo = null) {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateTo ?? date('Y-m-d');
            
            $query = "SELECT 
                        COUNT(*) as total_bookings,
                        COUNT(CASE WHEN b.status = 'pending' THEN 1 END) as pending_bookings,
                        COUNT(CASE WHEN b.status = 'confirmed' THEN 1 END) as confirmed_bookings,
                        COUNT(CASE WHEN b.status = 'completed' THEN 1 END) as completed_bookings,
                        COUNT(CASE WHEN b.status = 'cancelled' THEN 1 END) as cancelled_bookings,
                        COUNT(CASE WHEN b.check_in_time IS NOT NULL THEN 1 END) as checked_in_bookings,
                        COUNT(CASE WHEN b.check_out_time IS NOT NULL THEN 1 END) as checked_out_bookings
                     FROM bookings b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     WHERE ts.slot_date BETWEEN :date_from AND :date_to";
            
            $params = [
                ':date_from' => $dateFrom,
                ':date_to' => $dateTo
            ];
            
            if ($companyId) {
                $query .= " AND b.company_id = :company_id";
                $params[':company_id'] = $companyId;
            }
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Get booking statistics error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create new booking
     */
    public function createBooking($data) {
        $validation = $this->validateBookingData($data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Check slot availability
            if (!$this->isSlotAvailable($data['time_slot_id'])) {
                return ['success' => false, 'errors' => ['Slot není dostupný']];
            }
            
            // Generate booking number
            $bookingNumber = $this->generateBookingNumber();
            
            // Generate QR code
            $qrCode = $this->generateQRCode($bookingNumber);
            
            // Determine status (requires approval for some companies)
            $requiresApproval = $this->requiresApproval($data['company_id']);
            $status = $requiresApproval ? self::STATUS_PENDING : self::STATUS_CONFIRMED;
            
            $query = "INSERT INTO bookings (
                        booking_number, time_slot_id, company_id, driver_id, vehicle_id,
                        booking_type, reference_number, notes, qr_code, status,
                        created_by, created_at, updated_at
                     ) VALUES (
                        :booking_number, :time_slot_id, :company_id, :driver_id, :vehicle_id,
                        :booking_type, :reference_number, :notes, :qr_code, :status,
                        :created_by, NOW(), NOW()
                     )";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_number', $bookingNumber);
            $stmt->bindParam(':time_slot_id', $data['time_slot_id']);
            $stmt->bindParam(':company_id', $data['company_id']);
            $stmt->bindParam(':driver_id', $data['driver_id'] ?? null);
            $stmt->bindParam(':vehicle_id', $data['vehicle_id'] ?? null);
            $stmt->bindParam(':booking_type', $data['booking_type'] ?? self::TYPE_UNIVERSAL);
            $stmt->bindParam(':reference_number', $data['reference_number'] ?? null);
            $stmt->bindParam(':notes', $data['notes'] ?? null);
            $stmt->bindParam(':qr_code', $qrCode);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':created_by', $data['created_by']);
            
            if ($stmt->execute()) {
                $bookingId = $this->db->lastInsertId();
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'booking_id' => $bookingId,
                    'booking_number' => $bookingNumber,
                    'qr_code' => $qrCode,
                    'requires_approval' => $requiresApproval
                ];
            }
            
            $this->db->rollBack();
            return ['success' => false, 'errors' => ['Vytvoření rezervace se nezdařilo']];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Create booking error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při vytváření rezervace']];
        }
    }
    
    /**
     * Check-in booking
     */
    public function checkIn($bookingId, $qrCode = null) {
        try {
            $booking = $this->getBookingById($bookingId);
            if (!$booking) {
                return ['success' => false, 'errors' => ['Rezervace nenalezena']];
            }
            
            if ($booking['status'] !== self::STATUS_CONFIRMED) {
                return ['success' => false, 'errors' => ['Rezervace není potvrzena']];
            }
            
            if ($booking['check_in_time']) {
                return ['success' => false, 'errors' => ['Již je provedený check-in']];
            }
            
            // Validate QR code if provided
            if ($qrCode && $booking['qr_code'] !== $qrCode) {
                return ['success' => false, 'errors' => ['Neplatný QR kód']];
            }
            
            $query = "UPDATE bookings SET 
                        check_in_time = NOW(),
                        status = :status,
                        updated_at = NOW()
                      WHERE id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindValue(':status', self::STATUS_CHECKED_IN);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Check-in úspěšný'];
            }
            
            return ['success' => false, 'errors' => ['Check-in se nezdařil']];
            
        } catch (Exception $e) {
            error_log("Check-in error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při check-in']];
        }
    }
    
    /**
     * Check-out booking
     */
    public function checkOut($bookingId, $qrCode = null) {
        try {
            $booking = $this->getBookingById($bookingId);
            if (!$booking) {
                return ['success' => false, 'errors' => ['Rezervace nenalezena']];
            }
            
            if ($booking['status'] !== self::STATUS_CHECKED_IN) {
                return ['success' => false, 'errors' => ['Rezervace není check-in']];
            }
            
            if ($booking['check_out_time']) {
                return ['success' => false, 'errors' => ['Již je provedený check-out']];
            }
            
            // Validate QR code if provided
            if ($qrCode && $booking['qr_code'] !== $qrCode) {
                return ['success' => false, 'errors' => ['Neplatný QR kód']];
            }
            
            $query = "UPDATE bookings SET 
                        check_out_time = NOW(),
                        status = :status,
                        updated_at = NOW()
                      WHERE id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindValue(':status', self::STATUS_COMPLETED);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Check-out úspěšný'];
            }
            
            return ['success' => false, 'errors' => ['Check-out se nezdařil']];
            
        } catch (Exception $e) {
            error_log("Check-out error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při check-out']];
        }
    }
    
    /**
     * Approve booking
     */
    public function approveBooking($bookingId, $approvedBy) {
        try {
            $booking = $this->getBookingById($bookingId);
            if (!$booking) {
                return ['success' => false, 'errors' => ['Rezervace nenalezena']];
            }
            
            if ($booking['status'] !== self::STATUS_PENDING) {
                return ['success' => false, 'errors' => ['Rezervace není ve stavu čekání na schválení']];
            }
            
            $query = "UPDATE bookings SET 
                        status = :status,
                        approved_by = :approved_by,
                        approved_at = NOW(),
                        updated_at = NOW()
                      WHERE id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindValue(':status', self::STATUS_CONFIRMED);
            $stmt->bindParam(':approved_by', $approvedBy);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Rezervace byla schválena'];
            }
            
            return ['success' => false, 'errors' => ['Schválení se nezdařilo']];
            
        } catch (Exception $e) {
            error_log("Approve booking error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při schvalování']];
        }
    }
    
    /**
     * Cancel booking
     */
    public function cancelBooking($bookingId, $reason = null) {
        try {
            $booking = $this->getBookingById($bookingId);
            if (!$booking) {
                return ['success' => false, 'errors' => ['Rezervace nenalezena']];
            }
            
            if ($booking['status'] === self::STATUS_CANCELLED) {
                return ['success' => false, 'errors' => ['Rezervace je již zrušena']];
            }
            
            if ($booking['status'] === self::STATUS_COMPLETED) {
                return ['success' => false, 'errors' => ['Nelze zrušit dokončenou rezervaci']];
            }
            
            $query = "UPDATE bookings SET 
                        status = :status,
                        cancelled_at = NOW(),
                        cancellation_reason = :reason,
                        updated_at = NOW()
                      WHERE id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindValue(':status', self::STATUS_CANCELLED);
            $stmt->bindParam(':reason', $reason);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Rezervace byla zrušena'];
            }
            
            return ['success' => false, 'errors' => ['Zrušení se nezdařilo']];
            
        } catch (Exception $e) {
            error_log("Cancel booking error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při rušení']];
        }
    }
    
    /**
     * Update booking
     */
    public function updateBooking($bookingId, $data) {
        $validation = $this->validateBookingData($data, true);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        try {
            $booking = $this->getBookingById($bookingId);
            if (!$booking) {
                return ['success' => false, 'errors' => ['Rezervace nenalezena']];
            }
            
            // Check if slot is available if changing slot
            if (isset($data['time_slot_id']) && $data['time_slot_id'] != $booking['time_slot_id']) {
                if (!$this->isSlotAvailable($data['time_slot_id'])) {
                    return ['success' => false, 'errors' => ['Nový slot není dostupný']];
                }
            }
            
            $query = "UPDATE bookings SET ";
            $fields = [];
            $params = [':booking_id' => $bookingId];
            
            // Updateable fields
            $updateFields = ['time_slot_id', 'driver_id', 'vehicle_id', 'booking_type', 'reference_number', 'notes'];
            
            foreach ($updateFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                return ['success' => false, 'errors' => ['Žádné pole k aktualizaci']];
            }
            
            $fields[] = "updated_at = NOW()";
            $query .= implode(', ', $fields) . " WHERE id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute($params)) {
                return ['success' => true, 'message' => 'Rezervace byla aktualizována'];
            }
            
            return ['success' => false, 'errors' => ['Aktualizace se nezdařila']];
            
        } catch (Exception $e) {
            error_log("Update booking error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při aktualizaci rezervace']];
        }
    }

    /**
     * Change booking status
     */
    public function changeStatus($bookingId, $newStatus, $note = null) {
        try {
            $booking = $this->getBookingById($bookingId);
            if (!$booking) {
                return ['success' => false, 'errors' => ['Rezervace nenalezena']];
            }
            
            $validStatuses = [
                self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_CONFIRMED,
                self::STATUS_CHECKED_IN, self::STATUS_CHECKED_OUT, self::STATUS_COMPLETED,
                self::STATUS_CANCELLED, self::STATUS_DELAYED, self::STATUS_RESCHEDULED
            ];
            
            if (!in_array($newStatus, $validStatuses)) {
                return ['success' => false, 'errors' => ['Neplatný status']];
            }
            
            $query = "UPDATE bookings SET 
                        status = :status,
                        notes = CONCAT(IFNULL(notes, ''), :note),
                        updated_at = NOW()
                      WHERE id = :booking_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindParam(':status', $newStatus);
            $stmt->bindParam(':note', $note ? "\n" . date('Y-m-d H:i:s') . ": " . $note : '');
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Status byl změněn'];
            }
            
            return ['success' => false, 'errors' => ['Změna statusu se nezdařila']];
            
        } catch (Exception $e) {
            error_log("Change status error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při změně statusu']];
        }
    }
    
    // Private helper methods
    
    private function validateBookingData($data, $isUpdate = false) {
        $errors = [];
        
        if (!$isUpdate) {
            if (empty($data['time_slot_id'])) {
                $errors[] = 'Time slot ID je povinný';
            }
            
            if (empty($data['company_id'])) {
                $errors[] = 'Company ID je povinný';
            }
        }
        
        if (isset($data['booking_type']) && !in_array($data['booking_type'], [self::TYPE_LOADING, self::TYPE_UNLOADING, self::TYPE_UNIVERSAL])) {
            $errors[] = 'Neplatný typ rezervace';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    private function isSlotAvailable($slotId) {
        try {
            $query = "SELECT s.capacity, 
                           (SELECT COUNT(*) FROM bookings WHERE time_slot_id = s.id AND status NOT IN ('cancelled')) as booked_count
                     FROM time_slots s 
                     WHERE s.id = :slot_id AND s.is_blocked = 0";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();
            
            $slot = $stmt->fetch();
            if (!$slot) return false;
            
            return $slot['booked_count'] < $slot['capacity'];
            
        } catch (Exception $e) {
            error_log("Check slot availability error: " . $e->getMessage());
            return false;
        }
    }
    
    private function generateBookingNumber() {
        return 'BK' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    private function generateQRCode($bookingNumber) {
        return hash('sha256', $bookingNumber . time() . mt_rand());
    }
    
    private function requiresApproval($companyId) {
        try {
            $query = "SELECT requires_approval FROM companies WHERE id = :company_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':company_id', $companyId);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return $result ? $result['requires_approval'] : false;
            
        } catch (Exception $e) {
            return false;
        }
    }
}