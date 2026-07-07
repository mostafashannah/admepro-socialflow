-- Run once against the live database to add the HR module:
-- manager/salary/leave-credit fields on team members, a granular
-- role-permissions table, leave/WFH requests, and monthly attendance import.

ALTER TABLE team_members
  ADD COLUMN manager_id CHAR(36) NULL,
  ADD COLUMN salary DECIMAL(12,2) NULL,
  ADD COLUMN vacation_days_total DECIMAL(6,2) NOT NULL DEFAULT 21,
  ADD COLUMN vacation_days_used DECIMAL(6,2) NOT NULL DEFAULT 0,
  ADD COLUMN wfh_days_total DECIMAL(6,2) NOT NULL DEFAULT 12,
  ADD COLUMN wfh_days_used DECIMAL(6,2) NOT NULL DEFAULT 0;

-- Granular per-role permission flags, editable from the new Roles & Permissions
-- settings page. One row per (role, permission_key). Absence of a row for a
-- given key defaults to "not allowed" in the app.
CREATE TABLE IF NOT EXISTS role_permissions (
  id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  role VARCHAR(50) NOT NULL,
  permission_key VARCHAR(80) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY role_permission_unique (role, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leave / work-from-home requests, driven either from the app or from a team
-- member asking Pro over WhatsApp. Pro notifies request.manager_id for
-- approval and, on approval, deducts from the requester's credit columns above.
CREATE TABLE IF NOT EXISTS leave_requests (
  id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  team_member_id CHAR(36) NOT NULL,
  member_name VARCHAR(255) NOT NULL,
  type ENUM('vacation','wfh') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days DECIMAL(6,2) NOT NULL DEFAULT 1,
  reason TEXT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  manager_id CHAR(36) NULL,
  manager_name VARCHAR(255) NULL,
  decision_note TEXT NULL,
  source ENUM('app','whatsapp') NOT NULL DEFAULT 'app',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at TIMESTAMP NULL,
  INDEX idx_leave_member (team_member_id),
  INDEX idx_leave_manager (manager_id),
  INDEX idx_leave_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw daily attendance, populated by the monthly spreadsheet upload
-- (attendance-import.php). Used to reconcile against approved leave/WFH.
CREATE TABLE IF NOT EXISTS attendance_records (
  id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  team_member_id CHAR(36) NULL,
  member_name VARCHAR(255) NOT NULL,
  work_date DATE NOT NULL,
  status ENUM('present','absent','late','half_day','leave','wfh') NOT NULL DEFAULT 'present',
  check_in TIME NULL,
  check_out TIME NULL,
  note VARCHAR(255) NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY attendance_member_date (member_name, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
