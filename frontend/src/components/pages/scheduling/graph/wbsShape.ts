import type { TaskRow } from "./buildGraph";

/** "project" = the code-0 root, "summary" = a group row, "activity" = a leaf */
export type NodeKind = "project" | "summary" | "activity";

/** the only parent-less row of a project, always coded 0 (ProjectNodeService::isRoot) */
export function isProjectRow(task: TaskRow): boolean {
    const parent = task.parent_id;
    return (parent === null || parent === undefined || parent === "") && String(task.wbs_code ?? "") === "0";
}

/** role of a row inside the WBS: the project node, a group, or a real activity */
export function kindOf(task: TaskRow, parents: Set<string>): NodeKind {
    if (isProjectRow(task)) return "project";
    return parents.has(String(task.id)) ? "summary" : "activity";
}

/** ids of every row that is somebody's parent — the groups of the WBS */
export function parentIds(tasks: TaskRow[]): Set<string> {
    return new Set(
        tasks
            .map((task) => task.parent_id)
            .filter((parent) => parent !== null && parent !== undefined && parent !== "")
            .map(String),
    );
}
