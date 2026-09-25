<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\MediaManagerService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
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

        $tickets = $query->paginate($request->perPage(15))->withQueryString();

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

        return view('support-tickets.index', compact('tickets', 'stats'));
    }

    public function create()
    {
        $staffs = Auth::user()->can('support_ticket.assign')
            ? User::where('is_active', true)->get()
            : collect();

        return view('support-tickets.create', compact('staffs'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:technical_issue,curriculum,tuition,customer_complaint,other',
            'priority' => 'required|string|in:low,medium,high,urgent',
            'description' => 'required|string',
            'assignee_id' => 'nullable|exists:users,id',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xlsx|max:15360',
        ]);

        abort_if(! empty($validated['assignee_id']) && ! Auth::user()->can('support_ticket.assign'), 403);

        $attachmentPath = $this->handleUploadedFiles($request);

        $ticket = SupportTicket::create([
            'code' => SupportTicket::generateCode(),
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

        // Bắn thông báo chuông cho những người trong luồng ticket
        $this->notificationService->notifyTicketCreated($ticket);

        return redirect()->route('tickets.show', $ticket->id)
            ->with('status', "Đã tạo phiếu yêu cầu hỗ trợ / báo lỗi {$ticket->code} thành công!");
    }

    public function show($id)
    {
        $ticket = SupportTicket::with(['creator', 'assignee', 'messages.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail();
        $this->authorizeTicketParticipant($ticket);

        $canSeeInternal = $ticket->userCanSeeInternalNotes(Auth::user());
        if (! $canSeeInternal) {
            $ticket->setRelation('messages', $ticket->messages->reject(fn ($m) => $m->is_internal_note)->values());
        }

        $canPostInternal = $this->canManageTickets();
        $staffs = Auth::user()->can('support_ticket.assign')
            ? User::where('is_active', true)->get()
            : collect();

        return view('support-tickets.show', compact('ticket', 'staffs', 'canPostInternal'));
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

        // Nếu ticket đang ở trạng thái open mà có phản hồi, chuyển sang in_progress
        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        // Bắn thông báo phản hồi mới cho những người trong luồng ticket
        $this->notificationService->notifyTicketMessage($ticket, $msg, Auth::user());

        return redirect()->back()->with('status', 'Đã gửi phản hồi thành công!');
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

        // Bắn thông báo đổi trạng thái ticket
        $this->notificationService->notifyTicketStatusChanged($ticket, $validated['status'], Auth::user());

        return redirect()->back()->with('status', "Đã cập nhật trạng thái ticket sang: {$ticket->status_label}!");
    }

    public function assign(Request $request, $id)
    {
        $ticket = SupportTicket::where('id', $id)->orWhere('code', $id)->firstOrFail();

        $validated = $request->validate([
            'assignee_id' => 'required|exists:users,id',
        ]);

        $ticket->update(['assignee_id' => $validated['assignee_id']]);

        // Bắn thông báo cho người được phân công
        $assignee = User::find($validated['assignee_id']);
        if ($assignee) {
            $this->notificationService->notifyTicketAssigned($ticket, $assignee, Auth::user());
        }

        return redirect()->back()->with('status', "Đã phân công xử lý ticket cho {$ticket->assignee?->name}!");
    }

    /**
     * Lưu file tải lên theo cây thư mục /uploads/YYYY/MM/DD/
     */
    private function handleUploadedFiles(Request $request): ?string
    {
        $savedPaths = [];
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $relativeFolder = "uploads/{$year}/{$month}/{$day}";
        $destinationPath = public_path($relativeFolder);

        if (! File::isDirectory($destinationPath)) {
            File::makeDirectory($destinationPath, 0755, true, true);
        }

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
                    $file->move($destinationPath, $filename);
                    $savedPaths[] = "{$relativeFolder}/{$filename}";
                }
            }
        }

        // 2. Xử lý ảnh dán trực tiếp từ Clipboard (Base64) nếu có
        if ($request->filled('pasted_images')) {
            $pastedData = $request->input('pasted_images');
            $images = is_array($pastedData) ? $pastedData : json_decode($pastedData, true);

            if (is_array($images)) {
                foreach ($images as $base64) {
                    if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
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
                                $filename = time().'_pasted_'.Str::random(8).'.'.$type;
                                file_put_contents($destinationPath.'/'.$filename, $decoded);
                                $savedPaths[] = "{$relativeFolder}/{$filename}";
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
