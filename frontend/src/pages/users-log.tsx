import Pagination from "@/components/shared/Pagination";
import Empty from "@/components/shared/Empty";
import Loading from "@/components/shared/Loading";
import FilterForm from "@/components/pages/users-log/Filter";
import { api } from "@/lib/axios";
import { cn, formatDateToFa, formatNumber } from "@/lib/utils";
import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";

type LogRow = {
    id: number;
    checkindate: string | null;
    checkout_date: string | null;
    hour_normal: number | null;
    delay_minutes: number | null;
    extra_minutes: number | null;
    person: string | null;
};

type LogsResponse = { pages?: number; totalCount?: number; data?: LogRow[] };

const num = (value: number | null | undefined) => (value == null ? "---" : String(value));

/**
 * The global attendance list (top-level sidebar route "لیست ورود و خروج"):
 * every users_logs row, newest check-in first, permission 709. The person's
 * name rides on each row as `person` — issue 5: it is read from
 * `user.first_name / user.last_name`, because `user_id` points at user.id.
 * The totals row sums THE VISIBLE PAGE only — the seal orders table's pattern.
 */
export default function UsersLogList() {
    const [searchParams] = useSearchParams();
    const [logs, setLogs] = useState<LogsResponse | null>(null);

    useEffect(() => {
        let stale = false;

        const load = async () => {
            const query = searchParams.toString();
            const res = await api<LogsResponse>(`users-log/all${query ? `?${query}` : ""}`, "GET");

            if (!stale) setLogs(res);
        };

        load();

        return () => {
            stale = true;
        };
    }, [searchParams]);

    const rows = logs?.data ?? [];
    const sum = (key: "hour_normal" | "delay_minutes" | "extra_minutes") => rows.reduce((acc, row) => acc + Number(row[key] ?? 0), 0);

    return (
        <div className="w-full">
            <FilterForm loading={logs === null} />

            <Title />

            {logs === null ? (
                <div className="w-full flex items-center justify-center py-5">
                    <Loading />
                </div>
            ) : rows.length === 0 ? (
                <Empty />
            ) : (
                <div className="flex flex-col gap-1 border">
                    {rows.map((log, i) => (
                        <div key={log.id} className="w-full grid grid-cols-7 justify-between gap-4 px-4 py-1 hover:bg-gray-50 border-b">
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{i + 1}</span>
                            </div>
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{log.person || "---"}</span>
                            </div>
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{log.checkindate ? formatDateToFa(log.checkindate, true) : "---"}</span>
                            </div>
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{log.checkout_date ? formatDateToFa(log.checkout_date, true) : "---"}</span>
                            </div>
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{num(log.hour_normal)}</span>
                            </div>
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{num(log.delay_minutes)}</span>
                            </div>
                            <div className="flex items-center justify-center py-4">
                                <span className="text-xs text-center">{num(log.extra_minutes)}</span>
                            </div>
                        </div>
                    ))}

                    <div className="w-full grid grid-cols-7 justify-between gap-4 px-4 py-1 bg-secondary font-bold border-b">
                        <div className="flex items-center justify-center py-2">
                            <span className="text-xs text-center">جمع</span>
                        </div>
                        <div className="flex items-center justify-center py-2"></div>
                        <div className="flex items-center justify-center py-2"></div>
                        <div className="flex items-center justify-center py-2"></div>
                        <div className="flex items-center justify-center py-2">
                            <span className="text-xs text-center">{formatNumber(sum("hour_normal"), false, 1)}</span>
                        </div>
                        <div className="flex items-center justify-center py-2">
                            <span className="text-xs text-center">{formatNumber(sum("delay_minutes"))}</span>
                        </div>
                        <div className="flex items-center justify-center py-2">
                            <span className="text-xs text-center">{formatNumber(sum("extra_minutes"))}</span>
                        </div>
                    </div>
                </div>
            )}

            <Pagination totalPage={logs?.pages ?? 0} />
        </div>
    );
}

function Title() {
    /** «کاربر» — ایشوی ۵: نام از `user` می‌آید، نه از `contact` */
    const list = ["ردیف", "کاربر", "ساعت ورود", "ساعت خروج", "ساعت عادی", "تاخیر (دقیقه)", "اضافه (دقیقه)"];

    return (
        <div className="w-full justify-between gap-4 p-4 bg-secondary rounded-t-md grid grid-cols-7">
            {list.map((l: string, i: number) => (
                <div key={i} className={cn("flex items-center justify-center")}>
                    <span className="text-xs font-bold text-[#777777]">{l}</span>
                </div>
            ))}
        </div>
    );
}
