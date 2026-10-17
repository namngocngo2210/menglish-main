package placement

import (
	_ "embed"
	"encoding/json"
	"math"
	"strings"
)

// Thang điểm test đầu vào theo khối lớp (Laravel PlacementRubricService, BA chốt Q2 — 25/09/2026).
// Tổng = Nghe + Đọc & Viết + Nói (điểm thô theo thang khối) → lớp đề xuất; nhận xét từng kỹ năng gợi ý theo băng điểm.
// rubric.json trích nguyên hằng RUBRICS của Laravel (không sửa tay; Laravel đổi thì trích lại).

//go:embed rubric.json
var rubricJSON []byte

// ManualGroup: khối không có thang điểm (lớp 5–9, IELTS, người đi làm, mầm non) — Học vụ chọn lớp thủ công.
const ManualGroup = "khac"

// Skills theo thứ tự hiển thị.
var Skills = []string{"listening", "reading_writing", "speaking"}

var skillLabels = map[string]string{
	"listening":       "Nghe (Listening)",
	"reading_writing": "Đọc & Viết (Reading & Writing)",
	"speaking":        "Nói (Speaking)",
}

// SkillLabel: nhãn kỹ năng.
func SkillLabel(skill string) string { return skillLabels[skill] }

// Band: băng điểm của một kỹ năng (cận dưới Min).
type Band struct {
	Min   float64 `json:"min"`
	Range string  `json:"range"`
	Text  string  `json:"text"`
}

// Placement: băng tổng điểm → lớp đề xuất (LT: tổng < LT; LTE: tổng ≤ LTE; không cận: băng cao nhất).
type Placement struct {
	LT    *float64 `json:"lt"`
	LTE   *float64 `json:"lte"`
	Range string   `json:"range"`
	Class string   `json:"class"`
}

// MaxScores: điểm tối đa từng kỹ năng.
type MaxScores struct {
	Listening      float64 `json:"listening"`
	ReadingWriting float64 `json:"reading_writing"`
	Speaking       float64 `json:"speaking"`
}

func (m MaxScores) of(skill string) float64 {
	switch skill {
	case "listening":
		return m.Listening
	case "reading_writing":
		return m.ReadingWriting
	default:
		return m.Speaking
	}
}

// Total: tổng điểm tối đa.
func (m MaxScores) Total() float64 { return m.Listening + m.ReadingWriting + m.Speaking }

// Rubric: thang điểm của một khối.
type Rubric struct {
	Key        string            `json:"key"`
	Label      string            `json:"label"`
	Level      string            `json:"level"`
	Max        MaxScores         `json:"max"`
	Bands      map[string][]Band `json:"bands"`
	Placements []Placement       `json:"placements"`
}

type rubricFile struct {
	ManualLabel string   `json:"manual_label"`
	Notice      string   `json:"notice"`
	Groups      []Rubric `json:"groups"`
}

var (
	rubrics     []Rubric
	rubricByKey = map[string]*Rubric{}
	manualLabel string
	// NoRubricNotice: thông báo cho khối không có thang điểm.
	NoRubricNotice string
)

// Khối chưa có rubric: không giới hạn chặt thang từng kỹ năng, chỉ chặn số vô lý (lớp 8–9 không test Nói).
var manualMax = MaxScores{100, 100, 100}

// onlineManualScale: thang quy đổi khi tự chấm bài online cho khối không có rubric (tham khảo, không xếp lớp).
var onlineManualScale = MaxScores{10, 10, 10}

func init() {
	var f rubricFile
	if err := json.Unmarshal(rubricJSON, &f); err != nil {
		panic("placement: rubric.json: " + err.Error())
	}
	rubrics = f.Groups
	for i := range rubrics {
		rubricByKey[rubrics[i].Key] = &rubrics[i]
	}
	manualLabel = f.ManualLabel
	NoRubricNotice = f.Notice
}

// GradeGroup: khối lớp (4 khối có thang điểm + nhóm chọn lớp thủ công).
type GradeGroup struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// GradeGroups theo thứ tự hiển thị.
func GradeGroups() []GradeGroup {
	out := make([]GradeGroup, 0, len(rubrics)+1)
	for _, r := range rubrics {
		out = append(out, GradeGroup{r.Key, r.Label})
	}
	return append(out, GradeGroup{ManualGroup, manualLabel})
}

// IsValidGroup: khóa khối hợp lệ.
func IsValidGroup(g string) bool { return g == ManualGroup || rubricByKey[g] != nil }

// HasRubric: khối có thang điểm.
func HasRubric(g string) bool { return rubricByKey[g] != nil }

// GroupLabel: nhãn khối.
func GroupLabel(g string) string {
	if g == ManualGroup {
		return manualLabel
	}
	if r := rubricByKey[g]; r != nil {
		return r.Label
	}
	return "Chưa chọn khối lớp"
}

// MaxFor: điểm tối đa từng kỹ năng của khối.
func MaxFor(g string) MaxScores {
	if r := rubricByKey[g]; r != nil {
		return r.Max
	}
	return manualMax
}

// DetectGradeGroup: khối mặc định theo mã đề (người chấm chọn lại được). Lớp 5 trở lên / IELTS / mầm non → thủ công.
func DetectGradeGroup(code string) string {
	c := strings.ToUpper(code)
	switch {
	case strings.Contains(c, "PRE-G1"), strings.Contains(c, "PRE_G1"):
		return ManualGroup
	case strings.Contains(c, "G1-G2"), strings.Contains(c, "G1-G3"):
		return "khoi_1_2"
	case strings.Contains(c, "G2-G3"):
		return "khoi_2_3"
	case strings.Contains(c, "G3-G4"):
		return "khoi_3_4"
	case strings.Contains(c, "G4-G5"), strings.Contains(c, "G4-G6"):
		return "khoi_4_5"
	}
	return ManualGroup
}

// SuggestClass: lớp đề xuất theo tổng điểm ("" nếu khối chưa có thang).
func SuggestClass(g string, total float64) string {
	r := rubricByKey[g]
	if r == nil {
		return ""
	}
	for _, p := range r.Placements {
		switch {
		case p.LT != nil:
			if total < *p.LT {
				return p.Class
			}
		case p.LTE != nil:
			if total <= *p.LTE {
				return p.Class
			}
		default:
			return p.Class
		}
	}
	return ""
}

// SkillComment: nhận xét gợi ý theo băng điểm ("" nếu không có thang hoặc chưa có điểm).
func SkillComment(g, skill string, score *float64) string {
	r := rubricByKey[g]
	if r == nil || score == nil {
		return ""
	}
	text := ""
	for _, b := range r.Bands[skill] {
		if *score >= b.Min {
			text = b.Text
		}
	}
	return text
}

// ClassOptions: lớp đề xuất của mọi khối (gợi ý khi chọn lại lớp).
func ClassOptions() []string {
	seen := map[string]bool{}
	var out []string
	for _, r := range rubrics {
		for _, p := range r.Placements {
			if !seen[p.Class] {
				seen[p.Class] = true
				out = append(out, p.Class)
			}
		}
	}
	return out
}

// round1 làm tròn 1 chữ số thập phân như PHP round($x, 1).
func round1(v float64) float64 { return math.Round(v*10) / 10 }

// Evaluation: kết quả chấm theo khối.
type Evaluation struct {
	Total          float64
	SuggestedClass string
	Comments       map[string]string
}

// Evaluate: tổng điểm, lớp đề xuất và nhận xét gợi ý từng kỹ năng.
func Evaluate(g string, listening, readingWriting, speaking float64) Evaluation {
	total := round1(listening + readingWriting + speaking)
	return Evaluation{
		Total:          total,
		SuggestedClass: SuggestClass(g, total),
		Comments: map[string]string{
			"listening":       SkillComment(g, "listening", &listening),
			"reading_writing": SkillComment(g, "reading_writing", &readingWriting),
			"speaking":        SkillComment(g, "speaking", &speaking),
		},
	}
}

// RubricConfig: cấu hình gửi cho giao diện (form chấm, trang hướng dẫn) — tính tổng / lớp / nhận xét ngay trên form.
type RubricConfig struct {
	Groups       []RubricGroupConfig `json:"groups"`
	Notice       string              `json:"notice"`
	Skills       []GradeGroup        `json:"skills"`
	ClassOptions []string            `json:"class_options"`
	Rubrics      []Rubric            `json:"rubrics"`
}

// RubricGroupConfig: cấu hình gọn của một khối.
type RubricGroupConfig struct {
	Key        string            `json:"key"`
	Label      string            `json:"label"`
	HasRubric  bool              `json:"has_rubric"`
	Max        MaxScores         `json:"max"`
	Bands      map[string][]Band `json:"bands"`
	Placements []Placement       `json:"placements"`
}

// Config trả cấu hình thang điểm cho giao diện.
func Config() RubricConfig {
	cfg := RubricConfig{Notice: NoRubricNotice, ClassOptions: ClassOptions(), Rubrics: rubrics}
	for _, g := range GradeGroups() {
		gc := RubricGroupConfig{Key: g.Value, Label: g.Label, Max: MaxFor(g.Value), Bands: map[string][]Band{}, Placements: []Placement{}}
		if r := rubricByKey[g.Value]; r != nil {
			gc.HasRubric, gc.Bands, gc.Placements = true, r.Bands, r.Placements
		}
		cfg.Groups = append(cfg.Groups, gc)
	}
	for _, s := range Skills {
		cfg.Skills = append(cfg.Skills, GradeGroup{s, skillLabels[s]})
	}
	return cfg
}
