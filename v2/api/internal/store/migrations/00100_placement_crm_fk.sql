-- Gộp CRM ↔ test đầu vào: khoá ngoại giữa khách CRM (crm.leads) và bảng của phân hệ placement (00090), trước đó
-- chỉ giữ uuid vì hai phân hệ làm song song. Khách CRM chỉ xóa mềm (deleted_at) nên ON DELETE chỉ để dọn dữ liệu.
-- +goose Up
ALTER TABLE placement.invitations
    -- Link riêng của khách (CHECK kind = 'lead' ⇔ có lead_id) nên xóa khách thì xóa link
    ADD CONSTRAINT invitations_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES crm.leads (id) ON DELETE CASCADE;
ALTER TABLE placement.submissions
    -- Bài làm giữ lại (thành bài chưa gắn khách)
    ADD CONSTRAINT submissions_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES crm.leads (id) ON DELETE SET NULL;
ALTER TABLE crm.trial_bookings
    ADD CONSTRAINT trial_bookings_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES crm.leads (id) ON DELETE CASCADE;
ALTER TABLE crm.leads
    ADD CONSTRAINT leads_assigned_test_id_fkey FOREIGN KEY (assigned_test_id) REFERENCES placement.tests (id) ON DELETE SET NULL;

-- +goose Down
ALTER TABLE crm.leads DROP CONSTRAINT leads_assigned_test_id_fkey;
ALTER TABLE crm.trial_bookings DROP CONSTRAINT trial_bookings_lead_id_fkey;
ALTER TABLE placement.submissions DROP CONSTRAINT submissions_lead_id_fkey;
ALTER TABLE placement.invitations DROP CONSTRAINT invitations_lead_id_fkey;
