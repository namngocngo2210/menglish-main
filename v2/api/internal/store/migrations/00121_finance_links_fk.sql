-- Gộp phân hệ Học phí với kho: khóa ngoại cho các cột đã chờ sẵn trỏ tới finance.receipts / finance.tuitions.
-- ON DELETE SET NULL sẽ đụng trigger chặn sửa nhật ký kho, nên để mặc định (không xóa được phiếu đã xuất kho).
-- +goose Up
ALTER TABLE ops.stock_movements
    ADD CONSTRAINT stock_movements_receipt_fk FOREIGN KEY (receipt_id) REFERENCES finance.receipts (id),
    ADD CONSTRAINT stock_movements_tuition_fk FOREIGN KEY (tuition_id) REFERENCES finance.tuitions (id);

-- +goose Down
ALTER TABLE ops.stock_movements DROP CONSTRAINT stock_movements_tuition_fk, DROP CONSTRAINT stock_movements_receipt_fk;
