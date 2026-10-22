/**
 * Mở file PDF bằng pdf.js (chỉ tải thư viện khi trang thật sự cần PDF). Trả loading task ngay để bỏ dở được:
 *   task = await openPdf(url); const doc = await task.promise;   // đổi file / đóng → task.destroy()
 */
export async function openPdf(src) {
    const [pdfjs, worker] = await Promise.all([import('pdfjs-dist/legacy/build/pdf.mjs'), import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker')]);
    // Worker do Vite đóng gói thành file .js (hosting không phải lúc nào cũng trả đúng kiểu cho file .mjs).
    if (!pdfjs.GlobalWorkerOptions.workerPort) pdfjs.GlobalWorkerOptions.workerPort = new worker.default();
    // isEvalSupported:false: không cho pdf.js dựng hàm bằng eval từ nội dung PDF (file do người dùng tải lên).
    return pdfjs.getDocument({ url: src, isEvalSupported: false });
}
