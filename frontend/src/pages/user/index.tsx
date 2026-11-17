import Pagination from "@/components/shared/Pagination";
import { api } from "@/lib/axios";
import { LogOut } from "lucide-react";
import { useEffect, useState } from "react";
import Confirm from "@/components/ui/confirm";
import { toast } from "sonner";
import { useSearchParams } from "react-router-dom";
import Empty from "@/components/shared/Empty";
import Loading from "@/components/shared/Loading";
import FilterForm from "@/components/pages/user/index/Filter";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import Button from "@/components/shared/Button";
import { truncateByWord } from "@/lib/utils";
import FingerprintConflict from "@/components/pages/user/index/FingerprintConflict";
import DeviceStatusBadge from "@/components/pages/user/index/DeviceStatusBadge";
import ActiveDevice from "@/components/pages/user/index/ActiveDevice";
import RowActions from "@/components/pages/user/index/RowActions";
import ListHeader, { USER_LIST_GRID } from "@/components/pages/user/index/ListHeader";
import OnlineBadge from "@/components/pages/user/index/OnlineBadge";
import { useFingerprintDevice } from "@/components/pages/user/index/useFingerprintDevice";

export default function UserList() {
    const [searchParams] = useSearchParams();
    const [users, setUsers] = useState<any>({});
    const [activeLog, setActiveLog] = useState({});
    const [loading, setLoading] = useState<any>(true);
    const [showActiveModal, setShowAvtiveModal] = useState(false);

    const fetchUsers = async (loading = true) => {
        const params = new URLSearchParams(searchParams.toString());
        const query = params.toString();

        if (loading) setLoading(true);

        const res = await api(`user?${query ? `${query}&` : ""}expand=company, group`, "GET");

        setUsers(res);
        setLoading(false);
    };

    const fp = useFingerprintDevice(fetchUsers);

    const handleDelete = async (id: number) => {
        setLoading(true);

        const res = await api(`user/${id}`, "DELETE");

        if (res?.success) {
            fetchUsers();
            toast.success("کاربر با موفقیت حذف شد.");
        }
    };

    const handleLogoutAll = async () => {
        setLoading(true);

        const res = await api("login-log/logout-all", "POST");

        if (res?.success) {
            fetchUsers(false);
            toast.success(res.message);

            //
        } else {
            toast.error(res?.message || "خطایی رخ داده است");
        }
    };

    useEffect(() => {
        fetchUsers();
    }, [searchParams]);

    const handleShowActiveLog = (user: any) => {
        const active = user?.logs.find((l: any) => l.active == 1);

        setActiveLog({ ...active, user: `${user.first_name} ${user.last_name}` });
        setShowAvtiveModal(true);
    };

    return (
        <div className="w-full">
            <FilterForm loading={loading} />

            <Confirm title="آفلاین کردن تمام دستگاه ها" onConfirm={handleLogoutAll}>
                <Button className="w-auto! mb-4">
                    <LogOut size={17} />
                    <span>آفلاین کردن تمام دستگاه ها</span>
                </Button>
            </Confirm>

            <>
                <ListHeader />

                {loading ? (
                    <div className="w-full flex items-center justify-center py-5">
                        <Loading />
                    </div>
                ) : users?.data?.length == 0 ? (
                    <Empty />
                ) : (
                    <div className="flex flex-col gap-1 border">
                        {users?.data?.map((user: any, i: number) => (
                            <div key={user.id} className={`w-full ${USER_LIST_GRID} justify-between gap-4 px-4 py-1 hover:bg-gray-50 border-b`}>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{i + 1}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{user.id}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{user.first_name}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{user.last_name}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center" dir="ltr">
                                        {user?.phone_number || "---"}
                                    </span>
                                </div>
                                <div className="flex items-center justify-center py-4 min-w-0">
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="block w-full truncate text-xs text-center" dir="ltr">
                                                {user?.email ? truncateByWord(user.email, 20) : "---"}
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p className="text-[0.8rem] max-w-xl text-center">{user?.email}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{user?.company?.name || "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{user?.group_names || user?.group?.name || "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{user?.fingerprint_id ?? "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <DeviceStatusBadge user={user} />
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <OnlineBadge logs={user?.logs} />
                                </div>
                                <RowActions user={user} onFingerprint={fp.request} onShowActiveLog={handleShowActiveLog} onDelete={handleDelete} />
                            </div>
                        ))}
                    </div>
                )}

                <Pagination totalPage={users.pages} />
                <ActiveDevice open={showActiveModal} setOpen={setShowAvtiveModal} log={activeLog} fetchUsers={fetchUsers} />
                <FingerprintConflict conflict={fp.conflict} onReplace={fp.confirmReplace} onClose={fp.closeConflict} />
            </>
        </div>
    );
}

