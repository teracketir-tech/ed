// src/App.tsx

import {
    RouterProvider,
    createBrowserRouter,
    type RouteObject,
} from "react-router-dom";
import { routes } from "./routes/routes";
import { LoadingProvider } from "@/context/LoadingContext";
import { setLoadingContext } from "@/lib/axios";
import LoadingOverlay from "@/components/shared/LoadingOverlay";
import { useLoadingContext } from "@/hooks/useLoadingContext";
import { useEffect, useRef } from "react";
import RouteError from "@/components/shared/RouteError";
import { NotFound } from "@/components/shared/ErrorScreen";
import RootErrorBoundary from "@/components/shared/RootErrorBoundary";
import { useGlobalErrorListener } from "@/hooks/use-global-error-listener";

// Every route gets its own boundary, so a failure renders in the slot the failing
// route owned - inside <Layout /> - and the sidebar and Toaster stay mounted.
// Only the root keeps the full-screen variant, for errors thrown by Layout itself.
const withBoundaries = (list: RouteObject[], nested = false): RouteObject[] =>
    list.map((route) => {
        const next = {
            ...route,
            errorElement: route.errorElement ?? <RouteError inline={nested} />,
        };

        if (route.children?.length) {
            next.children = withBoundaries(route.children, true);
        }

        return next;
    });

const appRoutes = withBoundaries(routes);

// The catch-all hangs off the root route so a404 keeps the sidebar and header.
appRoutes.forEach((root) => {
    root.children = [
        ...(root.children ?? []),
        { path: "*", element: <NotFound /> },
    ];
});

const router = createBrowserRouter(appRoutes);

// کامپوننت برای تنظیم Context در axios
const AxiosSetup = () => {
    const loadingContext = useLoadingContext();
    const isSet = useRef(false);

    useEffect(() => {
        if (loadingContext && !isSet.current) {
            setLoadingContext(loadingContext);
            isSet.current = true;
        }
    }, [loadingContext]);

    return null;
};

export default function App() {
    useGlobalErrorListener();

    return (
        <RootErrorBoundary>
            <LoadingProvider>
                <AxiosSetup />
                <RouterProvider router={router} />
                <LoadingOverlay />
            </LoadingProvider>
        </RootErrorBoundary>
    );
}
