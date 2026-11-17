import type { NavigateFunction } from "react-router-dom";

type TrackedLocation = { pathname: string; search: string };

// the history entry before the current one - written by the Layout on every route
// change, so a page can tell whether the user actually came from its own list
let lastLocation: TrackedLocation | null = null;
let previousLocation: TrackedLocation | null = null;

export const trackLocation = (location: TrackedLocation): void => {
    previousLocation = lastLocation;
    lastLocation = location;
};

export const getPreviousLocation = (): TrackedLocation | null => previousLocation;

const hasPreviousEntry = (): boolean => {
    const state = window.history.state as { idx?: number } | null;

    // `history.length` cannot be used here: it also counts the entries the browser
    // tab had before this app was opened - `idx` is react-router's own entry index
    return (state?.idx ?? 0) > 0;
};

// the previous entry is this very list and it carries filters in its URL
const cameFromFilteredList = (listPath: string): boolean => previousLocation?.pathname === listPath && !!previousLocation?.search;

/**
 * Where a form goes after a successful save.
 *
 * - editing goes back, because the list it came from may carry filters - those
 *   filters live in the URL, so returning to the same entry restores them;
 * - creating also goes back when the user came from the same list and that list
 *   was filtered (e.g. the material list -> "ثبت انبار" flow), otherwise the
 *   filters would be lost by navigating to a bare list path;
 * - a form opened through a direct link has no previous entry of its own
 *   (`idx` is 0), so it falls back to the list instead of going nowhere.
 */
export const navigateAfterSave = (navigate: NavigateFunction, isEdit: boolean, listPath: string): void => {
    if (!hasPreviousEntry()) {
        navigate(listPath);
        return;
    }

    if (isEdit || cameFromFilteredList(listPath)) navigate(-1);
    else navigate(listPath);
};
