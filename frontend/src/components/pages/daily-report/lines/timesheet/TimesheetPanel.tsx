import { useState } from "react";
import Button from "@/components/shared/Button";
import Notice from "../../Notice";
import LineForm from "../LineForm";
import LineTable from "../LineTable";
import useLines from "../useLines";
import useLookups from "../useLookups";
import LogPicker from "./LogPicker";
import useTimesheetLogs from "./useTimesheetLogs";
import { combineDateTime, timeOnly } from "./values";
import { asLocalLogId, isLocalLogId } from "../useDraftQueue";
import { emptyValues, toValues, type LineRow, type LineValues } from "../types";
import { LINE_CONFIGS } from "../config";

interface Props {
    /** `project_id` of the header, for the shared lookup dictionaries */
    projectId: string;
    /** `company_id` of the header (issue 1) */
    companyId?: string;
    /** Owner of the rows — issue 4: a timesheet row belongs to the report */
    reportId: number | null;
    /** `report_date` of the header — the day the log rows are read from */
    reportDate: string;
    locked?: boolean;
}

/** Stored stamps are datetimes, the dialog holds `HH:MM` only */
const stripDates = (values: LineValues): LineValues => ({
    ...values,
    checkindate: timeOnly(values.checkindate),
    checkout_date: timeOnly(values.checkout_date),
});

/**
 * The timesheet tab (issue 1) — it no longer owns a table of its own and
 * writes the shared fingerprint log `users_logs` instead. Since issue 4 it
 * hangs from the report itself, so it lives in the first tab of the form.
 *
 * Two ways in, one table out:
 *   1. «افزودن از لاگ روز» ticks the rows the device wrote for `report_date`
 *      (LogPicker). When the day has none, the picker says so and manual
 *      entry is the way forward (issue 1.3).
 *   2. «افزودن دستی» is the plain line dialog with every user on offer —
 *      no position filter first (issue 1.4 / 5).
 *
 * Both land on the same rows, so editing and deleting work identically.
 *
 * Draft mode: while the header is still unsaved the day is read by
 * `reportDate` alone, and the ticked ids ride the draft queue (they are not
 * new rows, they only get stamped) so the first «ذخیره پیش‌نویس» attaches them.
 */
export default function TimesheetPanel({ projectId, companyId = "", reportId, reportDate, locked = false }: Props) {
    const config = LINE_CONFIGS.timesheets;
    const lookups = useLookups(projectId, companyId);
    const { rows, loading, saving, save, remove, reload, drafting, queueLogs, pendingLogs } = useLines("timesheets", null, reportId);
    const logs = useTimesheetLogs({ reportDate, reportId });

    const [pickerOpen, setPickerOpen] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [initial, setInitial] = useState<LineValues | null>(null);

    const add = () => {
        setInitial(emptyValues(config.fields));
        setFormOpen(true);
    };

    const edit = (row: LineRow) => {
        setInitial(stripDates(toValues(config.fields, row)));
        setFormOpen(true);
    };

    /** The dialog knows a time of day; the table stores date + time */
    const submit = (values: LineValues) =>
        save({
            ...values,
            checkindate: combineDateTime(reportDate, values.checkindate),
            checkout_date: combineDateTime(reportDate, values.checkout_date),
        });

    /**
     * در حالت پیش‌نویس رکوردهای تیک‌شدهٔ لاگ هنوز مهر نخورده‌اند؛ همان‌ها را با
     * شناسهٔ موقت جلوی ردیف‌های دستی نشان می‌دهیم تا انتخابِ کاربر دیده شود و
     * بتواند از صف هم برداردشان (حذف = کنار رفتن از صف، نه حذفِ سروری).
     */
    const queued = logs.rows
        .filter((row) => pendingLogs.some((id) => String(id) === String(row.id)))
        .map((row) => ({ ...row, id: asLocalLogId(row.id) }));

    /** به ترتیبِ بعد از ذخیره: ردیف‌های دستیِ صف، بعد رکوردهای تیک‌شدهٔ لاگ */
    const tableRows = [...rows, ...queued];

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <p className="text-sm font-bold">{config.title}</p>
                    <p className="text-xs text-[#777777]">{config.subtitle}</p>
                    {drafting && (
                        <p className="text-xs text-[#777777]">
                            گزارش هنوز ذخیره نشده است؛ این ردیف‌ها{pendingLogs.length ? " و رکوردهای تیک‌شدهٔ لاگ" : ""} با اولین «ذخیره پیش‌نویس» سربرگ ساخته می‌شوند.
                        </p>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="w-48">
                        <Button onClick={() => setPickerOpen(true)} disabled={locked || !reportDate}>
                            افزودن از لاگ روز
                        </Button>
                    </div>
                    <div className="w-36">
                        <Button onClick={add} disabled={locked}>
                            افزودن دستی
                        </Button>
                    </div>
                </div>
            </div>

            {!reportDate && <Notice text="برای ثبت تایم‌شیت ابتدا «تاریخ تنظیم فرم» را در سربرگ گزارش مشخص کنید." />}

            <LineTable
                config={config}
                rows={tableRows}
                loading={loading}
                label={lookups.label}
                readonly={locked}
                onEdit={edit}
                onDelete={(row) => remove(row.id)}
                canEdit={(row) => !isLocalLogId(row.id)}
            />

            <LogPicker
                open={pickerOpen}
                onClose={() => setPickerOpen(false)}
                reportDate={reportDate}
                rows={logs.rows}
                loading={logs.loading}
                saving={logs.saving}
                onConfirm={async (ids) => {
                    /**
                     * گزارش هنوز ساخته نشده — شناسه‌ها روی صف می‌مانند و با نخستین
                     * «ذخیره پیش‌نویس» همراه با خودِ گزارش مهر می‌خورند؛ خواندنِ
                     * لاگ‌ها از همین حالا با `reportDate` انجام می‌شود.
                     */
                    if (!reportId) {
                        queueLogs(ids);
                        return true;
                    }

                    const attached = await logs.attach(reportId, ids);
                    // The picker only stamps the rows; the table below reads
                    // the report's own rows, so it has to be re-read here.
                    if (attached) await reload();
                    return attached;
                }}
                initialPicked={pendingLogs}
            />

            <LineForm config={config} open={formOpen} onClose={() => setFormOpen(false)} options={lookups.options} initial={initial} saving={saving} onSave={submit} />
        </div>
    );
}
