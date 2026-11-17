import { BaseEdge, EdgeLabelRenderer, type EdgeProps } from "@xyflow/react";

type RoutedData = {
    /** orthogonal SVG path that already avoids every box (layoutGraph) */
    path?: string;
    /** where the relation caption belongs along that path */
    labelPos?: { x: number; y: number };
};

/**
 * An edge drawn from the path `layoutGraph` computed.
 *
 * React Flow's built-in edges always draw a straight/bezier line between the
 * two handles, which is exactly what ran under the activity boxes. The routing
 * decision lives in the layout pass, so this component only paints it.
 */
export default function RoutedEdge({ id, sourceX, sourceY, targetX, targetY, markerEnd, style, label, data }: EdgeProps) {
    const payload = (data ?? {}) as RoutedData;
    const fallback = `M ${sourceX},${sourceY} L ${targetX},${targetY}`;
    const at = payload.labelPos;

    return (
        <>
            <BaseEdge id={id} path={payload.path || fallback} markerEnd={markerEnd} style={style} />

            {label != null && at && (
                <EdgeLabelRenderer>
                    <div
                        style={{
                            transform: `translate(-50%, -50%) translate(${at.x}px, ${at.y}px)`,
                            position: "absolute",
                            pointerEvents: "none",
                        }}
                        className="nodrag nopan rounded bg-background px-1 text-[0.6rem] whitespace-nowrap text-muted-foreground"
                    >
                        {label}
                    </div>
                </EdgeLabelRenderer>
            )}
        </>
    );
}
