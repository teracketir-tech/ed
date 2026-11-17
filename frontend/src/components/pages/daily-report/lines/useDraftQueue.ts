import { useCallback, useRef, useState } from "react";
import { toast } from "sonner";
import { api } from "@/lib/axios";
import { LINE_CONFIGS } from "./config";
import { pickEditable, type ApiResponse, type LineKind, type LineRow, type LineValues } from "./types";

/** شناسهٔ موقتِ ردیفِ ساخته‌نشده — سرور بعداً شناسهٔ واقعی می‌دهد */
const LOCAL_PREFIX = "local-";
/** شناسهٔ موقتِ رکوردِ لاگِ تیک‌شده — تا مهر نخورده روی صف است */
const LOG_PREFIX = `${LOCAL_PREFIX}log-`;

let sequence = 0;

/** آیا این شناسه هنوز روی سرور نیست و باید ساخته شود؟ */
export const isLocalId = (id: number | string | null | undefined) => String(id ?? "").startsWith(LOCAL_PREFIX);

/** آیا این شناسه مالِ رکوردِ لاگِ تیک‌شده است (نه ردیفِ دستی)؟ */
export const isLocalLogId = (id: number | string | null | undefined) => String(id ?? "").startsWith(LOG_PREFIX);

/** شناسهٔ واقعیِ ردیفِ لاگ، از روی شناسهٔ موقتِ بالا */
export const logIdOf = (id: number | string) => String(id).slice(LOG_PREFIX.length);

/** نمایِ شناسهٔ موقتِ رکوردِ لاگ — تا جدول همان رکورد را با شناسهٔ موقت نشان دهد */
export const asLocalLogId = (id: number | string) => `${LOG_PREFIX}${id}`;

/** اولین پیامِ خطای سرور — تا کاربر بداند کدام فیلد را باید کامل کند */
const reasonOf = (res: ApiResponse | null): string => {
    if (res?.message) return res.message;

    const first = res?.errors ? Object.values(res.errors)[0] : undefined;

    return Array.isArray(first) ? (first[0] ?? "") : (first ?? "");
};

interface Props {
    kind: LineKind;
    /** شناسهٔ گزارش — تا خالی است ردیف‌ها روی همین صف می‌مانند */
    ownerId: number | string | null;
}

/**
 * صفِ ردیف‌هایی که پیش از ساختِ گزارش ساخته می‌شوند — ایشوی «ثبت با خودِ گزارش».
 *
 * تبِ «سربرگ» در حالتِ گزارش جدید هنوز `daily_report_id` ندارد؛ این هوک ردیف‌ها
 * را با شناسهٔ موقت نگه می‌دارد و به محضِ شناسه گرفتنِ سربرگ (`flush`) همان‌ها
 * را با همان گزارش می‌سازد. ناموفق‌ها روی صف می‌مانند و ویرایشِ بعدی دوباره
 * می‌سازدشان چون شناسه‌اش هنوز موقت است.
 *
 * استثنا — لاگ‌های دستگاه («افزودن از لاگ روز»): ردیف تازه نمی‌سازند و فقط با
 * `log_ids` هنگامِ `flush` با گزارش مهر می‌خورند.
 */
export default function useDraftQueue({ kind, ownerId }: Props) {
    const { entity, fields } = LINE_CONFIGS[kind];
    const [rows, setRows] = useState<LineRow[]>([]);
    /** شناسهٔ لاگ‌های تیک‌شده — تایم‌شیت اینها را نمی‌سازد، فقط با `log_ids` مهر می‌زند */
    const [logIds, setLogIds] = useState<(number | string)[]>([]);
    /** تا جلوی ساختِ دوبارهٔ همان صف برایِ یک گزارش را بگیرد (افکت دوبار اجرا می‌شود) */
    const flushedFor = useRef<number | string | null>(null);

    /** افزودن یا ویرایشِ روی صف — پاسخ همان چیزی است که دیالوگ انتظار دارد */
    const queue = useCallback(
        (values: LineValues): ApiResponse => {
            const id = isLocalId(values.id) ? String(values.id) : `${LOCAL_PREFIX}${++sequence}`;
            const row = { ...pickEditable(fields, values), id } as LineRow;

            setRows((prev) => (prev.some((item) => item.id === id) ? prev.map((item) => (item.id === id ? row : item)) : [...prev, row]));

            toast.success(`${entity} در صفِ ثبت ماند و با ذخیرهٔ سربرگ به گزارش اضافه می‌شود.`);

            return { success: true };
        },
        [entity, fields],
    );

    /** حذفِ یک ردیفِ ساخته‌نشده — هنوز چیزی روی سرور نیست، پس فقط از صف می‌رود */
    const discard = useCallback(
        (id: number | string) => {
            setRows((prev) => prev.filter((item) => item.id !== id));
            toast.success(`${entity} از صفِ ثبت حذف شد.`);
        },
        [entity],
    );

    /** ردیفی که بالاخره روی سرور نشست از صف کنار می‌رود — بی‌پیام چون خودِ ذخیره پیام داده است */
    const forget = useCallback((id: number | string) => setRows((prev) => prev.filter((item) => item.id !== id)), []);

    /**
     * تیکِ رکوردِ دستگاه در حالت پیش‌نویس — مثل ردیف‌های دستی تا ذخیرهٔ سربرگ
     * صبر می‌کند؛ تکرارها یکی می‌شوند.
     */
    const queueLogs = useCallback((ids: (number | string)[]) => {
        if (ids.length === 0) return;

        setLogIds((prev) => Array.from(new Set([...prev, ...ids])));
        toast.success(`${ids.length} ردیف لاگ در صفِ ثبت ماند و با ذخیرهٔ سربرگ به تایم‌شیت اضافه می‌شود.`);
    }, []);

    /** برداشتنِ یک رکوردِ لاگ از صف — هنوز مهری نخورده، پس فقط از فهرست می‌رود */
    const forgetLog = useCallback((id: number | string) => {
        setLogIds((prev) => prev.filter((item) => String(item) !== String(id)));
        toast.success("رکورد لاگ از صفِ ثبت حذف شد.");
    }, []);

    /** هر چه روی صف مانده با همین گزارش ساخته می‌شود؛ ناموفق‌ها سرِ جایشان می‌مانند */
    const flush = useCallback(async (): Promise<boolean> => {
        if (!ownerId || flushedFor.current === ownerId || (rows.length === 0 && logIds.length === 0)) return true;

        flushedFor.current = ownerId;

        const left: LineRow[] = [];
        let reason = "";

        for (const row of rows) {
            // ردیفِ صف را همین `pickEditable` ساخته، پس مقادیرِ فیلدها رشته‌اند
            const payload = pickEditable(fields, row as unknown as LineValues);
            const res = await api(`daily-report/${ownerId}/${kind}`, "POST", payload).catch(() => null);

            if (!res?.success) {
                left.push(row);
                if (!reason) reason = reasonOf(res);
            }
        }

        setRows(left);

        /**
         * لاگ‌های تیک‌شده «ساخته» نمی‌شوند، فقط با همین گزارش مهر می‌خورند — همان
         * `log_ids`‌ای که `TimesheetService::attach` بعد از ذخیره می‌فرستاد.
         */
        let leftLogs = 0;

        if (logIds.length) {
            const res = await api(`daily-report/${ownerId}/${kind}`, "POST", { log_ids: logIds }).catch(() => null);

            if (res?.success) setLogIds([]);
            else {
                leftLogs = logIds.length;
                if (!reason) reason = reasonOf(res);
            }
        }

        const failed = left.length + leftLogs;

        if (failed) toast.error(`${failed} ردیف «${entity}» با گزارش ساخته نشد${reason ? ` — ${reason}` : ""}؛ ردیفِ مانده را ویرایش و دوباره ذخیره کنید.`);

        return failed === 0;
    }, [entity, fields, kind, logIds, ownerId, rows]);

    return { rows, logIds, queue, queueLogs, forgetLog, discard, forget, flush };
}
