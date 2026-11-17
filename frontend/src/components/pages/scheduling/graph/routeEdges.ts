/** Geometry that keeps a routed line out of the activity boxes (feedback 7.2). */

export type Box = { x: number; y: number; width: number; height: number };
export type Point = { x: number; y: number };
/** horizontal room a line may run through without touching a box */
export type Window = { lo: number; hi: number };

/** clearance kept between a node box and any line slipping past it */
export const LANE_PAD = 22;
/** how far outside the outermost box a lane may run */
const OUTER = 60;
/** minimum useful gap — narrower windows are simply not lanes */
const MIN_GAP = 14;

const dist = (a: Point, b: Point) => Math.hypot(b.x - a.x, b.y - a.y);

function towards(from: Point, to: Point, step: number): Point {
    const d = dist(from, to) || 1;
    return { x: from.x + ((to.x - from.x) * step) / d, y: from.y + ((to.y - from.y) * step) / d };
}

/** collapse coincident points so the corner maths never divides by zero */
function dedupe(points: Point[]): Point[] {
    return points.filter((p, i) => i === 0 || dist(p, points[i - 1]) > 0.5);
}

/** orthogonal polyline with rounded corners, as one SVG path */
export function roundedPath(input: Point[]): string {
    const pts = dedupe(input);
    if (pts.length < 2) return "";

    let d = `M ${pts[0].x} ${pts[0].y}`;

    for (let i = 1; i < pts.length - 1; i++) {
        const prev = pts[i - 1];
        const cur = pts[i];
        const next = pts[i + 1];
        const r = Math.min(16, dist(prev, cur) / 2, dist(cur, next) / 2);
        const a = towards(cur, prev, r);
        const b = towards(cur, next, r);

        d += ` L ${a.x} ${a.y} Q ${cur.x} ${cur.y} ${b.x} ${b.y}`;
    }

    const last = pts[pts.length - 1];
    return `${d} L ${last.x} ${last.y}`;
}

/** point halfway along the polyline — where the relation caption sits */
export function midpoint(points: Point[]): Point {
    const pts = dedupe(points);
    const half = pts.reduce((sum, p, i) => (i ? sum + dist(pts[i - 1], p) : 0), 0) / 2;

    let seen = 0;

    for (let i = 1; i < pts.length; i++) {
        const seg = dist(pts[i - 1], pts[i]);

        if (seen + seg >= half) {
            const t = (half - seen) / (seg || 1);
            return { x: pts[i - 1].x + (pts[i].x - pts[i - 1].x) * t, y: pts[i - 1].y + (pts[i].y - pts[i - 1].y) * t };
        }

        seen += seg;
    }

    return pts[pts.length - 1] ?? { x: 0, y: 0 };
}

/**
 * The horizontal corridors a line may use to slip past the boxes it crosses.
 *
 * Each crossed box blocks `box ± LANE_PAD`; the merged complement is the set
 * of free corridors — one between every pair of neighbours plus the two outer
 * sides of the drawing. Because the intervals were merged across *every*
 * crossed row, a corridor is free in all of them at once.
 */
export function freeWindows(blocked: [number, number][], extent: [number, number]): Window[] {
    if (!blocked.length) return [{ lo: extent[0] - OUTER, hi: extent[1] + OUTER }];

    const merged: [number, number][] = [];

    [...blocked]
        .sort((a, b) => a[0] - b[0])
        .forEach(([a, b]) => {
            const last = merged[merged.length - 1];

            if (last && a <= last[1]) last[1] = Math.max(last[1], b);
            else merged.push([a, b]);
        });

    const windows: Window[] = [];
    let cursor = extent[0] - OUTER;

    merged.forEach(([a, b]) => {
        windows.push({ lo: cursor, hi: a });
        cursor = b;
    });

    windows.push({ lo: cursor, hi: extent[1] + OUTER });

    return windows.filter((w) => w.hi - w.lo > MIN_GAP);
}

/**
 * Hands out lane x positions so two lines never run on top of each other.
 *
 * Lines are routed shortest-first; each one claims a spot inside its nearest
 * corridor, stepping aside when an earlier line already sits there. The claims
 * are kept globally rather than per corridor, because two corridors can overlap
 * horizontally and a shared x would draw the lines on top of one another.
 */
export class LaneBook {
    private taken: number[] = [];

    take(windows: Window[], ideal: number, spacing = 16, avoid?: (x: number) => boolean): number | null {
        const order = windows
            .map((w) => ({
                w,
                distance: ideal >= w.lo && ideal <= w.hi ? 0 : Math.min(Math.abs(ideal - w.lo), Math.abs(ideal - w.hi)),
            }))
            .sort((a, b) => a.distance - b.distance);

        for (const { w } of order) {
            const home = Math.min(Math.max(ideal, w.lo), w.hi);

            for (let step = 0; step * spacing <= w.hi - w.lo; step++) {
                for (const x of step === 0 ? [home] : [home + step * spacing, home - step * spacing]) {
                    if (x < w.lo || x > w.hi) continue;
                    if (this.taken.some((t) => Math.abs(t - x) < spacing)) continue;
                    if (avoid && avoid(x)) continue;

                    this.taken.push(x);
                    return x;
                }
            }
        }

        return null;
    }
}

/** straight segments already drawn, so a later line can route around them */
export type Traffic = { x: number; lo: number; hi: number }[] | null;

/** the vertical spans a finished path occupies — a later lane must not cross them */
export function verticalsOf(points: Point[]): { x: number; lo: number; hi: number }[] {
    const out: { x: number; lo: number; hi: number }[] = [];

    for (let i = 1; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];

        if (Math.abs(a.x - b.x) < 0.5) out.push({ x: a.x, lo: Math.min(a.y, b.y), hi: Math.max(a.y, b.y) });
    }

    return out;
}

/** the horizontal band a plain Z runs through — a later line may cross it only off to the side */
export function bandOf(points: Point[]): { y: number; lo: number; hi: number } | null {
    let best: { y: number; lo: number; hi: number } | null = null;

    for (let i = 1; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];

        if (Math.abs(a.y - b.y) < 0.5 && (!best || Math.abs(b.x - a.x) > best.hi - best.lo)) {
            best = { y: a.y, lo: Math.min(a.x, b.x), hi: Math.max(a.x, b.x) };
        }
    }

    return best;
}
