import { cn } from "@/lib/utils";

// Twelve tracks, with the device-status column fixed at 140px so its longer
// header and pending badges stay on one line. The e-mail track is wider than a
// plain 1fr (1.5) because an address is one unbreakable LTR run.
export const USER_LIST_GRID =
    "grid grid-cols-[repeat(5,minmax(0,1fr))_minmax(0,1.5fr)_repeat(3,minmax(0,1fr))_140px_repeat(2,minmax(0,1fr))]";

const TITLES = [
    "ردیف",
    "شناسه",
    "نام",
    "نام خانوادگی",
    "موبایل",
    "ایمیل",
    "شرکت",
    "گروه",
    "اثر انگشت",
    "وضعیت ثبت در دستگاه",
    "وضعیت",
    "عملیات",
];

/** سربرگ لیست کاربران — ترتیب با سلول‌های هر ردیف در صفحه‌ی لیست یکی است */
export default function ListHeader() {
    return (
        <div className={`w-full justify-between gap-4 p-4 bg-secondary rounded-t-md ${USER_LIST_GRID}`}>
            {TITLES.map((l: string, i: number) => (
                <div key={i} className={cn("flex items-center justify-center")}>
                    <span className="text-xs font-bold text-[#777777]">{l}</span>
                </div>
            ))}
        </div>
    );
}
