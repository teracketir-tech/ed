import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { api } from "@/lib/axios";
import { LINE_CONFIGS } from "./config";
import useDraftQueue, { isLocalId, isLocalLogId, logIdOf } from "./useDraftQueue";
import useOwnerRows from "./useOwnerRows";
import { pickEditable, type ApiResponse, type LineKind, type LineValues } from "./types";

/**
 * Issue 1: the timesheet is no longer `daily-report-timesheets` — it is the
 * shared `users_logs` log, so its routes are that table's own CRUD routes.
 * The other four line kinds keep their own prefixes.
 */
const RESOURCE: Record<LineKind, string> = {
    manpower: "daily-report-manpower",
    equipment: "daily-report-equipment",
    materials: "daily-report-materials",
    deliveries: "daily-report-deliveries",
    timesheets: "users-log",
};

/**
 * ردیف‌های یک زیرمجموعه + ذخیره‌ی همان لحظه‌ای — الگوی اثبات‌شده‌ی ایشوی ۸.
 *
 * مالکیت ردیف از `config.ts` می‌آید نه از فراخوان (ایشوی ۱۰ و ۴):
 *   - `activity` (نیرو/ماشین/مصالح): فیلتر `daily_report_activity_id` و
 *     افزودن روی `daily-report/activity/{activityId}/{kind}`؛ گزارش از روی
 *     فعالیت استخراج می‌شود و کلاینت دیگر آن را نمی‌فرستد.
 *   - `report` (تحویل/تایم‌شیت): فیلتر `daily_report_id` و افزودن روی
 *     `daily-report/{reportId}/{kind}` — مالک خودِ گزارش است (ایشوی ۴).
 *
 * هر ردیف بلافاصله روی سرور می‌نشیند و سپس لیست دوباره خوانده می‌شود؛
 * بنابراین جابه‌جایی بین تب‌ها هیچ ورودی‌ای را از بین نمی‌برد.
 *
 * استثنا — ایشوی «ثبت با خودِ گزارش»: تبِ سربرگ در حالتِ گزارش جدید هنوز شناسه
 * ندارد؛ `save` ردیف را به `useDraftQueue` می‌سپارد و صف به‌محضِ شناسه‌دار
 * شدنِ گزارش، پیش از هر خواندنِ لیست ساخته می‌شود.
 */
export default function useLines(kind: LineKind, activityId: number | string | null, reportId: number | string | null = null) {
    const { entity, fields, release, owner } = LINE_CONFIGS[kind];
    const resource = RESOURCE[kind];
    const ownerId = owner === "report" ? reportId : activityId;
    const ownerKey = owner === "report" ? "daily_report_id" : "daily_report_activity_id";
    /** برچسبِ مالک در پیام‌ها — «به گزارش اضافه شد» یا «از فعالیت حذف شد» */
    const ownerName = owner === "report" ? "گزارش" : "فعالیت";
    const [saving, setSaving] = useState(false);
    /** ایشوی «ثبت با خودِ گزارش»: تا سربرگ شناسه ندارد، ردیف‌ها روی صف می‌مانند */
    const drafting = owner === "report" && !reportId;
    const draft = useDraftQueue({ kind, ownerId: owner === "report" ? reportId : null });
    const { rows, loading, reload } = useOwnerRows({ resource, ownerKey, ownerId });

    /** صفِ پیش‌نویس پیش از هر خواندنِ لیست ساخته می‌شود تا همان ردیف‌های تازه برگردند */
    useEffect(() => {
        if (!ownerId || (draft.rows.length === 0 && draft.logIds.length === 0)) return;

        draft.flush().then(() => reload());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ownerId]);

    /** افزودن یا ویرایش؛ پاسخِ سرور (با `errors` احتمالی) برای نمایش روی فرم برمی‌گردد */
    const save = useCallback(
        async (values: LineValues): Promise<ApiResponse | null> => {
            // گزارش هنوز ساخته نشده — ردیف روی صف می‌ماند تا با خودِ سربرگ ساخته شود
            if (drafting) return draft.queue(values);

            if (!ownerId) return null;

            // شناسهٔ موقت یعنی ساختِ همان ردیفِ ناموفقِ صف؛ PATCH ندارد، باید ساخته شود
            const temp = isLocalId(values.id);
            const editing = !!values.id && !temp;
            setSaving(true);

            try {
                // مالک از مسیر می‌آید: گزارش‌محور زیرِ `{id}` و فعالیت‌محور زیرِ `activity/`
                const url = editing
                    ? `${resource}/${values.id}`
                    : owner === "report"
                      ? `daily-report/${ownerId}/${kind}`
                      : `daily-report/activity/${ownerId}/${kind}`;
                const res = await api(url, editing ? "PATCH" : "POST", pickEditable(fields, values));

                if (res?.success) {
                    if (temp) draft.forget(values.id);
                    toast.success(editing ? `${entity} بروزرسانی شد.` : `${entity} به ${ownerName} اضافه شد.`);
                    await reload();
                }

                return res;
            } catch {
                // `api` خودش toast زده است
                return null;
            } finally {
                setSaving(false);
            }
        },
        [drafting, draft, entity, fields, resource, kind, owner, ownerName, ownerId, reload],
    );

    const remove = useCallback(
        async (id: number | string) => {
            // رکوردِ لاگِ تیک‌شده: چیزی مهر نخورده که آزاد شود، فقط از صف می‌رود
            if (isLocalLogId(id)) {
                draft.forgetLog(logIdOf(id));
                return true;
            }

            // ردیفِ ساخته‌نشده هنوز روی سرور نیست؛ فقط از صف کنار می‌رود
            if (isLocalId(id)) {
                draft.discard(id);
                return true;
            }

            const res = await api(`${resource}/${id}`, "DELETE").catch(() => null);

            if (res?.success) {
                // Feedback 1: "deleting" a timesheet really is a release — the
                // device row survives and only its stamps are taken off.
                toast.success(release ? `${entity} از ${ownerName} آزاد شد (رکورد لاگ پاک نشد).` : `${entity} از ${ownerName} حذف شد.`);
                await reload();
                return true;
            }

            if (res) toast.error(res.message || `${release ? "آزادسازی" : "حذف"} ${entity} انجام نشد.`);
            return false;
        },
        [draft, entity, resource, release, ownerName, reload],
    );

    /**
     * در حالتِ پیش‌نویس لیستِ دیده‌شده همان صف است و به‌محضِ ساختِ گزارش خواندنِ
     * سرور جایگزینش می‌شود (افکتِ بالا صف را همان لحظه می‌فرستد). ردیفِ ناموفق
     * سرِ جایش می‌ماند تا ویرایش و دوباره ذخیره شود. `queueLogs`/`pendingLogs`
     * برای «افزودن از لاگ روز»‌اند: رکوردهای دستگاه ساخته نمی‌شوند و فقط هنگامِ
     * `flush` با `log_ids` مهر می‌خورند.
     */
    return {
        rows: drafting ? draft.rows : draft.rows.length ? [...draft.rows, ...rows] : rows,
        loading,
        saving,
        save,
        remove,
        drafting,
        reload,
        queueLogs: draft.queueLogs,
        pendingLogs: draft.logIds,
    };
}
