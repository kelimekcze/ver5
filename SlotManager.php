<?php
/**
 * Slot Management Class
 * Logistic CRM System
 * 
 * Handles time slot creation, management, and scheduling
 */

class SlotManager {
    private $db;
    
    // Slot types
    const SLOT_TYPES = ['loading', 'unloading', 'universal'];
    
    // Slot statuses
    const STATUS_AVAILABLE = 'available';
    const STATUS_RESERVED = 'reserved';
    const STATUS_BLOCKED = 'blocked';
    const STATUS_COMPLETED = 'completed';
    
    // Recurring patterns
    const RECURRING_PATTERNS = ['none', 'daily', 'weekly', 'monthly'];
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Create time slot
     */
    public function createSlot($data) {
        $validation = $this->validateSlotData($data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Check for conflicts
            $conflicts = $this->checkSlotConflicts($data);
            if (!empty($conflicts)) {
                return ['success' => false, 'errors' => ['Slot konflikt: ' . implode(', ', $conflicts)]];
            }
            
            // Create main slot
            $slotId = $this->insertSlot($data);
            
            // Create recurring slots if specified
            if ($data['recurring_pattern'] !== 'none') {
                $this->createRecurringSlots($slotId, $data);
            }
            
            $this->db->commit();
            
            $this->logSlotAction($slotId, 'slot_created', $data);
            
            return [
                'success' => true,
                'slot_id' => $slotId,
                'message' => 'Slot byl úspěšně vytvořen'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Create slot error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při vytváření slotu']];
        }
    }
    
    /**
     * Update slot
     */
    public function updateSlot($slotId, $data) {
        $validation = $this->validateSlotData($data, true);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        try {
            // Get current slot data
            $currentSlot = $this->getSlotById($slotId);
            if (!$currentSlot) {
                return ['success' => false, 'errors' => ['Slot nenalezen']];
            }
            
            // Check for conflicts (excluding current slot)
            $conflicts = $this->checkSlotConflicts($data, $slotId);
            if (!empty($conflicts)) {
                return ['success' => false, 'errors' => ['Slot konflikt: ' . implode(', ', $conflicts)]];
            }
            
            // Update slot
            $query = "UPDATE time_slots SET 
                        slot_date = :slot_date,
                        slot_time_start = :slot_time_start,
                        slot_time_end = :slot_time_end,
                        slot_type = :slot_type,
                        capacity = :capacity,
                        is_blocked = :is_blocked,
                        block_reason = :block_reason,
                        updated_at = NOW()
                      WHERE id = :slot_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->bindParam(':slot_date', $data['slot_date']);
            $stmt->bindParam(':slot_time_start', $data['slot_time_start']);
            $stmt->bindParam(':slot_time_end', $data['slot_time_end']);
            $stmt->bindParam(':slot_type', $data['slot_type']);
            $stmt->bindParam(':capacity', $data['capacity']);
            $stmt->bindParam(':is_blocked', $data['is_blocked'] ? 1 : 0);
            $stmt->bindParam(':block_reason', $data['block_reason'] ?? null);
            
            if ($stmt->execute()) {
                $this->logSlotAction($slotId, 'slot_updated', [
                    'old_data' => $currentSlot,
                    'new_data' => $data
                ]);
                
                return ['success' => true, 'message' => 'Slot byl aktualizován'];
            }
            
            return ['success' => false, 'errors' => ['Aktualizace se nezdařila']];
            
        } catch (Exception $e) {
            error_log("Update slot error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při aktualizaci slotu']];
        }
    }
    
    /**
     * Delete slot
     */
    public function deleteSlot($slotId) {
        try {
            $this->db->beginTransaction();
            
            // Get slot data for logging
            $slot = $this->getSlotById($slotId);
            if (!$slot) {
                return ['success' => false, 'errors' => ['Slot nenalezen']];
            }
            
            // Check if slot has bookings
            $bookings = $this->getSlotBookings($slotId);
            if (!empty($bookings)) {
                return ['success' => false, 'errors' => ['Slot má aktivní rezervace a nelze ho smazat']];
            }
            
            // Delete slot
            $query = "DELETE FROM time_slots WHERE id = :slot_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            
            if ($stmt->execute()) {
                $this->logSlotAction($slotId, 'slot_deleted', $slot);
                $this->db->commit();
                
                return ['success' => true, 'message' => 'Slot byl smazán'];
            }
            
            $this->db->rollBack();
            return ['success' => false, 'errors' => ['Smazání se nezdařilo']];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Delete slot error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při mazání slotu']];
        }
    }
    
    /**
     * Get slots by date range
     */
    public function getSlotsByDateRange($startDate, $endDate, $warehouseId = null, $filters = []) {
        try {
            $query = "SELECT s.*, w.name as warehouse_name, wz.name as zone_name,
                            COUNT(b.id) as booking_count,
                            s.capacity - COUNT(b.id) as available_capacity
                     FROM time_slots s
                     JOIN warehouses w ON s.warehouse_id = w.id
                     LEFT JOIN warehouse_zones wz ON s.zone_id = wz.id
                     LEFT JOIN bookings b ON s.id = b.time_slot_id AND b.status NOT IN ('cancelled', 'completed')
                     WHERE s.slot_date BETWEEN :start_date AND :end_date";
            
            $params = [
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ];
            
            if ($warehouseId) {
                $query .= " AND s.warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $warehouseId;
            }
            
            // Apply filters
            if (isset($filters['slot_type'])) {
                $query .= " AND s.slot_type = :slot_type";
                $params[':slot_type'] = $filters['slot_type'];
            }
            
            if (isset($filters['is_blocked'])) {
                $query .= " AND s.is_blocked = :is_blocked";
                $params[':is_blocked'] = $filters['is_blocked'];
            }
            
            $query .= " GROUP BY s.id ORDER BY s.slot_date, s.slot_time_start";
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $slots = $stmt->fetchAll();
            
            // Process slots
            foreach ($slots as &$slot) {
                $slot['status'] = $this->calculateSlotStatus($slot);
                $slot['utilization'] = $this->calculateUtilization($slot);
            }
            
            return $slots;
            
        } catch (Exception $e) {
            error_log("Get slots error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get slot by ID
     */
    public function getSlotById($slotId) {
        try {
            $query = "SELECT s.*, w.name as warehouse_name, wz.name as zone_name
                     FROM time_slots s
                     JOIN warehouses w ON s.warehouse_id = w.id
                     LEFT JOIN warehouse_zones wz ON s.zone_id = wz.id
                     WHERE s.id = :slot_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Get slot by ID error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get available slots for booking
     */
    public function getAvailableSlots($warehouseId, $date, $slotType = null) {
        try {
            $query = "SELECT s.*, w.name as warehouse_name,
                            (s.capacity - COALESCE(booking_count, 0)) as available_capacity
                     FROM time_slots s
                     JOIN warehouses w ON s.warehouse_id = w.id
                     LEFT JOIN (
                         SELECT time_slot_id, COUNT(*) as booking_count
                         FROM bookings
                         WHERE status NOT IN ('cancelled', 'completed')
                         GROUP BY time_slot_id
                     ) b ON s.id = b.time_slot_id
                     WHERE s.warehouse_id = :warehouse_id
                     AND s.slot_date = :date
                     AND s.is_blocked = 0
                     AND (s.capacity - COALESCE(booking_count, 0)) > 0";
            
            $params = [
                ':warehouse_id' => $warehouseId,
                ':date' => $date
            ];
            
            if ($slotType) {
                $query .= " AND (s.slot_type = :slot_type OR s.slot_type = 'universal')";
                $params[':slot_type'] = $slotType;
            }
            
            $query .= " ORDER BY s.slot_time_start";
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get available slots error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Block slot
     */
    public function blockSlot($slotId, $reason = null) {
        try {
            $query = "UPDATE time_slots SET 
                        is_blocked = 1,
                        block_reason = :reason,
                        updated_at = NOW()
                      WHERE id = :slot_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->bindParam(':reason', $reason);
            
            if ($stmt->execute()) {
                $this->logSlotAction($slotId, 'slot_blocked', ['reason' => $reason]);
                return ['success' => true, 'message' => 'Slot byl zablokován'];
            }
            
            return ['success' => false, 'errors' => ['Blokace se nezdařila']];
            
        } catch (Exception $e) {
            error_log("Block slot error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při blokaci slotu']];
        }
    }
    
    /**
     * Unblock slot
     */
    public function unblockSlot($slotId) {
        try {
            $query = "UPDATE time_slots SET 
                        is_blocked = 0,
                        block_reason = NULL,
                        updated_at = NOW()
                      WHERE id = :slot_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            
            if ($stmt->execute()) {
                $this->logSlotAction($slotId, 'slot_unblocked');
                return ['success' => true, 'message' => 'Slot byl odblokován'];
            }
            
            return ['success' => false, 'errors' => ['Odblokace se nezdařila']];
            
        } catch (Exception $e) {
            error_log("Unblock slot error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při odblokaci slotu']];
        }
    }
    
    /**
     * Auto-reschedule delayed bookings
     */
    public function autoRescheduleDelayed() {
        try {
            $this->db->beginTransaction();
            
            // Get delayed bookings
            $query = "SELECT b.*, s.slot_date, s.slot_time_start, s.slot_time_end
                     FROM bookings b
                     JOIN time_slots s ON b.time_slot_id = s.id
                     WHERE b.status = 'delayed'
                     AND CONCAT(s.slot_date, ' ', s.slot_time_end) < NOW()";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $delayedBookings = $stmt->fetchAll();
            
            $rescheduledCount = 0;
            
            foreach ($delayedBookings as $booking) {
                // Find next available slot
                $nextSlot = $this->findNextAvailableSlot(
                    $booking['warehouse_id'],
                    $booking['booking_type'],
                    $booking['slot_date']
                );
                
                if ($nextSlot) {
                    // Move booking to new slot
                    $updateQuery = "UPDATE bookings SET 
                                   time_slot_id = :new_slot_id,
                                   status = 'rescheduled',
                                   notes = CONCAT(IFNULL(notes, ''), '\nAutomaticky přeplánováno: ', NOW()),
                                   updated_at = NOW()
                                   WHERE id = :booking_id";
                    
                    $updateStmt = $this->db->prepare($updateQuery);
                    $updateStmt->bindParam(':new_slot_id', $nextSlot['id']);
                    $updateStmt->bindParam(':booking_id', $booking['id']);
                    
                    if ($updateStmt->execute()) {
                        $rescheduledCount++;
                        
                        // Log reschedule
                        $this->logSlotAction($nextSlot['id'], 'booking_rescheduled', [
                            'booking_id' => $booking['id'],
                            'old_slot_id' => $booking['time_slot_id'],
                            'new_slot_id' => $nextSlot['id']
                        ]);
                        
                        // Send notification
                        $this->sendRescheduleNotification($booking, $nextSlot);
                    }
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'rescheduled_count' => $rescheduledCount,
                'message' => "Automaticky přeplánováno $rescheduledCount rezervací"
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Auto reschedule error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Chyba při automatickém přeplánování']];
        }
    }
    
    /**
     * Get slot statistics
     */
    public function getSlotStatistics($warehouseId = null, $dateFrom = null, $dateTo = null) {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateTo ?? date('Y-m-d');
            
            $query = "SELECT 
                        COUNT(s.id) as total_slots,
                        COUNT(CASE WHEN s.is_blocked = 1 THEN 1 END) as blocked_slots,
                        COUNT(CASE WHEN b.id IS NOT NULL THEN 1 END) as reserved_slots,
                        AVG(s.capacity) as avg_capacity,
                        SUM(s.capacity) as total_capacity,
                        COUNT(b.id) as total_bookings,
                        ROUND(COUNT(b.id) / COUNT(s.id) * 100, 2) as utilization_rate
                     FROM time_slots s
                     LEFT JOIN bookings b ON s.id = b.time_slot_id AND b.status NOT IN ('cancelled')
                     WHERE s.slot_date BETWEEN :date_from AND :date_to";
            
            $params = [
                ':date_from' => $dateFrom,
                ':date_to' => $dateTo
            ];
            
            if ($warehouseId) {
                $query .= " AND s.warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $warehouseId;
            }
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $stats = $stmt->fetch();
            
            // Get daily utilization
            $dailyQuery = "SELECT 
                            s.slot_date,
                            COUNT(s.id) as slots_count,
                            COUNT(b.id) as bookings_count,
                            ROUND(COUNT(b.id) / COUNT(s.id) * 100, 2) as daily_utilization
                          FROM time_slots s
                          LEFT JOIN bookings b ON s.id = b.time_slot_id AND b.status NOT IN ('cancelled')
                          WHERE s.slot_date BETWEEN :date_from AND :date_to";
            
            if ($warehouseId) {
                $dailyQuery .= " AND s.warehouse_id = :warehouse_id";
            }
            
            $dailyQuery .= " GROUP BY s.slot_date ORDER BY s.slot_date";
            
            $dailyStmt = $this->db->prepare($dailyQuery);
            foreach ($params as $key => $value) {
                $dailyStmt->bindValue($key, $value);
            }
            $dailyStmt->execute();
            
            $stats['daily_utilization'] = $dailyStmt->fetchAll();
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Get slot statistics error: " . $e->getMessage());
            return [];
        }
    }
    
    // Private helper methods
    
    private function validateSlotData($data, $isUpdate = false) {
        $errors = [];
        
        if (!$isUpdate || isset($data['warehouse_id'])) {
            if (empty($data['warehouse_id'])) {
                $errors[] = 'Sklad je povinný';
            }
        }
        
        if (!$isUpdate || isset($data['slot_date'])) {
            if (empty($data['slot_date'])) {
                $errors[] = 'Datum slotu je povinné';
            } elseif (!$this->isValidDate($data['slot_date'])) {
                $errors[] = 'Neplatné datum slotu';
            }
        }
        
        if (!$isUpdate || isset($data['slot_time_start'])) {
            if (empty($data['slot_time_start'])) {
                $errors[] = 'Čas začátku je povinný';
            } elseif (!$this->isValidTime($data['slot_time_start'])) {
                $errors[] = 'Neplatný čas začátku';
            }
        }
        
        if (!$isUpdate || isset($data['slot_time_end'])) {
            if (empty($data['slot_time_end'])) {
                $errors[] = 'Čas konce je povinný';
            } elseif (!$this->isValidTime($data['slot_time_end'])) {
                $errors[] = 'Neplatný čas konce';
            }
        }
        
        if (isset($data['slot_time_start']) && isset($data['slot_time_end'])) {
            if ($data['slot_time_start'] >= $data['slot_time_end']) {
                $errors[] = 'Čas konce musí být později než čas začátku';
            }
        }
        
        if (!$isUpdate || isset($data['slot_type'])) {
            if (isset($data['slot_type']) && !in_array($data['slot_type'], self::SLOT_TYPES)) {
                $errors[] = 'Neplatný typ slotu';
            }
        }
        
        if (!$isUpdate || isset($data['capacity'])) {
            if (isset($data['capacity'])) {
                $capacity = intval($data['capacity']);
                if ($capacity < 1 || $capacity > 100) {
                    $errors[] = 'Kapacita musí být mezi 1 a 100';
                }
            }
        }
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
    
    private function checkSlotConflicts($data, $excludeSlotId = null) {
        $conflicts = [];
        
        try {
            $query = "SELECT id FROM time_slots 
                     WHERE warehouse_id = :warehouse_id 
                     AND slot_date = :slot_date 
                     AND (
                         (slot_time_start < :end_time AND slot_time_end > :start_time)
                     )";
            
            if ($excludeSlotId) {
                $query .= " AND id != :exclude_id";
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':warehouse_id', $data['warehouse_id']);
            $stmt->bindParam(':slot_date', $data['slot_date']);
            $stmt->bindParam(':start_time', $data['slot_time_start']);
            $stmt->bindParam(':end_time', $data['slot_time_end']);
            
            if ($excludeSlotId) {
                $stmt->bindParam(':exclude_id', $excludeSlotId);
            }
            
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $conflicts[] = 'Časový konflikt s existujícím slotem';
            }
            
        } catch (Exception $e) {
            error_log("Check slot conflicts error: " . $e->getMessage());
            $conflicts[] = 'Chyba při kontrole konfliktů';
        }
        
        return $conflicts;
    }
    
    private function insertSlot($data) {
        $query = "INSERT INTO time_slots (
                    warehouse_id, zone_id, slot_date, slot_time_start, slot_time_end,
                    slot_type, capacity, available_capacity, is_blocked, block_reason,
                    recurring_pattern, recurring_until, created_by
                  ) VALUES (
                    :warehouse_id, :zone_id, :slot_date, :slot_time_start, :slot_time_end,
                    :slot_type, :capacity, :capacity, :is_blocked, :block_reason,
                    :recurring_pattern, :recurring_until, :created_by
                  )";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':warehouse_id', $data['warehouse_id']);
        $stmt->bindParam(':zone_id', $data['zone_id'] ?? null);
        $stmt->bindParam(':slot_date', $data['slot_date']);
        $stmt->bindParam(':slot_time_start', $data['slot_time_start']);
        $stmt->bindParam(':slot_time_end', $data['slot_time_end']);
        $stmt->bindParam(':slot_type', $data['slot_type'] ?? 'universal');
        $stmt->bindParam(':capacity', $data['capacity'] ?? 1);
        $stmt->bindParam(':is_blocked', $data['is_blocked'] ?? 0);
        $stmt->bindParam(':block_reason', $data['block_reason'] ?? null);
        $stmt->bindParam(':recurring_pattern', $data['recurring_pattern'] ?? 'none');
        $stmt->bindParam(':recurring_until', $data['recurring_until'] ?? null);
        $stmt->bindParam(':created_by', $data['created_by']);
        
        $stmt->execute();
        
        return $this->db->lastInsertId();
    }
    
    private function createRecurringSlots($parentSlotId, $data) {
        // TODO: Implement recurring slot creation logic
        // This would create multiple slots based on the recurring pattern
    }
    
    private function getSlotBookings($slotId) {
        $query = "SELECT * FROM bookings WHERE time_slot_id = :slot_id AND status NOT IN ('cancelled')";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':slot_id', $slotId);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    private function calculateSlotStatus($slot) {
        if ($slot['is_blocked']) {
            return 'blocked';
        }
        
        if ($slot['available_capacity'] <= 0) {
            return 'full';
        }
        
        if ($slot['booking_count'] > 0) {
            return 'partial';
        }
        
        return 'available';
    }
    
    private function calculateUtilization($slot) {
        if ($slot['capacity'] == 0) {
            return 0;
        }
        
        return round(($slot['booking_count'] / $slot['capacity']) * 100, 2);
    }
    
    private function findNextAvailableSlot($warehouseId, $slotType, $fromDate) {
        $query = "SELECT s.* 
                 FROM time_slots s
                 LEFT JOIN (
                     SELECT time_slot_id, COUNT(*) as booking_count
                     FROM bookings
                     WHERE status NOT IN ('cancelled', 'completed')
                     GROUP BY time_slot_id
                 ) b ON s.id = b.time_slot_id
                 WHERE s.warehouse_id = :warehouse_id
                 AND s.slot_date >= :from_date
                 AND s.is_blocked = 0
                 AND (s.slot_type = :slot_type OR s.slot_type = 'universal')
                 AND (s.capacity - COALESCE(b.booking_count, 0)) > 0
                 ORDER BY s.slot_date, s.slot_time_start
                 LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':warehouse_id', $warehouseId);
        $stmt->bindParam(':from_date', $fromDate);
        $stmt->bindParam(':slot_type', $slotType);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    private function sendRescheduleNotification($booking, $newSlot) {
        // TODO: Implement notification sending
        error_log("Reschedule notification should be sent for booking: " . $booking['id']);
    }
    
    private function logSlotAction($slotId, $action, $data = null) {
        try {
            $query = "INSERT INTO audit_log (action, entity_type, entity_id, new_values, ip_address, user_agent) 
                     VALUES (:action, 'time_slot', :entity_id, :new_values, :ip_address, :user_agent)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':entity_id', $slotId);
            $stmt->bindParam(':new_values', json_encode($data));
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null);
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Slot action log error: " . $e->getMessage());
        }
    }
    
    private function isValidDate($date) {
        return DateTime::createFromFormat('Y-m-d', $date) !== false;
    }
    
    private function isValidTime($time) {
        return DateTime::createFromFormat('H:i', $time) !== false;
    }
}