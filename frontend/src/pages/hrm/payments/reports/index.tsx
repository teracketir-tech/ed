// src/pages/hrm/reports/index.tsx

import { useState, useEffect } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import Button from "@/components/shared/Button";
import Input from "@/components/shared/inputs";
import Select from "@/components/shared/inputs/Select";
import Loading from "@/components/shared/Loading";
import Empty from "@/components/shared/Empty";
import { toast } from "sonner";
import { Search, FileSpreadsheet, Download } from "lucide-react";
import { api } from "@/lib/axios";

// ============== توابع کمکی ==============
const yearsList = () => {
    const currentYear = 1405;
    const arr = [];
    for (let year = currentYear - 15; year <= currentYear + 15; year++) {
        arr.push(year);
    }
    return arr;
};

const MONTH_OPTIONS = [
    { value: "1", label: "فروردین" },
    { value: "2", label: "اردیبهشت" },
    { value: "3", label: "خرداد" },
    { value: "4", label: "تیر" },
    { value: "5", label: "مرداد" },
    { value: "6", label: "شهریور" },
    { value: "7", label: "مهر" },
    { value: "8", label: "آبان" },
    { value: "9", label: "آذر" },
    { value: "10", label: "دی" },
    { value: "11", label: "بهمن" },
    { value: "12", label: "اسفند" },
];

const CONTRACT_TYPE_OPTIONS = [
    { value: "1", label: "موقت" },
    { value: "2", label: "ساعتی" },
    { value: "3", label: "پیمانکاری" },
];

const ENDPOINTS = {
    tab_rpt1: "hrm-reports/salary-penalty",
    tab_rpt2: "hrm-reports/payment-description",
    tab_rpt3: "hrm-reports/deduction-description",
};

export default function Reports() {
    const [activeTab, setActiveTab] = useState("tab_rpt1");
    const [loading, setLoading] = useState(false);
    const [users, setUsers] = useState([]);
    const [workgroups, setWorkgroups] = useState([]);
    const [data, setData] = useState([]);
    const [showResult, setShowResult] = useState(false);

    const yearOptions = yearsList().map((item) => ({
        value: String(item),
        label: String(item),
    }));

    const [filtersRpt1, setFiltersRpt1] = useState({
        user_id: "",
        personnel_code: "",
        national_code: "",
        workgroup_id: "",
    });

    const [filtersRpt2, setFiltersRpt2] = useState({
        user_id: "",
        personnel_code: "",
        national_code: "",
        workgroup_id: "",
        month: "",
        year: "",
        contract_type: "",
    });

    const [filtersRpt3, setFiltersRpt3] = useState({
        user_id: "",
        personnel_code: "",
        national_code: "",
        workgroup_id: "",
        month: "",
        year: "",
        contract_type: "",
    });

    // ============== دریافت کاربران ==============
    const fetchUsers = async () => {
        try {
            const res = await api("user?per-page=100&is_select=true", "GET");
            if (res?.data) setUsers(res.data);
        } catch (error) {
            console.error("Error fetching users:", error);
            toast.error("خطا در دریافت کاربران");
        }
    };

    // ============== دریافت گروه‌های کاری ==============
    const fetchWorkgroups = async () => {
        try {
            const res = await api("hrm-workgroup/items", "GET");
            if (res?.success && Array.isArray(res.data)) {
                setWorkgroups(
                    res.data.map((item) => ({
                        value: String(item.id),
                        label: item.name,
                    }))
                );
            }
        } catch (error) {
            console.error("Error fetching workgroups:", error);
        }
    };

    useEffect(() => {
        fetchUsers();
        fetchWorkgroups();
    }, []);

    // ============== ساخت query ==============
    const buildQuery = (filters) => {
        const params = new URLSearchParams();
        if (filters.user_id) params.set("user_id", filters.user_id);
        if (filters.personnel_code) params.set("personnel_code", filters.personnel_code);
        if (filters.national_code) params.set("national_code", filters.national_code);
        if (filters.workgroup_id) params.set("workgroup_id", filters.workgroup_id);
        if (filters.month) params.set("month", filters.month);
        if (filters.year) params.set("year", filters.year);
        if (filters.contract_type) params.set("contract_type", filters.contract_type);
        return params.toString();
    };

    // ============== دریافت داده ==============
    const fetchData = async (tab, filters) => {
        setLoading(true);
        setShowResult(false);
        setData([]);

        try {
            const query = buildQuery(filters);
            const endpoint = ENDPOINTS[tab];
            const res = await api(`${endpoint}?${query}`, "GET");

            let rows = [];
            if (Array.isArray(res)) rows = res;
            else if (Array.isArray(res?.data)) rows = res.data;
            else if (Array.isArray(res?.data?.data)) rows = res.data.data;

            setData(rows);
            setShowResult(true);

            if (rows.length === 0) toast.info("هیچ داده‌ای یافت نشد");
            else toast.success(`${rows.length} ردیف دریافت شد`);
        } catch (error) {
            console.error("Error fetching report:", error);
            toast.error("خطا در دریافت گزارش");
            setData([]);
            setShowResult(true);
        } finally {
            setLoading(false);
        }
    };

    // ============== گزینه‌ها ==============
    const userOptions = users.map((item) => ({
        value: String(item.id),
        label: `${item.first_name || ""} ${item.last_name || ""} (${item.personnel_code || item.phone_number || ""})`,
    }));

    const workgroupOptions = workgroups;

    // ============== ⭐ updateFilter (به جای handleFilterChange) ==============
    const updateFilter = (tab, field, value) => {
        if (tab === "tab_rpt1") setFiltersRpt1((p) => ({ ...p, [field]: value }));
        else if (tab === "tab_rpt2") setFiltersRpt2((p) => ({ ...p, [field]: value }));
        else if (tab === "tab_rpt3") setFiltersRpt3((p) => ({ ...p, [field]: value }));
    };

    const handleSelectChange = (tab, name, selectedOption) => {
        const value = selectedOption?.value || selectedOption || "";
        updateFilter(tab, name, value);
    };

    // ============== جستجو ==============
    const handleSearch = (tab) => {
        if (tab === "tab_rpt1") {
            fetchData("tab_rpt1", filtersRpt1);
        } else if (tab === "tab_rpt2") {
            if (!filtersRpt2.month || !filtersRpt2.year) {
                toast.warning("لطفاً ماه و سال را انتخاب کنید");
                return;
            }
            fetchData("tab_rpt2", filtersRpt2);
        } else if (tab === "tab_rpt3") {
            if (!filtersRpt3.month || !filtersRpt3.year) {
                toast.warning("لطفاً ماه و سال را انتخاب کنید");
                return;
            }
            fetchData("tab_rpt3", filtersRpt3);
        }
    };

    // ============== خروجی اکسل ==============
    const handleExportExcel = (tab) => {
        toast.info(
            `در حال خروجی اکسل از تب ${
                tab === "tab_rpt1" ? "دستمزد و جریمه" : tab === "tab_rpt2" ? "شرح پرداخت" : "شرح کسور"
            }`
        );
    };

    // ============== helpers ==============
    const formatNumber = (num) => {
        if (num === null || num === undefined || num === "") return "۰";
        return new Intl.NumberFormat("fa-IR").format(num);
    };

    const getMonthName = (month) => {
        const months = {
            1: "فروردین", 2: "اردیبهشت", 3: "خرداد",
            4: "تیر", 5: "مرداد", 6: "شهریور",
            7: "مهر", 8: "آبان", 9: "آذر",
            10: "دی", 11: "بهمن", 12: "اسفند",
        };
        return months[month] || "نامشخص";
    };

    // ============== ستون‌ها ==============
    const getColumns = (tab) => {
        if (tab === "tab_rpt1") {
            return [
                "ردیف", "کد پرسنلی", "نام پرسنل", "گروه کاری",
                "حقوق پایه", "ساعت اضافه کار", "مبلغ اضافه کار",
                "جمعه کاری", "مبلغ جمعه کاری", "روز غیبت", "جریمه غیبت",
                "پاداش", "جریمه", "قابل پرداخت",
            ];
        }
        if (tab === "tab_rpt2") {
            return [
                "ردیف", "کد پرسنلی", "نام پرسنل", "گروه کاری",
                "ماه", "سال", "حقوق پایه", "حق مسکن", "بن خواربار",
                "حق اولاد", "ایاب و ذهاب", "حق مسئولیت", "اضافه کار",
                "پاداش", "مجموع پرداختی",
            ];
        }
        if (tab === "tab_rpt3") {
            return [
                "ردیف", "کد پرسنلی", "نام پرسنل", "گروه کاری",
                "ماه", "سال", "بیمه سهم کارمند", "بیمه تکمیلی",
                "مالیات", "قسط وام", "مساعده", "جریمه غیبت",
                "سایر کسور", "مجموع کسور",
            ];
        }
        return [];
    };

    // ============== جدول ==============
    const renderTable = (tab) => {
        if (loading) {
            return (
                <div className="flex justify-center py-10">
                    <Loading />
                </div>
            );
        }

        if (!showResult) {
            return (
                <div className="flex justify-center py-10 text-gray-400">
                    برای مشاهده نتایج، دکمه جستجو را بزنید
                </div>
            );
        }

        const items = Array.isArray(data) ? data : [];

        if (items.length === 0) {
            return <Empty message="هیچ داده‌ای یافت نشد" />;
        }

        const columns = getColumns(tab);

        return (
            <div className="w-full overflow-x-auto">
                <table className="w-full border-collapse text-xs min-w-[1200px]">
                    <thead className="bg-gray-100">
                        <tr>
                            {columns.map((col, i) => (
                                <th key={i} className="px-2 py-2 text-center border-b whitespace-nowrap">
                                    {col}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item, index) => {
                            const fullName = `${item.user?.first_name || ""} ${item.user?.last_name || ""}`.trim() || "-";
                            const personnelCode = item.user?.personnel_code || "-";

                            if (tab === "tab_rpt1") {
                                return (
                                    <tr key={item.id ?? index} className="hover:bg-gray-50 border-b">
                                        <td className="px-2 py-2 text-center">{index + 1}</td>
                                        <td className="px-2 py-2 text-center">{personnelCode}</td>
                                        <td className="px-2 py-2 text-center">{fullName}</td>
                                        <td className="px-2 py-2 text-center">{item.workgroup_name || "-"}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.base_salary)}</td>
                                        <td className="px-2 py-2 text-center">{item.overtime_hours ?? "-"}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.overtime_pay)}</td>
                                        <td className="px-2 py-2 text-center">{item.friday_work ?? "-"}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.friday_pay)}</td>
                                        <td className="px-2 py-2 text-center">{item.absence_days ?? "-"}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.absence_penalty)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.bonus)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.penalty)}</td>
                                        <td className="px-2 py-2 text-center font-bold text-green-600">
                                            {formatNumber(item.net_payable)}
                                        </td>
                                    </tr>
                                );
                            }

                            if (tab === "tab_rpt2") {
                                return (
                                    <tr key={item.id ?? index} className="hover:bg-gray-50 border-b">
                                        <td className="px-2 py-2 text-center">{index + 1}</td>
                                        <td className="px-2 py-2 text-center">{personnelCode}</td>
                                        <td className="px-2 py-2 text-center">{fullName}</td>
                                        <td className="px-2 py-2 text-center">{item.workgroup_name || "-"}</td>
                                        <td className="px-2 py-2 text-center">{getMonthName(item.month)}</td>
                                        <td className="px-2 py-2 text-center">{item.year ?? "-"}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.base_salary)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.housing_allowance)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.food_allowance)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.child_allowance)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.transportation)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.responsibility)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.overtime)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.bonus)}</td>
                                        <td className="px-2 py-2 text-center font-bold text-green-600">
                                            {formatNumber(item.total_payment)}
                                        </td>
                                    </tr>
                                );
                            }

                            if (tab === "tab_rpt3") {
                                return (
                                    <tr key={item.id ?? index} className="hover:bg-gray-50 border-b">
                                        <td className="px-2 py-2 text-center">{index + 1}</td>
                                        <td className="px-2 py-2 text-center">{personnelCode}</td>
                                        <td className="px-2 py-2 text-center">{fullName}</td>
                                        <td className="px-2 py-2 text-center">{item.workgroup_name || "-"}</td>
                                        <td className="px-2 py-2 text-center">{getMonthName(item.month)}</td>
                                        <td className="px-2 py-2 text-center">{item.year ?? "-"}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.insurance)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.supplementary_insurance)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.tax)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.loan_installment)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.advance)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.absence_penalty)}</td>
                                        <td className="px-2 py-2 text-center">{formatNumber(item.other_deductions)}</td>
                                        <td className="px-2 py-2 text-center font-bold text-red-600">
                                            {formatNumber(item.total_deductions)}
                                        </td>
                                    </tr>
                                );
                            }

                            return null;
                        })}
                    </tbody>
                </table>
            </div>
        );
    };

    // ============== فیلترها ==============
    const renderFilters = (tab) => {
        const currentFilters =
            tab === "tab_rpt1" ? filtersRpt1 : tab === "tab_rpt2" ? filtersRpt2 : filtersRpt3;

        return (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="flex flex-col">
                    <label className="text-sm font-medium text-gray-700 mb-1">نام پرسنل</label>
                    <Select
                        name="user_id"
                        value={userOptions.find((opt) => opt.value === currentFilters.user_id) || null}
                        onChange={(opt) => handleSelectChange(tab, "user_id", opt)}
                        options={userOptions}
                        placeholder="همه پرسنل"
                        isClearable
                    />
                </div>

                {/* ⭐ onChange با value مستقیم */}
                <Input
                    type="text"
                    name="personnel_code"
                    value={currentFilters.personnel_code}
                    onChange={(value) => updateFilter(tab, "personnel_code", value)}
                    title="کد پرسنلی"
                    placeholder="کد پرسنلی"
                />

                <Input
                    type="text"
                    name="national_code"
                    value={currentFilters.national_code}
                    onChange={(value) => updateFilter(tab, "national_code", value)}
                    title="کد ملی"
                    placeholder="کد ملی"
                />

                <div className="flex flex-col">
                    <label className="text-sm font-medium text-gray-700 mb-1">گروه کاری</label>
                    <Select
                        name="workgroup_id"
                        value={workgroupOptions.find((opt) => opt.value === currentFilters.workgroup_id) || null}
                        onChange={(opt) => handleSelectChange(tab, "workgroup_id", opt)}
                        options={workgroupOptions}
                        placeholder="همه گروه‌ها"
                        isClearable
                    />
                </div>

                {(tab === "tab_rpt2" || tab === "tab_rpt3") && (
                    <>
                        <div className="flex flex-col">
                            <label className="text-sm font-medium text-gray-700 mb-1">ماه</label>
                            <Select
                                name="month"
                                value={MONTH_OPTIONS.find((opt) => opt.value === currentFilters.month) || null}
                                onChange={(opt) => handleSelectChange(tab, "month", opt)}
                                options={MONTH_OPTIONS}
                                placeholder="انتخاب ماه"
                                isClearable
                            />
                        </div>

                        <div className="flex flex-col">
                            <label className="text-sm font-medium text-gray-700 mb-1">سال</label>
                            <Select
                                name="year"
                                value={yearOptions.find((opt) => opt.value === currentFilters.year) || null}
                                onChange={(opt) => handleSelectChange(tab, "year", opt)}
                                options={yearOptions}
                                placeholder="انتخاب سال"
                                isClearable
                            />
                        </div>

                        <div className="flex flex-col">
                            <label className="text-sm font-medium text-gray-700 mb-1">نوع قرارداد</label>
                            <Select
                                name="contract_type"
                                value={CONTRACT_TYPE_OPTIONS.find((opt) => opt.value === currentFilters.contract_type) || null}
                                onChange={(opt) => handleSelectChange(tab, "contract_type", opt)}
                                options={CONTRACT_TYPE_OPTIONS}
                                placeholder="همه انواع"
                                isClearable
                            />
                        </div>
                    </>
                )}

                <div className="flex gap-2 mt-6 col-span-full">
                    <Button onClick={() => handleSearch(tab)} className="flex items-center gap-2">
                        <Search className="w-4 h-4" />
                        جستجو
                    </Button>
                    <Button variant="secondary" onClick={() => handleExportExcel(tab)} className="flex items-center gap-2">
                        <Download className="w-4 h-4" />
                        خروجی اکسل
                    </Button>
                </div>
            </div>
        );
    };

    // ============== رندر ==============
    return (
        <div className="w-full space-y-4">
            <div className="flex justify-between items-center">
                <h2 className="text-xl font-semibold flex items-center gap-2">
                    <FileSpreadsheet className="w-6 h-6" />
                    گزارشات
                </h2>
            </div>

            <Card className="w-full">
                <CardHeader className="pb-3">
                    <div className="flex gap-2 border-b pb-2 flex-wrap">
                        <button
                            className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                                activeTab === "tab_rpt1"
                                    ? "bg-blue-500 text-white"
                                    : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                            }`}
                            onClick={() => {
                                setActiveTab("tab_rpt1");
                                setData([]);
                                setShowResult(false);
                            }}
                        >
                            دستمزد و جریمه کارکرد
                        </button>
                        <button
                            className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                                activeTab === "tab_rpt2"
                                    ? "bg-blue-500 text-white"
                                    : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                            }`}
                            onClick={() => {
                                setActiveTab("tab_rpt2");
                                setData([]);
                                setShowResult(false);
                            }}
                        >
                            شرح پرداخت
                        </button>
                        <button
                            className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                                activeTab === "tab_rpt3"
                                    ? "bg-blue-500 text-white"
                                    : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                            }`}
                            onClick={() => {
                                setActiveTab("tab_rpt3");
                                setData([]);
                                setShowResult(false);
                            }}
                        >
                            شرح کسور
                        </button>
                    </div>
                </CardHeader>

                <CardContent>
                    <div className="mb-6 p-4 bg-gray-50 rounded-lg">{renderFilters(activeTab)}</div>

                    <div className="text-right text-xs text-gray-500 mb-2">
                        * مرتب‌سازی بر اساس کد پرسنلی می‌باشد
                    </div>

                    {renderTable(activeTab)}
                </CardContent>
            </Card>
        </div>
    );
}