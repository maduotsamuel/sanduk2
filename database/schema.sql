CREATE DATABASE IF NOT EXISTS sanduk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sanduk;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
 is_suspended BOOLEAN NOT NULL DEFAULT FALSE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS groups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 description TEXT,
 join_code CHAR(12) NOT NULL UNIQUE,
 contribution_amount DECIMAL(18,2) NOT NULL,
 frequency ENUM('weekly','monthly') NOT NULL,
 interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
 chairperson_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','paused') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (chairperson_id) REFERENCES users(id),
 CHECK (contribution_amount > 0), CHECK (interest_rate BETWEEN 0 AND 100)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS memberships (
 group_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
 requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at TIMESTAMP NULL,
 PRIMARY KEY(group_id,user_id),
 FOREIGN KEY (group_id) REFERENCES groups(id), FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 group_id BIGINT UNSIGNED NOT NULL,
 member_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('contribution','loan','repayment','withdrawal','expense') NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 details VARCHAR(500) NOT NULL DEFAULT '',
 period_label VARCHAR(80) NULL,
 reference_no VARCHAR(120) NULL,
 loan_id BIGINT UNSIGNED NULL,
 due_date DATE NULL,
 interest_rate DECIMAL(5,2) NULL,
 total_due DECIMAL(18,2) NULL,
 status ENUM('pending','approved','rejected','disbursed','repaid') NOT NULL DEFAULT 'pending',
 decided_by BIGINT UNSIGNED NULL,
 decided_at TIMESTAMP NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(group_id) REFERENCES groups(id),
 FOREIGN KEY(member_id) REFERENCES users(id),
 FOREIGN KEY(loan_id) REFERENCES requests(id),
 FOREIGN KEY(decided_by) REFERENCES users(id),
 INDEX idx_group_kind_status(group_id,kind,status), INDEX idx_member(member_id),
 CHECK(amount>0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 group_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NOT NULL UNIQUE,
 member_id BIGINT UNSIGNED NOT NULL,
 entry_type ENUM('contribution','loan_disbursement','repayment','withdrawal','expense') NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(group_id) REFERENCES groups(id),
 FOREIGN KEY(request_id) REFERENCES requests(id),
 FOREIGN KEY(member_id) REFERENCES users(id),
 INDEX idx_ledger_group(group_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 group_id BIGINT UNSIGNED NULL,
 action VARCHAR(90) NOT NULL,
 entity_type VARCHAR(50) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 self_approved BOOLEAN NOT NULL DEFAULT FALSE,
 note VARCHAR(500) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(actor_id) REFERENCES users(id),
 FOREIGN KEY(group_id) REFERENCES groups(id),
 INDEX idx_audit_group(group_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 subject VARCHAR(150) NOT NULL,
 message TEXT NOT NULL,
 status ENUM('open','resolved') NOT NULL DEFAULT 'open',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at TIMESTAMP NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 identifier_hash CHAR(64) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_attempt_identifier(identifier_hash,created_at),
 INDEX idx_attempt_ip(ip_hash,created_at)
) ENGINE=InnoDB;
DELIMITER $$
CREATE TRIGGER ledger_no_update BEFORE UPDATE ON ledger FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger entries cannot be modified'; END$$
CREATE TRIGGER ledger_no_delete BEFORE DELETE ON ledger FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger entries cannot be deleted'; END$$
DELIMITER ;
