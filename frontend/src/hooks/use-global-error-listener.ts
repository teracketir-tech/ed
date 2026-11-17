import { useEffect } from "react";
import { toast } from "sonner";
import { describeError } from "@/lib/error-message";

// Window-level failures never reach React - rejections, timer callbacks, library code.
// They get a toast instead of a screen so one broken background request keeps the app usable.
export function useGlobalErrorListener(): void {
    useEffect(() => {
        const report = (error: unknown) => {
            const info = describeError(error);

            console.error("[app] unhandled async error", error);
            toast.error(info.title, { description: info.message, duration: 8000 });
        };

        const onRejection = (event: PromiseRejectionEvent) => report(event.reason);

        const onError = (event: ErrorEvent) => {
            // Cross-origin script errors arrive with no message and no stack to show.
            if (event.message) report(event.error ?? event.message);
        };

        window.addEventListener("unhandledrejection", onRejection);
        window.addEventListener("error", onError);

        return () => {
            window.removeEventListener("unhandledrejection", onRejection);
            window.removeEventListener("error", onError);
        };
    }, []);
}
