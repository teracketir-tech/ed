import { Handle, Position, type NodeProps } from "@xyflow/react";
import type { TaskNodeData } from "./buildGraph";

/** frame of the node, by its role in the WBS and its place on the critical path */
function frame(kind: TaskNodeData["kind"], critical: boolean, focused: boolean) {
    if (focused) return "border-foreground ring-2 ring-foreground/30 shadow-md";
    if (kind === "project") return "border-foreground bg-foreground/5 shadow-sm";
    if (kind === "summary") return "border-dashed border-border bg-muted/40 shadow-sm";
    return critical ? "border-red-500 shadow-sm" : "border-border shadow-sm";
}

/** the small tag on the left of the header row */
function Tag({ kind, critical }: { kind: TaskNodeData["kind"]; critical: boolean }) {
    if (kind === "project") return <span className="shrink-0 rounded-full bg-foreground px-1.5 py-0.5 text-[0.6rem] text-background">پروژه</span>;
    if (kind === "summary") return <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[0.6rem] text-muted-foreground">گروه</span>;
    if (!critical) return null;

    return (
        <span className="flex shrink-0 items-center gap-1 rounded-full bg-red-50 px-1.5 py-0.5 text-[0.6rem] text-red-500">
            <span className="size-1 rounded-full bg-red-500"></span>
            بحرانی
        </span>
    );
}

/**
 * One box of the precedence diagram: WBS code + tag, the Persian name (RTL,
 * clamped) and the duration. The wrapper div is sized by `buildGraph`, this
 * component just fills it.
 *
 * The same component draws the project node (code 0) and the WBS group rows,
 * which read as containers rather than as activities (feedback 7.1).
 *
 * Precedence attaches on the top (incoming) and the bottom (outgoing),
 * matching the top-to-bottom rank direction of the layout (feedback 5).
 */
export default function TaskNode({ data }: NodeProps) {
    const node = data as TaskNodeData;

    return (
        <div dir="rtl" className={`flex h-full w-full flex-col gap-1 overflow-hidden rounded-md border bg-card px-4 py-2 text-right ${frame(node.kind, node.critical, node.focused)}`}>
            <div className="flex items-center justify-between gap-2">
                <span dir="auto" className="truncate text-[0.65rem] font-bold text-muted-foreground">
                    {node.wbs || "—"}
                </span>

                <Tag kind={node.kind} critical={node.critical} />
            </div>

            <p dir="auto" title={node.name} className="line-clamp-3 break-words text-[0.78rem] leading-5 text-foreground">
                {node.name}
            </p>

            <span className="mt-auto text-[0.65rem] text-muted-foreground">{node.duration}</span>

            <Handle
                type="target"
                position={Position.Top}
                style={{ background: "var(--muted-foreground)", width: 8, height: 8 }}
            />
            <Handle
                type="source"
                position={Position.Bottom}
                style={{ background: "var(--muted-foreground)", width: 8, height: 8 }}
            />
        </div>
    );
}
