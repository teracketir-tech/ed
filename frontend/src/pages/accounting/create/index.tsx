import Button from "@/components/shared/Button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { useFormik } from "formik";
import validationSchema from "./validationSchema";
import { api } from "@/lib/axios";
import { navigateAfterSave } from "@/lib/navigation";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { useNavigate, useParams } from "react-router-dom";
import Select from "@/components/shared/inputs/Select";
import DateInput from "@/components/shared/inputs/Date";
import Textarea from "@/components/shared/inputs/Textarea";
import uuid from "uuid-random";
import CompanySelect from "@/components/shared/CompanySelect";
import RowControll from "@/components/pages/accounting/create/RowControll";
import ItemTable from "@/components/pages/accounting/create/ItemTable";
import { useAuth } from "@/context/AuthContext";
import Input from "@/components/shared/inputs";
import { type UploadFile } from "antd";
import FilesUpload from "@/components/pages/accounting/FilesUpload";
import { appendFormValue, collectFiles, keptImageUrls } from "@/components/pages/accounting/uploadHelpers";
import { documentFileList, documentRow, documentValues } from "@/components/pages/accounting/editHelpers";

export default function CreateAccounting({ editMode = 0 }: { editMode?: number }) {
    const { auth } = useAuth();
    const { id } = useParams();
    const navigate = useNavigate();
    const [banks, setBanks] = useState([]);
    const [products, setProducts] = useState([]);
    const [items, setItems] = useState<any>([]);
    const [projects, setProjects] = useState([]);
    const [loading, setLoading] = useState(false);
    const [materials, setMaterials] = useState([]);
    const [contacts, setContacts] = useState([]);
    const [fileList, setFileList] = useState<UploadFile[]>([]);

    // the hydrate sets company_id from the document; its rows must survive it
    const hydratedCompany = useRef<string | null>(null);

    const addRows = (rows = 5) => {
        const array = [];

        for (let i = 0; i < rows; i++) {
            array.push({ id: uuid(), accounting_data_id: "", detail_id: "", description: "", debit: "", credit: "", details: [] });
        }

        return array;
    };

    const formik = useFormik({
        initialValues: {
            company_id: auth?.company_id || "",
            date: "",
            project_id: "",
            status: "1",
            description: "",
            pay_accounting_item_id: "",
        },
        onSubmit,
        validationSchema,
        validateOnChange: true,
        enableReinitialize: true,
        validateOnMount: true,
    });

    const fetchProjects = async () => {
        const res = await api(`project?per-page=1000`);

        if (res?.data) setProjects(res?.data?.map((d: any) => ({ label: d.name, value: d.id.toString(), company_id: d.company_id })));
    };

    const fetchContacts = async (q?: string | number | null, returnData?: boolean) => {
        const res = await api(`contact?company_id=${formik.values.company_id}&per-page=5000${q ? `&q=${q}` : ""}`);

        if (res?.data) {
            const newContacts = res?.data?.map((d: any) => ({ label: `${d.id}- ${d.alias}`, value: d.id.toString(), company_id: d.company_id }));

            setContacts(newContacts);

            if (returnData) return newContacts;
        }
    };

    const fetchBanks = async () => {
        const res = await api(`bank?per-page=1000`);

        if (res?.data)
            setBanks(res?.data?.map((d: any) => ({ label: `${d.id}- ${d?.name || d?.bankName?.name}`, value: d.id.toString(), company_id: d.company_id })));
    };

    const fetchProducts = async () => {
        const res = await api(`product?per-page=1000`);

        if (res?.data)
            setProducts(
                res?.data?.map((d: any) => ({
                    label: `${d.id}- ${d?.name}`,
                    value: d.id.toString(),
                    accounting_data_id: d.accounting_data_id,
                    company_id: d.company_id,
                })),
            );
    };

    const fetchMaterials = async () => {
        const res = await api(`material?material_type=1&per-page=1000`);

        if (res?.data) setMaterials(res?.data?.map((d: any) => ({ label: `${d.id}- ${d?.name}`, value: d.id.toString(), company_id: d.company_id })));
    };

    useEffect(() => {
        fetchBanks();
        fetchProducts();
        fetchProjects();
        fetchContacts();
        fetchMaterials();
        setItems(addRows());
    }, []);

    const fetchDocument = async () => {
        const res = await api(`accounting/${id}`, "GET");

        if (!res?.success) return;

        const document = res.data;

        hydratedCompany.current = String(document?.company_id || "");
        formik.setValues(documentValues(document, auth?.company_id));

        const rows = (document?.items || []).map((row: any) => documentRow(row));

        setItems(rows.length ? rows : addRows());
        setFileList(documentFileList(document));
    };

    useEffect(() => {
        if (editMode && id) fetchDocument();
    }, [editMode, id]);

    useEffect(() => {
        // an edit re-points the company from the document, not by the user
        if (hydratedCompany.current !== null && hydratedCompany.current === String(formik.values.company_id)) {
            hydratedCompany.current = null;
            return;
        }

        fetchContacts();

        setItems((prev: any) =>
            prev.map((p: any) => {
                formik.setFieldValue(`accounting_data_id_${p.id}`, "");

                return {
                    ...p,
                    [`accounting_data_id_${p.id}`]: "",
                    accounting_data_id: "",
                    details: [],
                    detail_id: "",
                    detail_name: "",
                };
            }),
        );
    }, [formik.values.company_id]);

    function onSubmit(data: object) {
        setLoading(true);

        const formData = new FormData();

        Object.entries(data).forEach(([key, value]) => appendFormValue(formData, key, value));
        appendFormValue(
            formData,
            "items",
            filledItems.map((item: any) => ({
                // a stored row sends its id so the edit rewrites that very row
                ...(typeof item.id === "number" ? { id: item.id } : {}),
                accounting_data_id: item.accounting_data_id,
                detail_id: item.detail_id,
                description: item.description,
                debit: item.debit,
                credit: item.credit,
            })),
        );

        // an edit lists the stored urls that stay; anything unlisted is dropped
        if (editMode) appendFormValue(formData, "kept_images", JSON.stringify(keptImageUrls(fileList)));

        collectFiles(fileList).forEach((file) => formData.append("images[]", file));

        api(editMode ? `accounting/${id}` : "accounting", editMode ? "PATCH" : "POST", formData)
            .then((res) => {
                if (res?.success) {
                    toast.success(editMode ? "سند با موفقیت ویرایش شد." : "سند با موفقیت ثبت شد.");
                    navigateAfterSave(navigate, !!editMode, "/accounting");

                    //
                } else {
                    toast.error(res?.message || "خطایی رخ داده است");
                }
            })
            .finally(() => {
                setLoading(false);
            });
    }

    const inBalance = () => {
        const totalDebit = items?.reduce((acc: number, item: any) => acc + Number(item?.debit || 0), 0);
        const totalCredit = items?.reduce((acc: number, item: any) => acc + Number(item?.credit || 0), 0);

        return Math.abs(totalDebit - totalCredit);
    };

    const filledItems = items.filter((it: any) => it?.accounting_data_id || it?.debit || it?.credit);

    const itemsIsValid = () => {
        if (!filledItems?.length) return false;

        return filledItems.every((item: any) => item.accounting_data_id && (!item?.details?.length || item.detail_id) && (item.debit || item.credit));
    };

    const isValid = inBalance() == 0 && itemsIsValid();

    const filteredContacts = contacts.filter((c: any) => !formik.values.company_id || c.company_id == formik.values.company_id);

    const filteredBanks = banks.filter((b: any) => !formik.values.company_id || b.company_id == formik.values.company_id);

    const filteredMaterials = materials.filter((m: any) => !formik.values.company_id || m.company_id == formik.values.company_id);

    const filteredProducts = products.filter((p: any) => !formik.values.company_id || p.company_id == formik.values.company_id);

    return (
        <Card className="w-full">
            <CardHeader className="mb-5">
                <CardTitle>{editMode ? "ویرایش سند" : "ثبت سند جدید"}</CardTitle>
                <CardDescription>تکمیل فیلدهایی که با * مشخص شده اند الزامی می باشد</CardDescription>
            </CardHeader>

            <CardContent>
                <form className="w-full flex flex-col gap-5 mb-5">
                    <div className="w-full grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 mb-5">
                        <CompanySelect formik={formik} />
                        <DateInput name="date" title="تاریخ" formik={formik} onChange={(date: any) => formik.setFieldValue("date", date)} />
                        <Select
                            name="project_id"
                            title="پروژه"
                            formik={formik}
                            required={false}
                            options={projects.filter((p: any) => !formik.values.company_id || p.company_id == formik.values.company_id)}
                        />
                        <Select
                            name="status"
                            title="وضعیت"
                            formik={formik}
                            disabled={!!editMode}
                            options={[
                                { label: "پیش نویس", value: "1" },
                                { label: "تایید شده", value: "2" },
                            ]}
                        />

                        {/* an edit never re-points the settlement item or approves */}
                        {!editMode && <Input name="pay_accounting_item_id" title="شناسه آیتم (پرداختنی / دریافتنی) اصلی" formik={formik} required={false} />}

                        <div className="col-span-1 md:col-span-2 lg:col-span-3 xl:col-span-4">
                            <Textarea name="description" title="شرح" formik={formik} />
                        </div>

                        <div className="col-span-1 md:col-span-2 lg:col-span-3 xl:col-span-4">
                            <FilesUpload fileList={fileList} setFileList={setFileList} />
                        </div>
                    </div>

                    <div className="flex flex-col gap-5 col-span-3">
                        <RowControll inBalance={inBalance} addRows={addRows} items={items} setItems={setItems} />
                        <ItemTable
                            contacts={filteredContacts}
                            products={filteredProducts}
                            materials={filteredMaterials}
                            banks={filteredBanks}
                            items={items}
                            setItems={setItems}
                            formik={formik}
                        />
                    </div>
                </form>
            </CardContent>

            <CardFooter>
                <Button onClick={formik.handleSubmit} className="mt-5" isLoading={loading} disabled={!formik.isValid || !isValid}>
                    {editMode ? "ویرایش" : "ثبت"}
                </Button>
            </CardFooter>
        </Card>
    );
}
