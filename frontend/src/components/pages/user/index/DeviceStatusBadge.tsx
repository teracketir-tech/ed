type DeviceUser = {
    fingerprint_id?: number | null;
    add_fingerid?: number | null;
    del_fingerid?: number | null;
};

type DeviceState = "none" | "pending_add" | "registered" | "pending_del";

// Priority: a queued delete wins, then a queued add, then an assigned id, else nothing yet.
// Queued means the column equals its OWN flag: del_fingerid is 1, add_fingerid is 2.
function deviceState(user: DeviceUser): DeviceState {
    if (user?.del_fingerid === 1) return "pending_del";
    if (user?.add_fingerid === 2 && user?.fingerprint_id != null) return "pending_add";
    if (user?.fingerprint_id != null) return "registered";
    return "none";
}

const STYLES: Record<DeviceState, { label: string; bg: string; dot: string; text: string }> = {
    none: { label: "عدم ثبت", bg: "bg-gray-100", dot: "bg-gray-400", text: "text-gray-500" },
    pending_add: { label: "در انتظار ثبت", bg: "bg-amber-50", dot: "bg-amber-500", text: "text-amber-600" },
    registered: { label: "ثبت شده", bg: "bg-green-50", dot: "bg-green-500", text: "text-green-600" },
    pending_del: { label: "در انتظار حذف", bg: "bg-red-50", dot: "bg-red-500", text: "text-red-500" },
};

/**
 * Device status badge for the fingerprint column: one of four states derived
 * from the user's id + pending flags, styled like the online/offline pill.
 */
export default function DeviceStatusBadge({ user }: { user: DeviceUser }) {
    const style = STYLES[deviceState(user)];

    return (
        <div className={`flex items-center gap-1.5 py-1 px-3 rounded-full text-xs w-fit whitespace-nowrap ${style.bg}`}>
            <p className={`size-1.5 rounded-full ${style.dot}`}></p>
            <p className={style.text}>{style.label}</p>
        </div>
    );
}
