import HeaderPanel from "./HeaderPanel";
import ActivitiesPanel from "./activities/ActivitiesPanel";
import PreviewPanel from "./preview/PreviewPanel";
import PlaceholderPanel from "./PlaceholderPanel";
import ReportLinesPanel from "./ReportLinesPanel";
import { tabTitle } from "./tabs";

interface Props {
    tab: string;
    /** فقط بخشِ مقادیرِ Formik که پنل‌ها می‌خوانند؛ کلِ فرم در state صفحه است */
    formik: { values: Record<string, string>; setFieldError: (field: string, message?: string) => void };
    /** شناسه‌ی گزارشِ ذخیره‌شده — تب‌های زیرمجموعه به آن نیاز دارند */
    reportId?: number | null;
    locked?: boolean;
    /** آیا این تب الان فعال است — پیش‌نمایش با `forceMount` فقط هنگامِ دیده‌شدن می‌خواند */
    active?: boolean;
    /** بند ۳: شمارشِ فعالیت‌ها برای مسدود کردن رفتن به «پیش‌نمایش» */
    onActivitiesChange?: (count: number, loading: boolean) => void;
    /**
     * بعد از هر ذخیرهٔ موفقِ سربرگ یکی می‌شود؛ سرور برایِ روزِ تازه ردیف‌ها را
     * از نو می‌سازد و تبِ فعالیت‌ها باید همان‌ها را بخواند.
     */
    headerRevision?: number;
}

/**
 * انتخاب پنلِ تبِ فعال.
 *
 * ایشوی ۱۰: سه تبِ زیرمجموعه‌ی فعالیت‌محور از اینجا رفتند چون هر ردیفِ آنها
 * به یک فعالیت تعلق دارد و داخلِ دیالوگِ همان فعالیت ثبت می‌شود.
 * ایشوی ۴: دو زیرمجموعه‌ی گزارش‌محور (تحویل و تایم‌شیت) اما داخلِ تبِ نخست
 * رندر می‌شوند چون مالکشان خودِ گزارش است.
 *
 * پس سربرگ (با همان دو بلوک)، فعالیت‌ها و پیش‌نمایش می‌مانند و هر تبِ
 * ناشناخته (کلید قدیمی در URL یا لینک قدیمی) به پیش‌فرض برمی‌گردد به‌جای خطای رندر.
 */
export default function ReportPanel({ tab, formik, reportId = null, locked = false, active = false, onActivitiesChange, headerRevision = 0 }: Props) {
    if (tab === "header") {
        return (
            <>
                <HeaderPanel formik={formik} locked={locked} />
                {/* مارجینِ بالا: سربرگ و بلوکِ «تحویل کالا» نباید به هم بچسبند */}
                <div className="mt-6">
                    <ReportLinesPanel formik={formik} reportId={reportId} locked={locked} />
                </div>
            </>
        );
    }

    if (tab === "activities") return <ActivitiesPanel formik={formik} reportId={reportId} locked={locked} onActivitiesChange={onActivitiesChange} headerRevision={headerRevision} />;

    if (tab === "preview") {
        return <PreviewPanel formik={formik} reportId={reportId} locked={locked} active={active} />;
    }

    return <PlaceholderPanel title={tabTitle(tab)} />;
}
