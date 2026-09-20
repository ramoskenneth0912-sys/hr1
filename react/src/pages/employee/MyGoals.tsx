// @ts-nocheck - legacy ESS module (was never type-checked before TS arrived).
// src/app/pages/employee/MyGoals.tsx
import { useEffect, useState } from "react";
import {
  Target, RefreshCw, CheckCircle, Clock, Loader2, Save, StickyNote,
  CheckCircle as CheckIcon, TriangleAlert,
} from "lucide-react";
import { V, TX, TX2, BD, SUCCESS, INFO, DANGER } from "../../lib/constants";
import { getEssList, essUpdate } from "../../lib/essApi";
import {
  Card, PageHeader, SectionHead, TableBase, TableRow, TdSub, Td,
  StatusBadge, Counter, GhostBtn, PrimaryBtn, SecondaryBtn, Modal, Toast,
} from "../../components/shared-ui";

type GoalStatus = "not_started" | "in_progress" | "completed" | "overdue" | "cancelled";
type GoalPriority = "low" | "medium" | "high";

interface Goal {
  id: number;
  title: string;
  description: string | null;
  target: string | null;
  category: string | null;
  start_date: string | null;
  due_date: string | null;
  progress: number;
  priority: GoalPriority;
  status: GoalStatus;
  notes: string | null;
  updated_at: string;
  editable: boolean;
}

type BadgeVariant = "success" | "warning" | "danger" | "info" | "secondary" | "purple";

const STATUS_LABEL: Record<GoalStatus, string> = {
  not_started: "Not Started",
  in_progress: "In Progress",
  completed: "Completed",
  overdue: "Overdue",
  cancelled: "Cancelled",
};

const STATUS_VARIANT: Record<GoalStatus, BadgeVariant> = {
  not_started: "secondary",
  in_progress: "info",
  completed: "success",
  overdue: "danger",
  cancelled: "secondary",
};

const PRIORITY_LABEL: Record<GoalPriority, string> = {
  low: "Low",
  medium: "Medium",
  high: "High",
};

const PRIORITY_VARIANT: Record<GoalPriority, BadgeVariant> = {
  low: "secondary",
  medium: "warning",
  high: "danger",
};

const fmtDate = (d: string | null) => {
  if (!d) return "—";
  const [y, m, day] = d.split("-");
  const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  return `${months[parseInt(m, 10) - 1]} ${parseInt(day, 10)}, ${y}`;
};

export default function MyGoals() {
  const [goals, setGoals] = useState<Goal[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [savingId, setSavingId] = useState<number | null>(null);
  const [progressInputs, setProgressInputs] = useState<Record<number, string>>({});
  const [notesGoal, setNotesGoal] = useState<Goal | null>(null);
  const [notesDraft, setNotesDraft] = useState("");
  const [toast, setToast] = useState<{ message: string; type: "success" | "error" } | null>(null);

  const loadGoals = async () => {
    setLoading(true);
    setError("");
    try {
      const list = await getEssList("/employee/goals");
      setGoals(Array.isArray(list) ? list : []);
    } catch (err) {
      console.error("My Goals load error:", err);
      setError("Unable to load your goals. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadGoals();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 3200);
    return () => clearTimeout(t);
  }, [toast]);

  const updateGoal = async (id: number, patch: Partial<Goal>, successMsg: string) => {
    setSavingId(id);
    try {
      await essUpdate(`/employee/goals/${id}`, patch);
      setToast({ message: successMsg, type: "success" });
      setProgressInputs((p) => { const c = { ...p }; delete c[id]; return c; });
      await loadGoals();
    } catch (err) {
      console.error("Goal update error:", err);
      setToast({
        message: (err as Error).message || "Unable to update your goal. Please try again.",
        type: "error",
      });
    } finally {
      setSavingId(null);
    }
  };

  const saveProgress = (goal: Goal) => {
    const raw = (progressInputs[goal.id] ?? "").trim();
    if (!/^\d+$/.test(raw)) {
      setToast({ message: "Progress must be a whole number between 0 and 100.", type: "error" });
      return;
    }
    const v = parseInt(raw, 10);
    if (v < 0 || v > 100) {
      setToast({ message: "Progress must be between 0 and 100.", type: "error" });
      return;
    }
    updateGoal(goal.id, { progress: v }, "Progress updated.");
  };

  const markComplete = (goal: Goal) => {
    updateGoal(goal.id, { status: "completed", progress: 100 }, "Goal marked as completed.");
  };

  const openNotes = (goal: Goal) => {
    setNotesDraft(goal.notes ?? "");
    setNotesGoal(goal);
  };

  const saveNotes = () => {
    if (!notesGoal) return;
    updateGoal(notesGoal.id, { notes: notesDraft }, "Notes saved.");
    setNotesGoal(null);
  };

  const inProgress = goals.filter((g) => g.status === "in_progress").length;
  const completed = goals.filter((g) => g.status === "completed").length;

  return (
    <div className="space-y-6">
      <PageHeader title="My Goals" subtitle="Track progress on your assigned goals" />

      <div className="grid grid-cols-3 gap-4">
        {[
          { label: "Total Goals", value: goals.length, icon: Target, iconBg: "#EDE9FE", iconColor: V },
          { label: "In Progress", value: inProgress, icon: RefreshCw, iconBg: "#EFF6FF", iconColor: INFO },
          { label: "Completed", value: completed, icon: CheckCircle, iconBg: "#F0FDF4", iconColor: SUCCESS },
        ].map((s) => (
          <Card key={s.label}>
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ background: s.iconBg }}>
                <s.icon size={18} style={{ color: s.iconColor }} />
              </div>
              <div>
                <div className="text-xl font-bold" style={{ color: TX }}><Counter value={s.value} /></div>
                <div className="text-xs" style={{ color: TX2 }}>{s.label}</div>
              </div>
            </div>
          </Card>
        ))}
      </div>

      <Card p={false}>
        <div className="p-6 border-b" style={{ borderColor: BD }}>
          <SectionHead title="My Assigned Goals" subtitle="Update your own progress; HR handles new goal creation" />
        </div>

        {loading ? (
          <div className="p-12">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-violet-600 mx-auto"></div>
          </div>
        ) : error ? (
          <div className="p-8">
            <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
              <TriangleAlert size={18} className="mt-0.5 shrink-0" style={{ color: DANGER }} />
              <div className="flex-1">
                <p className="text-sm font-semibold" style={{ color: TX }}>Something went wrong</p>
                <p className="text-sm" style={{ color: TX2 }}>{error}</p>
                <div className="mt-3">
                  <SecondaryBtn onClick={loadGoals}>Retry</SecondaryBtn>
                </div>
              </div>
            </div>
          </div>
        ) : goals.length === 0 ? (
          <div className="p-8 text-center">
            <Clock size={24} className="mx-auto mb-2" style={{ color: TX2 }} />
            <p className="text-sm" style={{ color: TX2 }}>No goals available yet.</p>
          </div>
        ) : (
          <TableBase headers={["Goal", "Category", "Start", "Due", "Progress", "Priority", "Status", "Manage"]}>
            {goals.map((g, i) => (
              <TableRow key={g.id} last={i === goals.length - 1}>
                <td className="px-5 py-3.5">
                  <div className="text-sm font-semibold" style={{ color: TX }}>{g.title}</div>
                  {(g.description || g.target) && (
                    <TdSub>{g.target ? `Target: ${g.target}` : g.description}</TdSub>
                  )}
                </td>
                <Td>{g.category || "—"}</Td>
                <TdSub>{fmtDate(g.start_date)}</TdSub>
                <TdSub style={g.status === "overdue" ? { color: DANGER } : undefined}>
                  {fmtDate(g.due_date)}
                </TdSub>
                <Td>
                  <div style={{ display: "flex", alignItems: "center", gap: 8, minWidth: 110 }}>
                    <div style={{ flex: 1, height: 6, borderRadius: 999, background: BD, overflow: "hidden" }}>
                      <div
                        style={{
                          height: "100%",
                          borderRadius: 999,
                          background: g.progress >= 100 ? SUCCESS : V,
                          width: `${g.progress}%`,
                        }}
                      />
                    </div>
                    <span className="text-xs font-semibold" style={{ color: TX2, width: 34, textAlign: "right" }}>
                      {g.progress}%
                    </span>
                  </div>
                </Td>
                <Td><StatusBadge label={PRIORITY_LABEL[g.priority] || g.priority} variant={PRIORITY_VARIANT[g.priority] || "secondary"} /></Td>
                <Td><StatusBadge label={STATUS_LABEL[g.status] || g.status} variant={STATUS_VARIANT[g.status] || "secondary"} /></Td>
                <td className="px-5 py-3.5">
                  {g.editable ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 6, flexWrap: "wrap" }}>
                      <input
                        type="number"
                        min={0}
                        max={100}
                        value={progressInputs[g.id] ?? String(g.progress)}
                        onChange={(e) => setProgressInputs({ ...progressInputs, [g.id]: e.target.value })}
                        disabled={savingId === g.id}
                        aria-label={`Progress for ${g.title}`}
                        className="w-20 rounded border px-2 py-1 text-sm"
                        style={{ borderColor: BD, color: TX }}
                      />
                      <GhostBtn icon={Save} onClick={() => saveProgress(g)} disabled={savingId === g.id}>Save</GhostBtn>
                      <GhostBtn icon={CheckIcon} onClick={() => markComplete(g)} disabled={savingId === g.id}>Complete</GhostBtn>
                      <GhostBtn icon={StickyNote} onClick={() => openNotes(g)} disabled={savingId === g.id}>Notes</GhostBtn>
                    </div>
                  ) : (
                    <span className="text-xs" style={{ color: TX2 }}>Finalized</span>
                  )}
                </td>
              </TableRow>
            ))}
          </TableBase>
        )}
      </Card>

      <Modal open={notesGoal !== null} onClose={() => setNotesGoal(null)} title={`Notes — ${notesGoal?.title ?? ""}`}>
        <textarea
          rows={6}
          value={notesDraft}
          onChange={(e) => setNotesDraft(e.target.value)}
          placeholder="Add a note about your progress on this goal..."
          className="w-full rounded border px-3 py-2 text-sm"
          style={{ borderColor: BD, color: TX, resize: "vertical" }}
        />
        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 12 }}>
          <SecondaryBtn onClick={() => setNotesGoal(null)}>Cancel</SecondaryBtn>
          <PrimaryBtn onClick={saveNotes} disabled={savingId === notesGoal?.id}>Save Notes</PrimaryBtn>
        </div>
      </Modal>

      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}