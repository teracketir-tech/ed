import Pagination from "@/components/shared/Pagination";
import { api } from "@/lib/axios";
import { checkAccess, cn, exportAccounting, formatDateToFa, formatNumber, truncateByWord } from "@/lib/utils";
import { CheckCircle, DownloadCloudIcon, Eye, FileDownIcon, FileSpreadsheet, PenBox, Trash2Icon } from "lucide-react";
import { useEffect, useState } from "react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import Confirm from "@/components/ui/confirm";
import { toast } from "sonner";
import Empty from "@/components/shared/Empty";
import Loading from "@/components/shared/Loading";
import { Link, useSearchParams } from "react-router-dom";
import FilterForm from "@/components/pages/accounting/index/Filter";
import Button from "@/components/shared/Button";
import Modal from "@/components/shared/Modal";
import ToolTip from "@/components/shared/ToolTip";
import { type UploadFile } from "antd";
import FilesUpload from "@/components/pages/accounting/FilesUpload";
import { collectFiles, parseFileUrls } from "@/components/pages/accounting/uploadHelpers";

export default function AccountingList() {
    const [searchParams] = useSearchParams();
    const [loading, setLoading] = useState<any>(true);
    const [accounting, setAccounting] = useState<any>({});
    const [selectedItem, setSelectedItem] = useState<any>({});
    const [openImagesModal, setOpenImagesModal] = useState(false);
    const [exportLoading, setExportLoading] = useState<any>(false);
    const [approveTarget, setApproveTarget] = useState<{ id: number } | null>(null);
    const [approveOpen, setApproveOpen] = useState(false);
    const [approveFiles, setApproveFiles] = useState<UploadFile[]>([]);
    const [approveLoading, setApproveLoading] = useState(false);

    const fetchAccounting = async (excel?: boolean, loading = true) => {
        const params = new URLSearchParams(searchParams.toString());
        const query = params.toString();

        if (excel) setExportLoading(true);
        else if (loading) setLoading(true);

        const res = await api(`accounting?${query ? `${query}&` : ""}${excel ? `excel=${excel}` : ""}`);

        if (excel) {
            return res;

            //
        } else {
            setAccounting(res);
            setLoading(false);
        }
    };

    const handleDelete = async (id: number) => {
        setLoading(true);

        const res = await api(`accounting/${id}`, "DELETE");

        if (res?.success) {
            fetchAccounting();
            toast.success("سند با موفقیت حذف شد.");
        }
    };

    const openApprove = (acc: { id: number }) => {
        setApproveTarget(acc);
        setApproveFiles([]);
        setApproveOpen(true);
    };

    const handleApprove = async () => {
        if (!approveTarget) return;

        setApproveLoading(true);

        const files = collectFiles(approveFiles);
        const formData = new FormData();

        files.forEach((file) => formData.append("images[]", file));

        const res = files.length
            ? await api(`accounting/${approveTarget.id}/approve`, "POST", formData)
            : await api(`accounting/${approveTarget.id}/approve`, "POST");

        if (res?.success) {
            setApproveOpen(false);
            setApproveTarget(null);
            fetchAccounting();
            toast.success("سند با موفقیت تایید شد.");
        } else {
            toast.error(res?.message || "خطایی رخ داده است");
        }

        setApproveLoading(false);
    };

    const collectParents = (id: number, byId: Record<number, any>): string[] => {
        const node = byId[id];

        if (!node || node.parent_id == null) return [];

        return [byId[node.parent_id].title, ...collectParents(node.parent_id, byId)];
    };

    const handleExport = async () => {
        const res = await fetchAccounting(true);

        const byId = Object.fromEntries(res.accounting_data_list.map((ad: any) => [ad.id, ad]));

        const data = res.data.map((ac: any) => ({
            ...ac,
            items: ac.items.map((ai: any) => ({ ...ai, parents: collectParents(ai.accounting_data_id, byId) })),
        }));

        await exportAccounting(data);

        setExportLoading(false);
    };

    const handleShowFiles = (item: any) => {
        setSelectedItem({
            ...item,
            createFiles: parseFileUrls(item?.image),
            approveFiles: parseFileUrls(item?.approve_image),
        });
        setOpenImagesModal(true);
    };

    useEffect(() => {
        fetchAccounting();
    }, [searchParams]);

    const getDetail = (item: any) => {
        const accountingData = item?.accountingData?.title || "";
        let detail = null;

        if (item?.contact_id) {
            detail = item?.contact?.alias;
        }

        if (item?.bank_id) {
            detail = item?.bank?.name || item?.bank?.bankName?.name;
        }

        if (item?.product_id) {
            detail = item?.product?.name;
        }

        if (item?.material_id) {
            detail = `${item?.material?.name}${item?.material?.color ? `(${item?.material?.color.name})` : ""}`;
        }

        return `${accountingData}${detail ? ` - ${detail}` : ""}`;
    };

    return (
        <div className="w-full">
            <FilterForm loading={loading} />

            <div className="flex items-center gap-5">
                {/* excel export */}
                <Button className="mb-4 w-48! flex items-center gap-1" isLoading={exportLoading} onClick={handleExport}>
                    <FileSpreadsheet size={17} />
                    <span>خروجی اکسل</span>
                </Button>
            </div>

            <>
                <Title />

                {loading ? (
                    <div className="w-full flex items-center justify-center py-5">
                        <Loading />
                    </div>
                ) : accounting?.data?.length == 0 ? (
                    <Empty />
                ) : (
                    <div className="flex flex-col gap-1 border">
                        {accounting?.data?.map((acc: any, i: number) => (
                            <div key={acc.id} className="w-full grid grid-cols-17 justify-between gap-4 px-4 py-1 hover:bg-gray-50 border-b">
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{i + 1}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc.id}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">
                                        {acc?.flag == 1 ? <p className="size-2 rounded-full bg-red-500"></p> : <>---</>}
                                    </span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{formatDateToFa(acc.created_at)}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{formatDateToFa(acc.date)}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc?.approve_date ? formatDateToFa(acc.approve_date) : "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span>{truncateByWord(acc.description)}</span>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <p>{acc.description}</p>
                                            </TooltipContent>
                                        </Tooltip>
                                    </span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{formatNumber(acc.total_debit, true)}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{formatNumber(acc.total_credit, true)}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc.status == "draft" ? "پیش‌نویس" : "تایید شده"}</span>
                                </div>
                                <div className="flex flex-col items-center justify-center py-4 text-xs">
                                    <p className="w-full text-center pb-1 mb-1 border-b">{getDetail(acc?.items?.[0])}</p>
                                    <p className="w-full text-center">{getDetail(acc?.items?.[1])}</p>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc?.project?.name || "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc?.company?.name || "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc?.user ? `${acc?.user?.first_name} ${acc?.user?.last_name}` : "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc?.user_os || "---"}</span>
                                </div>
                                <div className="flex items-center justify-center py-4">
                                    <span className="text-xs text-center">{acc?.user_ip || "---"}</span>
                                </div>
                                <div className="flex flex-wrap items-center justify-center py-4 gap-3">
                                    {checkAccess([614]) && acc?.status == "draft" && (
                                        <Link to={`/accounting/edit/${acc.id}`} className="cursor-pointer" role="link">
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

                                    {checkAccess([603]) && acc?.status == "draft" && (
                                        <button className="cursor-pointer" onClick={() => openApprove(acc)}>
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <CheckCircle className="size-3.5" />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>تایید</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </button>
                                    )}

                                    {checkAccess([612]) && (
                                        <Link to={`items?accounting_id=${acc.id}`} className="cursor-pointer" role="link">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Eye className="size-4" />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>مشاهده جزییات</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </Link>
                                    )}

                                    {checkAccess([613]) && (acc?.image || acc?.approve_image) && (
                                        <button className="cursor-pointer" onClick={() => handleShowFiles(acc)}>
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <DownloadCloudIcon className="size-4" />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>مشاهده فایل</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </button>
                                    )}

                                    {checkAccess([604]) && (
                                        <Confirm title="حذف سند" onConfirm={() => handleDelete(acc.id)}>
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
                            </div>
                        ))}
                    </div>
                )}

                {!loading && <Pagination totalPage={accounting.pages} />}
                <DownloadModal
                    open={openImagesModal}
                    setOpen={setOpenImagesModal}
                    createFiles={selectedItem.createFiles}
                    approveFiles={selectedItem.approveFiles}
                    id={selectedItem.id}
                />
                <ApproveModal
                    open={approveOpen}
                    setOpen={setApproveOpen}
                    docId={approveTarget?.id}
                    fileList={approveFiles}
                    setFileList={setApproveFiles}
                    loading={approveLoading}
                    onConfirm={handleApprove}
                />
            </>
        </div>
    );
}

function Title() {
    const list = [
        "ردیف",
        "شناسه",
        "عدم تطابق تاریخ",
        "تاریخ روز",
        "تاریخ سند",
        "تاریخ تایید",
        "شرح",
        "مبلغ بدهکار",
        "مبلغ بستانکار",
        "وضعیت",
        "حساب / تفصیل",
        "پروژه",
        "شرکت",
        "نام ثبت کننده",
        "سیستم عامل",
        "آی پی",
        "عملیات",
    ];

    return (
        <div className="w-full justify-between gap-4 p-4 bg-secondary rounded-t-md grid grid-cols-17">
            {list.map((l: string, i: number) => (
                <div key={i} className={cn("flex items-center justify-center")}>
                    <span className="text-xs font-bold text-[#777777]">{l}</span>
                </div>
            ))}
        </div>
    );
}

/** One heading per upload moment; a moment without files shows no heading. */
function FileGroup({ title, files }: { title: string; files: string[] }) {
    if (!files?.length) return null;

    return (
        <div className="mb-5 last:mb-0">
            <div className="flex items-center gap-3 mb-3">
                <span className="text-sm font-bold text-[#777777]">{title}</span>
                <div className="flex-1 border-b border-gray-200" />
            </div>
            <div className="grid grid-cols-3 gap-5">
                {files.map((img: string, i: number) => (
                    <Link key={i} to={img} className="cursor-pointer" role="link" target="_blank">
                        <ToolTip
                            triger={
                                <div className="flex flex-col gap-2 border rounded-md p-2">
                                    <div className="flex items-center justify-center border-b border-gray-100 pb-2">
                                        <FileDownIcon size={40} className="text-gray-300" />
                                    </div>
                                    <div dir="ltr">{truncateByWord(img, 20)}</div>
                                </div>
                            }
                            text="برای مشاهده، کلیک کنید"
                        />
                    </Link>
                ))}
            </div>
        </div>
    );
}

function DownloadModal({
    open,
    setOpen,
    createFiles,
    approveFiles,
    id,
}: {
    open: boolean;
    setOpen: any;
    createFiles: string[];
    approveFiles: string[];
    id: number;
}) {
    return (
        <Modal open={open} setOpen={setOpen} title={`فایل های سند : ${id}`}>
            <FileGroup title="فایل‌های زمان ثبت" files={createFiles || []} />
            <FileGroup title="فایل‌های زمان تایید" files={approveFiles || []} />
        </Modal>
    );
}

/** Approving collects its own attachments, kept apart from the registration ones. */
function ApproveModal({
    open,
    setOpen,
    docId,
    fileList,
    setFileList,
    loading,
    onConfirm,
}: {
    open: boolean;
    setOpen: (open: boolean) => void;
    docId?: number;
    fileList: UploadFile[];
    setFileList: (files: UploadFile[]) => void;
    loading: boolean;
    onConfirm: () => void;
}) {
    return (
        <Modal open={open} setOpen={setOpen} title={`تایید سند : ${docId ?? ""}`}>
            <p className="text-sm text-gray-500 mb-4">در صورت نیاز، فایل‌های مربوط به زمان تایید را پیوست کنید</p>
            <FilesUpload fileList={fileList} setFileList={setFileList} />
            <div className="flex justify-end gap-2 mt-5">
                <Button className="bg-transparent! text-[#222529]! border-2 border-[#222529]! h-9" onClick={() => setOpen(false)}>
                    انصراف
                </Button>
                <Button className="h-9" isLoading={loading} onClick={onConfirm}>
                    تایید سند
                </Button>
            </div>
        </Modal>
    );
}
