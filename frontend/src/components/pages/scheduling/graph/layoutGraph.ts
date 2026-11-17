import dagre from "dagre";
import type { CSSProperties } from "react";
import type { Edge } from "@xyflow/react";
import { floatContainers } from "./floatContainers";
import { LANE_PAD, LaneBook, bandOf, freeWindows, midpoint, roundedPath, type Box, type Point } from "./routeEdges";

/** dagre works with plain numbers; a CSS size may also be a string */
function sizeOf(value: number | string | undefined, fallback: number): number {
    return typeof value === "number" ? value : fallback;
}

type LayoutNode = {
    id: string;
    style?: CSSProperties;
    position: { x: number; y: number };
    data?: unknown;
};

/** room left under a box (or over its target) before a line may turn sideways */
const TURN = 30;

const kindOf = (node: LayoutNode) => (node.data as { kind?: string } | undefined)?.kind ?? "activity";
const isContainment = (edge: Edge) => Boolean((edge.data as { containment?: boolean } | undefined)?.containment);
const hasBothEnds = (ids: Set<string>, edge: Edge) => ids.has(String(edge.source)) && ids.has(String(edge.target));

/**
 * Layered layout of the diagram, plus a routing pass that keeps every line out
 * of the boxes (feedback 7.2).
 *
 * The two edge kinds are handled separately on purpose. dagre only ever sees
 * the precedence network of the activities, so adding the WBS overlay can never
 * shift the diagram that was already signed off. The project node and the group
 * rows are then floated above their own subtree, centred over it.
 *
 * dagre ranks nodes but hands no routing back — React Flow would otherwise draw
 * a straight line through whatever sits in between. So each edge is re-routed
 * here: it leaves its source at the bottom, turns in the empty band under that
 * rank, runs down a free lane that clears every box it has to pass, then turns
 * again in the band above its target.
 */
export function layoutGraph<T extends LayoutNode>(nodes: T[], edges: Edge[]): { nodes: T[]; edges: Edge[] } {
    const activities = nodes.filter((node) => kindOf(node) === "activity");
    const containers = nodes.filter((node) => kindOf(node) !== "activity");
    const activityIds = new Set(activities.map((node) => node.id));
    const containerIds = new Set(containers.map((node) => node.id));
    const precedence = edges.filter((edge) => !isContainment(edge));
    const containment = edges.filter(isContainment);

    const boxes = new Map<string, Box>();

    place(boxes, activities, precedence.filter((edge) => hasBothEnds(activityIds, edge)));
    place(boxes, containers, containment.filter((edge) => hasBothEnds(containerIds, edge)));
    floatContainers(boxes, containers, containment);

    return {
        nodes: nodes.map((node) => {
            const box = boxes.get(node.id);

            return box ? { ...node, position: { x: box.x, y: box.y } } : node;
        }),
        edges: routeEdges(edges, boxes),
    };
}

/** run dagre over one family of nodes and record where it put them */
function place(boxes: Map<string, Box>, nodes: LayoutNode[], edges: Edge[]): void {
    if (!nodes.length) return;

    const graph = new dagre.graphlib.Graph();

    graph.setGraph({ rankdir: "TB", nodesep: 56, ranksep: 110, marginx: 32, marginy: 32 });
    graph.setDefaultEdgeLabel(() => ({}));

    nodes.forEach((node) => {
        graph.setNode(node.id, { width: sizeOf(node.style?.width, 240), height: sizeOf(node.style?.height, 96) });
    });

    edges.forEach((edge) => graph.setEdge(String(edge.source), String(edge.target)));

    dagre.layout(graph);

    nodes.forEach((node) => {
        const placed = graph.node(node.id);

        if (placed) boxes.set(node.id, { x: placed.x - placed.width / 2, y: placed.y - placed.height / 2, width: placed.width, height: placed.height });
    });
}

/**
 * Rebuild every edge as an orthogonal path around the boxes.
 *
 * Shortest edges claim their lane first, so a long line that has to travel far
 * sideways steps around the ones already routed instead of covering them.
 */
function routeEdges(edges: Edge[], boxes: Map<string, Box>): Edge[] {
    const crossed = new Map<string, number>();

    edges.forEach((edge) => {
        const source = boxes.get(String(edge.source));
        const target = boxes.get(String(edge.target));

        crossed.set(String(edge.id), source && target ? obstacles(source, target, boxes).length : 0);
    });

    const book = new LaneBook();
    const routed = new Map<string, Edge>();
    const bands: { y: number; lo: number; hi: number }[] = [];

    [...edges]
        .sort((a, b) => (crossed.get(String(a.id)) ?? 0) - (crossed.get(String(b.id)) ?? 0))
        .forEach((edge) => {
            const source = boxes.get(String(edge.source));
            const target = boxes.get(String(edge.target));

            if (!source || !target) return;

            const wbs = isContainment(edge);
            const points = pathFor(source, target, boxes, book, bands, wbs);
            const band = bandOf(points);
            if (band) bands.push(band);

            routed.set(String(edge.id), {
                ...edge,
                data: { ...(edge.data ?? {}), path: roundedPath(points), labelPos: midpoint(points) },
            });
        });

    return edges.map((edge) => routed.get(String(edge.id)) ?? edge);
}

/** outermost x bounds the diagram occupies, so a lane never runs off the side */
function spanOf(boxes: Map<string, Box>): [number, number] {
    let min = Infinity;
    let max = -Infinity;

    boxes.forEach((box) => {
        min = Math.min(min, box.x);
        max = Math.max(max, box.x + box.width);
    });

    return [min, max];
}

/** boxes sitting in the vertical band a line has to travel through */
function obstacles(source: Box, target: Box, boxes: Map<string, Box>): Box[] {
    const from = source.y + source.height + TURN;
    const to = target.y - TURN;

    return [...boxes.values()].filter((box) => box !== source && box !== target && box.y < to && box.y + box.height > from);
}

function pathFor(
    source: Box,
    target: Box,
    boxes: Map<string, Box>,
    book: LaneBook,
    bands: { y: number; lo: number; hi: number }[],
    wbs: boolean,
): Point[] {
    const start: Point = { x: source.x + source.width / 2, y: source.y + source.height };
    const end: Point = { x: target.x + target.width / 2, y: target.y };
    // every line turns in the band right above its target, so its last drop is a
    // short curve into the box — the same shape whether it dodged a box or not
    const turn = Math.max(end.y - TURN, start.y + 1);
    const plain = () => [start, { x: start.x, y: turn }, { x: end.x, y: turn }, end];

    const between = obstacles(source, target, boxes);

    if (!between.length) return plain();

    const blocked = between.map((box): [number, number] => [box.x - LANE_PAD, box.x + box.width + LANE_PAD]);
    // a WBS lane must not slice the band another line already runs on (issue 2)
    const avoid = wbs ? (x: number) => bands.some((b) => b.y > start.y && b.y < end.y && x > b.lo - LANE_PAD && x < b.hi + LANE_PAD) : undefined;
    const lane = book.take(freeWindows(blocked, spanOf(boxes)), (start.x + end.x) / 2, 16, avoid);

    // no free lane left — fall back to the plain Z rather than draw over a box
    if (lane === null) return plain();

    return [
        start,
        { x: start.x, y: start.y + TURN },
        { x: lane, y: start.y + TURN },
        { x: lane, y: end.y - TURN },
        { x: end.x, y: end.y - TURN },
        end,
    ];
}
