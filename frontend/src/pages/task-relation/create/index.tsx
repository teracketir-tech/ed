import RelationForm from "@/components/pages/scheduling/RelationForm";

/**
 * صفحهٔ ثبت/ویرایش رابطه — همهٔ فرم در `scheduling/RelationForm` است چون مودال
 * «افزودن رابطه» لیست تسک‌ها همان فرم را با پروژه و تسک مقدمِ قفل‌شده می‌خواهد.
 */
export default function CreateRelation({ editMode = 0 }: { editMode?: number }) {
    return <RelationForm editMode={editMode} />;
}
