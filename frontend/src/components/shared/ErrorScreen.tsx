import { ArrowRight, ChevronDown, Home, RotateCcw, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { describeNotFound, type ScreenError } from "@/lib/error-message";

interface ErrorScreenProps {
    info: ScreenError;
    /** true = rendered by a route inside <Layout />, false = full-page fallback. */
    inline?: boolean;
}

const goBack = () => window.history.back();
const retry = () => window.location.reload();
const goHome = () => {
    window.location.href = "/";
};

/** The one screen every failure renders, so an error never looks like a stack trace. */
export function ErrorScreen({ info, inline = false }: ErrorScreenProps) {
    const notFound = info.kind === "notFound";

    return (
        <div role="alert" className={cn("flex w-full items-center justify-center min-h-[55vh]", inline ? "px-2 py-6" : "bg-background px-4 py-8")}>
            <Card className="w-full max-w-lg gap-0 rounded-xl border-border py-0 shadow-lg">
                <CardHeader className="flex flex-col items-center gap-3 px-6 pt-8 text-center">
                    <p className="flex size-14 items-center justify-center rounded-full bg-destructive/10">
                        <TriangleAlert className="size-7 text-destructive" aria-hidden="true" />
                    </p>

                    <div className="flex flex-wrap items-center justify-center gap-2">
                        <CardTitle className="text-lg leading-8 font-bold">{info.title}</CardTitle>
                        {info.code && (
                            <span dir="ltr" className="rounded-full border border-border bg-muted px-2 py-0.5 text-[0.7rem] font-medium text-muted-foreground">
                                {info.code}
                            </span>
                        )}
                    </div>

                    <CardDescription className="max-w-sm leading-7">{info.message}</CardDescription>
                </CardHeader>

                <CardContent className="px-6 pb-1 my-3">
                    <details className="group rounded-lg border border-border bg-muted/40">
                        <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-2.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                            جزئیات فنی
                            <ChevronDown className="size-3.5 transition-transform duration-200 group-open:rotate-180" aria-hidden="true" />
                        </summary>
                        <pre
                            dir="ltr"
                            className="max-h-56 overflow-auto border-t border-border px-4 py-3 text-start text-xs leading-6 whitespace-pre-wrap break-all text-foreground/70"
                        >
                            {info.detail}
                        </pre>
                    </details>
                </CardContent>

                <CardFooter className="flex flex-wrap justify-center gap-2 px-6 py-5">
                    {notFound ? (
                        <Button onClick={goHome}>
                            <Home className="size-4" aria-hidden="true" />
                            بازگشت به داشبورد
                        </Button>
                    ) : (
                        <Button onClick={retry}>
                            <RotateCcw className="size-4" aria-hidden="true" />
                            تلاش دوباره
                        </Button>
                    )}
                    <Button variant="outline" onClick={goBack}>
                        <ArrowRight className="size-4" aria-hidden="true" />
                        بازگشت
                    </Button>
                </CardFooter>
            </Card>
        </div>
    );
}

/** Element for the catch-all `*` route - no thrown value exists behind it. */
export const NotFound = () => <ErrorScreen info={describeNotFound()} />;
