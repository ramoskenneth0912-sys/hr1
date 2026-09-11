import { useEffect, useMemo, useState } from "react";
import { Award, CheckCircle2, ClipboardCheck, Compass, Plus, Save, Target, TriangleAlert } from "lucide-react";
import { essAction, essUpdate, getEssList } from "../../lib/essApi";
import { Card, PageHeader, SectionHead, StatusBadge } from "../../components/shared-ui";
import { BD, DANGER, SUCCESS, TX, TX2, V } from "../../lib/constants";

type GoalStatus = "not_started" | "in_progress" | "completed" | "overdue" | "cancelled";
type ActivityStatus = "not_started" | "in_progress" | "completed" | "cancelled";
type PlanStatus = "draft" | "in_progress" | "completed" | "cancelled";

interface Goal { id: number; title: string; status: GoalStatus; progress: number; due_date: string | null; }
interface Review { id: number; status: string; period: { name: string; period_type: string } | null; updated_at: string; }
interface Competency { id: number; competency_name: string; met: boolean | null; }
interface Activity { id: number; title: string; description: string | null; target_date: string | null; status: ActivityStatus; progress: number; notes: string | null; }
interface Plan { id: number; title: string; focus_area: string | null; description: string | null; target_date: string | null; status: PlanStatus; progress: number; notes: string | null; activities: Activity[]; }
interface PlanDraft { title: string; focus_area: string; description: string; target_date: string; notes: string; }

const REVIEW_STATUS_LABEL: Record<string, string> = { drafted: "Draft", assigned: "Assigned", self_assessment: "Self Assessment", manager_review: "Manager Review", finalized: "Finalized", acknowledged: "Acknowledged" };
const emptyPlan = { title: "", focus_area: "", description: "", target_date: "" };
const emptyActivity = { title: "", description: "", target_date: "" };

function formatDate(value: string | null) {
  if (!value) return "No target date";
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? "No target date" : date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}
function label(value: string) { return value.replaceAll("_", " "); }
function reviewLabel(value: string) { return REVIEW_STATUS_LABEL[value] ?? label(value); }
function statusVariant(status: string): "success" | "warning" | "danger" | "info" | "secondary" | "purple" {
  if (["completed", "finalized", "acknowledged"].includes(status)) return "success";
  if (["cancelled", "overdue"].includes(status)) return "danger";
  if (status === "manager_review") return "purple";
  if (["in_progress", "assigned"].includes(status)) return "info";
  if (status === "self_assessment") return "warning";
  return "secondary";
}
function clampProgress(value: string) {
  const number = Number(value);
  return Number.isFinite(number) ? Math.min(100, Math.max(0, Math.round(number))) : 0;
}
function toDraft(plan: Plan): PlanDraft {
  return { title: plan.title, focus_area: plan.focus_area ?? "", description: plan.description ?? "", target_date: plan.target_date ?? "", notes: plan.notes ?? "" };
}

export default function MyDevelopment() {
  const [summary, setSummary] = useState({ goals: [] as Goal[], reviews: [] as Review[], competencies: [] as Competency[] });
  const [plans, setPlans] = useState<Plan[]>([]);
  const [drafts, setDrafts] = useState<Record<number, PlanDraft>>({});
  const [activityForms, setActivityForms] = useState<Record<number, typeof emptyActivity>>({});
  const [planForm, setPlanForm] = useState(emptyPlan);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const loadData = async () => {
    setLoading(true);
    setError("");
    try {
      const [goals, reviews, competencies, development] = await Promise.all([
        getEssList("/employee/goals"), getEssList("/employee/performance"), getEssList("/employee/competencies"), getEssList("/employee/development"),
      ]);
      const nextPlans = Array.isArray(development) ? development as Plan[] : [];
      setSummary({ goals: Array.isArray(goals) ? goals : [], reviews: Array.isArray(reviews) ? reviews : [], competencies: Array.isArray(competencies) ? competencies : [] });
      setPlans(nextPlans);
      setDrafts(Object.fromEntries(nextPlans.map((plan) => [plan.id, toDraft(plan)])));
    } catch (err) {
      console.error("My Development load error:", err);
      setError("Unable to load your development information. Please try again.");
    } finally { setLoading(false); }
  };
  useEffect(() => { loadData(); }, []);

  const stats = useMemo(() => {
    const latestReview = [...summary.reviews].sort((a, b) => (b.updated_at || "").localeCompare(a.updated_at || ""))[0] ?? null;
    return {
      completedGoals: summary.goals.filter((goal) => goal.status === "completed").length,
      competencyGaps: summary.competencies.filter((competency) => competency.met !== true).length,
      latestReview,
      goalProgress: summary.goals.length ? Math.round(summary.goals.reduce((total, goal) => total + (Number(goal.progress) || 0), 0) / summary.goals.length) : 0,
    };
  }, [summary]);

  const submitPlan = async (event: React.FormEvent) => {
    event.preventDefault(); setSaving(true); setError(""); setNotice("");
    try {
      const response = await essAction("/employee/development", planForm);
      if (!response.success) { setError(response.message || "Unable to create your development plan."); return; }
      setPlanForm(emptyPlan); setNotice("Development plan created."); await loadData();
    } catch (err) { console.error("Development plan create error:", err); setError((err as Error).message || "Unable to create your development plan."); }
    finally { setSaving(false); }
  };
  const updatePlan = async (plan: Plan, patch: Partial<Plan>) => {
    setSaving(true); setError(""); setNotice("");
    try { await essUpdate(`/employee/development/${plan.id}`, patch); setNotice("Development plan updated."); await loadData(); }
    catch (err) { console.error("Development plan update error:", err); setError((err as Error).message || "Unable to update your development plan."); }
    finally { setSaving(false); }
  };
  const addActivity = async (plan: Plan, event: React.FormEvent) => {
    event.preventDefault(); setSaving(true); setError(""); setNotice("");
    try {
      const response = await essAction(`/employee/development/${plan.id}/activities`, activityForms[plan.id] ?? emptyActivity);
      if (!response.success) { setError(response.message || "Unable to add the development activity."); return; }
      setActivityForms((current) => ({ ...current, [plan.id]: emptyActivity })); setNotice("Development activity added."); await loadData();
    } catch (err) { console.error("Development activity create error:", err); setError((err as Error).message || "Unable to add the development activity."); }
    finally { setSaving(false); }
  };
  const updateActivity = async (plan: Plan, activity: Activity, patch: Partial<Activity>) => {
    setSaving(true); setError(""); setNotice("");
    try { await essUpdate(`/employee/development/${plan.id}/activities/${activity.id}`, patch); setNotice("Development activity updated."); await loadData(); }
    catch (err) { console.error("Development activity update error:", err); setError((err as Error).message || "Unable to update the development activity."); }
    finally { setSaving(false); }
  };

  return <div className="space-y-6">
    <PageHeader title="My Development" subtitle="Plan development actions and use your ESS records to guide your growth" />
    {notice && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</div>}
    {error && <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4"><TriangleAlert size={18} className="mt-0.5 shrink-0" style={{ color: DANGER }} /><p className="text-sm text-red-800">{error}</p></div>}
    {loading ? <Card><div className="flex items-center justify-center gap-3 py-10 text-sm" style={{ color: TX2 }}><span className="h-5 w-5 animate-spin rounded-full border-2 border-violet-200 border-t-violet-600" />Loading your development information…</div></Card> : <>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card><div className="flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50"><Target size={18} style={{ color: V }} /></div><div><div className="text-xl font-bold" style={{ color: TX }}>{stats.goalProgress}%</div><div className="text-xs" style={{ color: TX2 }}>Goal progress</div></div></div><p className="mt-3 text-xs" style={{ color: TX2 }}>{stats.completedGoals} of {summary.goals.length} assigned goals completed</p></Card>
        <Card><div className="flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50"><Award size={18} style={{ color: "#d97706" }} /></div><div><div className="text-xl font-bold" style={{ color: TX }}>{stats.competencyGaps}</div><div className="text-xs" style={{ color: TX2 }}>Competency gaps</div></div></div><p className="mt-3 text-xs" style={{ color: TX2 }}>Based on current competency assignments</p></Card>
        <Card><div className="flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50"><ClipboardCheck size={18} style={{ color: "#2563eb" }} /></div><div><div className="text-xl font-bold" style={{ color: TX }}>{summary.reviews.length}</div><div className="text-xs" style={{ color: TX2 }}>Performance reviews</div></div></div><p className="mt-3 text-xs" style={{ color: TX2 }}>{stats.latestReview ? `Latest: ${reviewLabel(stats.latestReview.status)}` : "No performance reviews are available yet."}</p></Card>
      </div>

      <Card p={false}><div className="border-b p-6" style={{ borderColor: BD }}><SectionHead title="Development overview" subtitle="Read-only context from your Goals, Performance, and Competencies records" /></div><div className="grid gap-6 p-6 lg:grid-cols-2">
        <div><div className="mb-3 flex items-center gap-2"><Compass size={17} style={{ color: V }} /><h3 className="text-sm font-semibold" style={{ color: TX }}>Current focus</h3></div>{summary.goals.length === 0 ? <p className="text-sm" style={{ color: TX2 }}>No assigned goals are available yet.</p> : <div className="space-y-3">{summary.goals.slice(0, 3).map((goal) => <div key={goal.id} className="rounded-xl border p-3" style={{ borderColor: BD }}><div className="flex items-start justify-between gap-3"><div><p className="text-sm font-medium" style={{ color: TX }}>{goal.title}</p><p className="mt-1 text-xs" style={{ color: TX2 }}>Target {formatDate(goal.due_date)}</p></div><StatusBadge label={label(goal.status)} variant={statusVariant(goal.status)} /></div></div>)}</div>}</div>
        <div><div className="mb-3 flex items-center gap-2"><CheckCircle2 size={17} style={{ color: SUCCESS }} /><h3 className="text-sm font-semibold" style={{ color: TX }}>Development signals</h3></div><div className="space-y-3">{stats.latestReview && <div className="flex items-center justify-between rounded-xl border p-3" style={{ borderColor: BD }}><div><p className="text-sm font-medium" style={{ color: TX }}>{stats.latestReview.period?.name ?? "Latest performance review"}</p><p className="mt-1 text-xs" style={{ color: TX2 }}>Performance review status</p></div><StatusBadge label={reviewLabel(stats.latestReview.status)} variant={statusVariant(stats.latestReview.status)} /></div>}<div className="flex items-center justify-between rounded-xl border p-3" style={{ borderColor: BD }}><div><p className="text-sm font-medium" style={{ color: TX }}>Competency assignments</p><p className="mt-1 text-xs" style={{ color: TX2 }}>Requirements managed in My Competencies</p></div><span className="text-sm font-semibold" style={{ color: TX }}>{summary.competencies.length}</span></div></div></div>
      </div></Card>

      <Card p={false}><div className="border-b p-6" style={{ borderColor: BD }}><SectionHead title="My Development Plans" subtitle="Create employee-owned objectives and keep roadmap actions current" /></div>
        <form onSubmit={submitPlan} className="grid gap-4 p-6 md:grid-cols-2">
          <label className="text-xs font-medium" style={{ color: TX2 }}>Development objective<input required maxLength={160} value={planForm.title} onChange={(event) => setPlanForm({ ...planForm, title: event.target.value })} placeholder="What capability do you want to build?" className="mt-1 block w-full rounded-xl border px-3 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
          <label className="text-xs font-medium" style={{ color: TX2 }}>Focus area <span className="font-normal">(optional)</span><input maxLength={80} value={planForm.focus_area} onChange={(event) => setPlanForm({ ...planForm, focus_area: event.target.value })} placeholder="For example, presentation skills" className="mt-1 block w-full rounded-xl border px-3 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
          <label className="text-xs font-medium md:col-span-2" style={{ color: TX2 }}>Description <span className="font-normal">— what success should look like</span><textarea maxLength={4000} value={planForm.description} onChange={(event) => setPlanForm({ ...planForm, description: event.target.value })} placeholder="Describe the development outcome you are working toward." className="mt-1 min-h-20 w-full rounded-xl border px-3 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
          <label className="text-xs font-medium" style={{ color: TX2 }}>Target date <span className="font-normal">— when you aim to reach it</span><input type="date" value={planForm.target_date} onChange={(event) => setPlanForm({ ...planForm, target_date: event.target.value })} className="mt-1 block w-full rounded-xl border px-3 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
          <button type="submit" disabled={saving} className="inline-flex items-center justify-center gap-2 self-end rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><Plus size={16} />Create development plan</button>
        </form>
        {plans.length === 0 ? <div className="border-t" style={{ borderColor: BD }}><div className="p-10 text-center"><p className="text-sm font-medium" style={{ color: TX }}>No development plan yet</p><p className="mt-2 text-sm" style={{ color: TX2 }}>Create an objective above to start your development roadmap.</p></div><div className="border-t p-6" style={{ borderColor: BD }}><h3 className="text-sm font-semibold" style={{ color: TX }}>Development roadmap</h3><p className="mt-1 text-xs" style={{ color: TX2 }}>Activities and action steps will appear here after you create a development plan.</p><p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm" style={{ color: TX2 }}>No development activities yet.</p></div></div> : <div className="space-y-4 border-t p-6" style={{ borderColor: BD }}>{plans.map((plan) => {
          const draft = drafts[plan.id] ?? toDraft(plan);
          const activityForm = activityForms[plan.id] ?? emptyActivity;
          return <div key={plan.id} className="rounded-2xl border p-5" style={{ borderColor: BD }}>
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"><div><h3 className="text-base font-semibold" style={{ color: TX }}>{plan.title}</h3><p className="mt-1 text-sm" style={{ color: TX2 }}>{plan.description || "No description provided."}</p><p className="mt-2 text-xs" style={{ color: TX2 }}>{plan.focus_area || "General development"} · Target {formatDate(plan.target_date)}</p></div><StatusBadge label={label(plan.status)} variant={statusVariant(plan.status)} /></div>
            <div className="mt-5"><div className="mb-2 flex items-center justify-between text-xs"><span style={{ color: TX2 }}>Plan progress</span><strong style={{ color: TX }}>{plan.progress}%</strong></div><div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-violet-500 transition-all" style={{ width: `${plan.progress}%` }} /></div></div>
            <div className="mt-5 grid gap-3 border-t pt-4 md:grid-cols-2" style={{ borderColor: BD }}>
              <label className="text-xs font-medium" style={{ color: TX2 }}>Objective<input maxLength={160} value={draft.title} onChange={(event) => setDrafts({ ...drafts, [plan.id]: { ...draft, title: event.target.value } })} className="mt-1 block w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
              <label className="text-xs font-medium" style={{ color: TX2 }}>Focus area<input maxLength={80} value={draft.focus_area} onChange={(event) => setDrafts({ ...drafts, [plan.id]: { ...draft, focus_area: event.target.value } })} className="mt-1 block w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
              <label className="text-xs font-medium md:col-span-2" style={{ color: TX2 }}>Description<textarea maxLength={4000} value={draft.description} onChange={(event) => setDrafts({ ...drafts, [plan.id]: { ...draft, description: event.target.value } })} className="mt-1 min-h-16 w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
              <label className="text-xs font-medium" style={{ color: TX2 }}>Target date<input type="date" value={draft.target_date} onChange={(event) => setDrafts({ ...drafts, [plan.id]: { ...draft, target_date: event.target.value } })} className="mt-1 block w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
              <label className="text-xs font-medium" style={{ color: TX2 }}>Status<select value={plan.status} onChange={(event) => updatePlan(plan, { status: event.target.value as PlanStatus })} className="mt-1 block w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }}><option value="draft">Draft</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></label>
              <label className="text-xs font-medium" style={{ color: TX2 }}>Progress <span className="font-normal">(0–100)</span><input type="number" min="0" max="100" value={plan.progress} onChange={(event) => updatePlan(plan, { progress: clampProgress(event.target.value) })} className="mt-1 block w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
              <label className="text-xs font-medium" style={{ color: TX2 }}>Notes <span className="font-normal">(optional)</span><input maxLength={3000} value={draft.notes} onChange={(event) => setDrafts({ ...drafts, [plan.id]: { ...draft, notes: event.target.value } })} className="mt-1 block w-full rounded-lg border px-2 py-2 text-sm font-normal text-slate-700" style={{ borderColor: BD }} /></label>
              <button type="button" disabled={saving} onClick={() => updatePlan(plan, draft)} className="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold text-violet-700 disabled:opacity-50 md:col-span-2" style={{ borderColor: BD }}><Save size={15} />Save plan changes</button>
            </div>
            <div className="mt-5 border-t pt-4" style={{ borderColor: BD }}><div className="flex items-center justify-between gap-3"><div><h4 className="text-sm font-semibold" style={{ color: TX }}>Development roadmap</h4><p className="mt-1 text-xs" style={{ color: TX2 }}>Turn the objective into practical action steps.</p></div><span className="text-xs font-medium" style={{ color: TX2 }}>{plan.activities.length} {plan.activities.length === 1 ? "step" : "steps"}</span></div>
              {plan.activities.length === 0 ? <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm" style={{ color: TX2 }}>No development activities yet. Add the first action step below.</p> : <div className="mt-3 space-y-3">{plan.activities.map((activity) => <div key={activity.id} className="rounded-xl bg-slate-50 p-3"><div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"><div><p className="text-sm font-medium" style={{ color: TX }}>{activity.title}</p><p className="mt-1 text-xs" style={{ color: TX2 }}>{activity.description || "No description provided."} · Target {formatDate(activity.target_date)}</p></div><StatusBadge label={label(activity.status)} variant={statusVariant(activity.status)} /></div><div className="mt-3 flex flex-wrap items-center gap-3"><div className="h-2 min-w-32 flex-1 overflow-hidden rounded-full bg-white"><div className="h-full rounded-full bg-violet-400" style={{ width: `${activity.progress}%` }} /></div><label className="text-xs" style={{ color: TX2 }}>Progress <input type="number" min="0" max="100" defaultValue={activity.progress} onBlur={(event) => updateActivity(plan, activity, { progress: clampProgress(event.target.value) })} className="ml-1 w-16 rounded-lg border px-2 py-1 text-xs text-slate-700" style={{ borderColor: BD }} />%</label><select value={activity.status} onChange={(event) => updateActivity(plan, activity, { status: event.target.value as ActivityStatus })} className="rounded-lg border px-2 py-1 text-xs text-slate-700" style={{ borderColor: BD }}><option value="not_started">Not started</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div></div>)}</div>}
              <form onSubmit={(event) => addActivity(plan, event)} className="mt-4 grid gap-2 md:grid-cols-[1fr_1fr_10rem_auto]"><input required maxLength={160} value={activityForm.title} onChange={(event) => setActivityForms({ ...activityForms, [plan.id]: { ...activityForm, title: event.target.value } })} placeholder="Action step" className="rounded-xl border px-3 py-2 text-sm" style={{ borderColor: BD }} /><input maxLength={4000} value={activityForm.description} onChange={(event) => setActivityForms({ ...activityForms, [plan.id]: { ...activityForm, description: event.target.value } })} placeholder="What will you do?" className="rounded-xl border px-3 py-2 text-sm" style={{ borderColor: BD }} /><input value={activityForm.target_date} onChange={(event) => setActivityForms({ ...activityForms, [plan.id]: { ...activityForm, target_date: event.target.value } })} type="date" className="rounded-xl border px-3 py-2 text-sm" style={{ borderColor: BD }} /><button type="submit" disabled={saving} className="inline-flex items-center justify-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold text-violet-700 disabled:opacity-50" style={{ borderColor: BD }}><Plus size={15} />Add step</button></form>
            </div>
          </div>;
        })}</div>}
      </Card>
      <Card><SectionHead title="Training and learning" subtitle="Kept separate from development planning" /><p className="mt-3 text-sm leading-6" style={{ color: TX2 }}>Training assignments and learning resources remain managed by their authoritative modules. They are not duplicated in your development plan.</p></Card>
    </>}
  </div>;
}
