import { AlertCircle, CheckCircle2 } from "lucide-react";

const FIELD_LABELS = {
    employer_id: "کارفرما",
    contract_type: "نوع قرارداد",
    contract_mode: "حالت قراردادی",
    has_user: "اختصاص کاربر",
    user_id: "کاربر",

    person_type: "شخصیت",
    first_name: "نام",
    last_name: "نام خانوادگی",
    father_name: "نام پدر",
    national_code: "کد ملی",
    shenasname_number: "شماره شناسنامه",
    shenasname_city: "محل صدور",
    mobile: "موبایل",

    contract_from_date: "تاریخ شروع قرارداد",
    contract_to_date: "تاریخ پایان قرارداد",
    contract_unvalid_date: "تاریخ عدم اعتبار",

    // ساعت کاری
    work_start_time: "ساعت شروع کار",
    work_end_time: "ساعت پایان کار",

    agreement_date: "تاریخ توافق",
    trial_from_date: "تاریخ شروع دوره آزمایشی",
    trial_to_date: "تاریخ پایان دوره آزمایشی",
    birth_date: "تاریخ تولد",

    service_subject: "موضوع قرارداد",
    introducer_first_name: "نام معرف",
    introducer_last_name: "نام خانوادگی معرف",

    minimum_hours: "حداقل کارکرد",
    minimum_hours_unit: "واحد حداقل کارکرد",
    minimum_hours_period: "دوره حداقل کارکرد",

    contract_amount: "مبلغ قرارداد",
    contract_payment_type: "نحوه پرداخت",
    base_salary: "مزد ماهانه",
    hourly_salary: "مزد ساعتی",

    company_name: "نام شخص حقوقی",
    company_registration_number: "شماره ثبت",
    company_type: "نوع شخص حقوقی",
    company_position: "سمت طرف قرارداد",
};

export default function ValidationSummary({
    formik,
    isBaseInfoComplete = true,
}) {
    // بررسی اطلاعات پایه قرارداد
    if (!isBaseInfoComplete) {
        const missingBase = [];

        if (!formik.values.employer_id) {
            missingBase.push("کارفرما");
        }

        if (!formik.values.contract_type) {
            missingBase.push("نوع قرارداد");
        }

        if (!formik.values.contract_mode) {
            missingBase.push("حالت قراردادی");
        }

        if (
            formik.values.has_user === "1" &&
            !formik.values.user_id
        ) {
            missingBase.push("کاربر");
        }

        if (missingBase.length > 0) {
            return (
                <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <AlertCircle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />

                    <div>
                        <p className="text-sm font-semibold text-amber-800 mb-2">
                            ابتدا اطلاعات پایه قرارداد را تکمیل کنید.
                        </p>

                        <ul className="list-disc list-inside space-y-1 text-sm text-amber-700">
                            {missingBase.map((item, index) => (
                                <li key={index}>{item}</li>
                            ))}
                        </ul>
                    </div>
                </div>
            );
        }
    }

    // بررسی خطاهای فرم
    const errorKeys = Object.keys(formik.errors || {});
    const hasErrors = errorKeys.length > 0;

    // اگر خطایی وجود ندارد
    if (!hasErrors) {
        if (formik.isValid && formik.dirty) {
            return (
                <div className="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-4">
                    <CheckCircle2 className="w-5 h-5 text-green-600 shrink-0" />

                    <p className="text-sm font-medium text-green-800">
                        همه فیلدهای الزامی تکمیل شده‌اند.
                    </p>
                </div>
            );
        }

        return null;
    }

    // آماده‌سازی لیست خطاها
    const errorList = errorKeys.map((key) => {
        const label = FIELD_LABELS[key] || key;
        const raw = formik.errors[key];

        const message =
            typeof raw === "string"
                ? raw
                : Array.isArray(raw)
                    ? raw.join("، ")
                    : String(raw);

        return {
            key,
            label,
            message,
        };
    });

    return (
        <div className="rounded-lg border border-red-200 bg-red-50 p-4">
            <div className="flex items-start gap-3">
                <AlertCircle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />

                <div className="flex-1">
                    <p className="text-sm font-semibold text-red-800 mb-3">
                        {errorList.length} مورد نیاز به اصلاح دارد:
                    </p>

                    <ul className="space-y-2">
                        {errorList.map((error) => (
                            <li
                                key={error.key}
                                className="flex flex-col gap-1 text-sm text-red-700"
                            >
                                <span className="font-medium">
                                    {error.label}
                                </span>

                                <span className="text-xs text-red-600">
                                    {error.message}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}