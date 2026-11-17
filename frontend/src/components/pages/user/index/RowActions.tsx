import { Info, MonitorSmartphone, PenBox, Trash2Icon } from "lucide-react";
import { Link } from "react-router-dom";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import Confirm from "@/components/ui/confirm";
import ToolTip from "@/components/shared/ToolTip";
import { checkAccess } from "@/lib/utils";
import FingerprintButtons from "./FingerprintButtons";
import LoginAsButton from "./LoginAsButton";
import { isOnline, type UserLog } from "./isOnline";
import type { FpAction } from "./useFingerprintDevice";

interface RowUser {
    id: number;
    fingerprint_id?: number | null;
    add_fingerid?: number | null;
    del_fingerid?: number | null;
    logs?: UserLog[] | null;
}

interface RowActionsProps {
    user: RowUser;
    onFingerprint: (user: RowUser, action: FpAction) => void;
    onShowActiveLog: (user: RowUser) => void;
    onDelete: (id: number) => void;
}

/** ستون «عملیات» لیست کاربران — همه‌ی دکمه‌های سطر پشتِ همان مجوزها */
export default function RowActions({ user, onFingerprint, onShowActiveLog, onDelete }: RowActionsProps) {
    return (
        <div className="flex flex-wrap items-center justify-center py-4 gap-3">
            <FingerprintButtons user={user} onAction={onFingerprint} />

            <LoginAsButton user={user} />

            {checkAccess([705]) && (
                <Link to={`/user/${user.id}/login-log`} role="link">
                    <ToolTip triger={<Info className="size-3.5" />} text="لاگ ورود" />
                </Link>
            )}

            {checkAccess([706]) && isOnline(user?.logs) && (
                <button className="cursor-pointer" onClick={() => onShowActiveLog(user)}>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <MonitorSmartphone className="size-4" />
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>دستگاه فعال</p>
                        </TooltipContent>
                    </Tooltip>
                </button>
            )}

            {checkAccess([703]) && (
                <Link to={`/user/edit/${user.id}`} className="cursor-pointer" role="link">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <PenBox className="w-3.5 h-3.5" />
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>ویرایش</p>
                        </TooltipContent>
                    </Tooltip>
                </Link>
            )}

            {checkAccess([704]) && (
                <Confirm title="حذف کاربر" onConfirm={() => onDelete(user.id)}>
                    <button className="cursor-pointer">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Trash2Icon className="w-4 h-4 text-red-500" />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>حذف</p>
                            </TooltipContent>
                        </Tooltip>
                    </button>
                </Confirm>
            )}
        </div>
    );
}
