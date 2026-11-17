import LinesPanel from "./lines/LinesPanel";
import TimesheetPanel from "./lines/timesheet/TimesheetPanel";
import { REPORT_LINE_KINDS } from "./lines/config";

interface Props {
    /** فقط بخشِ مقادیرِ Formik — پروژه، شرکت و تاریخِ همین سربرگ */
    formik: { values: Record<string, string> };
    /** مالکِ ردیف‌ها — ایشوی ۴: این دو زیرمجموعه به خودِ گزارش تعلق دارند */
    reportId: number | null;
    locked?: boolean;
}

/**
 * زیرمجموعه‌هایِ گزارش‌محورِ تبِ نخست (ایشوی ۴): «تحویل کالا» و «تایم‌شیت»
 * یک‌بار برای کلِ روز ثبت می‌شوند، پس داخلِ همین تب می‌نشینند نه داخلِ
 * دیالوگِ فعالیت (سه زیرمجموعه‌ی دیگر آنجا می‌مانند).
 *
 * «تایم‌شیت» پنلِ خودش را می‌خواهد (لاگِ همان روز + ثبت دستی) و بقیه با همان
 * `LinesPanel` مشترک رندر می‌شوند؛ همه از واژه‌نامه‌ی همین سربرگ استفاده
 * می‌کنند و تا پیش از ذخیره‌ی سربرگ ردیف‌هایشان را روی صفِ پیش‌نویس نگه
 * می‌دارند تا با خودِ گزارش ساخته شوند (ایشوی «ثبت با خودِ گزارش»).
 */
export default function ReportLinesPanel({ formik, reportId, locked = false }: Props) {
    const projectId = formik.values.project_id ?? "";
    const companyId = formik.values.company_id ?? "";
    const reportDate = formik.values.report_date ?? "";

    return (
        <div className="flex flex-col gap-6">
            {REPORT_LINE_KINDS.map((kind) =>
                kind === "timesheets" ? (
                    <TimesheetPanel
                        key={kind}
                        projectId={projectId}
                        companyId={companyId}
                        reportId={reportId}
                        reportDate={reportDate}
                        locked={locked}
                    />
                ) : (
                    <LinesPanel key={kind} kind={kind} reportId={reportId} projectId={projectId} companyId={companyId} locked={locked} />
                ),
            )}
        </div>
    );
}
