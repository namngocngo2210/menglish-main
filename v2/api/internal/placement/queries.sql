
-- name: ListTests :many
SELECT t.id, t.code, t.title, t.target_level, t.grade_level, t.duration_minutes, t.questions_count,
       t.is_active, t.is_preset, t.created_at,
       (count(s.id) FILTER (WHERE s.lead_id IS NULL OR @all_leads::boolean OR s.lead_id = ANY(@lead_ids::uuid[])))::int AS submissions_count
FROM placement.tests t
LEFT JOIN placement.submissions s ON s.test_id = t.id
WHERE t.deleted_at IS NULL
GROUP BY t.id
ORDER BY t.created_at DESC, t.id DESC;

-- name: GetTest :one
SELECT * FROM placement.tests WHERE id = $1 AND deleted_at IS NULL;

-- name: TestCodeTaken :one
SELECT EXISTS (SELECT 1 FROM placement.tests WHERE code = $1);

-- name: CreateTest :one
INSERT INTO placement.tests (code, title, description, target_level, grade_level, duration_minutes, questions_count,
                             questions, pdf_path, audio_url, is_active, is_preset, created_by)
VALUES (@code, @title, @description, @target_level, @grade_level, @duration_minutes, @questions_count,
        @questions, @pdf_path, @audio_url, @is_active, @is_preset, @created_by)
RETURNING *;

-- name: UpdateTest :one
UPDATE placement.tests
SET title = @title, description = @description, target_level = @target_level, grade_level = @grade_level,
    duration_minutes = @duration_minutes, questions_count = @questions_count, questions = @questions,
    pdf_path = @pdf_path, audio_url = @audio_url, is_active = @is_active,
    updated_at = now(), version = version + 1
WHERE id = @id AND deleted_at IS NULL
RETURNING *;

-- name: SetTestActive :one
UPDATE placement.tests SET is_active = @is_active, updated_at = now(), version = version + 1
WHERE id = @id AND deleted_at IS NULL
RETURNING *;

-- name: SoftDeleteTest :exec
UPDATE placement.tests SET deleted_at = now(), is_active = false, updated_at = now(), version = version + 1
WHERE id = $1 AND deleted_at IS NULL;

-- name: CountTestSubmissions :one
SELECT count(*)::int FROM placement.submissions WHERE test_id = $1;


-- name: CreateInvitation :one
INSERT INTO placement.invitations (test_id, token_hash, kind, lead_id, candidate_name, candidate_phone, candidate_email,
                                   note, expires_at, max_submissions, created_by)
VALUES (@test_id, @token_hash, @kind, @lead_id, @candidate_name, @candidate_phone, @candidate_email,
        @note, @expires_at, @max_submissions, @created_by)
RETURNING *;

-- name: ListInvitations :many
SELECT i.*, u.full_name AS created_by_name
FROM placement.invitations i
LEFT JOIN iam.users u ON u.id = i.created_by
WHERE i.test_id = $1
ORDER BY i.created_at DESC
LIMIT 100;

-- name: GetInvitation :one
SELECT * FROM placement.invitations WHERE id = $1;

-- name: RevokeInvitation :one
UPDATE placement.invitations SET revoked_at = now(), revoked_by = @revoked_by, updated_at = now()
WHERE id = @id AND revoked_at IS NULL
RETURNING *;

-- name: InvitationByToken :one
SELECT * FROM placement.invitations WHERE token_hash = $1;

-- name: LockInvitationByToken :one
SELECT * FROM placement.invitations WHERE token_hash = $1 FOR UPDATE;

-- name: MarkInvitationOpened :exec
UPDATE placement.invitations SET opened_at = coalesce(opened_at, now()), updated_at = now() WHERE id = $1;

-- name: CountInvitationUse :exec
UPDATE placement.invitations SET submission_count = submission_count + 1, updated_at = now() WHERE id = $1;


-- name: CreateSubmission :one
INSERT INTO placement.submissions (test_id, invitation_id, lead_id, candidate_name, candidate_phone, candidate_email,
                                   grade_group, listening_score, reading_score, reading_writing_score,
                                   listening_comment, reading_writing_comment, writing_content, answers,
                                   violation_count, violation_log, auto_submitted)
VALUES (@test_id, @invitation_id, @lead_id, @candidate_name, @candidate_phone, @candidate_email,
        @grade_group, @listening_score, @reading_score, @reading_writing_score,
        @listening_comment, @reading_writing_comment, @writing_content, @answers,
        @violation_count, @violation_log, @auto_submitted)
RETURNING *;

-- name: ListSubmissions :many
-- Bài làm gần đây trong phạm vi được xem (bài chưa gắn khách hoặc khách CRM người xem thấy), lọc theo đề.
SELECT s.id, s.number, s.test_id, s.lead_id, s.candidate_name, s.candidate_phone, s.grade_group,
       s.listening_score, s.reading_score, s.writing_score, s.speaking_score, s.reading_writing_score,
       s.overall_score, s.total_score, s.suggested_class, s.chosen_class, s.recommended_course,
       s.status, s.violation_count, s.auto_submitted, s.created_at,
       t.code AS test_code, t.title AS test_title
FROM placement.submissions s
JOIN placement.tests t ON t.id = s.test_id
WHERE (sqlc.narg(test_id)::uuid IS NULL OR s.test_id = sqlc.narg(test_id))
  AND (s.lead_id IS NULL OR @all_leads::boolean OR s.lead_id = ANY(@lead_ids::uuid[]))
ORDER BY s.created_at DESC, s.id DESC
LIMIT @lim;

-- name: SubmissionStats :one
SELECT count(*)::int AS total, (count(*) FILTER (WHERE status = 'pending'))::int AS pending
FROM placement.submissions s
WHERE s.lead_id IS NULL OR @all_leads::boolean OR s.lead_id = ANY(@lead_ids::uuid[]);

-- name: GetSubmission :one
SELECT s.*, t.code AS test_code, t.title AS test_title, t.questions AS test_questions, t.pdf_path AS test_pdf_path,
       g.full_name AS grader_name
FROM placement.submissions s
JOIN placement.tests t ON t.id = s.test_id
LEFT JOIN iam.users g ON g.id = s.grader_id
WHERE s.id = $1;

-- name: LockSubmission :one
SELECT s.*, t.code AS test_code
FROM placement.submissions s
JOIN placement.tests t ON t.id = s.test_id
WHERE s.id = $1
FOR UPDATE OF s;

-- name: SaveGrade :one
UPDATE placement.submissions
SET grade_group = @grade_group, listening_score = @listening_score, reading_writing_score = @reading_writing_score,
    speaking_score = @speaking_score, total_score = @total_score, overall_score = @total_score,
    suggested_class = @suggested_class, chosen_class = @chosen_class, recommended_course = @chosen_class,
    listening_comment = @listening_comment, reading_writing_comment = @reading_writing_comment,
    speaking_comment = @speaking_comment, teacher_comments = @teacher_comments,
    grader_id = @grader_id, status = @status,
    graded_at = CASE WHEN @status = 'graded' THEN now() ELSE graded_at END,
    updated_at = now(), version = version + 1
WHERE id = @id
RETURNING *;


-- name: LeadTrialBookings :many
SELECT * FROM crm.trial_bookings WHERE lead_id = $1 ORDER BY session_date, start_time;

-- name: LockLeadTrials :exec
-- Khóa theo khách (thay khóa dòng crm.leads của Laravel): hai người xếp cùng lúc không vượt 2 lượt học thử.
SELECT pg_advisory_xact_lock(hashtextextended('crm.trial_bookings:' || CAST(@lead_id::uuid AS text), 0));

-- name: CreateTrialBooking :one
INSERT INTO crm.trial_bookings (lead_id, class_id, class_session_id, branch_id, session_date, start_time, end_time,
                                notes, booked_by)
VALUES (@lead_id, @class_id, @class_session_id, @branch_id, @session_date, @start_time, @end_time, @notes, @booked_by)
RETURNING *;

-- name: GetTrialBooking :one
SELECT * FROM crm.trial_bookings WHERE id = $1;

-- name: CancelTrialBooking :one
UPDATE crm.trial_bookings
SET status = 'cancelled', notes = @notes, updated_at = now(), version = version + 1
WHERE id = @id AND status = 'scheduled'
RETURNING *;

-- name: SaveTrialFeedback :one
UPDATE crm.trial_bookings
SET status = @status, rating = @rating, remarks = @remarks, feedback = @feedback,
    feedback_by = @feedback_by, feedback_at = now(), updated_at = now(), version = version + 1
WHERE id = @id
RETURNING *;

-- name: ListTrialSchedule :many
-- "Lịch học thử": buổi học thử của khách trong phạm vi CRM người xem; sắp tới tăng dần, đã diễn ra giảm dần.
SELECT b.*, bb.full_name AS booked_by_name, fb.full_name AS feedback_by_name, br.name AS branch_name
FROM crm.trial_bookings b
LEFT JOIN iam.users bb ON bb.id = b.booked_by
LEFT JOIN iam.users fb ON fb.id = b.feedback_by
LEFT JOIN core.branches br ON br.id = b.branch_id
WHERE (@all_leads::boolean OR b.lead_id = ANY(@lead_ids::uuid[]))
  AND (sqlc.narg(status)::text IS NULL OR b.status = sqlc.narg(status))
  AND CASE WHEN @past::boolean THEN b.session_date < @today::date ELSE b.session_date >= @today::date END
ORDER BY
  CASE WHEN @past::boolean THEN b.session_date END DESC,
  CASE WHEN @past::boolean THEN b.start_time END DESC,
  CASE WHEN NOT @past::boolean THEN b.session_date END ASC,
  CASE WHEN NOT @past::boolean THEN b.start_time END ASC,
  b.id
LIMIT @lim OFFSET @off;

-- name: CountTrialSchedule :one
SELECT count(*)::int FROM crm.trial_bookings b
WHERE (@all_leads::boolean OR b.lead_id = ANY(@lead_ids::uuid[]))
  AND (sqlc.narg(status)::text IS NULL OR b.status = sqlc.narg(status))
  AND CASE WHEN @past::boolean THEN b.session_date < @today::date ELSE b.session_date >= @today::date END;

-- name: ListTeacherTrials :many
-- "Nhận xét học thử": khách học thử (chưa hủy) trên buổi giáo viên dạy / lớp phụ trách; phạm vi CRM "Toàn hệ thống" thấy hết.
SELECT b.*, fb.full_name AS feedback_by_name
FROM crm.trial_bookings b
LEFT JOIN iam.users fb ON fb.id = b.feedback_by
WHERE b.status <> 'cancelled'
  AND (@all_trials::boolean OR b.class_session_id = ANY(@session_ids::uuid[]) OR b.class_id = ANY(@class_ids::uuid[]))
  AND CASE WHEN @past::boolean THEN b.session_date < @today::date ELSE b.session_date >= @today::date END
ORDER BY
  CASE WHEN @past::boolean THEN b.session_date END DESC,
  CASE WHEN NOT @past::boolean THEN b.session_date END ASC,
  CASE WHEN NOT @past::boolean THEN b.start_time END ASC,
  b.id
LIMIT @lim OFFSET @off;

-- name: CountTeacherTrials :one
SELECT count(*)::int FROM crm.trial_bookings b
WHERE b.status <> 'cancelled'
  AND (@all_trials::boolean OR b.class_session_id = ANY(@session_ids::uuid[]) OR b.class_id = ANY(@class_ids::uuid[]))
  AND CASE WHEN @past::boolean THEN b.session_date < @today::date ELSE b.session_date >= @today::date END;
