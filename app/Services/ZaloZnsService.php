<?php

namespace App\Services;

use App\Support\CenterInfo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZaloZnsService
{
    /**
     * Send Big Test results to parent via Zalo ZNS API
     */
    public static function sendBigTestResult(
        string $phone,
        string $studentName,
        string $className,
        string $testTitle,
        float|int|string $listening,
        float|int|string $reading,
        float|int|string $writing,
        float|int|string $speaking,
        float|int|string $overall,
        string $progressNote = ''
    ): array {
        // Chuẩn hóa số điện thoại dạng 84xxxxxxxxx
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '84' . substr($cleanPhone, 1);
        }

        $accessToken = config('services.zalo.access_token');
        $templateId = config('services.zalo.template_id_bigtest', '342918');
        $mode = config('services.zalo.mode', 'sandbox');

        $templateData = [
            'student_name' => $studentName,
            'class_name' => $className,
            'test_title' => $testTitle,
            'listening_score' => (string)$listening,
            'reading_score' => (string)$reading,
            'writing_score' => (string)$writing,
            'speaking_score' => (string)$speaking,
            'overall_score' => (string)$overall,
            'progress_note' => $progressNote ?: 'Đạt kết quả tốt trong kỳ thi định kỳ.',
            'center_name' => CenterInfo::name(),
            'hotline' => (string) CenterInfo::phone(),
        ];

        // Nếu ở chế độ Sandbox / Chưa cấu hình Access Token thật -> Mock Send & Log
        if (empty($accessToken) || $mode === 'sandbox') {
            Log::info("[Zalo ZNS Sandbox] Sending Big Test Result to {$cleanPhone}", [
                'template_id' => $templateId,
                'data' => $templateData,
            ]);

            return [
                'success' => true,
                'mode' => 'sandbox',
                'message' => "Đã ghi nhận gửi Zalo ZNS giả lập (Sandbox) cho {$studentName} ({$cleanPhone})",
                'data' => $templateData,
            ];
        }

        // Chế độ LIVE: Gửi HTTP POST tới Zalo Cloud Business API
        try {
            $response = Http::withHeaders([
                'access_token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post('https://business.openapi.zalo.me/message/template', [
                'phone' => $cleanPhone,
                'template_id' => $templateId,
                'template_data' => $templateData,
                'tracking_id' => 'BT_' . uniqid(),
            ]);

            $resData = $response->json();
            $isSuccess = ($resData['error'] ?? -1) === 0;

            if ($isSuccess) {
                Log::info("[Zalo ZNS Live] Sent successfully to {$cleanPhone}", $resData);
            } else {
                Log::warning("[Zalo ZNS Live] Failed to send to {$cleanPhone}", $resData);
            }

            return [
                'success' => $isSuccess,
                'mode' => 'live',
                'raw_response' => $resData,
                'message' => $resData['message'] ?? ($isSuccess ? 'Gửi thành công' : 'Lỗi từ Zalo API'),
            ];
        } catch (\Exception $e) {
            Log::error("[Zalo ZNS Error] Exception: " . $e->getMessage());
            return [
                'success' => false,
                'mode' => 'live',
                'message' => 'Lỗi kết nối máy chủ Zalo: ' . $e->getMessage(),
            ];
        }
    }
}
