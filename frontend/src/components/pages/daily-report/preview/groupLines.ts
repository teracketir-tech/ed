import { ACTIVITY_LINE_KINDS, REPORT_LINE_KINDS } from "../lines/config";
import type { LineKind, LineRow } from "../lines/types";
import type { ReportData } from "../report";

/** ردیف‌های یک زیرمجموعه برای یک فعالیت */
export interface KindRows {
    kind: LineKind;
    rows: LineRow[];
}

/** زیرمجموعه‌هایِ دارایِ ردیفِ یک فعالیت */
export interface ActivityBlock {
    key: string;
    title: string;
    parts: KindRows[];
    count: number;
}

const keep = (parts: KindRows[]) => parts.filter((part) => part.rows.length > 0);
const total = (parts: KindRows[]) => parts.reduce((sum, part) => sum + part.rows.length, 0);

/** عنوانِ بلوک — کد WBS و عنوانِ تسک از snapshot خودِ ردیفِ فعالیت می‌آید */
export function activityTitle(activity: { wbs_code?: string | null; task_name?: string | null }): string {
    return `${activity.wbs_code || "-"} — ${activity.task_name || "—"}`;
}

/**
 * گروه‌بندیِ پیش‌نمایش بر اساس فعالیت — ایشوی ۱۰.
 *
 * قبلاً پنج جدولِ سراسری داشتیم که ردیف‌های همه‌ی فعالیت‌ها را قاطی می‌کرد؛
 * حالا هر فعالیت بلوکِ خودش را دارد و زیرمجموعه‌هایِ فعالیت‌محورش زیر آن
 * می‌نشینند تا ناظر ببیند این عملکردِ کدام Task از WBS است.
 *
 * بخشِ خالیِ یک فعالیت حذف نمی‌شود (داشتنِ ردیف در همه‌ی بخش‌ها الزامی
 * نیست، ایشوی ۱۰-C) ولی زیرمجموعه‌ی بدون ردیف کنار می‌رود تا پیش‌نمایش
 * کوتاه بماند. ردیفِ فعالیت‌محورِ بی‌مالک — که با FK نباید ممکن باشد — در
 * انتها گروه‌بندی می‌شود تا چیزی از دید ناظر پنهان نماند.
 *
 * ایشوی ۴: «تحویل کالا» و «تایم‌شیت» دیگر داخلِ این بلوک‌ها نیستند؛
 * `reportBlock` آن‌ها را یک‌جا می‌آورد چون مالکشان خودِ گزارش است.
 */
export function groupByActivity(report: ReportData): ActivityBlock[] {
    const activities = report.activities ?? [];

    const blocks: ActivityBlock[] = activities.map((activity) => {
        const parts = keep(ACTIVITY_LINE_KINDS.map((kind) => ({ kind, rows: (report[kind] ?? []).filter((row) => String(row.daily_report_activity_id ?? "") === String(activity.id)) })));

        return { key: String(activity.id), title: activityTitle(activity), parts, count: total(parts) };
    });

    const known = new Set(activities.map((activity) => String(activity.id)));
    const rest = keep(ACTIVITY_LINE_KINDS.map((kind) => ({ kind, rows: (report[kind] ?? []).filter((row) => !known.has(String(row.daily_report_activity_id ?? ""))) })));

    if (rest.length) {
        blocks.push({ key: "_rest", title: "ردیف‌های بدون فعالیت", parts: rest, count: total(rest) });
    }

    return blocks;
}

/**
 * زیرمجموعه‌هایِ گزارش‌محور (ایشوی ۴) — یک بلوکِ جداگانه برای «تحویل کالا» و
 * «تایم‌شیت» که هیچ فعالیتی ندارند. اگر هیچ ردیفی ثبت نشده باشد `null`
 * برمی‌گردد تا پیش‌نمایش و خروجی اکسل بخشِ خالی نسازند.
 */
export function reportBlock(report: ReportData): ActivityBlock | null {
    const parts = keep(REPORT_LINE_KINDS.map((kind) => ({ kind, rows: report[kind] ?? [] })));

    if (!parts.length) return null;

    return { key: "_report", title: "گزارش", parts, count: total(parts) };
}
