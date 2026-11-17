import Modal from "@/components/shared/Modal";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import Confirm from "@/components/ui/confirm";
import { api } from "@/lib/axios";
import { checkAccess } from "@/lib/utils";
import { LogIn } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

interface HqGroup {
    id: number;
    name: string | null;
}

interface LoginAsUser {
    id: number;
    first_name?: string | null;
    last_name?: string | null;
    hq_groups?: HqGroup[];
}

/**
 * Signs the current session in as the row it sits on.
 *
 * The button is drawn from the server's own admission list (`hq_groups`), so
 * a row whose groups were all born elsewhere never offers a door that would
 * be shut one step later. A row with several admissible groups makes the
 * caller name one, and never picks one for itself.
 */
export default function LoginAsButton({ user }: { user: LoginAsUser }) {
    const [picking, setPicking] = useState(false);

    const groups = user?.hq_groups ?? [];
    const name = `${user?.first_name ?? ""} ${user?.last_name ?? ""}`.trim();

    // The permission is checked here only to keep the button out of the way;
    // the server enforces it again on the call itself.
    if (!checkAccess([710]) || groups.length === 0) return null;

    // A full reload, not a route change: every cached piece of the app - the
    // roles, the sidebar, the request header - has to be rebuilt from the
    // token that just changed hands.
    const signIn = async (group: HqGroup) => {
        const res = await api(`user/${user.id}/login-as`, "POST", { group_id: group.id });

        if (res?.success) {
            localStorage.setItem("token", res.token);
            localStorage.setItem("auth", JSON.stringify(res.user));

            toast.success(`شما وارد حساب ${name} شدید`);

            window.location.assign("/");
        } else {
            toast.error(res?.message || "خطایی رخ داده است");
        }
    };

    const label = (group: HqGroup) => `ورود با ${name} گروه ${group.name ?? group.id}`;

    return (
        <>
            {groups.length > 1 ? (
                <button className="cursor-pointer" onClick={() => setPicking(true)}>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <LogIn className="w-4 h-4" />
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>ورود با کاربر</p>
                        </TooltipContent>
                    </Tooltip>
                </button>
            ) : (
                <Confirm title={`ورود با ${name}`} onConfirm={() => signIn(groups[0])}>
                    <button className="cursor-pointer">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <LogIn className="w-4 h-4" />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>ورود با کاربر</p>
                            </TooltipContent>
                        </Tooltip>
                    </button>
                </Confirm>
            )}

            <Modal open={picking} setOpen={setPicking} title={`ورود با ${name}`} className="sm:max-w-sm">
                <p className="text-sm text-[#777777]">این کاربر چند گروه برای این پنل دارد؛ با کدام گروه می‌خواهید وارد شوید؟</p>

                <div className="flex flex-col gap-2">
                    {groups.map((group) => (
                        <Confirm key={group.id} title={label(group)} onConfirm={() => signIn(group)}>
                            <button className="w-full text-right rounded-md border px-3 py-2 text-sm cursor-pointer hover:bg-gray-50">
                                {group.name ?? group.id}
                            </button>
                        </Confirm>
                    ))}
                </div>
            </Modal>
        </>
    );
}
