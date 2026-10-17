-- Gộp phân hệ: khoá ngoại cho các cột uuid trước đây chỉ ghi chú "FK tới … thêm khi gộp" mà bảng đích nay đã có
-- (học viên, khách CRM, hàng hóa, khóa học). Chỉ thêm cho cột do hệ thống ghi hoặc đã kiểm tồn tại trước khi ghi; cột
-- nhận id từ người dùng mà chưa kiểm (khóa chờ xếp lớp của khách, học viên của phiếu xuất kho, việc gắn order học liệu)
-- để lại tới khi có bước kiểm.
-- Hồ sơ học viên / khách chỉ xóa mềm nên ON DELETE chỉ để dọn dữ liệu.
-- +goose Up
ALTER TABLE academic.students
    ADD CONSTRAINT students_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES crm.leads (id) ON DELETE SET NULL;
ALTER TABLE crm.leads
    ADD CONSTRAINT leads_converted_student_id_fkey FOREIGN KEY (converted_student_id) REFERENCES academic.students (id) ON DELETE SET NULL;
ALTER TABLE crm.promotions
    ADD CONSTRAINT promotions_course_id_fkey FOREIGN KEY (course_id) REFERENCES academic.courses (id) ON DELETE SET NULL;
ALTER TABLE crm.enrollment_confirmations
    ADD CONSTRAINT enrollment_confirmations_student_id_fkey FOREIGN KEY (student_id) REFERENCES academic.students (id) ON DELETE CASCADE;
ALTER TABLE placement.submissions
    ADD CONSTRAINT submissions_student_id_fkey FOREIGN KEY (student_id) REFERENCES academic.students (id) ON DELETE SET NULL;
ALTER TABLE ops.work_tasks
    ADD CONSTRAINT work_tasks_student_id_fkey FOREIGN KEY (student_id) REFERENCES academic.students (id) ON DELETE SET NULL,
    ADD CONSTRAINT work_tasks_merchandise_item_id_fkey FOREIGN KEY (merchandise_item_id) REFERENCES ops.inventory_items (id) ON DELETE SET NULL;
CREATE INDEX work_tasks_restock_idx ON ops.work_tasks (merchandise_item_id, branch_id)
    WHERE kind = 'merchandise_restock' AND status IN ('new', 'in_progress', 'overdue');

-- +goose Down
DROP INDEX ops.work_tasks_restock_idx;
ALTER TABLE ops.work_tasks
    DROP CONSTRAINT work_tasks_merchandise_item_id_fkey,
    DROP CONSTRAINT work_tasks_student_id_fkey;
ALTER TABLE placement.submissions DROP CONSTRAINT submissions_student_id_fkey;
ALTER TABLE crm.enrollment_confirmations DROP CONSTRAINT enrollment_confirmations_student_id_fkey;
ALTER TABLE crm.promotions DROP CONSTRAINT promotions_course_id_fkey;
ALTER TABLE crm.leads DROP CONSTRAINT leads_converted_student_id_fkey;
ALTER TABLE academic.students DROP CONSTRAINT students_lead_id_fkey;
