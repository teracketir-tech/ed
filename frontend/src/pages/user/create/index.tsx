import Button from "@/components/shared/Button";
import Input from "@/components/shared/inputs";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { useFormik } from "formik";
import validationSchema from "./validationSchema";
import { api } from "@/lib/axios";
import { navigateAfterSave } from "@/lib/navigation";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { useNavigate, useParams } from "react-router-dom";
import Select from "@/components/shared/inputs/Select";
import CompanySelect from "@/components/shared/CompanySelect";
import { useAuth } from "@/context/AuthContext";

export default function CreateUser({ editMode = 0 }: { editMode?: number }) {
    const { auth } = useAuth();
    const { id } = useParams();
    const navigate = useNavigate();
    const [groups, setGroups] = useState<any>([]);
    const [loading, setLoading] = useState(false);
    const [initLoading, setInitLoading] = useState(false);

    const fetchGroups = async () => {
        const res = await api("group?per-page=1000", "GET");
        setGroups(res?.data?.map((g: any) => ({ label: g.name, value: g.id.toString(), company_id: g.company_id })));
    };

    const formik = useFormik({
        initialValues: {
            company_id: auth?.company_id || "",
            first_name: "",
            last_name: "",
            phone_number: "",
            password_hash: "",
            group_id: "",
        },
        onSubmit,
        validationSchema,
        validateOnChange: true,
        enableReinitialize: true,
        validateOnMount: true,
    });

    // The dash panel lets a company-1 user carry several groups and stores
    // them as a JSON array, so this form has to hold and show them as a list.
    const isMultiGroup = String(formik.values.company_id ?? "") === "1";

    const readGroupId = (raw: unknown): string[] => {
        if (Array.isArray(raw)) return raw.map(String);

        try {
            const decoded = JSON.parse(String(raw));

            if (Array.isArray(decoded)) return decoded.map(String);
        } catch {
            // not JSON - one id stored the plain way
        }

        return raw ? [String(raw)] : [];
    };

    const fetchUser = async () => {
        setLoading(true);
        setInitLoading(true);
        const res = await api(`user/${id}`, "GET");

        if (res?.success) {
            const data = { ...res?.data };
            const ids = readGroupId(data.group_id);

            // every row stores a list, but only company 1 may hold several ids;
            // the single-select has to receive one bare id to be able to match it
            data.group_id = String(data.company_id ?? "") === "1" ? ids : (ids[0] ?? "");

            formik.setValues(data);
        }

        setLoading(false);
    };

    useEffect(() => {
        fetchGroups();

        if (editMode && id) fetchUser();
    }, [id, editMode]);

    useEffect(() => {
        if (initLoading) setInitLoading(false);
        else formik.setFieldValue("group_id", isMultiGroup ? [] : "");
    }, [formik.values.company_id]);

    function onSubmit(values: Record<string, unknown>) {
        if (!editMode && !formik.values.password_hash) {
            toast.error("لطفا کلمه عبور را وارد کنید");
            return;
        }

        setLoading(true);

        // only the dash panel (company 1) stores a list; the hq and seal panels
        // hold exactly one group as a bare id, so the shape written here has to
        // follow the company too - otherwise a plain row turns into a list
        const groupId = values.group_id;
        const groupIds = Array.isArray(groupId) ? groupId : [groupId].filter(Boolean);

        const payload = {
            ...values,
            group_id: String(values.company_id ?? "") === "1" ? groupIds : (groupIds[0] ?? ""),
        };

        api(editMode ? `user/${id}` : "user", editMode ? "PATCH" : "POST", payload)
            .then((res) => {
                if (res?.success) {
                    toast.success("کاربر با موفقیت ثبت شد.");
                    navigateAfterSave(navigate, !!editMode, "/user");
                }
            })
            .finally(() => {
                setLoading(false);
            });
    }

    const filteredGroups = groups.filter((group: any) => group.company_id == (formik.values?.company_id || null));

    return (
        <Card className="w-full">
            <CardHeader className="mb-5">
                <CardTitle>{editMode ? "ویرایش کاربر" : "ثبت کاربر جدید"}</CardTitle>
                <CardDescription>تکمیل فیلدهایی که با * مشخص شده اند الزامی می باشد</CardDescription>
            </CardHeader>

            <CardContent>
                <form className="w-full grid grid-cols-3 gap-5 mb-5">
                    <CompanySelect formik={formik} />
                    <Input type="text" name="first_name" title="نام" formik={formik} autoComplete="off" />
                    <Input type="text" name="last_name" title="نام خانوادگی" formik={formik} autoComplete="off" />
                    <Input type="text" name="phone_number" title="شماره موبایل" formik={formik} autoComplete="off" />
                    <Select title="گروه" name="group_id" formik={formik} options={filteredGroups || []} isMulti={isMultiGroup} />
                    <Input type="password" name="password_hash" title="کلمه عبور" formik={formik} autoComplete="new-password" required={!editMode} />
                </form>
            </CardContent>

            <CardFooter>
                <Button onClick={formik.handleSubmit} className="mt-5" isLoading={loading} disabled={!formik.isValid}>
                    ثبت
                </Button>
            </CardFooter>
        </Card>
    );
}
