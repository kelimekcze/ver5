<?php
/**
 * Database Configuration Class
 * Logistic CRM System
 * 
 * Handles database connection and configuration
 */

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset;
    private $conn;
    
    public function __construct() {
        // Database configuration - load from environment or set defaults
        $this->host = $_ENV['DB_HOST'] ?? 'localhost';
        $this->db_name = $_ENV['DB_NAME'] ?? 'logistic_crm';
        $this->username = $_ENV['DB_USER'] ?? 'root';
        $this->password = $_ENV['DB_PASS'] ?? '';
        $this->charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
    }
    
    /**
     * Create database connection
     */
    public function connect() {
        $this->conn = null;
        
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}",
                PDO::ATTR_TIMEOUT => 10
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            
            // In production, don't expose database errors
            if ($_ENV['APP_ENV'] !== 'production') {
                throw new Exception("Database connection failed: " . $e->getMessage());
            } else {
                throw new Exception("Database connection failed");
            }
        }
        
        return $this->conn;
    }
    
    /**
     * Get connection instance
     */
    public function getConnection() {
        if ($this->conn === null) {
            $this->connect();
        }
        return $this->conn;
    }
    
    /**
     * Close database connection
     */
    public function close() {
        $this->conn = null;
    }
    
    /**
     * Test database connection
     */
    public function testConnection() {
        try {
            $conn = $this->connect();
            $stmt = $conn->query("SELECT 1");
            return $stmt !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get database configuration for debugging
     */
    public function getConfig() {
        return [
            'host' => $this->host,
            'database' => $this->db_name,
            'username' => $this->username,
            'charset' => $this->charset
        ];
    }
    
    /**
     * Create tables if they don't exist (for development)
     */
    public function createTables() {
        $conn = $this->connect();
        
        // SQL for creating basic tables
        $tables = [
            'companies' => "
                CREATE TABLE IF NOT EXISTS companies (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    address TEXT,
                    phone VARCHAR(50),
                    email VARCHAR(255),
                    requires_approval BOOLEAN DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ",
            
            'users' => "
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT,
                    full_name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    phone VARCHAR(50),
                    password_hash VARCHAR(255) NOT NULL,
                    user_type ENUM('super_admin', 'admin', 'logistics', 'driver') NOT NULL,
                    avatar_url VARCHAR(500),
                    is_active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (company_id) REFERENCES companies(id)
                )
            ",
            
            'warehouses' => "
                CREATE TABLE IF NOT EXISTS warehouses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    address TEXT,
                    capacity INT DEFAULT 100,
                    is_active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ",
            
            'warehouse_zones' => "
                CREATE TABLE IF NOT EXISTS warehouse_zones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    warehouse_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    capacity INT DEFAULT 10,
                    is_active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
                )
            ",
            
            'time_slots' => "
                CREATE TABLE IF NOT EXISTS time_slots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    warehouse_id INT NOT NULL,
                    zone_id INT,
                    slot_date DATE NOT NULL,
                    slot_time_start TIME NOT NULL,
                    slot_time_end TIME NOT NULL,
                    slot_type ENUM('loading', 'unloading', 'universal') DEFAULT 'universal',
                    capacity INT DEFAULT 1,
                    is_blocked BOOLEAN DEFAULT 0,
                    block_reason TEXT,
                    recurring_pattern ENUM('none', 'daily', 'weekly', 'monthly') DEFAULT 'none',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
                    FOREIGN KEY (zone_id) REFERENCES warehouse_zones(id),
                    INDEX idx_slot_date_time (slot_date, slot_time_start),
                    INDEX idx_warehouse_date (warehouse_id, slot_date)
                )
            ",
            
            'vehicles' => "
                CREATE TABLE IF NOT EXISTS vehicles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT,
                    license_plate VARCHAR(20) NOT NULL,
                    type VARCHAR(100),
                    capacity DECIMAL(10,2),
                    is_active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (company_id) REFERENCES companies(id)
                )
            ",
            
            'bookings' => "
                CREATE TABLE IF NOT EXISTS bookings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    booking_number VARCHAR(50) UNIQUE NOT NULL,
                    time_slot_id INT NOT NULL,
                    company_id INT NOT NULL,
                    driver_id INT,
                    vehicle_id INT,
                    booking_type ENUM('loading', 'unloading', 'universal') DEFAULT 'universal',
                    reference_number VARCHAR(100),
                    notes TEXT,
                    qr_code VARCHAR(255) UNIQUE,
                    status ENUM('pending', 'approved', 'confirmed', 'checked_in', 'checked_out', 'completed', 'cancelled', 'delayed', 'rescheduled') DEFAULT 'pending',
                    check_in_time TIMESTAMP NULL,
                    check_out_time TIMESTAMP NULL,
                    approved_by INT,
                    approved_at TIMESTAMP NULL,
                    cancelled_at TIMESTAMP NULL,
                    cancellation_reason TEXT,
                    created_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id),
                    FOREIGN KEY (company_id) REFERENCES companies(id),
                    FOREIGN KEY (driver_id) REFERENCES users(id),
                    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
                    FOREIGN KEY (approved_by) REFERENCES users(id),
                    FOREIGN KEY (created_by) REFERENCES users(id),
                    INDEX idx_booking_number (booking_number),
                    INDEX idx_status (status),
                    INDEX idx_company_date (company_id, created_at)
                )
            ",
            
            'booking_documents' => "
                CREATE TABLE IF NOT EXISTS booking_documents (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id INT NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    original_filename VARCHAR(255) NOT NULL,
                    file_type VARCHAR(50),
                    file_size INT,
                    uploaded_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                    FOREIGN KEY (uploaded_by) REFERENCES users(id)
                )
            ",
            
            'audit_log' => "
                CREATE TABLE IF NOT EXISTS audit_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    action VARCHAR(100) NOT NULL,
                    entity_type VARCHAR(50),
                    entity_id INT,
                    old_values JSON,
                    new_values JSON,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    INDEX idx_user_action (user_id, action),
                    INDEX idx_entity (entity_type, entity_id),
                    INDEX idx_created_at (created_at)
                )
            "
        ];
        
        try {
            foreach ($tables as $tableName => $sql) {
                $conn->exec($sql);
                error_log("Table '$tableName' created or verified");
            }
            return true;
        } catch (PDOException $e) {
            error_log("Error creating tables: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Insert sample data for development
     */
    public function insertSampleData() {
        $conn = $this->connect();
        
        try {
            // Insert sample company
            $conn->exec("
                INSERT IGNORE INTO companies (id, name, address, email, requires_approval) VALUES 
                (1, 'Demo Company', 'Praha 1, Czech Republic', 'demo@company.com', 0)
            ");
            
            // Insert sample admin user
            $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                INSERT IGNORE INTO users (id, company_id, full_name, email, password_hash, user_type) VALUES 
                (1, 1, 'Admin User', 'admin@demo.com', :password, 'super_admin')
            ");
            $stmt->bindParam(':password', $passwordHash);
            $stmt->execute();
            
            // Insert sample warehouse
            $conn->exec("
                INSERT IGNORE INTO warehouses (id, name, address, capacity) VALUES 
                (1, 'Main Warehouse', 'Praha 5, Czech Republic', 50)
            ");
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Error inserting sample data: " . $e->getMessage());
            return false;
        }
    }
}