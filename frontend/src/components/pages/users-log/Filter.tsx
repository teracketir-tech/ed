import Filters from "@/components/shared/Filters";
import UserSelect from "@/components/shared/UserSelect";
import DateInput from "@/components/shared/inputs/Date";
import { useFormik } from "formik";
import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";

/**
 * Filters for the attendance list: the user (issue 5 — `user_id` holds
 * `user.id`, never `contact.id`, so the picker reads `user/options`) and the
 * check-in date as a range — the accounting items date-range pattern, one
 * comma-joined param the backend splits into from/to.
 */
export default function FilterForm({ loading }: { loading: boolean }) {
    const [open, setOpen] = useState(false);
    const [searchParams, setSearchParams] = useSearchParams();

    const formik = useFormik({
        initialValues: {
            user_id: "",
            checkindate: "",
        },
        onSubmit,
        validationSchema: false,
    });

    // formik's methods are referentially stable — destructured so the effect
    // below can list it as a dep (depending on the whole formik object would
    // re-run on every keystroke and overwrite typing).
    const { setFieldValue } = formik;

    function onSubmit(data: object) {
        const params = new URLSearchParams(searchParams.toString());

        Object.entries(data).forEach(([key, value]) => {
            if (value) params.set(key, value.toString());
            else params.delete(key);
        });

        setSearchParams(params);
    }

    // Restore URL values when the panel opens.
    useEffect(() => {
        if (!open) return;

        ["user_id", "checkindate"].forEach((key) => {
            const value = searchParams.get(key);

            if (value) setFieldValue(key, value);
        });
    }, [open, searchParams, setFieldValue]);

    return (
        <Filters open={open} setOpen={setOpen} loading={loading} formik={formik}>
            <form className="w-full grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-5">
                <UserSelect name="user_id" title="کاربر" formik={formik} />
                <DateInput name="checkindate" title="تاریخ ورود" formik={formik} required={false} isRange={true} />
            </form>
        </Filters>
    );
}
