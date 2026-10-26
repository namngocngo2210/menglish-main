<?php

namespace App\Services\Crm;

use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nhập khách CRM từ Excel: kiểm tra từng dòng (dùng chung cho bước xem trước) và ghi các dòng hợp lệ của bản xem trước
 * (job ImportCrmLeads chạy nền). Một dòng lỗi không làm hỏng cả lần nhập.
 */
class CrmLeadImporter
{
    /**
     * @param  array<string, mixed>  $preview  bản xem trước đã lưu (rows, branch_id, assigned_user_id, file_name)
     * @return array{created: int, skipped: list<string>}
     */
    public function import(array $preview, User $user): array
    {
        $created = 0;
        $skipped = [];
        foreach ($preview['rows'] as $row) {
            if (! empty($row['errors'])) {
                $skipped[] = "Dòng {$row['line']}: ".implode('; ', $row['errors']);

                continue;
            }
            $data = $row['data'];
            // Kiểm tra lại lúc nhập: có thể đã có người tạo khách cùng SĐT sau khi xem trước.
            $phoneNormalized = CrmCustomer::normalizePhone($data['phone']);
            $seen = [];
            $seenEmails = [];
            $errors = $this->validateRow($data, $seen, $seenEmails);
            if (! empty($data['owner_id']) && ! LeadOwners::isCandidate(User::find($data['owner_id']))) {
                $errors[] = 'Người phụ trách '.($data['owner_name'] ?? '').' không còn hoạt động';
            }
            if ($errors) {
                $skipped[] = "Dòng {$row['line']}: ".implode('; ', $errors);

                continue;
            }

            try {
                DB::transaction(function () use ($data, $phoneNormalized, $preview, $user) {
                    $customer = CrmCustomer::create([
                        'code' => CrmCustomer::generateCode(),
                        'name' => $data['name'],
                        'phone' => $data['phone'],
                        'phone_normalized' => $phoneNormalized,
                        'parent_name' => $data['parent_name'] ?: null,
                        'parent_phone' => $data['parent_phone'] ?: null,
                        'email' => $data['email'] ?: null,
                        'dob' => $data['dob'] ?: null,
                        'gender' => $data['gender'] ?: null,
                        'address' => $data['address'] ?: null,
                        'school' => $data['school'] ?: null,
                        'source' => $data['source'],
                        'course_interest' => $data['course_interest'] ?: null,
                        'notes' => $data['notes'] ?: null,
                        'branch_id' => $preview['branch_id'],
                        'assigned_user_id' => ($data['owner_id'] ?? null) ?: $preview['assigned_user_id'],
                        'stage' => 'new',
                        'deal_value' => 0,
                    ]);
                    CrmCustomerHistory::create([
                        'customer_id' => $customer->id,
                        'user_id' => $user->id,
                        'type' => 'system',
                        'content' => 'Thêm khách hàng qua nhập Excel (file '.($preview['file_name'] ?? '').', nguồn: '.$customer->source.').',
                    ]);
                });
                $created++;
            } catch (UniqueConstraintViolationException) {
                $skipped[] = "Dòng {$row['line']}: SĐT hoặc email vừa được tạo bởi phiên khác.";
            } catch (QueryException $e) {
                // Một dòng dữ liệu lạ không được làm hỏng cả lần nhập (các dòng khác vẫn nhập tiếp).
                report($e);
                $skipped[] = "Dòng {$row['line']}: dữ liệu không lưu được, vui lòng kiểm tra lại các cột của dòng này.";
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Lỗi của 1 dòng (rỗng = hợp lệ). $seenPhones / $seenEmails dùng phát hiện trùng trong cùng file.
     *
     * @return list<string>
     */
    public function validateRow(array $data, array &$seenPhones, array &$seenEmails, int $line = 0): array
    {
        $errors = [];
        $normalized = null;
        $email = null;
        if (blank($data['name'])) {
            $errors[] = 'Thiếu họ tên';
        }
        if (blank($data['phone'])) {
            $errors[] = 'Thiếu số điện thoại';
        } elseif (! CrmCustomer::isValidVietnamesePhone($data['phone'])) {
            $errors[] = 'SĐT sai định dạng (10 số bắt đầu bằng 0 hoặc +84)';
        } else {
            $normalized = CrmCustomer::normalizePhone($data['phone']);
            if (isset($seenPhones[$normalized])) {
                $errors[] = "Trùng SĐT với dòng {$seenPhones[$normalized]} trong file";
            } elseif ($existing = CrmCustomer::where('phone_normalized', $normalized)->first(['code'])) {
                $errors[] = "SĐT đã có trong CRM ({$existing->short_code})";
            }
        }
        if (filled($data['parent_phone']) && (! CrmCustomer::isValidVietnamesePhone($data['parent_phone']) || mb_strlen($data['parent_phone']) > 20)) {
            $errors[] = 'SĐT phụ huynh sai định dạng';
        }
        if (filled($data['email'])) {
            $email = Str::lower($data['email']);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email sai định dạng';
            } elseif (isset($seenEmails[$email])) {
                $errors[] = 'Trùng email trong file';
            } elseif (CrmCustomer::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $errors[] = 'Email đã có trong CRM';
            }
        }
        if (filled($data['dob_error'] ?? null)) {
            $errors[] = "Ngày sinh không hợp lệ ({$data['dob_error']}), nhập dạng ngày/tháng/năm, vd. 15/08/2015";
        }
        if (filled($data['gender']) && mb_strlen($data['gender']) > 20) {
            $errors[] = 'Giới tính quá dài (tối đa 20 ký tự, vd. Nam / Nữ)';
        }

        // Chỉ dòng hợp lệ mới "giữ chỗ" SĐT / email: dòng lỗi bị bỏ qua nên không được chặn dòng hợp lệ trùng số phía sau.
        if (! $errors) {
            if ($normalized !== null) {
                $seenPhones[$normalized] = $line;
            }
            if ($email !== null) {
                $seenEmails[$email] = true;
            }
        }

        return $errors;
    }
}
