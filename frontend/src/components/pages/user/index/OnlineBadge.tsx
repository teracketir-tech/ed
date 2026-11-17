import { isOnline, type UserLog } from "./isOnline";

/** چیپ سبز «آنلاین» یا قرمز «آفلاین» بر اساس لاگ‌های فعال کاربر */
export default function OnlineBadge({ logs }: { logs?: UserLog[] | null }) {
    if (isOnline(logs))
        return (
            <div className="flex items-center gap-1.5 py-1 px-3 rounded-full text-xs bg-green-50">
                <p className="size-1.5 rounded-full bg-green-500"></p>
                <p className="text-green-500">آنلاین</p>
            </div>
        );

    return (
        <div className="flex items-center gap-1.5 py-1 px-3 rounded-full text-xs bg-red-50">
            <p className="size-1.5 rounded-full bg-red-500"></p>
            <p className="text-red-500">آفلاین</p>
        </div>
    );
}
