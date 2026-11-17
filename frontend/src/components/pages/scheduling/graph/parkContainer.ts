import type { Box } from "./routeEdges";

/** breathing room so two cards never end up flush against each other */
const BOX_PAD = 16;

/** horizontal step between two lanes a container may be parked in */
const laneStep = (box: Box) => box.width + 56;

/** true when `spot` runs into any box except its own */
function blocked(boxes: Map<string, Box>, id: string, spot: Box): boolean {
    for (const [otherId, box] of boxes) {
        if (otherId === id) continue;

        const clash =
            spot.x - BOX_PAD < box.x + box.width &&
            spot.x + spot.width + BOX_PAD > box.x &&
            spot.y - BOX_PAD < box.y + box.height &&
            spot.y + spot.height + BOX_PAD > box.y;

        if (clash) return true;
    }

    return false;
}

/**
 * The floated spot is absolute: another branch's activity (or a sibling summary
 * whose subtree starts on the same row) may already be standing there. Park the
 * container on a free lane of the SAME row instead of pushing it up: rows never
 * shift, activities never move, and the walk always finds room, so no project
 * structure can stack cards on top of each other.
 *
 * `parentLane` is the box of a WBS-container parent, when the container has
 * one. Then the container parks in the parent's lane (same column x), so a
 * whole family stacks in one column and reads as a single branch. Only when
 * that lane is blocked — or when the parent is an activity, so there is no
 * family column to join — does it fall back to marching left off its own
 * centre (user ruling 2026-10-14: queue leftward, never to the far side).
 */
export function parkContainer(boxes: Map<string, Box>, id: string, parentLane?: Box): void {
    const own = boxes.get(id);

    if (!own || !blocked(boxes, id, own)) return;

    if (parentLane) {
        const aligned = { ...own, x: parentLane.x };

        if (!blocked(boxes, id, aligned)) {
            boxes.set(id, aligned);
            return;
        }
    }

    const step = laneStep(own);

    for (let lane = 1; ; lane++) {
        const spot = { ...own, x: own.x - lane * step };

        if (!blocked(boxes, id, spot)) {
            boxes.set(id, spot);
            return;
        }
    }
}
