import type { Edge } from "@xyflow/react";
import type { Box } from "./routeEdges";
import { parkContainer } from "./parkContainer";

/** how far a summary / the project node floats above the row it summarises */
export const CONTAINER_GAP = 110;

/**
 * Put every summary and the project node directly above its own subtree (7.1).
 *
 * Containers are floated children-first, so a parent always finds its children
 * already placed. A container with nothing visible below it keeps its dagre spot.
 *
 * A floated spot may be occupied, so a second pass parks each container on a
 * free lane (parkContainer). Lanes are shared down a WBS family: a container
 * with a container parent parks in its parent's lane rather than its own, so an
 * entire branch stacks in ONE column (4 / 4.1 / 4.1.1) instead of scattering
 * across lanes — which is what keeps the tree readable and its lines short.
 */
export function floatContainers(boxes: Map<string, Box>, containers: { id: string }[], containment: Edge[]): void {
    const children = new Map<string, string[]>();
    const parentOf = new Map<string, string>();

    containment.forEach((edge) => {
        const source = String(edge.source);
        const target = String(edge.target);
        children.set(source, [...(children.get(source) ?? []), target]);
        parentOf.set(target, source);
    });

    const depth = new Map<string, number>();

    const depthOf = (id: string): number => {
        if (depth.has(id)) return depth.get(id) as number;

        depth.set(id, 0); // a malformed cycle must not hang the layout
        let value = 0;

        (children.get(id) ?? []).forEach((child) => {
            value = Math.max(value, 1 + depthOf(child));
        });

        depth.set(id, value);
        return value;
    };

    const ordered = containers
        .map((container) => ({ container, level: depthOf(container.id) }))
        .sort((a, b) => a.level - b.level);

    // pass 1: every container floats above its own subtree, centred on it
    ordered.forEach(({ container }) => {
        const own = boxes.get(container.id);

        if (!own) return;

        const kids = (children.get(container.id) ?? []).map((id) => boxes.get(id)).filter((box): box is Box => Boolean(box));

        if (!kids.length) return;

        const minX = Math.min(...kids.map((kid) => kid.x));
        const maxX = Math.max(...kids.map((kid) => kid.x + kid.width));
        const minY = Math.min(...kids.map((kid) => kid.y));

        boxes.set(container.id, {
            x: (minX + maxX) / 2 - own.width / 2,
            y: minY - CONTAINER_GAP - own.height,
            width: own.width,
            height: own.height,
        });
    });

    // pass 2: park containers on a free lane, walking parents-first so a child
    // can adopt its parent's lane. A container whose parent is an activity (a
    // top-level group) parks on its own, marching left off its own centre.
    const containersOnly = new Set(containers.map((c) => c.id));

    [...ordered].reverse().forEach(({ container }) => {
        const parent = parentOf.get(container.id);
        const lane = parent && containersOnly.has(parent) ? boxes.get(parent) : undefined;

        parkContainer(boxes, container.id, lane);
    });
}
