import { useCallback, useLayoutEffect, useRef } from "react";
import type { ReactNode } from "react";
import { createPortal } from "react-dom";

// react-select's own MenuPortal re-measures the control from a ref callback on every commit and
// feeds the result back into its own state; with menuPlacement="auto" the top/bottom flip never
// settles on short screens -> React #185 (maximum update depth exceeded). This replacement owns
// positioning outright: it re-measures the control on every animation frame while the menu is open
// and writes straight to the DOM (no state, no autoUpdate subscription), so it cannot loop. It also
// escapes every scrollable ancestor
// (modal forms, tables) because the menu is portalled to a body-level root, and it restores pointer
// events + wheel/touch scrolling that Radix's modal scroll-lock would otherwise block.
let portalRoot: HTMLDivElement | null = null;

// react-remove-scroll listens on document (bubble, passive:false) for BOTH wheel and touchmove
// and preventDefaults any such event whose target sits outside the dialog shards - so without
// this guard a portalled menu is unscrollable inside an open dialog: by wheel on desktop and
// by finger on mobile. Stopping propagation at the root keeps the native scroll (the default
// action) intact while the document listener never sees the event. A named function makes
// addEventListener idempotent: re-registering after HMR is a no-op, not a duplicate.
const stopScrollLock = (event: Event) => event.stopPropagation();

const getPortalRoot = (): HTMLDivElement => {
    if (!portalRoot?.isConnected) {
        // Reuse an existing root when present (e.g. HMR re-executed this module).
        portalRoot =
            document.querySelector<HTMLDivElement>("[data-select-menu-portal]") ?? document.createElement("div");
        if (!portalRoot.isConnected) {
            portalRoot.setAttribute("data-select-menu-portal", "");
            document.body.appendChild(portalRoot);
        }
    }
    portalRoot.addEventListener("wheel", stopScrollLock);
    portalRoot.addEventListener("touchmove", stopScrollLock);
    return portalRoot;
};

interface Props {
    children?: ReactNode;
    controlElement?: HTMLElement | null;
}

const GAP = 8;
const MIN_MENU = 200;

const SafeMenuPortal = ({ children, controlElement }: Props) => {
    const wrapperRef = useRef<HTMLDivElement>(null);

    // Position the portalled menu against the control's viewport rect and pick above/below from
    // the real free space. Written directly to the DOM on purpose: routing this through setState is
    // exactly what made react-select's own portal loop into React #185, so positioning never touches
    // React state. We also force the menu's anchor + list height here so react-select's MenuPlacer
    // (which measures a different offsetParent) cannot disagree and misplace the menu.
    const place = useCallback(() => {
        const wrapper = wrapperRef.current;
        if (!wrapper || !controlElement) return;

        const rect = controlElement.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom - GAP;
        const spaceAbove = rect.top - GAP;
        const openAbove = spaceBelow < MIN_MENU && spaceAbove > spaceBelow;
        const available = Math.max(openAbove ? spaceAbove : spaceBelow, MIN_MENU);

        // Sync instead of assign: place() runs every frame while the menu is open, and rewriting an
        // unchanged value would invalidate style on every tick for nothing.
        const sync = (property: string, value: string) => {
            if (wrapper.style.getPropertyValue(property) !== value) wrapper.style.setProperty(property, value);
        };

        const anchor = openAbove ? "above" : "below";
        if (wrapper.dataset.menuAnchor !== anchor) wrapper.dataset.menuAnchor = anchor;
        sync("position", "fixed");
        sync("left", `${rect.left}px`);
        sync("width", `${rect.width}px`);
        sync("z-index", "100");
        if (openAbove) {
            sync("top", "auto");
            sync("bottom", `${window.innerHeight - rect.top + GAP}px`);
        } else {
            sync("bottom", "auto");
            sync("top", `${rect.bottom + GAP}px`);
        }

        // The menu is portalled to <body>, so it would inherit the page's 16px instead of the select
        // wrapper's own text size - mirror the control's computed metrics so the menu keeps exactly
        // the typography it had when it rendered inline.
        const controlStyle = getComputedStyle(controlElement);
        sync("font-size", controlStyle.fontSize);
        sync("line-height", controlStyle.lineHeight);
        sync("font-family", controlStyle.fontFamily);

        // Constrain the scrollable list to the space we actually have; the list already scrolls.
        // Match by role: custom styles can strip the emotion "-menu-list" label from the class.
        const list = wrapper.querySelector<HTMLElement>('[role="listbox"]');
        const maxHeight = `${available}px`;
        if (list && list.style.maxHeight !== maxHeight) list.style.maxHeight = maxHeight;
    }, [controlElement]);

    useLayoutEffect(() => {
        place();
        // Re-place on every frame while the menu is open: data loading, scrollbar toggles and modal
        // reflows move the control without ever firing scroll/resize, which left the menu visibly
        // detached from its select (stale rect). Frame granularity subsumes the old scroll/resize
        // listeners, and the writes stay pure DOM - never React state - so the loop cannot feed
        // back into a render (React #185).
        let frame = requestAnimationFrame(function follow() {
            place();
            frame = requestAnimationFrame(follow);
        });
        return () => cancelAnimationFrame(frame);
    }, [place]);

    if (!controlElement) return null;

    return createPortal(
        <div
            ref={wrapperRef}
            // Radix sets pointer-events:none on body while a dialog is open; without this the
            // portalled menu would be unclickable (verified via elementFromPoint).
            style={{ pointerEvents: "auto" }}
        >
            {children}
        </div>,
        getPortalRoot()
    );
};

export default SafeMenuPortal;
