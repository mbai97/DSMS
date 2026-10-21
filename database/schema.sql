-- ============================================================================
-- Beckyn Certificate Verification System - Complete Schema
-- ============================================================================
-- This file contains the complete database schema for all modules including:
-- - Authentication & Admin Management
-- - Certificate Management
-- - Settings & Configuration
-- - Student/Learner Records
-- - Verification Logging
-- ============================================================================

-- ============================================================================
-- MODULE 1: AUTHENTICATION & ADMIN MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 2: CONFIGURATION & SETTINGS
-- ============================================================================

CREATE TABLE IF NOT EXISTS app_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_applied_at (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 3: STUDENT/LEARNER MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    code VARCHAR(8) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    code VARCHAR(8) NOT NULL UNIQUE,
    certificate_type VARCHAR(150) NOT NULL,
    duration_days SMALLINT UNSIGNED NULL,
    default_fee DECIMAL(10,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_id VARCHAR(80) NULL UNIQUE,
    admission_number VARCHAR(40) NULL UNIQUE,
    holder_name VARCHAR(190) NOT NULL,
    course_name VARCHAR(190) NOT NULL,
    course_id INT UNSIGNED NULL,
    branch_id INT UNSIGNED NULL,
    course_duration_days SMALLINT UNSIGNED NULL,
    total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    phone_number VARCHAR(30) NULL,
    id_number VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    next_of_kin_name VARCHAR(190) NULL,
    next_of_kin_phone VARCHAR(30) NULL,
    next_of_kin_relationship VARCHAR(100) NULL,
    completed_on DATE NULL,
    certificate_id BIGINT UNSIGNED NULL UNIQUE,
    enrollment_date DATE NOT NULL,
    status ENUM('active','inactive','completed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_students_holder_name (holder_name),
    KEY idx_students_course (course_name),
    KEY idx_students_branch (branch_id),
    KEY idx_students_status (status),
    KEY idx_enrollment_date (enrollment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_sequences (
    branch_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    admission_year SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (branch_id, course_id, admission_year),
    CONSTRAINT fk_sequence_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sequence_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_no VARCHAR(30) NOT NULL UNIQUE,
    make_model VARCHAR(120) NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    status ENUM('available','maintenance','retired') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_vehicles_branch (branch_id),
    KEY idx_vehicles_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fleet_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NULL,
    branch_id INT UNSIGNED NOT NULL,
    assigned_on DATE NOT NULL,
    returned_on DATE NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fleet_current (vehicle_id, returned_on),
    KEY idx_fleet_student (student_id),
    CONSTRAINT fk_fleet_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fleet_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_fleet_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fleet_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','mpesa','airtel_money','bank','other') NOT NULL,
    reference VARCHAR(100) NULL,
    notes VARCHAR(255) NULL,
    received_by INT UNSIGNED NULL,
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payments_student (student_id),
    KEY idx_payments_paid_at (paid_at),
    CONSTRAINT fk_payment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_admin FOREIGN KEY (received_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO branches (name, code) VALUES
    ('Machakos', 'MAC'), ('Tala', 'TAL'), ('Kimutwa', 'KIM'), ('Kitengela', 'KIT');

INSERT IGNORE INTO courses (name, code, certificate_type) VALUES
    ('Driving School', 'DR', 'Certificate of Competence'),
    ('Computer Packages', 'CP', 'Computer Packages'),
    ('Defensive Driving', 'DD', 'Defensive Driving'),
    ('Mechanics', 'ME', 'Mechanical Artisan');

-- ============================================================================
-- MODULE 4: CERTIFICATE MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS certificates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    certificate_number VARCHAR(60) NOT NULL UNIQUE,
    verification_token CHAR(64) NOT NULL UNIQUE,
    holder_name VARCHAR(190) NOT NULL,
    learner_id VARCHAR(80) NULL,
    certificate_type VARCHAR(150) NOT NULL DEFAULT 'Certificate of Competence',
    course_name VARCHAR(190) NOT NULL,
    course_topics TEXT NULL,
    training_duration VARCHAR(100) NULL,
    issue_date DATE NOT NULL,
    completion_date DATE NULL,
    expiry_date DATE NULL,
    director_name VARCHAR(120) NULL,
    status ENUM('valid','revoked') NOT NULL DEFAULT 'valid',
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(255) NULL,
    qr_path VARCHAR(255) NULL,
    pdf_path VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cert_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_certificate_number (certificate_number),
    INDEX idx_verification_token (verification_token),
    INDEX idx_holder_name (holder_name),
    INDEX idx_learner_id (learner_id),
    INDEX idx_issue_date (issue_date),
    INDEX idx_expiry_date (expiry_date),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 5: VERIFICATION & LOGGING
-- ============================================================================

CREATE TABLE IF NOT EXISTS verification_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    certificate_id BIGINT UNSIGNED NULL,
    certificate_number VARCHAR(60) NULL,
    result ENUM('valid','expired','revoked','not_found') NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_certificate FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE SET NULL,
    INDEX idx_log_certificate (certificate_id),
    INDEX idx_certificate_number (certificate_number),
    INDEX idx_verified_at (verified_at),
    INDEX idx_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INITIAL DATA SETUP
-- ============================================================================

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES
    ('brand_color', '#1ca7d9'),
    ('accent_color', '#d7265b'),
    ('gold_color', '#d4af37'),
    ('signature_label', 'Authorized Signatory');

-- ============================================================================
-- STORED PROCEDURES & HELPER FUNCTIONS
-- ============================================================================

-- Function: get_certificate_by_number
-- Purpose: Retrieve certificate details by certificate number
-- Usage: SELECT * FROM certificates WHERE certificate_number = 'BKDS-2026-000001'

-- Function: certificate_status
-- Purpose: Determine certificate status (valid, expired, revoked)
-- Returns: 'valid', 'expired', or 'revoked'
-- Logic:
--   1. If status = 'revoked' THEN return 'revoked'
--   2. ELSE IF expiry_date IS NOT NULL AND expiry_date < TODAY THEN return 'expired'
--   3. ELSE return 'valid'

-- Function: next_certificate_number
-- Purpose: Generate the next sequential certificate number
-- Parameters: prefix (e.g., 'BKDS')
-- Format: PREFIX-YEAR-SEQUENCE (e.g., BKDS-2026-000001)

-- Function: log_verification
-- Purpose: Log certificate verification attempts
-- Captures: certificate_id, result (valid/expired/revoked/not_found), ip_address, user_agent

-- ============================================================================
-- MODULE DESCRIPTIONS
-- ============================================================================

/*
MODULE 1: AUTHENTICATION & ADMIN MANAGEMENT
- Table: admins
  - Stores admin user credentials and session information
  - Fields: id, name, email (unique), password_hash, role, created_at
  - Security: Password hashing with PASSWORD_HASH (PHP default)
  
  Functions:
  - login_admin(int $id): Authenticate and create session
  - admin_logged_in(): Check if admin is logged in
  - require_admin(): Enforce admin-only access
  - csrf_field(): Generate CSRF protection token
  - verify_csrf(): Validate CSRF token

MODULE 2: CONFIGURATION & SETTINGS
- Tables: app_settings, app_migrations
  - app_settings: Key-value configuration store
  - app_migrations: Track schema versions and applied migrations
  
  Default Settings:
  - brand_color: #1ca7d9
  - accent_color: #d7265b
  - gold_color: #d4af37
  - signature_label: Authorized Signatory
  
  Functions:
  - setting($db, $key, $default): Retrieve setting with fallback
  - update_setting($db, $key, $value): Update or create setting

MODULE 3: STUDENT/LEARNER MANAGEMENT
- Table: students
  - Stores learner information linked to certificates
  - Fields: id, learner_id (unique), holder_name, course_name, enrollment_date, status
  - Status: active | inactive
  - Indices: holder_name, course_name, status, enrollment_date

MODULE 4: CERTIFICATE MANAGEMENT
- Table: certificates
  - Core certificate data with QR and PDF references
  - Fields: certificate_number (unique), verification_token (unique), holder_name, 
            course_name, certificate_type, issue_date, expiry_date, director_name,
            status, revoked_at, revoked_reason, qr_path, pdf_path, created_by
  - Status: valid | revoked
  - Foreign Key: created_by → admins(id)
  
  Functions:
  - next_certificate_number($db, $prefix): Generate sequential cert number
  - certificate_status($cert): Determine current status
  - get_certificate_by_number($db, $number): Lookup by certificate number

MODULE 5: VERIFICATION & LOGGING
- Table: verification_logs
  - Audit trail of all certificate verification attempts
  - Fields: certificate_id, certificate_number, result, ip_address, user_agent, verified_at
  - Result: valid | expired | revoked | not_found
  
  Functions:
  - log_verification($db, $cert, $result): Record verification attempt

ADDITIONAL UTILITY FUNCTIONS:
- e($value): HTML escape output
- app_url($config, $path): Generate application URLs
- storage_path($path): Get storage directory paths
- redirect($url): Redirect HTTP response
- random_token($length): Generate secure random tokens
- ensure_storage(): Create required storage directories
*/
