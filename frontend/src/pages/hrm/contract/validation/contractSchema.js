// src/pages/hrm/contract/validation/contractSchema.js
// ============== تبدیل اعداد فارسی/عربی به انگلیسی ==============
const PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹";
const ARABIC_DIGITS  = "٠١٢٣٤٥٦٧٨٩";

const toEnglishDigits = (value) => {
    if (value === null || value === undefined) return value;
    return String(value)
        .replace(/[۰-۹]/g, (d) => String(PERSIAN_DIGITS.indexOf(d)))
        .replace(/[٠-٩]/g, (d) => String(ARABIC_DIGITS.indexOf(d)));
};
import * as yup from "yup";

// ============== ابزارهای کمکی ==============
const digitsOnly = (value) => /^\d+$/.test(value || "");

const optionalNumber = (min = 0, max = null) => {
    let schema = yup
        .number()
        .transform((value, original) => {
            if (original === "" || original === null || original === undefined) return null;
            const n = Number(original);
            return Number.isNaN(n) ? original : n;
        })
        .nullable()
        .typeError("مقدار باید عدد باشد");
    if (min !== null) schema = schema.min(min, `حداقل ${min} مجاز است`);
    if (max !== null) schema = schema.max(max, `حداکثر ${max} مجاز است`);
    return schema;
};

const optionalString = (max = 255) =>
    yup
        .string()
        .nullable()
        .transform((v) => (v === "" ? null : v))
        .max(max, `حداکثر ${max} کاراکتر`);

const requiredPersianDate = (fieldLabel) =>
    yup
        .string()
        .required(`${fieldLabel} الزامی است`)
        .transform((v) => (v === "" || v === null || v === undefined ? v : toEnglishDigits(v)))
        .matches(/^\d{4}\/\d{2}\/\d{2}$/, `${fieldLabel} باید به فرمت YYYY/MM/DD باشد`);

const optionalPersianDate = (fieldLabel) =>
    yup
        .string()
        .nullable()
        .transform((v) => {
            if (v === "" || v === null || v === undefined) return null;
            return toEnglishDigits(v);
        })
        .test("persian-date", `${fieldLabel} باید به فرمت YYYY/MM/DD باشد`, (value) => {
            if (value === null || value === undefined) return true;
            return /^\d{4}\/\d{2}\/\d{2}$/.test(value);
        });

const persianTime = (fieldLabel) =>
    yup
        .string()
        .nullable()
        .transform((v) => (v === "" ? null : v))
        .test("persian-time", `${fieldLabel} باید به فرمت HH:MM باشد`, (value) => {
            if (!value) return true;
            return /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value);
        });

// ============== Schema اصلی ==============
export const getContractValidationSchema = (contractType) => {
    const type = String(contractType ?? "");

    // ===== فیلدهای پایه (مشترک همه انواع) =====
    const base = {
        // --- اطلاعات پایه ---
        employer_id: yup
            .string()
            .nullable()
            .test("employer-required", "کارفرما الزامی است", (v) => !!v),
        contract_type: yup
            .string()
            .required("نوع قرارداد الزامی است")
            .oneOf(["1", "2", "3"], "نوع قرارداد نامعتبر است"),
        contract_mode: yup
            .string()
            .nullable()
            .test("mode-required", "حالت قراردادی الزامی است", (v) => !!v)
            .test("mode-valid", "حالت قراردادی نامعتبر است", (v) => !v || ["1", "2"].includes(v)),
        has_user: yup.string().nullable().oneOf(["0", "1"], "مقدار نامعتبر"),
        user_id: yup.string().when("has_user", {
            is: "1",
            then: (s) => s.required("انتخاب کاربر الزامی است"),
            otherwise: (s) => s.nullable(),
        }),
        personnel_code: optionalString(50),
        title: optionalString(200),
        description: optionalString(2000),
        job_position_ids: yup.array().of(yup.string().nullable()).nullable(),

        // --- مشخصات شخص (حقیقی/حقوقی) ---
        person_type: yup.string().nullable().oneOf(["1", "2"], "نوع شخصیت نامعتبر است"),
        first_name: yup
            .string()
            .required("نام الزامی است")
            .max(100, "حداکثر ۱۰۰ کاراکتر")
            .matches(/^[\u0600-\u06FF\sA-Za-z]+$/, "نام فقط می‌تواند حروف باشد"),
        last_name: yup
            .string()
            .required("نام خانوادگی الزامی است")
            .max(100, "حداکثر ۱۰۰ کاراکتر")
            .matches(/^[\u0600-\u06FF\sA-Za-z]+$/, "نام خانوادگی فقط می‌تواند حروف باشد"),
        father_name: yup
            .string()
            .required("نام پدر الزامی است")
            .max(100, "حداکثر ۱۰۰ کاراکتر")
            .matches(/^[\u0600-\u06FF\sA-Za-z]+$/, "نام پدر فقط می‌تواند حروف باشد"),
        national_code: yup
            .string()
            .required("کد ملی الزامی است")
            .matches(/^\d{10}$/, "کد ملی باید ۱۰ رقم باشد")
            .test("national-code-check", "کد ملی نامعتبر است", (value) => {
                if (!value || value.length !== 10) return true;
                if (/^(\d)\1{9}$/.test(value)) return false;
                const check = +value[9];
                let sum = 0;
                for (let i = 0; i < 9; i++) sum += +value[i] * (10 - i);
                const rem = sum % 11;
                return (rem < 2 && check === rem) || (rem >= 2 && check === 11 - rem);
            }),
        shenasname_number: yup
            .string()
            .required("شماره شناسنامه الزامی است")
            .max(50, "حداکثر ۵۰ کاراکتر"),
        shenasname_city: yup
            .string()
            .required("محل صدور الزامی است")
            .max(100, "حداکثر ۱۰۰ کاراکتر"),
        gender: yup.string().nullable().oneOf(["1", "2"], "جنسیت نامعتبر است"),
        address: optionalString(1000),
        postal_code: yup
            .string()
            .nullable()
            .transform((v) => (v === "" ? null : v))
            .test("postal-code", "کد پستی باید ۱۰ رقم باشد", (v) => !v || /^\d{10}$/.test(v)),
        phone_prefix: yup
            .string()
            .nullable()
            .transform((v) => (v === "" ? null : v))
            .test("phone-prefix", "پیش‌شماره باید ۲ تا ۵ رقم باشد", (v) => !v || /^\d{2,5}$/.test(v)),
        phone: yup
            .string()
            .nullable()
            .transform((v) => (v === "" ? null : v))
            .test("phone", "تلفن باید ۸ رقم باشد", (v) => !v || /^\d{8}$/.test(v)),
        mobile: yup
            .string()
            .required("موبایل الزامی است")
            .matches(/^09\d{9}$/, "موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد"),
        child_count: optionalNumber(0, 10),

        // --- شخص حقوقی ---
        company_name: optionalString(200),
        company_registration_number: optionalString(50),
        company_type: yup.string().nullable().oneOf(["1","2","3","4","5","6", null], "نوع شخص حقوقی نامعتبر"),
        company_position: yup.string().nullable().oneOf(["1","2","3", null], "سمت نامعتبر"),

        // --- محل انجام کار ---
        state_id: yup.string().nullable(),
        city_id: yup.string().nullable(),
        agent_id: yup.string().nullable(),

        // --- معرف ---
        has_introducer: yup.string().nullable().oneOf(["0", "1"]),
        introducer_first_name: yup.string().when("has_introducer", {
            is: "1",
            then: (s) => s.required("نام معرف الزامی است").max(100),
            otherwise: (s) => s.nullable(),
        }),
        introducer_last_name: yup.string().when("has_introducer", {
            is: "1",
            then: (s) => s.required("نام خانوادگی معرف الزامی است").max(100),
            otherwise: (s) => s.nullable(),
        }),
        introducer_phone_prefix: yup.string().nullable(),
        introducer_phone: yup.string().nullable(),
        introducer_mobile: yup
            .string()
            .nullable()
            .transform((v) => (v === "" ? null : v))
            .test("intro-mobile", "موبایل معرف نامعتبر است", (v) => !v || /^09\d{9}$/.test(v)),
        introducer_relation: optionalString(100),
        introducer_address: optionalString(1000),

        // --- موضوع قرارداد ---
        service_subject: optionalString(2000),

        // --- مدت قرارداد ---
        contract_duration_months: optionalNumber(0, 600),
        contract_duration_days: optionalNumber(0, 31),
        contract_from_date: requiredPersianDate("تاریخ شروع"),
        contract_to_date: optionalPersianDate("تاریخ پایان")
            .test("to-date-after-from", "تاریخ پایان باید بعد از تاریخ شروع باشد", function (value) {
                const { contract_from_date } = this.parent;
                if (!value || !contract_from_date) return true;
                return value >= contract_from_date;
            }),
        contract_unvalid_date: optionalPersianDate("تاریخ عدم اعتبار"),
        has_trial_period: yup.string().nullable().oneOf(["0", "1"]),
        trial_from_date: optionalPersianDate("تاریخ شروع دوره آزمایشی"),
        trial_to_date: optionalPersianDate("تاریخ پایان دوره آزمایشی")
            .test("trial-after", "پایان دوره آزمایشی باید بعد از شروع باشد", function (value) {
                const { trial_from_date } = this.parent;
                if (!value || !trial_from_date) return true;
                return value >= trial_from_date;
            }),
        agreement_date: optionalPersianDate("تاریخ توافق"),
        minimum_hours: optionalNumber(0, 1000),
        minimum_hours_unit: yup.string().nullable().oneOf(["hour", "day", null]),
        minimum_hours_period: yup.string().nullable().oneOf(["week", "month", null]),
    };

    // ===== فیلدهای اختصاصی هر نوع =====
    if (type === "1") {
        // موقت
        return yup.object({
            ...base,
            birth_date: optionalPersianDate("تاریخ تولد"),
            marital_status: yup.string().nullable().oneOf(["1", "2", "3", null], "وضعیت تاهل نامعتبر"),
            coupon: optionalNumber(0),
            child_allowance: optionalNumber(0),
            marriage_allowance: optionalNumber(0),
            base_salary: optionalNumber(0),
            housing_allowance: optionalNumber(0),
            welfare_allowance: optionalNumber(0),
            seniority_allowance: optionalNumber(0),
            performance_bonus: optionalNumber(0),
            responsibility_allowance: optionalNumber(0),
            transportation_allowance: optionalNumber(0),
            other_allowance: optionalNumber(0),
        });
    }

    if (type === "2") {
        // ساعتی
        return yup.object({
            ...base,
            marital_status: yup.string().nullable().oneOf(["1", "2", "3", null], "وضعیت تاهل نامعتبر"),
            coupon: optionalNumber(0),
            hourly_salary: optionalNumber(0),
            hourly_housing_allowance: optionalNumber(0),
            hourly_welfare_allowance: optionalNumber(0),
            hourly_child_allowance: optionalNumber(0),
            hourly_marriage_allowance: optionalNumber(0),
            hourly_seniority_allowance: optionalNumber(0),
            hourly_bonus: optionalNumber(0),
            hourly_leave_salary: optionalNumber(0),
            hourly_performance_bonus: optionalNumber(0),
            transportation_allowance: optionalNumber(0),
        });
    }

    if (type === "3") {
        // پیمانکاری
        return yup.object({
            ...base,
            contract_amount: optionalNumber(0),
            contract_payment_type: yup
                .string()
                .required("نحوه پرداخت الزامی است")
                .oneOf(["daily", "weekly", "monthly", "project"], "نحوه پرداخت نامعتبر"),
            responsibility_allowance: optionalNumber(0),
            performance_bonus: optionalNumber(0),
            transportation_allowance: optionalNumber(0),
        });
    }

    // نوع نامشخص → فقط پایه
    return yup.object(base);
};