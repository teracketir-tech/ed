import { Component, type ErrorInfo, type ReactNode } from "react";
import { describeError } from "@/lib/error-message";
import { ErrorScreen } from "./ErrorScreen";

interface Props {
    children: ReactNode;
}

interface State {
    error: unknown;
}

/** Outermost net: catches what the router never sees - Layout, contexts, the shell. */
export default class RootErrorBoundary extends Component<Props, State> {
    state: State = { error: null };

    static getDerivedStateFromError(error: unknown): State {
        return { error };
    }

    componentDidCatch(error: unknown, info: ErrorInfo) {
        console.error("[app] uncaught render error", error, info.componentStack);
    }

    render() {
        if (this.state.error) {
            return <ErrorScreen info={describeError(this.state.error)} />;
        }

        return this.props.children;
    }
}
