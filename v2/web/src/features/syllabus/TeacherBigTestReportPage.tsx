// Bảng điểm Big Test các lớp giáo viên phụ trách — GV chính hoặc GVNN (TeacherPortalController::bigTestReport,
// /teacher/big-test-report).
import { useQuery } from "@tanstack/react-query";
import { errorMessage } from "../../api";
import { Alert, Badge } from "../../components/ui";
import { useQueryParam } from "../../router";
import { syllabusApi } from "./api";
import {
    EmptyRow,
    PageHeader,
    Pagination,
    setParam,
    TableCard,
    tableCls,
    tbodyCls,
    td,
    th,
} from "./components";

const show = (v: number | string | null | undefined) =>
    v === null || v === undefined ? "—" : String(v);

export function TeacherBigTestReportPage() {
    const page = Number(useQueryParam("page")) || 1;
    const list = useQuery({
        queryKey: ["syllabus", "teacher-big-test-report", page],
        queryFn: () => syllabusApi.teacherBigTestReport(page),
    });
    const d = list.data;
    const score = `${td} text-center font-mono`;
    return (
        <>
            <PageHeader
                title="Bảng điểm Big Test các lớp tôi phụ trách"
                back="/teacher/home"
            />
            {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
            <TableCard
                header={<h2 className="font-semibold">Kết quả Big Test</h2>}
                footer={
                    d && (
                        <Pagination
                            page={d.page.page}
                            pageSize={d.page.page_size}
                            total={d.page.total}
                            unit="kết quả"
                            onPage={(p) =>
                                setParam("page", p > 1 ? String(p) : null)
                            }
                        />
                    )
                }
            >
                <table className={`${tableCls} min-w-[720px]`}>
                    <thead>
                        <tr>
                            <th className={th}>Học viên</th>
                            <th className={th}>Kỳ thi / Lớp</th>
                            <th className={`${th} text-center`}>Nghe</th>
                            <th className={`${th} text-center`}>Đọc</th>
                            <th className={`${th} text-center`}>Viết</th>
                            <th className={`${th} text-center`}>Nói</th>
                            <th className={`${th} text-center`}>Tổng</th>
                            <th className={th}>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody className={tbodyCls}>
                        {list.isPending && (
                            <EmptyRow
                                colSpan={8}
                                icon="hourglass_empty"
                                title="Đang tải…"
                            />
                        )}
                        {d?.results.map((r) => (
                            <tr key={r.id}>
                                <td className={`${td} font-semibold`}>
                                    {show(r.student_name)}
                                </td>
                                <td className={td}>
                                    <div className="font-medium text-primary">
                                        {show(r.test_title)}
                                    </div>
                                    <div className="text-xs text-on-surface-variant">
                                        {r.class_name}
                                    </div>
                                </td>
                                <td className={score}>
                                    {show(r.listening_score)}
                                </td>
                                <td className={score}>
                                    {show(r.reading_score)}
                                </td>
                                <td className={score}>
                                    {show(r.writing_score)}
                                </td>
                                <td className={score}>
                                    {show(r.speaking_score)}
                                </td>
                                <td
                                    className={`${score} font-semibold text-primary`}
                                >
                                    {show(r.overall_score)}
                                </td>
                                <td className={td}>
                                    <Badge
                                        tone={
                                            r.approved ? "success" : "warning"
                                        }
                                    >
                                        {r.approved ? "Đã duyệt" : "Chờ duyệt"}
                                    </Badge>
                                </td>
                            </tr>
                        ))}
                        {d && d.results.length === 0 && (
                            <EmptyRow
                                colSpan={8}
                                icon="military_tech"
                                title="Chưa có kết quả Big Test nào cho các lớp của bạn."
                            />
                        )}
                    </tbody>
                </table>
            </TableCard>
        </>
    );
}
