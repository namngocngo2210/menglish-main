<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\MediaManagerService;
use App\Services\NotificationService;
use App\Support\Ui;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

class SupportTicketController extends Controller
{
    use RendersModals;

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /** Danh sách ticket: "Tạo Ticket Mới" mở modal 2xl, "Trao đổi" mở modal 3xl (hội thoại + trả lời). */
    public function index(Request $request): Response
    {
        $query = SupportTicket::with(['creator', 'assignee'])->latest();

        if (! $this->canManageTickets()) {
            $query->where(function ($query) {
                $query->where('creator_id', Auth::id())
                    ->orWhere('assignee_id', Auth::id());
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tickets = $query->paginate($request->perPage(15))->withQueryString()
            ->through(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'title' => $ticket->title,
                'category_label' => $ticket->category_label,
                'priority' => $ticket->priority,
                'priority_badge' => $ticket->priority_badge,
                'status_label' => $ticket->status_label,
                'status_badge' => $ticket->status_badge,
                'creator' => $ticket->creator?->name,
                'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at->toIso8601String(),
            ]);

        $statsQuery = SupportTicket::query();
        if (! $this->canManageTickets()) {
            $statsQuery->where(function ($query) {
                $query->where('creator_id', Auth::id())
                    ->orWhere('assignee_id', Auth::id());
            });
        }

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'open' => (clone $statsQuery)->where('status', 'open')->count(),
            'in_progress' => (clone $statsQuery)->where('status', 'in_progress')->count(),
            'resolved' => (clone $statsQuery)->where('status', 'resolved')->count(),
        ];

        return Inertia::render('SupportTickets/Index', ['tickets' => $tickets, 'stats' => $stats]);
    }

    /** Tạo ticket: mở từ danh sách → modal; mở thẳng URL → trang riêng. */
    public function create(): Response
    {
        $staffs = Auth::user()->can('support_ticket.assign')
            ? $this->ticketHandlers()
            : collect();

        return $this->modalPage('SupportTickets/Create', ['staffs' => Ui::options($staffs, 'name')]);
    }

    /** Ticket giống hệt (cùng người tạo, tiêu đề, mô tả) tạo trong khoảng này coi là gửi lặp, không tạo thêm. */
    private const DUPLICATE_WINDOW_MINUTES = 10;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:technical_issue,curriculum,tuition,customer_complaint,other',
            'priority' => 'required|string|in:low,medium,high,urgent',
            'description' => 'required|string',
            'assignee_id' => 'nullable|exists:users,id',
            'submission_token' => 'nullable|string|max:64',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xlsx|max:15360',
        ]);

        abort_if(! empty($validated['assignee_id']) && ! Auth::user()->can('support_ticket.assign'), 403);
        if (! empty($validated['assignee_id'])) {
            $this->ensureValidHandler((int) $validated['assignee_id']);
        }

        $token = $validated['submission_token'] ?? null;

        // Gửi lặp (bấm lại sau khi mạng lỗi / gateway timeout trong khi lần đầu đã lưu): trả về ticket đã có.
        if ($existing = $this->findSubmittedTicket($token, $validated)) {
            return $this->ticketAlreadyCreated($existing);
        }

        $attachmentPath = $this->handleUploadedFiles($request);

        try {
            $ticket = DB::transaction(function () use ($validated, $token, $attachmentPath) {
                $ticket = SupportTicket::create([
                    'code' => SupportTicket::generateCode(),
                    'submission_token' => $token,
                    'title' => $validated['title'],
                    'category' => $validated['category'],
                    'priority' => $validated['priority'],
                    'description' => $validated['description'],
                    'attachment_path' => $attachmentPath,
                    'creator_id' => Auth::id(),
                    'assignee_id' => $validated['assignee_id'] ?? null,
                    'status' => 'open',
                ]);

                // Tạo tin nhắn khởi tạo đầu tiên
                TicketMessage::create([
                    'support_ticket_id' => $ticket->id,
                    'user_id' => Auth::id(),
                    'message' => $validated['description'],
                    'attachment_path' => $attachmentPath,
                    'is_internal_note' => false,
                ]);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Hai request cùng mã lần gửi chạy song song: request kia đã lưu trước → bỏ file vừa lưu, trả về ticket đó.
            $existing = $this->findSubmittedTicket($token, $validated);
            if (! $existing) {
                throw $e;
            }
            $this->deleteStoredFiles($attachmentPath);

            return $this->ticketAlreadyCreated($existing);
        }

        // Bắn thông báo chuông + email cho những người trong luồng ticket (sau khi đã trả phản hồi)
        defer(fn () => $this->notificationService->notifyTicketCreated($ticket));

        return $this->modalSaved("Đã tạo phiếu yêu cầu hỗ trợ / báo lỗi {$ticket->code} thành công!", route('tickets.show', $ticket->id));
    }

    /**
     * Ticket mà lần gửi này đã tạo trước đó: cùng mã lần gửi của form, hoặc (form cũ không có mã / mở lại form gõ lại)
     * ticket cùng người tạo, cùng tiêu đề và mô tả trong DUPLICATE_WINDOW_MINUTES phút gần nhất.
     */
    private function findSubmittedTicket(?string $token, array $validated): ?SupportTicket
    {
        $mine = SupportTicket::where('creator_id', Auth::id());

        if ($token && ($ticket = (clone $mine)->where('submission_token', $token)->first())) {
            return $ticket;
        }

        return (clone $mine)
            ->where('title', $validated['title'])
            ->where('description', $validated['description'])
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->latest('id')
            ->first();
    }

    private function ticketAlreadyCreated(SupportTicket $ticket)
    {
        return $this->modalSaved("Ticket {$ticket->code} đã được tạo trước đó, hệ thống không tạo thêm bản trùng.", route('tickets.show', $ticket->id));
    }

    private function deleteStoredFiles(?string $attachmentPath): void
    {
        $paths = $attachmentPath ? (json_decode($attachmentPath, true) ?: [$attachmentPath]) : [];
        Storage::disk(self::ATTACHMENT_DISK)->delete($paths);
    }

    /** Chi tiết ticket: mở từ danh sách → modal (hội thoại + ô trả lời); mở thẳng URL → trang đầy đủ. */
    public function show($id): Response
    {
        $ticket = SupportTicket::with(['creator', 'assignee', 'messages.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail();
        $this->authorizeTicketParticipant($ticket);

        $canSeeInternal = $ticket->userCanSeeInternalNotes(Auth::user());
        if (! $canSeeInternal) {
            $ticket->setRelation('messages', $ticket->messages->reject(fn ($m) => $m->is_internal_note)->values());
        }

        $canPostInternal = $this->canManageTickets();
        $staffs = Auth::user()->can('support_ticket.assign')
            ? $this->ticketHandlers()
            : collect();

        return $this->modalPage('SupportTickets/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'title' => $ticket->title,
                'category_label' => $ticket->category_label,
                'priority' => $ticket->priority,
                'priority_badge' => $ticket->priority_badge,
                'status' => $ticket->status,
                'status_label' => $ticket->status_label,
                'status_badge' => $ticket->status_badge,
                'is_finished' => $ticket->isFinished(),
                'creator' => $ticket->creator?->name,
                'assignee_id' => $ticket->assignee_id,
                'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at->toIso8601String(),
            ],
            // Cũ trước, mới nhất ở cuối (quan hệ messages sắp mới → cũ).
            'messages' => $ticket->messages->reverse()->values()->map(fn (TicketMessage $msg) => [
                'id' => $msg->id,
                'user' => $msg->user?->name,
                'message' => $msg->message,
                'is_internal_note' => (bool) $msg->is_internal_note,
                'created_at' => $msg->created_at->toIso8601String(),
                'attachments' => collect($msg->attachment_list)->map(fn (string $file) => [
                    'name' => basename($file),
                    'ext' => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                    'url' => route('tickets.attachment', ['id' => $ticket->id, 'path' => $file]),
                ])->values()->all(),
            ])->all(),
            'staffs' => Ui::options($staffs, 'name'),
            'canPostInternal' => $canPostInternal,
            'canReopen' => $ticket->userCanReopen(Auth::user()),
        ]);
    }

    public function storeMessage(Request $request, $id)
    {
        $ticket = SupportTicket::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $this->authorizeTicketParticipant($ticket);

        $validated = $request->validate([
            'message' => 'required|string',
            'is_internal_note' => 'nullable|boolean',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xlsx|max:15360',
        ]);

        abort_if($request->boolean('is_internal_note') && ! $this->canManageTickets(), 403);

        $attachmentPath = $this->handleUploadedFiles($request);

        $msg = TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'message' => $validated['message'],
            'attachment_path' => $attachmentPath,
            'is_internal_note' => $request->boolean('is_internal_note'),
        ]);

        // Người xử lý (không phải người tạo) phản hồi → "Đang xử lý"; người tạo trả lời ticket đã giải quyết
        // → mở lại để người xử lý thấy.
        $byCreator = (int) Auth::id() === (int) $ticket->creator_id;
        if (($ticket->status === 'open' && ! $byCreator) || ($ticket->status === 'resolved' && $byCreator && ! $msg->is_internal_note)) {
            $ticket->update(['status' => 'in_progress']);
        }

        // Thông báo + email (SMTP, chậm) cho những người trong luồng ticket: chạy sau khi đã trả phản hồi.
        $sender = Auth::user();
        defer(fn () => $this->notificationService->notifyTicketMessage($ticket, $msg, $sender));

        return $this->ticketActionDone('Đã gửi phản hồi thành công!');
    }

    public function updateStatus(Request $request, $id)
    {
        $ticket = SupportTicket::where('id', $id)->orWhere('code', $id)->firstOrFail();

        $validated = $request->validate([
            'status' => 'required|string|in:open,in_progress,resolved,closed',
        ]);

        $ticket->update([
            'status' => $validated['status'],
            'resolved_at' => in_array($validated['status'], ['resolved', 'closed']) ? now() : null,
        ]);

        // Bắn thông báo đổi trạng thái ticket (sau khi đã trả phản hồi)
        $actor = Auth::user();
        defer(fn () => $this->notificationService->notifyTicketStatusChanged($ticket, $validated['status'], $actor));

        return $this->ticketActionDone("Đã cập nhật trạng thái ticket sang: {$ticket->status_label}!");
    }

    /**
     * Mở lại ticket đã giải quyết / đã đóng → "Đang xử lý". Người đổi được trạng thái (support_ticket.close) và người
     * tạo ticket (vấn đề chưa hết) bấm được. Ghi 1 dòng vào hội thoại để biết ai mở lại lúc nào; báo người liên quan.
     */
    public function reopen($id)
    {
        $ticket = SupportTicket::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $this->authorizeTicketParticipant($ticket);
        abort_unless($ticket->userCanReopen(Auth::user()), 403);

        if (! $ticket->isFinished()) {
            return $this->modalFailed('Ticket đang mở, không cần mở lại.', 'status');
        }

        $ticket->update(['status' => 'in_progress', 'resolved_at' => null]);
        TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'message' => 'Đã mở lại ticket, chuyển sang Đang xử lý.',
            'is_internal_note' => false,
        ]);

        $actor = Auth::user();
        defer(fn () => $this->notificationService->notifyTicketStatusChanged($ticket, 'in_progress', $actor));

        return $this->ticketActionDone('Đã mở lại ticket.');
    }

    public function assign(Request $request, $id)
    {
        $ticket = SupportTicket::where('id', $id)->orWhere('code', $id)->firstOrFail();

        $validated = $request->validate([
            'assignee_id' => 'required|exists:users,id',
        ]);
        $this->ensureValidHandler((int) $validated['assignee_id']);

        $ticket->update(['assignee_id' => $validated['assignee_id']]);

        // Bắn thông báo cho người được phân công
        $assignee = User::find($validated['assignee_id']);
        if ($assignee) {
            $actor = Auth::user();
            defer(fn () => $this->notificationService->notifyTicketAssigned($ticket, $assignee, $actor));
        }

        return $this->ticketActionDone("Đã phân công xử lý ticket cho {$ticket->assignee?->name}!");
    }

    /**
     * Xem / tải file đính kèm của ticket. Chỉ người trong luồng ticket (người tạo,
     * người xử lý, người quản lý ticket) được xem; file của ghi chú nội bộ chỉ
     * người được xem ghi chú nội bộ mới mở được. File mới lưu ở disk riêng tư
     * (storage/app/private/tickets/...); file cũ (public/uploads/...) vẫn phục vụ
     * qua route này để giữ liên kết.
     */
    public function attachment(Request $request, $id)
    {
        $ticket = SupportTicket::with('messages')->where('id', $id)->orWhere('code', $id)->firstOrFail();
        $this->authorizeTicketParticipant($ticket);

        $path = (string) $request->query('path', '');
        $canSeeInternal = $ticket->userCanSeeInternalNotes(Auth::user());

        $allowed = collect($ticket->attachment_list)
            ->merge($ticket->messages
                ->filter(fn (TicketMessage $message) => $canSeeInternal || ! $message->is_internal_note)
                ->flatMap(fn (TicketMessage $message) => $message->attachment_list));

        abort_unless($path !== '' && $allowed->contains($path), 404);
        abort_if(str_contains($path, '..'), 404);

        if (str_starts_with($path, 'uploads/')) {
            // Tương thích dữ liệu cũ lưu trong public/uploads.
            $absolute = public_path($path);
            abort_unless(is_file($absolute), 404);

            return response()->file($absolute, ['X-Content-Type-Options' => 'nosniff']);
        }

        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($path, basename($path), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Kết thúc thao tác trên ticket (phản hồi / đổi trạng thái / phân công): quay lại trang trước kèm thông báo.
     * Trong modal (<UiForm stay>): trang nền (danh sách) cập nhật, modal giữ mở và tải lại hội thoại mới.
     */
    private function ticketActionDone(string $message)
    {
        return redirect()->back()->with('status', $message);
    }

    /** Disk riêng tư lưu file đính kèm ticket (không truy cập trực tiếp qua URL công khai). */
    public const ATTACHMENT_DISK = 'local';

    /**
     * Lưu file tải lên vào disk riêng tư theo cây thư mục tickets/YYYY/MM/DD/.
     */
    private function handleUploadedFiles(Request $request): ?string
    {
        $savedPaths = [];
        $relativeFolder = 'tickets/'.date('Y').'/'.date('m').'/'.date('d');
        $disk = Storage::disk(self::ATTACHMENT_DISK);

        // 1. Xử lý file upload thông thường (hoặc qua kéo thả vào input file)
        if ($request->hasFile('attachments')) {
            $files = $request->file('attachments');
            if (! is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    // Đuôi file lấy từ nội dung (finfo) — không tin đuôi client, chống polyglot .php
                    $extension = MediaManagerService::safeExtension($file);
                    if ($extension === null) {
                        continue; // Nội dung không đoán được loại an toàn -> bỏ qua file
                    }
                    $cleanOriginal = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $cleanOriginal = Str::slug(Str::limit($cleanOriginal, 30, ''));
                    $filename = time().'_'.($cleanOriginal ? $cleanOriginal.'_' : '').Str::random(6).'.'.$extension;
                    $savedPaths[] = $file->storeAs($relativeFolder, $filename, self::ATTACHMENT_DISK);
                }
            }
        }

        // 2. Xử lý ảnh dán trực tiếp từ Clipboard (Base64) nếu có
        if ($request->filled('pasted_images')) {
            $pastedData = $request->input('pasted_images');
            $images = is_array($pastedData) ? $pastedData : json_decode($pastedData, true);

            if (is_array($images)) {
                foreach ($images as $base64) {
                    if (is_string($base64) && preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                        $base64Data = substr($base64, strpos($base64, ',') + 1);
                        $type = strtolower($type[1]); // jpg, png, gif, webp
                        if (in_array($type, ['jpeg', 'jpg', 'png', 'gif', 'webp'])) {
                            $decoded = base64_decode($base64Data, true);
                            $mime = $decoded === false ? false : (new \finfo(FILEINFO_MIME_TYPE))->buffer($decoded);
                            $allowedMimes = [
                                'jpeg' => 'image/jpeg',
                                'jpg' => 'image/jpeg',
                                'png' => 'image/png',
                                'gif' => 'image/gif',
                                'webp' => 'image/webp',
                            ];
                            if ($decoded !== false && strlen($decoded) <= 15 * 1024 * 1024 && ($allowedMimes[$type] ?? null) === $mime) {
                                $path = $relativeFolder.'/'.time().'_pasted_'.Str::random(8).'.'.$type;
                                $disk->put($path, $decoded);
                                $savedPaths[] = $path;
                            }
                        }
                    }
                }
            }
        }

        if (empty($savedPaths)) {
            return null;
        }

        return count($savedPaths) === 1 ? $savedPaths[0] : json_encode($savedPaths);
    }

    /**
     * Người được phân công xử lý ticket: nhân sự đang hoạt động có quyền
     * support_ticket.update (theo vai trò hoặc phân quyền cá nhân).
     */
    private function ticketHandlers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereNull('locked_at')
            ->orderBy('name')
            ->with(['permissionOverrides', 'roles', 'permissions'])
            ->get()
            ->filter(fn (User $user) => $user->can('support_ticket.update'))
            ->values();
    }

    private function ensureValidHandler(int $userId): void
    {
        $handler = User::query()->where('is_active', true)->whereNull('locked_at')->find($userId);

        if (! $handler || ! $handler->can('support_ticket.update')) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Chỉ phân công ticket cho nhân sự có quyền xử lý ticket.',
            ]);
        }
    }

    private function canManageTickets(): bool
    {
        return SupportTicket::userCanManage(Auth::user());
    }

    private function authorizeTicketParticipant(SupportTicket $ticket): void
    {
        abort_unless(
            $this->canManageTickets()
                || (int) $ticket->creator_id === (int) Auth::id()
                || (int) $ticket->assignee_id === (int) Auth::id(),
            403
        );
    }
}
