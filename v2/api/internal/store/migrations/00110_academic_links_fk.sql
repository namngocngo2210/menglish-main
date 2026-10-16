-- Gộp phân hệ Lớp học với các phân hệ khác: khóa ngoại cho các cột đã chờ sẵn trỏ tới academic.courses / students /
-- classes / class_sessions / enrollments (CRM, test đầu vào, công việc, báo cáo, kho) và lượt xếp lớp → khách CRM.
-- Khóa ngoại tới học viên / khách CRM / khóa học không phụ thuộc lớp ở 00101.
-- Hồ sơ / lớp chỉ xóa mềm nên ON DELETE chỉ để dọn dữ liệu test.
-- +goose Up
ALTER TABLE crm.leads
    ADD CONSTRAINT leads_waiting_course_fk FOREIGN KEY (waiting_course_id) REFERENCES academic.courses (id) ON DELETE SET NULL;
ALTER TABLE crm.enrollment_confirmations
    ADD CONSTRAINT enrollment_confirmations_enrollment_fk FOREIGN KEY (enrollment_id) REFERENCES academic.enrollments (id) ON DELETE SET NULL,
    ADD CONSTRAINT enrollment_confirmations_class_fk FOREIGN KEY (class_id) REFERENCES academic.classes (id) ON DELETE CASCADE;
ALTER TABLE academic.enrollments
    ADD CONSTRAINT enrollments_lead_fk FOREIGN KEY (lead_id) REFERENCES crm.leads (id) ON DELETE SET NULL;
CREATE INDEX enrollments_lead_idx ON academic.enrollments (lead_id) WHERE lead_id IS NOT NULL;

ALTER TABLE crm.trial_bookings
    ADD CONSTRAINT trial_bookings_class_fk FOREIGN KEY (class_id) REFERENCES academic.classes (id) ON DELETE CASCADE,
    ADD CONSTRAINT trial_bookings_session_fk FOREIGN KEY (class_session_id) REFERENCES academic.class_sessions (id) ON DELETE CASCADE;

ALTER TABLE ops.work_tasks
    ADD CONSTRAINT work_tasks_class_fk FOREIGN KEY (class_id) REFERENCES academic.classes (id) ON DELETE SET NULL;
CREATE INDEX work_tasks_class_idx ON ops.work_tasks (class_id, due_date) WHERE class_id IS NOT NULL;
ALTER TABLE ops.staff_reports
    ADD CONSTRAINT staff_reports_class_fk FOREIGN KEY (class_id) REFERENCES academic.classes (id) ON DELETE SET NULL;
ALTER TABLE ops.stock_movements
    ADD CONSTRAINT stock_movements_student_fk FOREIGN KEY (student_id) REFERENCES academic.students (id) ON DELETE SET NULL;

-- +goose Down
ALTER TABLE ops.stock_movements DROP CONSTRAINT stock_movements_student_fk;
ALTER TABLE ops.staff_reports DROP CONSTRAINT staff_reports_class_fk;
DROP INDEX ops.work_tasks_class_idx;
ALTER TABLE ops.work_tasks DROP CONSTRAINT work_tasks_class_fk;
ALTER TABLE crm.trial_bookings DROP CONSTRAINT trial_bookings_session_fk, DROP CONSTRAINT trial_bookings_class_fk;
DROP INDEX academic.enrollments_lead_idx;
ALTER TABLE academic.enrollments DROP CONSTRAINT enrollments_lead_fk;
ALTER TABLE crm.enrollment_confirmations DROP CONSTRAINT enrollment_confirmations_class_fk,
    DROP CONSTRAINT enrollment_confirmations_enrollment_fk;
ALTER TABLE crm.leads DROP CONSTRAINT leads_waiting_course_fk;
