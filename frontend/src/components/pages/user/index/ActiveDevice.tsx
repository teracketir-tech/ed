import Modal from "@/components/shared/Modal";
import ToolTip from "@/components/shared/ToolTip";
import { api } from "@/lib/axios";
import { formatDateToFa } from "@/lib/utils";
import { LogOut } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

interface ActiveLog {
    id?: number;
    os?: string;
    ip?: string;
    created_at?: string;
    user?: string;
}

/**
 * The session a row is holding right now, and the way to end it.
 *
 * Opened from the user list only for an online row, so `log` is the active
 * login log of that row plus the name the modal title spells out.
 */
export default function ActiveDevice({
    open,
    setOpen,
    log,
    fetchUsers,
}: {
    open: boolean;
    setOpen: (open: boolean) => void;
    log: ActiveLog;
    fetchUsers: (loading?: boolean) => void;
}) {
    const [loading, setLoading] = useState(false);

    const handleLogout = async () => {
        setLoading(true);

        const res = await api(`login-log/logout/${log.id}`, "POST");

        if (res?.success) {
            toast.success(res.message);
            fetchUsers(false);
            setOpen(false);

            //
        } else {
            toast.error(res?.message || "خطایی رخ داده است");
        }

        setLoading(false);
    };

    return (
        <Modal open={open} setOpen={setOpen} title={`دستگاه فعال : ${log?.user}`} className="w-full">
            <div className="w-full flex flex-col text-[0.8rem]! border rounded-t-md">
                <div className="w-full justify-between gap-4 p-4 bg-secondary rounded-t-md grid grid-cols-4">
                    <p className="text-center text-[#777777]">دستگاه</p>
                    <p className="text-center text-[#777777]">آی پی</p>
                    <p className="text-center text-[#777777]">زمان</p>
                    <p className="text-center text-[#777777]">عملیات</p>
                </div>

                <div className="grid grid-cols-4 items-center p-3">
                    <p className="text-center">{log?.os}</p>
                    <p className="text-center">{log?.ip}</p>
                    <p className="text-center">{formatDateToFa(log?.created_at, true)}</p>
                    <p className="text-center">
                        <ToolTip
                            triger={
                                <button className="cursor-pointer" onClick={handleLogout}>
                                    {loading ? (
                                        <p className="size-4 rounded-full border border-red-500 border-l-transparent animate-spin" />
                                    ) : (
                                        <LogOut className="size-3.5 text-red-500" />
                                    )}
                                </button>
                            }
                            text="خروج"
                        />
                    </p>
                </div>
            </div>
        </Modal>
    );
}
