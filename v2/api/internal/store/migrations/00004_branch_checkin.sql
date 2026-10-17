-- Cài đặt chấm công theo chi nhánh (giống Laravel: Cơ sở & chi nhánh → "Chấm công"): tọa độ, bán kính được chấm (mét),
-- giờ vào / ra và số phút cho phép đến muộn. Nhân sự chỉ chấm công được khi GPS nằm trong bán kính của chi nhánh.
-- Ràng buộc ở đây lỏng hơn API (API: bán kính 20–5000, muộn 0–240, giờ ra sau giờ vào) để nhập được dữ liệu cũ.
-- +goose Up
ALTER TABLE core.branches
    ADD COLUMN latitude           numeric(10, 7) CHECK (latitude BETWEEN -90 AND 90),
    ADD COLUMN longitude          numeric(10, 7) CHECK (longitude BETWEEN -180 AND 180),
    ADD COLUMN checkin_radius     integer NOT NULL DEFAULT 100 CHECK (checkin_radius BETWEEN 0 AND 100000),
    ADD COLUMN work_start_time    time,
    ADD COLUMN work_end_time      time,
    ADD COLUMN late_grace_minutes smallint NOT NULL DEFAULT 0 CHECK (late_grace_minutes >= 0),
    ADD CONSTRAINT branches_location_pair CHECK ((latitude IS NULL) = (longitude IS NULL));

-- +goose Down
ALTER TABLE core.branches
    DROP CONSTRAINT branches_location_pair,
    DROP COLUMN latitude,
    DROP COLUMN longitude,
    DROP COLUMN checkin_radius,
    DROP COLUMN work_start_time,
    DROP COLUMN work_end_time,
    DROP COLUMN late_grace_minutes;
