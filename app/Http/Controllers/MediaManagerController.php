<?php

namespace App\Http\Controllers;

use App\Services\MediaManagerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class MediaManagerController extends Controller
{
    protected MediaManagerService $mediaService;

    public function __construct(MediaManagerService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Màn hình quản lý toàn bộ tệp tin & media (hỗ trợ duyệt thư mục kiểu Google Drive)
     */
    public function index(Request $request)
    {
        $allFiles = $this->mediaService->getAllFiles();
        $stats = $this->mediaService->getStorageStats($allFiles);

        $currentFolder = trim($request->get('folder', ''), '/');

        $filters = [
            'search' => $request->get('search'),
            'type' => $request->get('type', 'all'),
            'directory' => $currentFolder ?: $request->get('directory', 'all'),
            'size_range' => $request->get('size_range', 'all'),
            'date_range' => $request->get('date_range', 'all'),
            'sort' => $request->get('sort', 'latest'),
        ];

        $filteredFiles = $this->mediaService->filterFiles($allFiles, $filters);

        $perPage = $request->perPage(24);
        $paginatedFiles = $this->mediaService->paginate(
            $filteredFiles,
            $perPage,
            $request->get('page', 1),
            ['path' => route('media.index'), 'query' => $request->query()]
        );

        $directories = $stats['directories'];
        $subFolders = $this->mediaService->getSubFolders($currentFolder);

        // Xây dựng thanh Breadcrumbs
        $breadcrumbs = [
            ['name' => 'Thư mục gốc (uploads)', 'path' => ''],
        ];
        if ($currentFolder) {
            $parts = explode('/', $currentFolder);
            $accum = '';
            foreach ($parts as $part) {
                $accum = $accum ? $accum.'/'.$part : $part;
                $breadcrumbs[] = [
                    'name' => $part,
                    'path' => $accum,
                ];
            }
        }

        return view('media.index', [
            'files' => $paginatedFiles,
            'stats' => $stats,
            'filters' => $filters,
            'directories' => $directories,
            'subFolders' => $subFolders,
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $breadcrumbs,
            'totalFilteredCount' => $filteredFiles->count(),
            'totalFilteredSize' => $this->mediaService->formatBytes($filteredFiles->sum('size')),
        ]);
    }

    /**
     * Tạo thư mục mới trên đĩa
     */
    public function createFolder(Request $request)
    {
        $validated = $request->validate([
            'folder_name' => 'required|string|max:100',
            'parent_folder' => 'nullable|string|max:200',
        ]);

        $result = $this->mediaService->createFolder($validated['folder_name'], $validated['parent_folder'] ?? '');

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, $result['success'] ? 200 : 400);
        }

        if ($result['success']) {
            $redirectQuery = ! empty($validated['parent_folder']) ? ['folder' => $validated['parent_folder']] : [];

            return redirect()->route('media.index', $redirectQuery)->with('status', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }

    /**
     * Xóa thư mục trên đĩa
     */
    public function deleteFolder(Request $request)
    {
        $validated = $request->validate([
            'folder_path' => 'required|string|max:255',
        ]);

        $result = $this->mediaService->deleteFolder($validated['folder_path']);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, $result['success'] ? 200 : 400);
        }

        if ($result['success']) {
            $parentPath = dirname($validated['folder_path']);
            $parentPath = ($parentPath === '.' || $parentPath === '/') ? '' : $parentPath;

            return redirect()->route('media.index', $parentPath ? ['folder' => $parentPath] : [])->with('status', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }

    /**
     * Di chuyển các file vào thư mục đích
     */
    public function moveFiles(Request $request)
    {
        $validated = $request->validate([
            'selected_files' => 'required|array|min:1',
            'target_folder' => 'required|string|max:255',
        ]);

        $result = $this->mediaService->moveFiles($validated['selected_files'], $validated['target_folder']);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, $result['success'] ? 200 : 400);
        }

        if ($result['success']) {
            return redirect()->route('media.index', ['folder' => $validated['target_folder']])->with('status', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }

    /**
     * Xóa 1 file vật lý trên đĩa
     */
    public function destroy(Request $request, $id)
    {
        $result = $this->mediaService->deletePhysicalFile($id);

        if ($result['success']) {
            return redirect()->back()->with('status', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }

    /**
     * Xóa hàng loạt tệp đã chọn
     */
    public function bulkDestroy(Request $request)
    {
        $selectedIds = $request->input('selected_files', []);

        if (empty($selectedIds) || ! is_array($selectedIds)) {
            return redirect()->back()->with('error', 'Vui lòng chọn ít nhất một tệp tin để xóa!');
        }

        $result = $this->mediaService->deleteMultipleFiles($selectedIds);

        if ($result['success']) {
            return redirect()->back()->with('status', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }

    /**
     * Xóa toàn bộ tệp tin khớp với bộ lọc hiện tại
     */
    public function destroyFiltered(Request $request)
    {
        $allFiles = $this->mediaService->getAllFiles();

        $filters = [
            'search' => $request->get('search'),
            'type' => $request->get('type', 'all'),
            'directory' => $request->get('directory', 'all'),
            'size_range' => $request->get('size_range', 'all'),
            'date_range' => $request->get('date_range', 'all'),
        ];

        // Đảm bảo không xóa sạch toàn bộ hệ thống nếu không có điều kiện lọc cụ thể trừ khi người dùng xác nhận
        $hasActiveFilter = ! empty($filters['search'])
            || ($filters['type'] !== 'all')
            || ($filters['directory'] !== 'all')
            || ($filters['size_range'] !== 'all')
            || ($filters['date_range'] !== 'all');

        if (! $hasActiveFilter) {
            return redirect()->back()
                ->with('error', 'Thao tác dọn dẹp yêu cầu ít nhất một điều kiện lọc (từ khóa, loại tệp, thư mục, dung lượng hoặc ngày) để tránh xóa nhầm toàn bộ tệp tin.');
        }

        $filteredFiles = $this->mediaService->filterFiles($allFiles, $filters);

        if ($filteredFiles->isEmpty()) {
            return redirect()->back()->with('error', 'Không tìm thấy tệp tin nào khớp với bộ lọc để xóa!');
        }

        $encodedIds = $filteredFiles->pluck('id')->all();
        $result = $this->mediaService->deleteMultipleFiles($encodedIds);

        if ($result['success']) {
            return redirect()->route('media.index')->with('status', "Dọn dẹp thành công: {$result['message']}");
        }

        return redirect()->back()->with('error', $result['message']);
    }

    /**
     * Tải tệp tin về máy
     */
    public function download(Request $request, $id)
    {
        $realPath = $this->mediaService->resolveManagedFile($id);

        if (! $realPath) {
            abort(404, 'Tệp tin không tồn tại.');
        }

        return response()->download($realPath);
    }

    /**
     * Tải lên tệp tin (Hỗ trợ kéo thả hoặc chọn nhiều tệp)
     */
    public function upload(Request $request)
    {
        $request->validate([
            'files' => 'nullable|array',
            'files.*' => 'file|max:102400|mimes:jpg,jpeg,png,gif,webp,bmp,ico,pdf,doc,docx,txt,rtf,odt,xls,xlsx,csv,ods,mp4,mov,avi,mkv,webm,mp3,wav,ogg,m4a,zip,rar,7z,tar,gz',
            'file' => 'nullable|file|max:102400|mimes:jpg,jpeg,png,gif,webp,bmp,ico,pdf,doc,docx,txt,rtf,odt,xls,xlsx,csv,ods,mp4,mov,avi,mkv,webm,mp3,wav,ogg,m4a,zip,rar,7z,tar,gz',
            'folder' => 'nullable|string|max:255',
        ], [
            'files.*.file' => 'Tệp tải lên không hợp lệ hoặc vượt quá giới hạn dung lượng.',
            'files.*.max' => 'Dung lượng tệp không được vượt quá 100MB.',
            'file.file' => 'Tệp tải lên không hợp lệ hoặc vượt quá giới hạn dung lượng.',
            'file.max' => 'Dung lượng tệp không được vượt quá 100MB.',
        ]);

        $uploadedFiles = [];
        if ($request->hasFile('files')) {
            $uploadedFiles = $request->file('files');
        } elseif ($request->hasFile('file')) {
            $uploadedFiles = [$request->file('file')];
        }

        if (empty($uploadedFiles)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Không tìm thấy tệp tin nào để tải lên.'], 422);
            }

            return redirect()->back()->with('error', 'Vui lòng chọn hoặc kéo thả ít nhất một tệp tin để tải lên!');
        }

        $targetFolder = $request->input('folder', 'auto_date');
        $result = $this->mediaService->uploadFiles($uploadedFiles, $targetFolder);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, $result['success'] ? 200 : 400);
        }

        if ($result['success']) {
            return redirect()->route('media.index', $targetFolder !== 'auto_date' ? ['folder' => $targetFolder] : [])->with('status', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }
}
