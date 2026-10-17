package catalogs

import (
	"context"
	"fmt"
	"slices"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Phòng học theo chi nhánh (RoomController) và danh mục loại phòng (RoomTypeController).
// Phạm vi dữ liệu room.scope_branch | room.scope_all: Học vụ / Quản lý cơ sở chỉ thấy và thêm phòng ở chi nhánh của mình.

const roomsPageSize = 15

var (
	errRoomNotFound     = notFound("Không tìm thấy phòng học.")
	errRoomTypeNotFound = notFound("Không tìm thấy loại phòng.")
	errRoomOutOfScope   = &iam.Error{Status: 403, Code: "outside_branch", Message: "Phòng học này thuộc chi nhánh ngoài phạm vi bạn được quản lý."}
	errNoBranch         = &iam.Error{Status: 403, Code: "no_branch", Message: "Tài khoản chưa được gán chi nhánh, vui lòng liên hệ Admin."}
)

type BranchOption struct {
	ID   uuid.UUID `json:"id"`
	Code string    `json:"code"`
	Name string    `json:"name"`
}

// RoomScope là dữ liệu chung của các tab Phòng học: chi nhánh trong phạm vi, chi nhánh cố định, màn chặn, số đếm.
type RoomScope struct {
	Blocked bool           `json:"blocked"`
	Branch  []BranchOption `json:"branches"`
	// Phạm vi chi nhánh với đúng một chi nhánh: ô chi nhánh cố định, không cho đổi
	LockedBranch *BranchOption `json:"locked_branch"`
	Counts       struct {
		Rooms int64 `json:"rooms"`
		Types int64 `json:"types"`
	} `json:"counts"`
}

func (s *Service) roomScope(ctx context.Context, actor *iam.Access) (RoomScope, []uuid.UUID, error) {
	var sc RoomScope
	limit := actor.BranchLimit("room")
	sc.Blocked = limit != nil && len(limit) == 0
	rows, err := s.q.ScopeBranches(ctx, limit)
	if err != nil {
		return sc, nil, err
	}
	sc.Branch = make([]BranchOption, 0, len(rows))
	for _, b := range rows {
		sc.Branch = append(sc.Branch, BranchOption{ID: b.ID, Code: b.Code, Name: b.Name})
	}
	if limit != nil && len(sc.Branch) == 1 {
		sc.LockedBranch = &sc.Branch[0]
	}
	if !sc.Blocked {
		if sc.Counts.Rooms, err = s.q.CountRooms(ctx, limit); err != nil {
			return sc, nil, err
		}
	}
	if sc.Counts.Types, err = s.q.CountRoomTypes(ctx); err != nil {
		return sc, nil, err
	}
	return sc, limit, nil
}

type Room struct {
	ID          uuid.UUID `json:"id"`
	BranchID    uuid.UUID `json:"branch_id"`
	Branch      string    `json:"branch"`
	RoomTypeID  uuid.UUID `json:"room_type_id"`
	Type        string    `json:"type"`
	TypeActive  bool      `json:"type_active"`
	Name        string    `json:"name"`
	Capacity    *int32    `json:"capacity"`
	Description *string   `json:"description"`
	// Lớp còn mở đang gắn phòng (tên lớp, ca học tuần)
	Classes []RoomClass `json:"classes"`
}

type RoomTypeOption struct {
	Value    uuid.UUID `json:"value"`
	Label    string    `json:"label"`
	IsActive bool      `json:"is_active"`
}

type RoomList struct {
	RoomScope
	Rooms *Page[Room]      `json:"rooms"` // nil khi bị chặn
	Types []RoomTypeOption `json:"types"`
}

type RoomFilter struct {
	BranchID   *uuid.UUID
	RoomTypeID *uuid.UUID
	Page       int
}

func (s *Service) ListRooms(ctx context.Context, actor *iam.Access, f RoomFilter) (RoomList, error) {
	sc, limit, err := s.roomScope(ctx, actor)
	if err != nil {
		return RoomList{}, err
	}
	out := RoomList{RoomScope: sc}
	if out.Types, err = s.roomTypeOptions(ctx); err != nil {
		return out, err
	}
	if sc.Blocked {
		return out, nil
	}
	page, lim, off := limits(f.Page, roomsPageSize)
	rows, err := s.q.ListRooms(ctx, catalogsdb.ListRoomsParams{Scope: limit, BranchID: f.BranchID, RoomTypeID: f.RoomTypeID, Lim: lim, Off: off})
	if err != nil {
		return out, err
	}
	var total int64
	items := make([]Room, 0, len(rows))
	for _, r := range rows {
		total = r.Total
		items = append(items, Room{
			ID: r.ID, BranchID: r.BranchID, Branch: r.BranchName, RoomTypeID: r.RoomTypeID, Type: r.TypeName, TypeActive: r.TypeActive,
			Name: r.Name, Capacity: r.Capacity, Description: r.Description, Classes: []RoomClass{},
		})
	}
	if err := s.attachRoomClasses(ctx, items); err != nil {
		return out, err
	}
	p := newPage(items, total, page, roomsPageSize)
	out.Rooms = &p
	return out, nil
}

// roomTypeOptions: mọi loại phòng, loại đang dùng trước; loại ngừng dùng kèm "(ngừng dùng)".
func (s *Service) roomTypeOptions(ctx context.Context) ([]RoomTypeOption, error) {
	rows, err := s.q.ListRoomTypes(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]RoomTypeOption, 0, len(rows))
	for _, t := range rows {
		label := t.Name
		if !t.IsActive {
			label += " (ngừng dùng)"
		}
		out = append(out, RoomTypeOption{Value: t.ID, Label: label, IsActive: t.IsActive})
	}
	return out, nil
}

type RoomInput struct {
	BranchID    *uuid.UUID `json:"branch_id"` // chỉ dùng khi thêm; sửa phòng giữ nguyên chi nhánh
	Name        string     `json:"name"`
	RoomTypeID  *uuid.UUID `json:"room_type_id"`
	Capacity    *int32     `json:"capacity"`
	Description string     `json:"description"`
}

// validateRoom kiểm dữ liệu phòng; currentType: loại hiện tại của phòng đang sửa (được giữ dù đã ngừng dùng).
func (s *Service) validateRoom(ctx context.Context, in *RoomInput, currentType *uuid.UUID) error {
	in.Name = strings.TrimSpace(in.Name)
	in.Description = strings.TrimSpace(in.Description)
	switch {
	case in.Name == "":
		return iam.Invalid("Vui lòng nhập tên phòng.")
	case tooLong(in.Name, 100):
		return iam.Invalid("Tên phòng tối đa 100 ký tự.")
	case in.RoomTypeID == nil:
		return iam.Invalid("Vui lòng chọn loại phòng.")
	case in.Capacity != nil && *in.Capacity < 1:
		return iam.Invalid("Sức chứa phải là số nguyên lớn hơn 0")
	case in.Capacity != nil && *in.Capacity > 999:
		return iam.Invalid("Sức chứa tối đa 999 chỗ.")
	case tooLong(in.Description, 2000):
		return iam.Invalid("Mô tả tối đa 2000 ký tự.")
	}
	t, err := s.q.GetRoomType(ctx, *in.RoomTypeID)
	if isNoRows(err) || (err == nil && !t.IsActive && (currentType == nil || *currentType != t.ID)) {
		return iam.Invalid("Loại phòng đã ngừng dùng hoặc không tồn tại.")
	}
	return err
}

func roomNameErr(err error) error {
	if isUnique(err, "rooms_branch_name_uq") {
		return iam.Invalid("Tên phòng đã tồn tại trong chi nhánh này")
	}
	return err
}

func (s *Service) CreateRoom(ctx context.Context, actor *iam.Access, in RoomInput) (uuid.UUID, string, error) {
	limit := actor.BranchLimit("room")
	if limit != nil && len(limit) == 0 {
		return uuid.Nil, "", errNoBranch
	}
	if in.BranchID == nil {
		return uuid.Nil, "", iam.Invalid("Vui lòng chọn chi nhánh.")
	}
	if limit != nil && !slices.Contains(limit, *in.BranchID) {
		return uuid.Nil, "", iam.Invalid("Bạn chỉ được thêm phòng cho chi nhánh của mình.")
	}
	if ok, err := s.q.BranchExists(ctx, *in.BranchID); err != nil {
		return uuid.Nil, "", err
	} else if !ok {
		return uuid.Nil, "", iam.Invalid("Chi nhánh đã chọn không tồn tại trong hệ thống.")
	}
	if err := s.validateRoom(ctx, &in, nil); err != nil {
		return uuid.Nil, "", err
	}
	id, err := s.q.CreateRoom(ctx, catalogsdb.CreateRoomParams{
		BranchID: *in.BranchID, RoomTypeID: *in.RoomTypeID, Name: in.Name, Capacity: in.Capacity,
		Description: optText(in.Description), CreatedBy: &actor.UserID,
	})
	if err != nil {
		return uuid.Nil, "", roomNameErr(err)
	}
	s.log.Info("room created", "room_id", id, "branch_id", *in.BranchID, "by", actor.UserID)
	return id, fmt.Sprintf("Đã lưu phòng học %s.", in.Name), nil
}

// scopedRoom: phòng trong phạm vi chi nhánh của người thao tác.
func (s *Service) scopedRoom(ctx context.Context, actor *iam.Access, id uuid.UUID) (catalogsdb.GetRoomRow, error) {
	r, err := s.q.GetRoom(ctx, id)
	if isNoRows(err) {
		return r, errRoomNotFound
	}
	if err != nil {
		return r, err
	}
	if !actor.CoversBranch("room", &r.BranchID) {
		return r, errRoomOutOfScope
	}
	return r, nil
}

func (s *Service) GetRoom(ctx context.Context, actor *iam.Access, id uuid.UUID) (Room, error) {
	r, err := s.scopedRoom(ctx, actor, id)
	if err != nil {
		return Room{}, err
	}
	out := []Room{{ID: r.ID, BranchID: r.BranchID, Branch: r.BranchName, RoomTypeID: r.RoomTypeID, Name: r.Name,
		Capacity: r.Capacity, Description: r.Description, Classes: []RoomClass{}}}
	if err := s.attachRoomClasses(ctx, out); err != nil {
		return Room{}, err
	}
	return out[0], nil
}

// attachRoomClasses: điền lớp đang dùng từng phòng (RoomService::usage).
func (s *Service) attachRoomClasses(ctx context.Context, rooms []Room) error {
	if s.Classes == nil || len(rooms) == 0 {
		return nil
	}
	ids := make([]uuid.UUID, 0, len(rooms))
	for _, r := range rooms {
		ids = append(ids, r.ID)
	}
	usage, err := s.Classes.RoomClasses(ctx, ids)
	if err != nil {
		return err
	}
	for i := range rooms {
		if cs := usage[rooms[i].ID]; len(cs) > 0 {
			rooms[i].Classes = cs
		}
	}
	return nil
}

func (s *Service) UpdateRoom(ctx context.Context, actor *iam.Access, id uuid.UUID, in RoomInput) (string, error) {
	r, err := s.scopedRoom(ctx, actor, id)
	if err != nil {
		return "", err
	}
	if err := s.validateRoom(ctx, &in, &r.RoomTypeID); err != nil {
		return "", err
	}
	// Đổi tên phòng thì chép tên mới sang lớp / buổi học sắp tới còn dùng tên cũ (RoomService::syncName).
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		err := s.q.WithTx(tx).UpdateRoom(ctx, catalogsdb.UpdateRoomParams{
			ID: id, RoomTypeID: *in.RoomTypeID, Name: in.Name, Capacity: in.Capacity, Description: optText(in.Description),
		})
		if err != nil || s.Classes == nil || r.Name == in.Name {
			return err
		}
		return s.Classes.RoomRenamed(ctx, tx, id, r.BranchID, r.Name, in.Name)
	})
	if err != nil {
		return "", roomNameErr(err)
	}
	s.log.Info("room updated", "room_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã cập nhật phòng học %s.", in.Name), nil
}

func (s *Service) DeleteRoom(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	r, err := s.scopedRoom(ctx, actor, id)
	if err != nil {
		return "", err
	}
	// Phòng đang có lớp đang học thì chặn; lớp chưa bắt đầu học thì bỏ liên kết phòng khỏi các lớp đó rồi xóa.
	var detached []string
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if s.Classes != nil {
			var err error
			if detached, err = s.Classes.RoomDeleting(ctx, tx, id, r.Name); err != nil {
				return err
			}
		}
		return s.q.WithTx(tx).DeleteRoom(ctx, id)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("room deleted", "room_id", id, "by", actor.UserID)
	msg := fmt.Sprintf("Đã xóa %s.", r.Name)
	if len(detached) > 0 {
		msg += fmt.Sprintf(" Đã bỏ liên kết phòng khỏi lớp %s.", strings.Join(detached, ", "))
	}
	return msg, nil
}

// ───────────────────────── Loại phòng ─────────────────────────

type RoomType struct {
	ID       uuid.UUID `json:"id"`
	Name     string    `json:"name"`
	IsActive bool      `json:"is_active"`
	InUse    bool      `json:"in_use"`
}

type RoomTypeList struct {
	RoomScope
	RoomTypes []RoomType `json:"room_types"`
}

func (s *Service) ListRoomTypes(ctx context.Context, actor *iam.Access) (RoomTypeList, error) {
	sc, _, err := s.roomScope(ctx, actor)
	if err != nil {
		return RoomTypeList{}, err
	}
	rows, err := s.q.ListRoomTypes(ctx)
	if err != nil {
		return RoomTypeList{}, err
	}
	out := RoomTypeList{RoomScope: sc, RoomTypes: make([]RoomType, 0, len(rows))}
	for _, t := range rows {
		out.RoomTypes = append(out.RoomTypes, RoomType{ID: t.ID, Name: t.Name, IsActive: t.IsActive, InUse: t.InUse})
	}
	return out, nil
}

func roomTypeName(name string) (string, error) {
	name = strings.TrimSpace(name)
	switch {
	case name == "":
		return "", iam.Invalid("Vui lòng nhập tên loại phòng.")
	case tooLong(name, 100):
		return "", iam.Invalid("Tên loại phòng tối đa 100 ký tự.")
	}
	return name, nil
}

func roomTypeErr(err error) error {
	if isUnique(err, "room_types_name_uq") {
		return iam.Invalid("Loại phòng này đã có trong danh mục.")
	}
	return err
}

func (s *Service) CreateRoomType(ctx context.Context, actor *iam.Access, name string) (RoomType, string, error) {
	name, err := roomTypeName(name)
	if err != nil {
		return RoomType{}, "", err
	}
	t, err := s.q.CreateRoomType(ctx, catalogsdb.CreateRoomTypeParams{Name: name, CreatedBy: &actor.UserID})
	if err != nil {
		return RoomType{}, "", roomTypeErr(err)
	}
	s.log.Info("room type created", "room_type_id", t.ID, "by", actor.UserID)
	return RoomType{ID: t.ID, Name: t.Name, IsActive: t.IsActive}, fmt.Sprintf("Đã thêm loại phòng %s.", t.Name), nil
}

func (s *Service) RenameRoomType(ctx context.Context, actor *iam.Access, id uuid.UUID, name string) (string, error) {
	name, err := roomTypeName(name)
	if err != nil {
		return "", err
	}
	t, err := s.q.RenameRoomType(ctx, catalogsdb.RenameRoomTypeParams{ID: id, Name: name})
	if isNoRows(err) {
		return "", errRoomTypeNotFound
	}
	if err != nil {
		return "", roomTypeErr(err)
	}
	s.log.Info("room type renamed", "room_type_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã lưu loại phòng %s.", t.Name), nil
}

// ToggleRoomType: Ngừng dùng ↔ Dùng lại.
func (s *Service) ToggleRoomType(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	t, err := s.q.ToggleRoomType(ctx, id)
	if isNoRows(err) {
		return "", errRoomTypeNotFound
	}
	if err != nil {
		return "", err
	}
	s.log.Info("room type toggled", "room_type_id", id, "active", t.IsActive, "by", actor.UserID)
	if t.IsActive {
		return fmt.Sprintf("Đã dùng lại loại phòng %s.", t.Name), nil
	}
	return fmt.Sprintf("Đã ngừng dùng loại phòng %s.", t.Name), nil
}

func (s *Service) DeleteRoomType(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	t, err := s.q.GetRoomType(ctx, id)
	if isNoRows(err) {
		return "", errRoomTypeNotFound
	}
	if err != nil {
		return "", err
	}
	if used, err := s.q.RoomTypeInUse(ctx, id); err != nil {
		return "", err
	} else if used {
		return "", conflict("Loại phòng đang được sử dụng — chỉ có thể Ngừng dùng")
	}
	if err := s.q.DeleteRoomType(ctx, id); err != nil {
		return "", err
	}
	s.log.Info("room type deleted", "room_type_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã xóa loại phòng %s.", t.Name), nil
}
