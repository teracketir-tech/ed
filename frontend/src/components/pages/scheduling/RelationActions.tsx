import Modal from "@/components/shared/Modal";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { checkAccess } from "@/lib/utils";
import { ArrowLeftRight, Link2 } from "lucide-react";
import { useState } from "react";
import { Link } from "react-router-dom";
import RelationForm from "./RelationForm";

interface Props {
    task: { id: number | string; project_id: number | string };
    /** ثبت موفق از مودال — لیست تسک‌ها تازه می‌شود چون رابطه برنامه را جابه‌جا می‌کند */
    onCreated?: () => void;
}

/**
 * دو اکشنِ رابطهٔ هر سطرِ لیست تسک‌ها:
 *
 *  - «روابط» → صفحهٔ لیست روابط همین پروژه با فیلتر `predecessor_task_id`،
 *    پس همان ردیفِ مقدم بودنِ این تسک نشان داده می‌شود.
 *  - «افزودن رابطه» → مودالی با همان فرمِ صفحهٔ ساخت رابطه، با پروژه و تسک مقدم
 *    از قبل انتخاب‌شده و غیرفعال؛ فقط تسک وابسته و بقیهٔ فیلدها می‌ماند.
 */
export default function RelationActions({ task, onCreated }: Props) {
    const [open, setOpen] = useState(false);
    const relationsUrl = `/task-relation?project_id=${task.project_id}&predecessor_task_id=${task.id}`;

    return (
        <>
            {checkAccess([2406]) && (
                <Link to={relationsUrl} className="cursor-pointer" role="link" aria-label="روابط">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <ArrowLeftRight className="w-3.5 h-3.5" />
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>روابط</p>
                        </TooltipContent>
                    </Tooltip>
                </Link>
            )}

            {checkAccess([2405]) && (
                <>
                    <button className="cursor-pointer" onClick={() => setOpen(true)} aria-label="افزودن رابطه">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Link2 className="w-3.5 h-3.5" />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>افزودن رابطه</p>
                            </TooltipContent>
                        </Tooltip>
                    </button>

                    <Modal title="افزودن رابطه" open={open} setOpen={setOpen} className="w-11/12 sm:max-w-3xl">
                        <RelationForm
                            bare
                            lockedProjectId={String(task.project_id ?? "")}
                            lockedPredecessorId={String(task.id ?? "")}
                            onSaved={() => {
                                setOpen(false);
                                onCreated?.();
                            }}
                        />
                    </Modal>
                </>
            )}
        </>
    );
}
