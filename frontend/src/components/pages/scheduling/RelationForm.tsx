import Button from "@/components/shared/Button";
import Input from "@/components/shared/inputs";
import Select from "@/components/shared/inputs/Select";
import ProjectSelect from "@/components/shared/ProjectSelect";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { useProjectTasks } from "@/hooks/use-project-tasks";
import { applyApiErrors } from "@/lib/apiErrors";
import { api } from "@/lib/axios";
import { navigateAfterSave } from "@/lib/navigation";
import { useFormik } from "formik";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { durationUnitOptions, relationTypeOptions, toTaskOptions } from "./options";
import relationSchema, { type RelationValues } from "./relationSchema";

interface Props {
    editMode?: number;
    /** پروژهٔ قفل‌شده — مودال «افزودن رابطه» پروژه را از ردیف تسک می‌آورد */
    lockedProjectId?: string | null;
    /** تسک مقدم قفل‌شده — همان ردیفی که دکمه روی آن بود */
    lockedPredecessorId?: string | null;
    /** به‌جای navigate: مودال بسته می‌شود و لیست تازه می‌شود */
    onSaved?: (res: any) => void;
    /** لخت: بدون کارت و تیتر — مودال خودش آن‌ها را دارد */
    bare?: boolean;
}

/**
 * فرمِ ثبت/ویرایش رابطه — هم صفحه‌های `/task-relation/create` و `edit/:id` و هم
 * مودال «افزودن رابطه» لیست تسک‌ها همین را می‌سازند. با `lockedProjectId` /
 * `lockedPredecessorId` پروژه و تسک مقدم از قبل انتخاب و غیرفعال می‌شوند.
 */
export default function RelationForm({ editMode = 0, lockedProjectId, lockedPredecessorId, onSaved, bare = false }: Props) {
    const { id } = useParams();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const [loading, setLoading] = useState(false);
    /** the project the form was filled from — a real switch is what resets it */
    const loadedProject = useRef<string | null>(null);
    const locked = !!lockedPredecessorId;

    const formik = useFormik<RelationValues>({
        initialValues: {
            project_id: lockedProjectId || searchParams.get("project_id") || "",
            predecessor_task_id: lockedPredecessorId || "",
            // one field for both pages: edit seeds it from the stored row,
            // create starts empty (feedback 3)
            successor_task_ids: [] as string[],
            relation_type: "FS",
            lag: "0",
            lag_unit: "1",
        },
        validationSchema: relationSchema,
        onSubmit,
        validateOnChange: true,
        enableReinitialize: true,
        validateOnMount: true,
    });

    const taskOptions = toTaskOptions(useProjectTasks(formik.values.project_id));

    // the predecessor cannot be its own successor, so it never offered there
    const successorOptions = taskOptions.filter((o) => o.value !== String(formik.values.predecessor_task_id));

    useEffect(() => {
        if (!editMode || !id) return;

        setLoading(true);
        api(`task-relation/${id}`, "GET")
            .then((res: any) => {
                if (!res?.success) return;

                const projectId = String(res.data.project_id ?? "");

                loadedProject.current = projectId;

                formik.setValues({
                    project_id: projectId,
                    predecessor_task_id: String(res.data.predecessor_task_id ?? ""),
                    // ایشوی ۶: سرور کلِ گروه را می‌دهد، نه فقط سطری که باز شد
                    successor_task_ids: Array.isArray(res.data.successor_task_ids)
                        ? res.data.successor_task_ids.map((v: any) => String(v))
                        : res.data.successor_task_id
                          ? [String(res.data.successor_task_id)]
                          : [],
                    relation_type: String(res.data.relation_type ?? "FS"),
                    lag: res.data.lag != null ? String(res.data.lag) : "0",
                    lag_unit: String(res.data.lag_unit ?? "1"),
                });
            })
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id, editMode]);

    // Tasks of the previous project have nothing to do with the new one. In edit
    // mode only a real switch by the user clears the fields - the form being
    // filled from the server must not wipe the selections it just loaded.
    useEffect(() => {
        if (locked) return;
        if (editMode && loadedProject.current === null) return;
        if (editMode && formik.values.project_id === loadedProject.current) return;

        formik.setFieldValue("predecessor_task_id", "");
        formik.setFieldValue("successor_task_ids", []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [formik.values.project_id, editMode, locked]);

    async function onSubmit(values: RelationValues) {
        setLoading(true);

        try {
            // both modes post the same successor list; the server rewrites the row
            // in edit mode and creates rows in create mode
            const res = editMode ? await api(`task-relation/${id}`, "PUT", values) : await api("task-relation", "POST", values);

            if (applyApiErrors(res, formik)) return;

            if (res?.success) {
                const created = Number(res?.created ?? 0);
                const skipped = Number(res?.skipped ?? 0);

                if (created > 1) toast.success(`${created} رابطه با موفقیت ثبت شد${skipped ? ` (${skipped} مورد تکراری نادیده گرفته شد)` : ""}.`);
                else if (!created && skipped) toast.info("این روابط قبلا ثبت شده بودند.");
                else toast.success(editMode ? "رابطه با موفقیت ویرایش شد." : "رابطه با موفقیت اضافه شد.");

                if (onSaved) onSaved(res);
                else navigateAfterSave(navigate, !!editMode, "/task-relation");
            }
        } finally {
            setLoading(false);
        }
    }

    /** the form minus its shell — the page wraps it in a card, the modal doesn't */
    const formBody = (
        // columns follow the box, not the window: the modal is narrower than the
        // page, so the same form is 2-up there and 3-up on the page
        <div className="@container w-full">
            <form className="w-full grid grid-cols-1 @md:grid-cols-2 @3xl:grid-cols-3 gap-5 mb-5">
                <ProjectSelect formik={formik} disabled={!!lockedProjectId} />
                <Select name="predecessor_task_id" title="تسک مقدم (پیش‌نیاز)" formik={formik} options={taskOptions} disabled={locked} />

                {/* feedback 3: multi on both pages — edit starts from the stored row */}
                <Select
                    name="successor_task_ids"
                    title="تسک وابسته (یک یا چند)"
                    formik={formik}
                    options={successorOptions}
                    isMulti
                    placeholder="انتخاب کنید (امکان انتخاب چند مورد)"
                />

                <Select name="relation_type" title="نوع رابطه" formik={formik} options={relationTypeOptions} />
                {/* feedback 4: Persian equivalent of Lead / Lag */}
                <Input type="number" name="lag" title="پیش افتادگی - تاخیر" formik={formik} placeholder="مثبت یا منفی" />
                <Select name="lag_unit" title="واحد پیش افتادگی - تاخیر" formik={formik} options={durationUnitOptions} />
            </form>

            <p className="text-xs text-[#777777] leading-5">
                مقدار <b>مثبت</b> یعنی تاخیر و مقدار <b>منفی</b> یعنی پیش افتادگی. برای مثال FS با تاخیر ۲ یعنی تسک وابسته، ۲ واحد بعد از پایان تسک مقدم شروع
                می‌شود. با انتخاب چند تسک وابسته، به ازای هر تسک یک رابطه با همین نوع و همین مقدار ثبت می‌شود؛ روابط تکراری به‌صورت خودکار نادیده گرفته می‌شوند.
            </p>
        </div>
    );

    const submit = (
        <Button onClick={formik.handleSubmit} className="mt-5" isLoading={loading} disabled={!formik.isValid}>
            ثبت
        </Button>
    );

    // the modal «افزودن رابطه» carries its own title, so it takes the form bare
    if (bare) {
        return (
            <div className="w-full">
                {formBody}
                {submit}
            </div>
        );
    }

    return (
        <Card className="w-full">
            <CardHeader className="mb-5">
                <CardTitle>{editMode ? "ویرایش رابطه" : "ثبت رابطه جدید"}</CardTitle>
                <CardDescription>تکمیل فیلدهایی که با * مشخص شده اند الزامی می باشد</CardDescription>
            </CardHeader>

            <CardContent>{formBody}</CardContent>

            <CardFooter>{submit}</CardFooter>
        </Card>
    );
}
