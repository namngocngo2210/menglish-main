<?php

/**
 * Danh mục SLA điều khiển bằng trang "Cấu hình SLA" (system-config.sla). Mỗi mục:
 *  - value / unit: ngưỡng mặc định (giờ hoặc số lần); Admin đổi được, lưu ở bảng sla_settings.
 *  - penalty: hết hạn có tự lập biên bản phạt không (biên bản pending → giải trình → CM/HT/Admin xác nhận → vào lương).
 *  - amount: mức phạt gợi ý (đ) ghi vào biên bản; 0 = người chốt quyết mức phạt.
 *  - task: có tự giao việc cho người phụ trách khi mốc được kích hoạt.
 *  - category (tuỳ chọn): loại lỗi của biên bản (Penalty::CATEGORIES) — quyết định ai chốt; mặc định 'operations'.
 */
return [
    'groups' => [
        'crm' => 'CRM & Tuyển sinh',
        'teacher' => 'Giáo viên & Trợ giảng',
        'academic' => 'Học thuật & Dự án',
    ],

    /** Bậc phạt theo số lần tái phạm cộng dồn trong N tháng (system_settings sla.ladder_reset_months). */
    'ladder_reset_months' => 12,

    'rules' => [
        'crm.first_contact' => [
            'group' => 'crm',
            'label' => 'Liên hệ khách mới lần đầu',
            'description' => 'Kể từ lúc thêm khách, người phụ trách (Học vụ) phải liên hệ khách (gọi / nhắn / gặp, liên hệ được) hoặc chuyển trạng thái. Trễ → biên bản phạt cho người phụ trách + báo Admin.',
            'value' => 24,
            'unit' => 'hours',
            'penalty' => true,
            'amount' => 0,
            'task' => false,
            'violation' => 'Quá hạn SLA liên hệ khách mới',
        ],
        'crm.status_move' => [
            'group' => 'crm',
            'label' => 'Chuyển trạng thái chăm sóc tối đa',
            'description' => 'Kể từ lúc thêm khách, tối đa số giờ này phải chuyển khách khỏi trạng thái "Mới". Trễ → biên bản phạt + báo Admin.',
            'value' => 36,
            'unit' => 'hours',
            'penalty' => true,
            'amount' => 0,
            'task' => false,
            'violation' => 'Quá hạn SLA chuyển trạng thái chăm sóc khách',
        ],
        'crm.test_result' => [
            'group' => 'crm',
            'label' => 'Trả kết quả test đầu vào',
            'description' => 'Từ lúc khách nộp bài test, tự giao việc "Trả kết quả test" cho người phụ trách; phải ghi nhận đã gửi kết quả trong thời hạn này. Trễ → biên bản phạt + báo Admin.',
            'value' => 24,
            'unit' => 'hours',
            'penalty' => true,
            'amount' => 0,
            'task' => true,
            'violation' => 'Quá hạn SLA trả kết quả test đầu vào',
        ],
        'crm.trial_feedback' => [
            'group' => 'crm',
            'label' => 'Phản hồi sau buổi học thử',
            'description' => 'Từ lúc buổi học thử kết thúc, tự giao việc "Phản hồi phụ huynh sau học thử" cho người phụ trách; phải liên hệ phụ huynh trong thời hạn này. Trễ → biên bản phạt + báo Admin.',
            'value' => 24,
            'unit' => 'hours',
            'penalty' => true,
            'amount' => 0,
            'task' => true,
            'violation' => 'Quá hạn SLA phản hồi sau học thử',
        ],
        'crm.tuition_followup' => [
            'group' => 'crm',
            'label' => 'Theo dõi thu học phí tuần đầu',
            'description' => 'Từ lúc chốt / chờ xếp lớp, tự giao việc theo dõi học phí cho người phụ trách; học phí phải thu đủ trong thời hạn này. Quá hạn → báo Admin (mặc định không tự phạt).',
            'value' => 168,
            'unit' => 'hours',
            'penalty' => false,
            'amount' => 0,
            'task' => true,
            'violation' => 'Quá hạn thu học phí tuần đầu sau khi chốt',
        ],
        'crm.failed_contacts' => [
            'group' => 'crm',
            'label' => 'Liên hệ thất bại liên tiếp',
            'description' => 'Sau số lần liên hệ không được liên tiếp này (gọi / nhắn / hình thức bất kỳ), hệ thống cảnh báo Admin và giao việc báo cáo cho người phụ trách. Không phạt.',
            'value' => 3,
            'unit' => 'count',
            'penalty' => false,
            'amount' => 0,
            'task' => true,
            'violation' => 'Liên hệ khách thất bại liên tiếp',
        ],

        'gv.remarks_late' => [
            'group' => 'teacher',
            'label' => 'Nhận xét sau buổi học (GV)',
            'description' => 'Sau khi buổi học kết thúc, GV phải gửi nhận xét buổi học (nội dung bài, bài tập, nhận xét học viên) trong thời hạn này. Trễ → biên bản cho GV theo bậc phạt tái phạm (Quy chế GV mục 4: lần 1 nhắc nhở, lần 2 phạt 30.000đ, từ lần 3 phạt 60.000đ).',
            'value' => 12,
            'unit' => 'hours',
            'penalty' => true,
            'amount' => 0,
            'ladder' => [0, 30000, 60000],
            'task' => false,
            'violation' => 'Gửi nhận xét sau buổi học trễ',
        ],

        'academic.milestone_late' => [
            'group' => 'academic',
            'label' => 'Trễ mốc dự án học thuật',
            'description' => 'Dự án soạn sách / chương trình đã chốt tiến độ: mốc chưa hoàn thành khi qua 23:59 ngày hạn cộng số giờ ân hạn này → biên bản cho người nhận mốc (chưa có người nhận thì người phụ trách dự án), Học thuật / Admin xác nhận mới tính vào lương. Trước hạn 24 giờ hệ thống nhắc người nhận mốc.',
            'value' => 1,
            'unit' => 'hours',
            'penalty' => true,
            'amount' => 0,
            'task' => false,
            'category' => 'academic',
            'violation' => 'Trễ deadline mốc dự án học thuật',
        ],
    ],
];
