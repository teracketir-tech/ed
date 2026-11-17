import { UserPlus, UserMinus } from "lucide-react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import Confirm from "@/components/ui/confirm";
import { checkAccess } from "@/lib/utils";
import type { FpAction } from "./useFingerprintDevice";

type FingerprintUser = { id: number; fingerprint_id?: number | null; add_fingerid?: number | null; del_fingerid?: number | null };

/**
 * The one fingerprint-device action a row is eligible for, as an icon with a
 * tooltip (matching the other action-column buttons). A user with no id gets
 * "add"; a user who already has one gets "remove". A PENDING user (either
 * direction) gets no button at all until the device sync resolves. Each click
 * is confirmed first, then handed to the list's fingerprint hook.
 */
export default function FingerprintButtons({ user, onAction }: {
    user: FingerprintUser;
    onAction: (user: FingerprintUser, action: FpAction) => void;
}) {
    const hasFingerprint = user?.fingerprint_id != null;
    // a queued user is the one whose flag column holds its own number, not merely a value
    const pending = user?.add_fingerid === 2 || user?.del_fingerid === 1;

    return (
        <>
            {checkAccess([707]) && !hasFingerprint && !pending && (
                <Confirm title="افزودن به دستگاه انگشت زنی" onConfirm={() => onAction(user, "add")}>
                    <button className="cursor-pointer">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <UserPlus className="w-4 h-4 text-black" />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>اضافه به دستگاه انگشت زنی</p>
                            </TooltipContent>
                        </Tooltip>
                    </button>
                </Confirm>
            )}

            {checkAccess([708]) && hasFingerprint && !pending && (
                <Confirm title="حذف از دستگاه انگشت زنی" onConfirm={() => onAction(user, "del")}>
                    <button className="cursor-pointer">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <UserMinus className="w-4 h-4 text-black" />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>حذف از دستگاه انگشت زنی</p>
                            </TooltipContent>
                        </Tooltip>
                    </button>
                </Confirm>
            )}
        </>
    );
}
