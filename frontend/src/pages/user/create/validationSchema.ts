import * as yup from "yup";

export default yup.object().shape({
    first_name: yup.string().required("لطفا نام را وارد کنید"),
    last_name: yup.string().required("لطفا نام خانوادگی را وارد کنید"),
    phone_number: yup
        .string()
        .required("لطفا شماره موبایل را وارد کنید")
        .matches(/^09\d{9}$/, "شماره موبایل معتبر نیست (باید با 09 شروع شود و 11 رقم باشد)"),
    // company-1 users carry a list of groups, everyone else a single id, so
    // the field is mixed and the real check is "not empty" in either shape
    group_id: yup
        .mixed()
        .required("لطفا گروه را انتخاب کنید")
        .test("group-picked", "لطفا گروه را انتخاب کنید", (value) => (Array.isArray(value) ? value.length > 0 : !!value)),
});
