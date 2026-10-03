<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\RoomService;
use App\Support\DataScope;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quản lý phòng học (mockup "Quản lý phòng học"): danh sách phòng theo chi nhánh + khung giờ lớp đang dùng phòng,
 * Thêm / Sửa phòng (modal), Xóa phòng (Admin), tab Tra cứu phòng trống. Danh mục loại phòng: RoomTypeController.
 *
 * Phạm vi dữ liệu "room.scope_*": Học vụ / Quản lý cơ sở chỉ thấy và thêm phòng ở chi nhánh của mình;
 * tài khoản ở mức chi nhánh mà chưa được gán chi nhánh nào thấy màn chặn "liên hệ Admin".
 */
class RoomController extends Controller
{
    use RendersModals;

    public function __construct(private readonly RoomService $rooms) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $shared = $this->shared($user);
        if ($shared['blocked']) {
            return Inertia::render('Rooms/Index', $shared + ['rooms' => null]);
        }

        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'room_type_id' => ['nullable', 'integer'],
        ]);

        $rooms = Room::query()
            ->visibleTo($user)
            ->with(['branch:id,name', 'type'])
            ->when($filters['branch_id'] ?? null, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($filters['room_type_id'] ?? null, fn ($query, $id) => $query->where('room_type_id', $id))
            ->orderBy('branch_id')
            ->orderBy('name')
            ->paginate($request->perPage(15))
            ->withQueryString();

        $usage = $this->rooms->usage($rooms->getCollection()->pluck('id'));

        return Inertia::render('Rooms/Index', $shared + [
            'rooms' => $rooms->through(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'branch' => $room->branch?->name,
                'type' => $room->type?->name,
                'type_active' => (bool) $room->type?->is_active && ! $room->type?->trashed(),
                'capacity' => $room->capacity,
                'classes' => $usage->get($room->id, []),
            ]),
            'types' => Ui::options(RoomType::query()->orderByDesc('is_active')->orderBy('name')->get(), fn (RoomType $t) => $t->displayName()),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->formView($request->user(), null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $room = Room::create($data);

        return $this->modalSaved("Đã lưu phòng học {$room->name}.", route('rooms.index'));
    }

    public function edit(Request $request, Room $room): Response
    {
        $this->authorizeRoom($request->user(), $room);

        return $this->formView($request->user(), $room);
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $this->authorizeRoom($request->user(), $room);
        $data = $this->validated($request, $room);
        $oldName = $room->name;

        DB::transaction(function () use ($room, $data, $oldName) {
            $room->update($data);
            $this->rooms->syncName($room, $oldName);
        });

        return $this->modalSaved("Đã cập nhật phòng học {$room->name}.", route('rooms.index'));
    }

    /**
     * Xóa phòng (Admin): phòng đang có lớp học → chặn; phòng chỉ gán cho lớp chưa bắt đầu học → bỏ liên kết phòng
     * khỏi các lớp đó rồi xóa (xóa mềm, lớp cũ vẫn hiện tên phòng).
     */
    public function destroy(Request $request, Room $room): RedirectResponse
    {
        $this->authorizeRoom($request->user(), $room);
        $classes = $room->openClasses()->orderBy('name')->get();
        $studying = $classes->filter(fn (ClassModel $class) => $class->isStudying());
        if ($studying->isNotEmpty()) {
            return back()->with('error', "Không thể xóa: {$room->name} đang được lớp {$studying->pluck('name')->implode(', ')} sử dụng.");
        }

        DB::transaction(function () use ($room, $classes) {
            $this->rooms->detach($room, $classes);
            $room->delete();
        });

        return back()->with('success', "Đã xóa {$room->name}."
            .($classes->isNotEmpty() ? " Đã bỏ liên kết phòng khỏi lớp {$classes->pluck('name')->implode(', ')}." : ''));
    }

    /**
     * Tra cứu phòng trống: phòng của chi nhánh không có buổi học chồng giờ trong khung giờ của ngày đã chọn.
     * Lỗi bộ lọc hiện dưới từng ô, giữ nguyên giá trị đã nhập (không chuyển trang).
     */
    public function availability(Request $request): Response
    {
        $user = $request->user();
        $shared = $this->shared($user);
        $input = [
            'branch_id' => $request->query('branch_id', $shared['lockedBranch']['id'] ?? null),
            'date' => $request->query('date'),
            'start' => $request->query('start'),
            'end' => $request->query('end'),
            'room_type_id' => $request->query('room_type_id'),
        ];
        $searched = $request->has('date');
        $errors = [];
        $result = null;

        if (! $shared['blocked'] && $searched) {
            $validator = validator($input, [
                'branch_id' => ['required', 'integer', Rule::in(array_column($shared['branches'], 'value'))],
                'date' => ['required', 'date_format:Y-m-d'],
                'start' => ['required', 'date_format:H:i'],
                'end' => ['required', 'date_format:H:i', 'after:start'],
                'room_type_id' => ['nullable', 'integer'],
            ], [
                'branch_id.required' => 'Vui lòng chọn chi nhánh',
                'branch_id.in' => 'Chi nhánh nằm ngoài phạm vi bạn được xem',
                'date.required' => 'Vui lòng chọn ngày',
                'date.date_format' => 'Ngày không hợp lệ',
                'start.required' => 'Vui lòng chọn khoảng giờ',
                'start.date_format' => 'Giờ bắt đầu không hợp lệ',
                'end.required' => 'Vui lòng chọn khoảng giờ',
                'end.date_format' => 'Giờ kết thúc không hợp lệ',
                'end.after' => 'Giờ kết thúc phải sau giờ bắt đầu',
            ]);

            if ($validator->fails()) {
                $errors = collect($validator->errors()->messages())->map(fn (array $messages) => $messages[0])->all();
            } else {
                $found = $this->rooms->available((int) $input['branch_id'], $input['date'], $input['start'], $input['end'], $input['room_type_id'] ? (int) $input['room_type_id'] : null);
                $result = [
                    'branch' => collect($shared['branches'])->firstWhere('value', (int) $input['branch_id'])['label'] ?? '',
                    'total' => $found['total'],
                    'holiday' => $found['holiday'],
                    'branch_has_rooms' => Room::where('branch_id', $input['branch_id'])->exists(),
                    'rooms' => $found['rooms']->map(fn (Room $room) => [
                        'id' => $room->id,
                        'name' => $room->name,
                        'type' => $room->type?->name,
                        'capacity' => $room->capacity,
                    ])->all(),
                ];
            }
        }

        return Inertia::render('Rooms/Availability', $shared + [
            'filters' => $input,
            'filterErrors' => $errors,
            'result' => $result,
            'types' => Ui::options(RoomType::query()->orderByDesc('is_active')->orderBy('name')->get(), fn (RoomType $t) => $t->displayName()),
        ]);
    }

    /**
     * Dữ liệu chung của các tab Phòng học: chi nhánh trong phạm vi, chi nhánh cố định (Học vụ một chi nhánh),
     * màn chặn khi chưa được gán chi nhánh, số đếm trên tab.
     *
     * @return array<string, mixed>
     */
    public function shared(User $user): array
    {
        $branchIds = DataScope::branchIds($user, 'room');
        $branches = Branch::query()->active()
            ->when($branchIds !== null, fn ($query) => $query->whereIn('id', $branchIds))
            ->orderBy('name')
            ->get(['id', 'name']);
        $blocked = $branchIds !== null && $branchIds === [];

        return [
            'blocked' => $blocked,
            'branches' => Ui::options($branches, 'name'),
            // Phạm vi chi nhánh với đúng một chi nhánh: ô chi nhánh cố định, không cho đổi.
            'lockedBranch' => $branchIds !== null && $branches->count() === 1 ? ['id' => $branches->first()->id, 'name' => $branches->first()->name] : null,
            'counts' => [
                'rooms' => $blocked ? 0 : Room::query()->visibleTo($user)->count(),
                'types' => RoomType::count(),
            ],
            'canManage' => $user->can('room.manage'),
            'canDelete' => $user->can('room.delete'),
            'canManageTypes' => $user->can('room.manage_types'),
        ];
    }

    /** Thêm / Sửa phòng: modal; mở thẳng URL → trang đầy đủ. Chưa có loại phòng đang dùng → form khoá + nhắc cấu hình loại phòng. */
    private function formView(User $user, ?Room $room): Response
    {
        $shared = $this->shared($user);
        abort_if($shared['blocked'], 403, 'Tài khoản chưa được gán chi nhánh, vui lòng liên hệ Admin.');

        $types = RoomType::query()->active()->orderBy('name')->get();
        $typeOptions = Ui::options($types, 'name');
        if ($room && ! $types->contains('id', $room->room_type_id) && $room->type) {
            array_unshift($typeOptions, ['value' => $room->room_type_id, 'label' => $room->type->displayName()]);
        }

        return $this->modalPage('Rooms/Form', [
            'room' => $room ? [
                'id' => $room->id,
                'name' => $room->name,
                'branch_id' => $room->branch_id,
                'branch' => $room->branch?->name,
                'room_type_id' => $room->room_type_id,
                'capacity' => $room->capacity,
                'description' => $room->description,
            ] : null,
            'branches' => $shared['branches'],
            'lockedBranch' => $shared['lockedBranch'],
            'types' => $typeOptions,
            'canManageTypes' => $shared['canManageTypes'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Room $room): array
    {
        $user = $request->user();
        $branchIds = DataScope::branchIds($user, 'room');
        // Sửa phòng: chi nhánh cố định (muốn chuyển chi nhánh thì tạo phòng mới).
        $branchId = $room ? $room->branch_id : (int) $request->input('branch_id');

        $data = $request->validate([
            'branch_id' => $room ? ['nullable'] : ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at'), Rule::when($branchIds !== null, [Rule::in($branchIds ?? [])])],
            'name' => ['required', 'string', 'max:100', Rule::unique('rooms', 'name')->where('branch_id', $branchId)->whereNull('deleted_at')->ignore($room?->id)],
            'room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->whereNull('deleted_at')->where(fn ($query) => $query->where('is_active', true)->when($room, fn ($q) => $q->orWhere('id', $room->room_type_id)))],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'branch_id.required' => 'Vui lòng chọn chi nhánh.',
            'branch_id.in' => 'Bạn chỉ được thêm phòng cho chi nhánh của mình.',
            'name.required' => 'Vui lòng nhập tên phòng.',
            'name.max' => 'Tên phòng tối đa 100 ký tự.',
            'name.unique' => 'Tên phòng đã tồn tại trong chi nhánh này',
            'room_type_id.required' => 'Vui lòng chọn loại phòng.',
            'room_type_id.exists' => 'Loại phòng đã ngừng dùng hoặc không tồn tại.',
            'capacity.integer' => 'Sức chứa phải là số nguyên lớn hơn 0',
            'capacity.min' => 'Sức chứa phải là số nguyên lớn hơn 0',
            'capacity.max' => 'Sức chứa tối đa 999 chỗ.',
        ]);

        $data['name'] = trim($data['name']);
        $data['branch_id'] = $branchId;

        return $data;
    }

    private function authorizeRoom(User $user, Room $room): void
    {
        abort_unless(DataScope::coversBranch($user, 'room', $room->branch_id), 403, 'Phòng học này thuộc chi nhánh ngoài phạm vi bạn được quản lý.');
    }

    /**
     * Phòng của các chi nhánh cho ô "Phòng học" ở Tạo / Sửa lớp (lọc theo chi nhánh lớp ở phía Vue), kèm lớp đang dùng.
     *
     * @param  iterable<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    public static function classFormOptions(iterable $branchIds, ?int $excludeClassId = null): array
    {
        $rooms = Room::query()->with('type')->whereIn('branch_id', collect($branchIds)->all())->orderBy('name')->get();
        $usage = app(RoomService::class)->usage($rooms->pluck('id'));

        return $rooms->map(fn (Room $room) => [
            'value' => $room->id,
            'label' => $room->optionLabel(),
            'name' => $room->name,
            'branch_id' => $room->branch_id,
            'capacity' => $room->capacity,
            'classes' => collect($usage->get($room->id, []))->reject(fn (array $c) => $c['id'] === $excludeClassId)->values()->all(),
        ])->values()->all();
    }
}
