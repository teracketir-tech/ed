import { api } from "@/lib/axios";

export interface UserOption {
    label: string;
    value: string;
}

/**
 * Options of the system users — the single source for every select whose value
 * is `user.id` (issue 5): the person behind a timesheet row, and the person
 * filter of the attendance list.
 *
 * The label is built in exactly one place so the timesheet table, its dialog
 * and the attendance filter all spell a name the same way.
 *
 * `user/options` sits behind no extra role (issue 18), only the session.
 *
 * @param companyId restricts the list to one company; empty means every user —
 *                  the daily-report header passes its own company (issue 1).
 */
export async function loadUserOptions(companyId?: string | number): Promise<UserOption[]> {
    const res = await api(`user/options${companyId ? `?company_id=${companyId}` : ""}`).catch(() => null);

    return (res?.data || []).map((row: { id: number | string; first_name?: string | null; last_name?: string | null }) => ({
        label: `${row.first_name ?? ""} ${row.last_name ?? ""}`.trim() || String(row.id),
        value: String(row.id),
    }));
}
