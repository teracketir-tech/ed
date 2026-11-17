import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/axios";
import type { LineRow } from "./types";

interface Props {
    /** نام جدول در مسیرها — `RESOURCE` در `useLines` */
    resource: string;
    /** کلید فیلتر: `daily_report_id` یا `daily_report_activity_id` */
    ownerKey: string;
    ownerId: number | string | null;
}

/**
 * خواندنِ لیستِ ردیف‌های یک مالک.
 *
 * اینجا فقط «خواندن» است؛ افزودن/ویرایش/حذف در `useLines` می‌مانند چون به
 * صفِ پیش‌نویس و پیام‌های کاربر پیوسته‌اند و جدا کردنشان فقط رفت‌وبرگشت
 * state را دردست‌تر می‌کرد.
 */
export default function useOwnerRows({ resource, ownerKey, ownerId }: Props) {
    const [rows, setRows] = useState<LineRow[]>([]);
    const [loading, setLoading] = useState(false);
    /** شمارهٔ آخرین خواندن — پاسخِ کهنه نباید روی لیستِ تازه بنویسد */
    const request = useRef(0);

    const load = useCallback(async () => {
        if (!ownerId) {
            setRows([]);
            return;
        }

        const mine = ++request.current;
        setLoading(true);
        const res = await api(`${resource}?${ownerKey}=${ownerId}&per-page=0`).catch(() => null);

        if (mine !== request.current) return;

        setRows(res?.data ?? []);
        setLoading(false);
    }, [resource, ownerKey, ownerId]);

    useEffect(() => {
        load();
    }, [load]);

    return { rows, loading, reload: load };
}
