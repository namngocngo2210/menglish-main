package syllabus

import (
	"context"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
)

// Khóa SLA của Big Test (config/sla.php).
const (
	rulePaperReminder = "big_test.paper_reminder"
	rulePaperApproval = "big_test.paper_approval"
	rulePaperMissing  = "big_test.paper_missing"
	ruleResultsLate   = "big_test.results_late"
)

// Mặc định khi chưa đồng bộ hr.sla_rules (catalogs/sla/sla.json).
var slaDefaults = map[string]catalogs.SlaRule{
	rulePaperReminder: {Key: rulePaperReminder, Unit: "days", Value: 7, Enabled: true},
	rulePaperApproval: {Key: rulePaperApproval, Unit: "days", Value: 3, Enabled: true},
	rulePaperMissing:  {Key: rulePaperMissing, Unit: "hours", Value: 24, Enabled: true, HasPenalty: true, Penalty: true},
	ruleResultsLate: {Key: ruleResultsLate, Unit: "days", Value: 7, Enabled: true, HasPenalty: true, Penalty: true,
		Amount: 50000},
	ruleAdjustment: {Key: ruleAdjustment, Unit: "days", Value: 3, Enabled: true},
}

// rule: cấu hình hiệu lực của một SLA (Sla::rule), lỗi / chưa có thì dùng mặc định.
func (s *Service) rule(ctx context.Context, key string) catalogs.SlaRule {
	if s.sla != nil {
		if r, err := s.sla.SlaRule(ctx, key); err == nil {
			return r
		}
	}
	return slaDefaults[key]
}

// warnDays: cảnh báo / nhắc duyệt đề từ N ngày trước ngày thi (BigTestSlaService::warnDays).
func (s *Service) warnDays(ctx context.Context) int { return int(s.rule(ctx, rulePaperReminder).Value) }

// approveBeforeDays: đề phải phân phối trước ngày thi ít nhất N ngày (BigTestOrder::leadDays).
func (s *Service) approveBeforeDays(ctx context.Context) int {
	return int(s.rule(ctx, rulePaperApproval).Value)
}

// resultDeadlineDays: số ngày trả kết quả cho phụ huynh kể từ ngày thi (BigTest::resultDeadlineDays).
func (s *Service) resultDeadlineDays(ctx context.Context) int {
	return int(s.rule(ctx, ruleResultsLate).Value)
}
