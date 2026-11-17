export interface UserLog {
    id: number;
    active?: number | string | null;
}

/** یعنی کاربر دستِ‌کم یک لاگِ فعال دارد (ردیف‌ها را از تازه‌ترین می‌خواند) */
export const isOnline = (logs?: UserLog[] | null) => logs?.sort((a, b) => b.id - a.id)?.some((l) => l.active == 1);
