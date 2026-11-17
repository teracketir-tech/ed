import Modal from "@/components/shared/Modal";
import Button from "@/components/shared/Button";
import type { FpConflict } from "./useFingerprintDevice";

/**
 * Second step of a fingerprint action: somebody is already pending on the
 * device, so the pending user is named and the caller chooses to replace them
 * or back off. Rendered once by the list, driven by the conflict state.
 */
export default function FingerprintConflict({ conflict, onReplace, onClose }: { conflict: FpConflict | null; onReplace: () => void; onClose: () => void }) {
    const isAdd = conflict?.action === "add";

    return (
        <Modal
            open={!!conflict}
            setOpen={(open) => {
                if (!open) onClose();
            }}
            title={isAdd ? "افزودن به دستگاه انگشت زنی" : "حذف از دستگاه انگشت زنی"}
        >
            <p className="text-sm my-3">
                {isAdd ? (
                    <span className="">
                        <span className="py-0.5 px-3 rounded bg-amber-100 text-xs mb-2">{conflict?.pendingName}</span> در انتظار ثبت در دستگاه انگشت زنی است.
                        آیا جایگزین شود؟
                    </span>
                ) : (
                    <span className="">
                        <span className="py-0.5 px-3 rounded bg-amber-100 text-xs mb-2">{conflict?.pendingName}</span> در انتظار حذف از دستگاه انگشت زنی است.
                        آیا جایگزین شود؟
                    </span>
                )}
            </p>

            <div className="grid grid-cols-2 gap-3 mt-2">
                <Button onClick={onClose} className="bg-transparent! text-[#222529]! border-2 border-[#222529]! h-9">
                    خیر
                </Button>
                <Button onClick={onReplace} className="h-9">
                    بله
                </Button>
            </div>
        </Modal>
    );
}
