import { useState, useEffect } from "react";
import { toast } from "sonner";
import { api } from "@/lib/axios";
import Button from "@/components/shared/Button";
import Loading from "@/components/shared/Loading";
import { Printer, X, User, Wallet, Package, FileText } from "lucide-react";
import { formatNumber } from "@/lib/utils";

// ============================================================
// ===== کارت فیش حقوقی =====
// ============================================================
export default function SalarySlipCard({ slipId, onClose }) {
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null);

    // ===== دریافت فیش =====
    useEffect(() => {
        const fetchData = async () => {
            setLoading(true);
            try {
                const res = await api(`hrm-salary-slip/${slipId}`, "GET");
                if (res?.success && res?.data) {
                    setData(res.data);
                } else {
                    toast.error(res?.message || "فیش یافت نشد");
                }
            } catch (error) {
                console.error("Error fetching slip:", error);
                toast.error("خطا در دریافت فیش");
            }
            setLoading(false);
        };
        if (slipId) fetchData();
    }, [slipId]);

    // ===== پرینت =====
    const handlePrint = () => {
        window.open(`/hrm/payments/salary/print/${slipId}`, "_blank");
    };

    if (loading) {
        return (
            <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
                <div className="bg-white rounded-lg p-8">
                    <Loading />
                </div>
            </div>
        );
    }

    if (!data) return null;

    const user = data.user || {};
    const earnings = {
        base_salary: Number(data.base_salary) || 0,
        housing: Number(data.housing_allowance) || 0,
        food: Number(data.food_allowance) || 0,
        child: Number(data.child_allowance) || 0,
        seniority: Number(data.seniority_allowance) || 0,
        performance: Number(data.performance_bonus) || 0,
        responsibility: Number(data.responsibility_allowance) || 0,
        transport: Number(data.transportation_allowance) || 0,
        overtime: Number(data.overtime_pay) || 0,
        friday: Number(data.friday_work_pay) || 0,
        holiday: Number(data.holiday_work_pay) || 0,
        mission: Number(data.mission_pay) || 0,
        bonus: Number(data.bonus_amount) || 0,
        arrears: Number(data.arrears_amount) || 0,
        compensatory31: Number(data.compensatory_31_days) || 0,
        annual_bonus: Number(data.annual_bonus) || 0,
        vacation_buyback: Number(data.vacation_buyback) || 0,
        prev_month: Number(data.prev_month_remain) || 0,
        total: Number(data.total_additions) || 0,
    };

    const deductions = {
        insurance: Number(data.employee_insurance) || 0,
        supplementary_insurance: Number(data.supplementary_insurance) || 0,
        tax: Number(data.tax) || 0,
        loan: Number(data.loan_installment) || 0,
        advance: Number(data.advance_amount) || 0,
        delay: Number(data.delay) || 0,
        delay_penalty: Number(data.late_penalty_8_hours) || 0,
        early_leave: Number(data.early_leave) || 0,
        absence: Number(data.absence) || 0,
        penalty: Number(data.penalty) || 0,
        unauthorized_exit: Number(data.unauthorized_exit) || 0,
        student_vacation: Number(data.student_vacation) || 0,
        unpaid_vacation: Number(data.unpaid_vacation) || 0,
        company_purchase: Number(data.company_purchase) || 0,
        deduct29: Number(data.deduct_29_days) || 0,
        other: Number(data.other_deduction) || 0,
        total: Number(data.total_deductions) || 0,
    };

    const netPay = Number(data.net_payable) || 0;

    // ===== اموال =====
    const assets = data.assets || [];

    return (
        <div className="fixed inset-0 bg-black/50 flex items-start justify-center z-50 p-4 overflow-y-auto">
            <div className="bg-white rounded-lg shadow-xl w-full max-w-6xl my-4">
                {/* ===== هدر ===== */}
                <div className="bg-gradient-to-l from-blue-600 to-blue-800 text-white p-4 rounded-t-lg flex justify-between items-center">
                    <div>
                        <h2 className="text-lg font-bold">فیش حقوق و دستمزد</h2>
                        <p className="text-sm opacity-90">
                            {data.month_name} {data.year}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            onClick={handlePrint}
                            className="bg-white text-blue-600 hover:bg-gray-100 flex items-center gap-2"
                            size="sm"
                        >
                            <Printer className="w-4 h-4" />
                            پرینت
                        </Button>
                        <button
                            onClick={onClose}
                            className="text-white hover:bg-white/20 p-2 rounded"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* ===== بدنه ===== */}
                <div className="p-4">
                    {/* ===== مشخصات پرسنلی ===== */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 p-3 bg-gray-50 rounded-lg border">
                        <div className="flex items-center gap-2">
                            <User className="w-4 h-4 text-blue-600" />
                            <div>
                                <p className="text-xs text-gray-500">نام و نام خانوادگی</p>
                                <p className="font-bold">{user.first_name} {user.last_name}</p>
                            </div>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">کد پرسنلی</p>
                            <p className="font-bold">{user.personnel_code || "-"}</p>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">کد ملی</p>
                            <p className="font-bold">{user.national_id || "-"}</p>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">شماره حساب</p>
                            <p className="font-bold">{data.account_number || "-"}</p>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">بانک</p>
                            <p className="font-bold">{data.bank_name || "-"}</p>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">شماره بیمه</p>
                            <p className="font-bold">{user.insurance_number || "-"}</p>
                        </div>
                    </div>

                    {/* ===== دو ستون: مزایا و کسورات ===== */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* ===== ستون مزایا ===== */}
                        <div className="border rounded-lg overflow-hidden">
                            <div className="bg-green-600 text-white p-2 font-bold text-sm">
                                💰 شرح پرداخت‌ها (مزایا)
                            </div>
                            <div className="p-2 space-y-1 text-sm">
                                {earnings.base_salary > 0 && <Row label="حقوق ثابت" value={earnings.base_salary} />}
                                {earnings.housing > 0 && <Row label="حق مسکن" value={earnings.housing} />}
                                {earnings.food > 0 && <Row label="بن خواربار" value={earnings.food} />}
                                {earnings.child > 0 && <Row label="حق اولاد" value={earnings.child} />}
                                {earnings.seniority > 0 && <Row label="حق سنوات" value={earnings.seniority} />}
                                {earnings.performance > 0 && <Row label="پاداش عملکرد" value={earnings.performance} />}
                                {earnings.responsibility > 0 && <Row label="حق مسئولیت" value={earnings.responsibility} />}
                                {earnings.transport > 0 && <Row label="ایاب و ذهاب" value={earnings.transport} />}
                                {earnings.overtime > 0 && <Row label="اضافه‌کاری" value={earnings.overtime} />}
                                {earnings.friday > 0 && <Row label="جمعه‌کاری" value={earnings.friday} />}
                                {earnings.holiday > 0 && <Row label="تعطیل‌کاری" value={earnings.holiday} />}
                                {earnings.mission > 0 && <Row label="مأموریت" value={earnings.mission} />}
                                {earnings.bonus > 0 && <Row label="پاداش" value={earnings.bonus} />}
                                {earnings.arrears > 0 && <Row label="معوقه" value={earnings.arrears} />}
                                {earnings.compensatory31 > 0 && <Row label="جبران کارکرد ۳۱ روزه" value={earnings.compensatory31} />}
                                {earnings.annual_bonus > 0 && <Row label="عیدی" value={earnings.annual_bonus} />}
                                {earnings.vacation_buyback > 0 && <Row label="بازخرید مرخصی" value={earnings.vacation_buyback} />}
                                {earnings.prev_month > 0 && <Row label="مانده حقوق ماه قبل" value={earnings.prev_month} />}

                                <div className="border-t-2 border-green-500 mt-2 pt-2 flex justify-between font-bold text-green-700">
                                    <span>جمع پرداخت‌ها</span>
                                    <span>{formatNumber(earnings.total)} ریال</span>
                                </div>
                            </div>
                        </div>

                        {/* ===== ستون کسورات ===== */}
                        <div className="border rounded-lg overflow-hidden">
                            <div className="bg-red-600 text-white p-2 font-bold text-sm">
                                📉 شرح کسورات
                            </div>
                            <div className="p-2 space-y-1 text-sm">
                                {deductions.insurance > 0 && <Row label="بیمه سهم کارمند" value={deductions.insurance} danger />}
                                {deductions.supplementary_insurance > 0 && <Row label="بیمه تکمیلی" value={deductions.supplementary_insurance} danger />}
                                {deductions.tax > 0 && <Row label="مالیات حقوق" value={deductions.tax} danger />}
                                {deductions.loan > 0 && <Row label="قسط وام" value={deductions.loan} danger />}
                                {deductions.advance > 0 && <Row label="مساعده" value={deductions.advance} danger />}
                                {deductions.delay > 0 && <Row label="تأخیر" value={deductions.delay} danger />}
                                {deductions.delay_penalty > 0 && <Row label="جریمه تأخیر بیش از ۸ ساعت" value={deductions.delay_penalty} danger />}
                                {deductions.early_leave > 0 && <Row label="تعجیل" value={deductions.early_leave} danger />}
                                {deductions.absence > 0 && <Row label="غیبت" value={deductions.absence} danger />}
                                {deductions.penalty > 0 && <Row label="جریمه" value={deductions.penalty} danger />}
                                {deductions.unauthorized_exit > 0 && <Row label="خروج غیرمجاز" value={deductions.unauthorized_exit} danger />}
                                {deductions.student_vacation > 0 && <Row label="مرخصی دانشجویی" value={deductions.student_vacation} danger />}
                                {deductions.unpaid_vacation > 0 && <Row label="مرخصی بدون حقوق" value={deductions.unpaid_vacation} danger />}
                                {deductions.company_purchase > 0 && <Row label="خرید از شرکت" value={deductions.company_purchase} danger />}
                                {deductions.deduct29 > 0 && <Row label="کسر کارکرد ۲۹ روزه" value={deductions.deduct29} danger />}
                                {deductions.other > 0 && <Row label="کسور متفرقه" value={deductions.other} danger />}

                                <div className="border-t-2 border-red-500 mt-2 pt-2 flex justify-between font-bold text-red-700">
                                    <span>جمع کسورات</span>
                                    <span>{formatNumber(deductions.total)} ریال</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* ===== خالص پرداختی ===== */}
                    <div className="mt-4 p-4 bg-gradient-to-l from-green-600 to-green-800 text-white rounded-lg flex justify-between items-center">
                        <span className="text-lg font-bold">خالص پرداختی</span>
                        <span className="text-2xl font-bold">{formatNumber(netPay)} ریال</span>
                    </div>

                    {/* ===== اموال در اختیار ===== */}
                    {assets.length > 0 && (
                        <div className="mt-4 border rounded-lg overflow-hidden">
                            <div className="bg-blue-600 text-white p-2 font-bold text-sm flex items-center gap-2">
                                <Package className="w-4 h-4" />
                                اموال در اختیار ({assets.length} مورد)
                            </div>
                            <div className="p-2">
                                <div className="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                                    {assets.map((asset, idx) => (
                                        <div key={idx} className="p-2 bg-gray-50 rounded flex justify-between">
                                            <span>{asset.asset?.name || "کالا"}</span>
                                            <span className="text-gray-500">×{asset.assigned_count}</span>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-2 text-xs text-gray-500 text-left">
                                    مجموع ارزش: {formatNumber(assets.reduce((sum, a) => sum + (Number(a.total_price) || 0), 0))} ریال
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ===== توضیحات ===== */}
                    {data.description && (
                        <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <p className="text-sm">
                                <span className="font-bold">توضیحات: </span>
                                {data.description}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ============================================================
// ===== کامپوننت Row =====
// ============================================================
function Row({ label, value, danger = false }) {
    return (
        <div className="flex justify-between py-1 border-b border-gray-100">
            <span className={danger ? "text-red-700" : "text-gray-700"}>{label}</span>
            <span className={`font-medium ${danger ? "text-red-700" : "text-green-700"}`}>
                {formatNumber(value)}
            </span>
        </div>
    );
}