import Input from "@/components/shared/inputs";
import type { FormikProps } from "formik";
import { percentOf } from "./progress";
import type { ActivityValues } from "./types";

interface Props {
    name: "daily_progress" | "quality_percent";
    title: string;
    formik: FormikProps<ActivityValues>;
    /** سابقه‌ی این تسک در پروژه — فقط «درصد پیشرفت» مقدارِ غیرصفر دارد */
    base?: number;
    required?: boolean;
}

/**
 * فیلدِ درصد به‌صورت **اینپوت عددی** — ایشوی ۶ (جایگزین اسلایدر) و ایشوی ۸.
 *
 * مقدارِ واردشده همان «پیشرفتِ امروز» (delta) است نه تجمعی، چون
 * `daily_progress` پیشرفتِ همین گزارش است (بند ۲۱۰) و `cumulative_progress`
 * را Backend جمع می‌زند؛ سقف‌ها (0..100) را همان yup فرم می‌گیرد.
 *
 * ایشوی ۸ (عنوان‌ها): کنارِ فیلد، سه عدد دیده می‌شود — «پیشرفت فعالیت»
 * (قبل از امروز)، «امروز» همین ورودی، و «تجمعی» که جمع هر دو است و بعد از
 * تأیید گزارش جایگزینِ پیشرفت فعالیتِ گزارش بعدی می‌شود.
 *
 * خطای سرور بدون قیدِ `touched` نمایش داده می‌شود چون `applyApiErrors` فقط
 * `setFieldError` می‌زند؛ وگرنه پیامِ مهمِ «تجمعی بیش از ۱۰۰ درصد» که Backend
 * روی همین فیلد می‌گذارد نامرئی می‌ماند.
 */
export default function ProgressField({ name, title, formik, base = 0, required = false }: Props) {
    const isProgress = name === "daily_progress";
    const value = percentOf(formik.values[name]);
    const before = percentOf(base);
    /** رسیدنِ سابقه به ۱۰۰ یعنی جایی برای پیشرفت نمانده */
    const done = isProgress && before >= 100;
    const total = done ? 100 : isProgress ? Math.min(100, before + value) : value;
    /** ورودیِ بیش از سقف، سرور هم رد می‌کند؛ پس عددِ تجمعی سرخ می‌شود */
    const overflow = isProgress && before + value > 100;
    const error = formik.errors[name];

    return (
        <div className="flex w-full flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
                <label htmlFor={name} className="flex items-center gap-0.5 text-[0.8rem]! text-[#000000e0] font-bold">
                    {required && <span className="text-red-500">* </span>}
                    {title}
                </label>
                <span className={`rounded bg-secondary px-2 py-0.5 text-[0.75rem] font-bold tabular-nums ${overflow ? "text-red-500" : ""}`}>
                    {isProgress ? `تجمعی ${total}٪` : `${total}٪`}
                </span>
            </div>

            <Input name={name} type="number" formik={formik} noErrorText disabled={done} />

            <div className="flex items-center justify-between gap-2 text-[0.65rem] text-[#777777]">
                {done ? (
                    <span className="font-medium text-foreground">پیشرفتِ این تسک به ۱۰۰٪ رسیده است</span>
                ) : isProgress && before > 0 ? (
                    <>
                        <span>پیشرفت فعالیت {before}٪</span>
                        <span className="font-medium text-foreground">امروز {value}٪</span>
                        <span className={`font-bold ${overflow ? "text-red-500" : ""}`}>تجمعی {total}٪</span>
                    </>
                ) : (
                    <>
                        <span>{isProgress ? "بدون سابقه در این پروژه" : "کیفیتِ همین گزارش"}</span>
                        <span className="font-bold">{value}٪</span>
                    </>
                )}
            </div>

            {error && <p className="text-red-500 text-xs">{error}</p>}
        </div>
    );
}
