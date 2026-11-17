import CompanySelect from "@/components/shared/CompanySelect";
import Filters from "@/components/shared/Filters";
import Input from "@/components/shared/inputs";
import Select from "@/components/shared/inputs/Select";
import { useAuth } from "@/context/AuthContext";
import { api } from "@/lib/axios";
import { useFormik } from "formik";
import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";

interface GroupOption {
    id: number;
    name: string;
}

// PHP only hands a query key back as a list when it carries the empty
// brackets, so every picked group travels as `group_id[]` and the whole
// selection is dropped before it is rebuilt - otherwise an emptied pick
// would leave the old ids behind.
function writeGroups(params: URLSearchParams, ids: string[]) {
    params.delete("group_id");
    params.delete("group_id[]");

    ids.forEach((id) => params.append("group_id[]", id));
}

export default function FilterForm({ loading }: { loading: boolean }) {
    const { auth } = useAuth();
    const [open, setOpen] = useState(false);
    const [groups, setGroups] = useState<GroupOption[]>([]);
    const [searchParams, setSearchParams] = useSearchParams();

    const formik = useFormik({
        initialValues: {
            company_id: auth?.company_id || "",
            id: "",
            first_name: "",
            last_name: "",
            phone_number: "",
            email: "",
            group_id: [] as string[],
            active: "",
            fingerprint: "",
        },
        onSubmit,
        validationSchema: false,
    });

    useEffect(() => {
        api("group?per-page=1000", "GET").then((res) => setGroups(res?.data ?? []));
    }, []);

    function onSubmit(data: Record<string, string | string[]>) {
        const params = new URLSearchParams(searchParams.toString());

        Object.entries(data).forEach(([key, value]) => {
            if (key === "group_id") {
                writeGroups(params, (value as string[]) ?? []);
                return;
            }

            if (value) params.set(key, value.toString());
            else params.delete(key);
        });

        setSearchParams(params);
    }

    useEffect(() => {
        if (open) {
            Object.entries(formik.values).forEach(([key]) => {
                if (key === "group_id") {
                    formik.setFieldValue(key, searchParams.getAll("group_id[]"));
                    return;
                }

                if (searchParams.get(key)) formik.setFieldValue(key, searchParams.get(key)?.toString());
            });
        }
    }, [open]);

    return (
        <Filters open={open} setOpen={setOpen} loading={loading} formik={formik}>
            <form className="w-full grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-5">
                <CompanySelect formik={formik} />
                <Input name="id" title="شناسه" formik={formik} required={false} />
                <Input name="first_name" title="نام" formik={formik} required={false} />
                <Input name="last_name" title="نام خانوادگی" formik={formik} required={false} />
                <Input name="phone_number" title="موبایل" formik={formik} required={false} />
                <Input name="email" title="ایمیل" formik={formik} required={false} />
                <Select
                    name="group_id"
                    title="گروه"
                    formik={formik}
                    required={false}
                    isMulti
                    options={groups.map((group: { id: number; name: string }) => ({ value: String(group.id), label: group.name }))}
                />
                <Select
                    name="active"
                    title="وضعیت"
                    formik={formik}
                    required={false}
                    options={[
                        { value: "0", label: "آفلاین" },
                        { value: "1", label: "آنلاین" },
                    ]}
                />
                <Select
                    name="fingerprint"
                    title="اثر انگشت"
                    formik={formik}
                    required={false}
                    options={[
                        { value: "1", label: "دارد" },
                        { value: "0", label: "ندارد" },
                    ]}
                />
                <Select
                    name="device_status"
                    title="وضعیت ثبت در دستگاه"
                    formik={formik}
                    required={false}
                    options={[
                        { value: "1", label: "در انتظار ثبت" },
                        { value: "2", label: "ثبت شده" },
                        { value: "3", label: "در انتظار حذف" },
                        { value: "4", label: "عدم ثبت" },
                    ]}
                />
            </form>
        </Filters>
    );
}
