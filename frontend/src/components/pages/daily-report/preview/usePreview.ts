import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/axios";
import useLookups from "../lines/useLookups";
import type { ReportData } from "../report";

/**
 * داده‌ی تب «پیش‌نمایش» — سند فاز ۲ بند ۱۴: «دریافت کامل گزارش روزانه» با یک
 * درخواستِ `GET hq/daily-report/{id}` که سربرگ، فعالیت‌ها و پنج زیرمجموعه را
 * همراه می‌آورد.
 *
 * برچسب‌ها (اشخاص / مصالح / واحد / نوع ماشین‌آلات / تسک‌ها) از همان
 * `useLookups` تب‌های زیرمجموعه می‌آیند تا نسخه‌ی دومی از آنها ساخته نشود.
 *
 * `report === undefined` یعنی هنوز خوانده نشده (loading) و `null` یعنی پیدا نشد؛
 * خواندنِ داده از `read` می‌آید که خودش setState نمی‌کند تا افکتِ بارگذاری
 * setStateِ همزمان نداشته باشد.
 */
export default function usePreview(reportId: number | null, projectId: string, companyId: string = "", active: boolean = true) {
    const [report, setReport] = useState<ReportData | null | undefined>(undefined);
    /**
     * صفحه‌ی «نمایش گزارش» (ایشوی ۱۸، مورد ۱۱) پروژه/شرکتی از بیرون نمی‌آورد؛
     * آنها را از همین گزارشِ خوانده‌شده برمی‌داریم. در فرمِ سربرگ مقدارِ فرم
     * مقدم است و رفتارش دست‌نخورده می‌ماند.
     */
    const lookups = useLookups(projectId || String(report?.project_id ?? ""), companyId || String(report?.company_id ?? ""));

    const read = useCallback(async (): Promise<ReportData | null> => {
        if (!reportId) return null;

        const res = await api(`daily-report/${reportId}`).catch(() => null);
        return res?.success ? (res.data as ReportData) : null;
    }, [reportId]);

    // ایشوی ۱۲: پنل با `forceMount` هنگامِ بارگذاریِ صفحه سوار می‌شود، پس اگر
    // فقط یک بار بخواند، حذفِ فعالیت در تبِ «فعالیت‌ها» هرگز به پیش‌نمایش
    // نمی‌رسد؛ تا وقتی تب پنهان است واکشی نمی‌شود و ورود به تب همیشه تازه
    // می‌خواند (بدونِ حلقه: خواندن فقط از تغییرِ تب یا شناسه روی می‌دهد).
    useEffect(() => {
        if (!active) return;

        let alive = true;

        read().then((data) => alive && setReport(data));

        return () => {
            alive = false;
        };
    }, [read, active]);

    /** دوباره‌خواندن بعد از «ثبت نهایی» — فقط از رویدادِ دکمه صدا زده می‌شود */
    const reload = useCallback(async () => {
        setReport(await read());
    }, [read]);

    return { report, loading: report === undefined, reload, ...lookups };
}
