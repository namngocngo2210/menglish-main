-- Cổng giáo viên: điểm mini test theo Unit của giáo trình lớp, 4 kỹ năng Nghe / Nói / Đọc / Viết (Laravel
-- mini_test_scores — TeacherPortalController::scores / scoresStore). Điểm tổng = trung bình 4 kỹ năng.
-- +goose Up
CREATE TABLE academic.mini_test_scores (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    class_id         uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    student_id       uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    -- Giáo viên nhập điểm
    teacher_id       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    syllabus_unit_id uuid, -- FK tới bảng Unit của phân hệ Giáo trình thêm khi gộp
    -- Tên bài: "Unit 3: …" (theo Unit) hoặc tên tự nhập khi lớp chưa gắn giáo trình
    name             text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    score            numeric(6, 2) CHECK (score >= 0),
    max_score        numeric(6, 2) NOT NULL DEFAULT 10 CHECK (max_score > 0 AND max_score <= 100),
    -- {"listening": 8, "speaking": 7.5, "reading": 9, "writing": 6}
    skill_scores     jsonb CHECK (skill_scores IS NULL OR jsonb_typeof(skill_scores) = 'object'),
    test_date        date NOT NULL,
    note             text CHECK (length(note) <= 1000),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (class_id, student_id, name, test_date)
);
CREATE INDEX mini_test_scores_student_idx ON academic.mini_test_scores (student_id, test_date DESC);
CREATE INDEX mini_test_scores_class_idx ON academic.mini_test_scores (class_id, test_date);
CREATE INDEX mini_test_scores_teacher_idx ON academic.mini_test_scores (teacher_id, test_date) WHERE teacher_id IS NOT NULL;

-- +goose Down
DROP TABLE academic.mini_test_scores;
