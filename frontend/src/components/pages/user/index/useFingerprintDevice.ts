import { useState } from "react";
import { api } from "@/lib/axios";
import { toast } from "sonner";

export type FpAction = "add" | "del";

export interface FpConflict {
    action: FpAction;
    targetId: number;
    pendingId: number;
    pendingName: string;
}

/**
 * Drives the two fingerprint-device actions opened from the user list.
 *
 * A request either succeeds (the row is queued / id assigned, list refreshed)
 * or comes back as a `conflict`: somebody else is already pending on the
 * device. In that case the pending user is surfaced so the caller can confirm
 * a replacement, which re-sends the request with `replace: true`.
 */
export function useFingerprintDevice(refresh: (loading?: boolean) => void) {
    const [conflict, setConflict] = useState<FpConflict | null>(null);

    const request = async (user: { id: number }, action: FpAction, replace = false) => {
        const res = await api(`user/${user.id}/fingerprint-${action}`, "POST", replace ? { replace: true } : undefined);

        if (res?.success) {
            toast.success(action === "add" ? "در صف افزودن به دستگاه انگشت زنی قرار گرفت." : "در صف حذف از دستگاه انگشت زنی قرار گرفت.");
            refresh(false);
        } else if (res?.conflict) {
            setConflict({
                action,
                targetId: user.id,
                pendingId: res?.pending_user?.id,
                pendingName: res?.pending_user?.name,
            });
        } else {
            toast.error(res?.message || "خطایی رخ داده است");
        }
    };

    const confirmReplace = () => {
        if (!conflict) return;

        const { action, targetId } = conflict;
        setConflict(null);
        request({ id: targetId }, action, true);
    };

    const closeConflict = () => setConflict(null);

    return { request, conflict, confirmReplace, closeConflict };
}
