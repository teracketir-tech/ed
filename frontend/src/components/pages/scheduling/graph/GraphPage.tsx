import Button from "@/components/shared/Button";
import Empty from "@/components/shared/Empty";
import Loading from "@/components/shared/Loading";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { checkAccess } from "@/lib/utils";
import { useMemo } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { buildGraph, type TaskNodeData } from "./buildGraph";
import GraphCanvas from "./GraphCanvas";
import { layoutGraph } from "./layoutGraph";
import { useGraphData } from "./useGraphData";

/**
 * Full-page dependency graph (Activity-on-Node / PDM precedence diagram).
 *
 * Two entries share this component:
 *   `/task/graph`     — every activity of the filtered project
 *   `/task/graph/:id` — one task centred, with its direct relations
 */
export default function DependencyGraph() {
    const { id } = useParams();
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();

    const focusId = id ? String(id) : null;
    const urlProjectId = searchParams.get("project_id");

    const { projectId, tasks, relations, unit, projectName, focusTask, loading, error } = useGraphData(focusId, urlProjectId);

    const graph = useMemo(() => buildGraph({ tasks, relations, focusId, unit }), [tasks, relations, focusId, unit]);
    const laidOut = useMemo(() => layoutGraph(graph.nodes, graph.edges), [graph]);

    // the project node and the WBS group rows are containers, not activities
    const activityCount = graph.nodes.filter((node) => (node.data as TaskNodeData).kind === "activity").length;

    const focusRelations = focusId
        ? relations.filter((relation) => String(relation.predecessor_task_id) === focusId || String(relation.successor_task_id) === focusId).length
        : relations.length;

    const title = focusTask
        ? `گراف وابستگی‌های «${focusTask.name ?? "تسک"}»`
        : projectName
          ? `گراف وابستگی‌های پروژه «${projectName}»`
          : "گراف وابستگی‌های پروژه";

    return (
        <div className="w-full">
            <Card className="w-full">
                {/*
                  Feedback 5.3: the activity/relation counters no longer sit next
                  to a back button — they live inside the card itself, opposite
                  the CardTitle (at the left end).
                */}
                <CardHeader className="mb-5 flex flex-row items-start justify-between gap-4">
                    <div className="flex flex-col gap-1.5">
                        <CardTitle>{title}</CardTitle>
                        <CardDescription>نمودار شبکه وابستگی‌ها — چیدمان لایه‌ای از مقدم (بالا) به وابسته (پایین)</CardDescription>
                    </div>

                    {!loading && !error && (
                        <Card className="w-auto shrink-0 bg-card px-4 py-2 shadow-sm">
                            <p className="text-xs text-muted-foreground whitespace-nowrap">
                                {activityCount.toLocaleString()} فعالیت · {focusRelations.toLocaleString()} رابطه
                            </p>
                        </Card>
                    )}
                </CardHeader>

                <CardContent>
                    {loading ? (
                        <div className="flex h-64 items-center justify-center">
                            <Loading />
                        </div>
                    ) : error ? (
                        <Empty text={error} />
                    ) : graph.nodes.length === 0 ? (
                        <Empty text="برای نمایش گراف، ابتدا یک پروژه را انتخاب کنید" />
                    ) : (
                        <>
                            {focusId && focusRelations === 0 && (
                                <p className="mb-3 rounded border border-border bg-muted px-3 py-2 text-xs text-muted-foreground">
                                    این تسک هنوز هیچ رابطه‌ای ندارد؛ فقط خودِ تسک نمایش داده شده است.
                                </p>
                            )}

                            <GraphCanvas nodes={laidOut.nodes} edges={laidOut.edges} />

                            {/* the WBS tree still draws with no relations, so the CTA moved below the canvas */}
                            {relations.length === 0 && checkAccess([2405]) && (
                                <div className="mt-4 flex flex-col items-center gap-3">
                                    <Empty text="هنوز رابطه‌ای بین تسک‌های این پروژه ثبت نشده است" />
                                    <Button className="w-auto!" onClick={() => navigate(`/task-relation/create?project_id=${projectId}`)}>
                                        ثبت رابطه جدید
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
