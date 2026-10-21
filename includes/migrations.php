<?php

declare(strict_types=1);

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
    $db->exec("UPDATE admins SET role = 'manager' WHERE role IN ('director', 'editor')");
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
}
