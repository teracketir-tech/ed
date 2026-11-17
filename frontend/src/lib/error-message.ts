// One shape for every failure the UI has to explain, so a single screen can render them all.

export type ErrorKind = "render" | "notFound" | "network" | "unknown";

export interface ScreenError {
    kind: ErrorKind;
    title: string;
    message: string;
    /** HTTP status or React error number, shown as a small badge. */
    code?: string;
    /** Raw technical text - only ever revealed behind the collapsed section. */
    detail: string;
}

// Persian is the product language: these are user-facing strings, not comments.
const COPY: Record<ErrorKind, { title: string; message: string }> = {
    render: {
        title: "خطایی در نمایش این صفحه رخ داد",
        message: "این صفحه به درستی بارگذاری نشد. دوباره تلاش کنید یا به صفحه قبل بازگردید.",
    },
    notFound: { title: "صفحه مورد نظر یافت نشد", message: "آدرسی که باز کردید وجود ندارد یا جابه‌جا شده است." },
    network: {
        title: "ارتباط با سرور برقرار نشد",
        message: "اتصال به سرور برقرار نشد. اینترنت خود را بررسی کنید و دوباره تلاش کنید.",
    },
    unknown: { title: "خطای غیرمنتظره", message: "مشکلی پیش آمد. اگر ادامه داشت با پشتیبانی تماس بگیرید." },
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === "object" && value !== null;

// React ships only minified text in production, but it always keeps the #nnn number.
const reactCode = (text: string): string | undefined => text.match(/#\d{3,5}/)?.[0];

const safeJson = (value: unknown): string => {
    if (value === undefined || value === null) return "";
    try {
        const text = JSON.stringify(value, null, 2);
        return text.length > 2000 ? `${text.slice(0, 2000)}…` : text;
    } catch {
        return String(value);
    }
};

const build = (kind: ErrorKind, detail: string, code?: string): ScreenError =>
    ({ kind, ...COPY[kind], code, detail });

const messageOf = (error: unknown): string => {
    if (error instanceof Error) return error.message;
    if (typeof error === "string") return error;
    return safeJson(error);
};

const nameOf = (error: unknown): string | undefined =>
    error instanceof Error ? error.name : undefined;

const stackOf = (error: Error): string =>
    [error.message, error.stack].filter(Boolean).join("\n");

/** React Router hands 404s over as {status, statusText, data} instead of an Error. */
const fromResponse = (error: Record<string, unknown>): ScreenError | null => {
    if (typeof error.status !== "number") return null;

    const status = error.status;
    const detail = [`${status} ${error.statusText ?? ""}`.trim(), safeJson(error.data)]
        .filter(Boolean)
        .join("\n");

    return status === 404
        ? build("notFound", detail, String(status))
        : build("network", detail, String(status));
};

/** Browser failures that mean "we never reached the server", not "our code broke". */
const NETWORKISH = /fetch|network|load failed|timeout|ECONN/i;

export const describeError = (error: unknown): ScreenError => {
    if (isRecord(error)) {
        const response = fromResponse(error);
        if (response) return response;
    }

    const text = messageOf(error);

    if (error instanceof Error && NETWORKISH.test(text)) {
        return build("network", stackOf(error), error.name);
    }

    if (text) {
        const stack = error instanceof Error ? stackOf(error) : safeJson(error);
        return build("render", stack, reactCode(text) ?? nameOf(error));
    }

    return build("unknown", "The error carried no message.");
};

/** What the catch-all `*` route renders - no thrown value behind it. */
export const describeNotFound = (): ScreenError => build("notFound", "Unmatched path.");
