<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\CoverRegistration;
use App\Models\CoverRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\CoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CoverRequestController extends Controller
{
    public function __construct(private CoverService $covers) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $approver = $user->can('cover.approve');
        abort_unless($approver || $user->can('class.teach'), 403);
        abort_if($request->has('status') && ! $approver, 403);
        $data = $request->validate(['status' => ['sometimes', 'required', Rule::in(['open', 'approved', 'cancelled'])]]);

        return response()->json(['data' => $this->list($user, $data['status'] ?? null)]);
    }

    /** Teacher-portal page: open requests for the team, my own sessions that can ask for cover, and the approval list. */
    public function board(Request $request): Response
    {
        $user = $request->user();
        $approver = $user->can('cover.approve');
        abort_unless($approver || $user->can('class.teach'), 403);
        $sessions = $user->can('cover.request') || $approver
            ? ClassSession::query()->with('classModel:id,name')
                ->where(fn ($q) => $q->where('teacher_id', $user->id)->orWhere('foreign_teacher_id', $user->id))
                ->where('status', 'scheduled')->whereDate('date', '>=', today())
                ->whereDoesntHave('coverRequests', fn ($q) => $q->whereIn('status', ['open', 'approved']))
                ->orderBy('date')->orderBy('start_time')->limit(30)->get()
                ->filter(fn (ClassSession $s) => $s->startsAt()->isFuture())
                ->map(fn (ClassSession $s) => ['id' => $s->id, 'date' => $s->date->toDateString(), 'start_time' => $s->start_time->format('H:i'),
                    'end_time' => $s->end_time->format('H:i'), 'class_name' => $s->classModel?->name])->values()
            : collect();

        return Inertia::render('Cover/Index', [
            'covers' => $this->list($user, null, $approver ? ['open', 'approved'] : null),
            'sessions' => $sessions,
            'canApprove' => $approver,
            'userId' => $user->id,
        ]);
    }

    private function list(User $user, ?string $status, ?array $statuses = null)
    {
        $approver = $user->can('cover.approve');
        $query = CoverRequest::query()->with(['classSession', 'requester:id,name'])->withCount('registrations')
            ->withExists(['registrations as i_registered' => fn ($q) => $q->where('teacher_id', $user->id)]);
        if ($statuses) {
            $query->whereIn('status', $statuses);
        } elseif ($status !== null) {
            $query->where('status', $status);
        } elseif ($approver) {
            $query->where('status', 'open');
        } else {
            $query->where(fn ($q) => $q->where('status', 'open')->orWhere('requested_by', $user->id)
                ->orWhere(fn ($q) => $q->where('status', 'approved')->where('approved_user_id', $user->id)));
        }
        if ($approver) {
            $query->with('registrations.teacher:id,name');
        }

        return $query->orderByDesc('id')->get()->map(fn (CoverRequest $cover) => $this->payload($cover, $user));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('cover.request') || $request->user()->can('cover.approve'), 403);
        $data = $request->validate([
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
            'reason' => ['nullable', 'string'],
        ]);
        $cover = $this->covers->create(ClassSession::findOrFail($data['class_session_id']), $request->user(), $data['reason'] ?? null);

        return response()->json($this->payload($cover, $request->user()), 201);
    }

    public function register(Request $request, CoverRequest $coverRequest): JsonResponse
    {
        $cover = $this->covers->register($coverRequest, $request->user());

        return response()->json($this->payload($cover, $request->user()));
    }

    public function unregister(Request $request, CoverRequest $coverRequest): JsonResponse
    {
        $cover = $this->covers->unregister($coverRequest, $request->user());

        return response()->json($this->payload($cover, $request->user()));
    }

    public function approve(Request $request, CoverRequest $coverRequest): JsonResponse
    {
        abort_unless($request->user()->can('cover.approve'), 403);
        $data = $request->validate(['teacher_id' => ['required', 'integer']]);
        $cover = $this->covers->approve($coverRequest, $request->user(), (int) $data['teacher_id']);

        return response()->json($this->payload($cover, $request->user()));
    }

    public function reopen(Request $request, CoverRequest $coverRequest): JsonResponse
    {
        $cover = $this->covers->reopen($coverRequest, $request->user());

        return response()->json($this->payload($cover, $request->user()));
    }

    public function cancel(Request $request, CoverRequest $coverRequest): JsonResponse
    {
        $cover = $this->covers->cancel($coverRequest, $request->user());

        return response()->json($this->payload($cover, $request->user()));
    }

    private function payload(CoverRequest $cover, User $viewer): array
    {
        $cover->loadMissing(['classSession', 'requester:id,name']);
        $session = $cover->classSession;
        $approver = $viewer->can('cover.approve');
        $data = [
            'id' => $cover->id,
            'status' => $cover->status,
            'reason' => $cover->reason,
            'session' => [
                'id' => $session->id,
                'date' => $session->date->toDateString(),
                'start_time' => $session->start_time->format('H:i'),
                'end_time' => $session->end_time->format('H:i'),
                'shift_name' => $session->shift_name,
                'branch_id' => $session->branch_id,
            ],
            'requested_by' => ['id' => $cover->requested_by, 'name' => $cover->requester?->name],
            'registrations_count' => $cover->registrations_count ?? $cover->registrations()->count(),
            'i_registered' => (bool) ($cover->i_registered ?? $cover->registrations()->where('teacher_id', $viewer->id)->exists()),
        ];
        if ($approver || (int) $cover->requested_by === (int) $viewer->id
            || ($cover->status === 'approved' && (int) $cover->approved_user_id === (int) $viewer->id)) {
            $class = $session->classModel;
            $data['class'] = $class ? ['id' => $class->id, 'name' => $class->name, 'code' => $class->code] : null;
            $data['students'] = $class?->rosterStudents()->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->name])->all() ?? [];
        }
        if ($approver) {
            $cover->loadMissing('registrations.teacher:id,name');
            $data['registrations'] = $cover->registrations->map(fn (CoverRegistration $registration) => [
                'teacher_id' => $registration->teacher_id,
                'name' => $registration->teacher?->name,
            ])->all();
        }

        return $data;
    }
}
