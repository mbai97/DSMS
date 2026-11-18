<?php

declare(strict_types=1);

function run_staff_management_migration(PDO $db): void
{
    $version = 'staff-management-20261110';
    $stmt = $db->prepare('SELECT 1 FROM app_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS staff (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            department VARCHAR(100) NOT NULL,
            job_title VARCHAR(120) NOT NULL,
            hire_date DATE NULL,
            status ENUM('active','on_leave','inactive') NOT NULL DEFAULT 'active',
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_staff_department (department),
            KEY idx_staff_status (status),
            KEY idx_staff_hire_date (hire_date),
            CONSTRAINT fk_staff_creator FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $db->prepare('INSERT IGNORE INTO app_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
}

<<<<<<< HEAD
function run_accounting_migration(PDO $db): void
{
    $version = 'accounting-payroll-20261110';
    $stmt = $db->prepare('SELECT 1 FROM app_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS staff_salaries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id INT UNSIGNED NOT NULL,
            monthly_salary DECIMAL(12,2) NOT NULL,
            effective_from DATE NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_salary_effective (staff_id, effective_from),
            KEY idx_staff_salary_effective (effective_from),
            CONSTRAINT fk_salary_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
            CONSTRAINT fk_salary_updater FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS payroll_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id INT UNSIGNED NOT NULL,
            payroll_month DATE NOT NULL,
            gross_salary DECIMAL(12,2) NOT NULL,
            deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_salary DECIMAL(12,2) NOT NULL,
            status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
            paid_at DATETIME NULL,
            payment_method ENUM('cash','mpesa','airtel_money','bank','other') NULL,
            payment_reference VARCHAR(100) NULL,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payroll_staff_month (staff_id, payroll_month),
            KEY idx_payroll_month_status (payroll_month, status),
            CONSTRAINT fk_payroll_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
            CONSTRAINT fk_payroll_creator FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
            CONSTRAINT fk_payroll_updater FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $db->prepare('INSERT IGNORE INTO app_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
}

function run_reporting_migration(PDO $db): void
{
    $version = 'reports-enquiries-courses-20261118';
    $stmt = $db->prepare('SELECT 1 FROM app_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS enquiries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospect_name VARCHAR(190) NOT NULL,
            phone_number VARCHAR(30) NULL,
            email VARCHAR(190) NULL,
            branch_id INT UNSIGNED NULL,
            course_id INT UNSIGNED NULL,
            notes VARCHAR(500) NULL,
            status ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
            recorded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_enquiries_created_at (created_at),
            KEY idx_enquiries_branch_course (branch_id, course_id),
            KEY idx_enquiries_status (status),
            CONSTRAINT fk_enquiry_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
            CONSTRAINT fk_enquiry_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
            CONSTRAINT fk_enquiry_recorder FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $courses = [
        ['A1', 'A1', 'Certificate of Competence'],
        ['A2', 'A2', 'Certificate of Competence'],
        ['B', 'B', 'Certificate of Competence'],
        ['B1', 'B1', 'Certificate of Competence'],
        ['B2', 'B2', 'Certificate of Competence'],
        ['B3', 'B3', 'Certificate of Competence'],
        ['C', 'C', 'Certificate of Competence'],
        ['C1', 'C1', 'Certificate of Competence'],
        ['D1', 'D1', 'Certificate of Competence'],
        ['First Aid', 'FA', 'First Aid'],
    ];
    $insertCourse = $db->prepare('INSERT IGNORE INTO courses (name, code, certificate_type) VALUES (?, ?, ?)');
    foreach ($courses as $course) {
        $insertCourse->execute($course);
    }

    $stmt = $db->prepare('INSERT IGNORE INTO app_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
}

function run_automated_reporting_migration(PDO $db): void
{
    $version = 'automated-reporting-20261118';
    $stmt = $db->prepare('SELECT 1 FROM app_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute(['enquiries', 'source']);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE enquiries ADD COLUMN source ENUM('staff','public') NOT NULL DEFAULT 'staff' AFTER status");
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS scheduled_report_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_type VARCHAR(20) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            recipient_email VARCHAR(190) NOT NULL,
            status ENUM('sent','failed') NOT NULL,
            error_message VARCHAR(500) NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            UNIQUE KEY uq_scheduled_report_delivery (period_type, period_start, recipient_email),
            KEY idx_scheduled_report_attempt (status, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $settings = $db->prepare('INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        ['report_recipients', ''],
        ['report_sender', ''],
        ['report_schedule', 'day,week,month,quarter,half_year,year'],
    ] as $setting) {
        $settings->execute($setting);
    }

    $stmt = $db->prepare('INSERT IGNORE INTO app_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
}

=======
>>>>>>> f91be251338f2353359be1b43ac98fffc3d194f7
function run_database_migrations(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS app_migrations (
            version VARCHAR(100) PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $version = $db->prepare('SELECT 1 FROM app_migrations WHERE version = ? LIMIT 1');
    $version->execute(['role-branch-access-20261021']);
    if ($version->fetchColumn()) {
        run_staff_management_migration($db);
<<<<<<< HEAD
        run_accounting_migration($db);
        run_reporting_migration($db);
        run_automated_reporting_migration($db);
=======
>>>>>>> f91be251338f2353359be1b43ac98fffc3d194f7
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS branches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            code VARCHAR(8) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS courses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            code VARCHAR(8) NOT NULL UNIQUE,
            certificate_type VARCHAR(150) NOT NULL,
            duration_days SMALLINT UNSIGNED NULL,
            default_fee DECIMAL(10,2) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach ([
        ['Machakos', 'MAC'],
        ['Tala', 'TAL'],
        ['Kimutwa', 'KIM'],
        ['Kitengela', 'KIT'],
    ] as [$name, $code]) {
        $stmt = $db->prepare('INSERT IGNORE INTO branches (name, code) VALUES (?, ?)');
        $stmt->execute([$name, $code]);
    }

    foreach ([
        ['Driving School', 'DR', 'Certificate of Competence'],
        ['Computer Packages', 'CP', 'Computer Packages'],
        ['Defensive Driving', 'DD', 'Defensive Driving'],
        ['Mechanics', 'ME', 'Mechanical Artisan'],
    ] as [$name, $code, $certificateType]) {
        $stmt = $db->prepare('INSERT IGNORE INTO courses (name, code, certificate_type) VALUES (?, ?, ?)');
        $stmt->execute([$name, $code, $certificateType]);
    }

    $columns = [
        'branch_id' => 'INT UNSIGNED NULL',
        'course_id' => 'INT UNSIGNED NULL',
        'admission_number' => 'VARCHAR(40) NULL',
        'course_duration_days' => 'SMALLINT UNSIGNED NULL',
        'total_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'completed_on' => 'DATE NULL',
        'certificate_id' => 'BIGINT UNSIGNED NULL',
        'phone_number' => 'VARCHAR(30) NULL',
        'id_number' => 'VARCHAR(50) NULL',
        'email' => 'VARCHAR(190) NULL',
        'next_of_kin_name' => 'VARCHAR(190) NULL',
        'next_of_kin_phone' => 'VARCHAR(30) NULL',
        'next_of_kin_relationship' => 'VARCHAR(100) NULL',
    ];
    foreach ($columns as $column => $definition) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute(['students', $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec('ALTER TABLE students ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute(['admins', 'branch_id']);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE admins ADD COLUMN branch_id INT UNSIGNED NULL');
    }
    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'admins' AND index_name = 'idx_admin_branch'"
    );
    if ((int)$index->fetchColumn() === 0) {
        $db->exec('CREATE INDEX idx_admin_branch ON admins (branch_id)');
    }
    $db->exec("UPDATE admins SET role = 'manager' WHERE role = 'editor'");
    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'admission_number' AND non_unique = 0"
    );
    if ((int)$index->fetchColumn() === 0) {
        $db->exec('CREATE UNIQUE INDEX uq_students_admission_number ON students (admission_number)');
    }
    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'certificate_id' AND non_unique = 0"
    );
    if ((int)$index->fetchColumn() === 0) {
        $db->exec('CREATE UNIQUE INDEX uq_students_certificate_id ON students (certificate_id)');
    }

    $stmt = $db->prepare(
        'SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute(['students', 'status']);
    $statusType = (string)$stmt->fetchColumn();
    if ($statusType !== '' && !str_contains($statusType, "'completed'")) {
        $db->exec("ALTER TABLE students MODIFY status ENUM('active','inactive','completed') NOT NULL DEFAULT 'active'");
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS vehicles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            registration_no VARCHAR(30) NOT NULL UNIQUE,
            make_model VARCHAR(120) NOT NULL,
            branch_id INT UNSIGNED NOT NULL,
            status ENUM('available','maintenance','retired') NOT NULL DEFAULT 'available',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vehicles_branch (branch_id),
            KEY idx_vehicles_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS admission_sequences (
            branch_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            admission_year SMALLINT UNSIGNED NOT NULL,
            last_number INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (branch_id, course_id, admission_year),
            CONSTRAINT fk_sequence_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
            CONSTRAINT fk_sequence_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS fleet_assignments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS student_payments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $db->prepare('INSERT IGNORE INTO app_migrations (version) VALUES (?)');
    $stmt->execute(['role-branch-access-20261021']);
    run_staff_management_migration($db);
<<<<<<< HEAD
    run_accounting_migration($db);
    run_reporting_migration($db);
    run_automated_reporting_migration($db);
=======
>>>>>>> f91be251338f2353359be1b43ac98fffc3d194f7
}
