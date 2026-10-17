-- Gộp phân hệ Học phí với kho: khóa ngoại cho các cột đã chờ sẵn trỏ tới finance.receipts / finance.tuitions.
-- ON DELETE SET NULL sẽ đụng trigger chặn sửa nhật ký kho, nên để mặc định (không xóa được phiếu đã xuất kho).
-- NOT VALID: chỉ kiểm dòng mới. Dòng kho cũ (trước khi có Học phí) có thể mang mã phiếu không tồn tại và không sửa được
-- (nhật ký kho chặn UPDATE), kiểm cả bảng sẽ làm migrate hỏng.
-- +goose Up
ALTER TABLE ops.stock_movements
    ADD CONSTRAINT stock_movements_receipt_fk FOREIGN KEY (receipt_id) REFERENCES finance.receipts (id) NOT VALID,
    ADD CONSTRAINT stock_movements_tuition_fk FOREIGN KEY (tuition_id) REFERENCES finance.tuitions (id) NOT VALID;

-- +goose Down
ALTER TABLE ops.stock_movements DROP CONSTRAINT stock_movements_tuition_fk, DROP CONSTRAINT stock_movements_receipt_fk;
