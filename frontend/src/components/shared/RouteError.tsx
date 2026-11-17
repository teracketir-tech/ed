import { useRouteError } from "react-router-dom";
import { describeError } from "@/lib/error-message";
import { ErrorScreen } from "./ErrorScreen";

interface RouteErrorProps {
    /** true when mounted as a child route's errorElement - the layout is still alive. */
    inline?: boolean;
}

/** React Router errorElement: takes the failing route's slot, leaving <Layout /> intact. */
export default function RouteError({ inline = false }: RouteErrorProps) {
    const error = useRouteError();

    console.error("[route] failed to render", error);

    return <ErrorScreen info={describeError(error)} inline={inline} />;
}
