// src/app/pages/employee/MyCompetencies.tsx
import { useEffect, useState, useMemo } from "react";
import { Layers, CheckCircle2, AlertTriangle, Search, Award, Send } from "lucide-react";
import { V, TX, TX2, BD } from "../../lib/constants";
import { getEssList } from "../../lib/essApi";
import { Card, PageHeader, SectionHead, GapBar, Toast, Modal } from "../../components/shared-ui";

interface CompetencyItem {
  id: number;
  competency_id: number;
  competency_name: string;
  description: string | null;
  category: string | null;
  required_level: number;
  required_level_label: string | null;
  current_level: number | null;
  current_level_label: string | null;
  evaluated: boolean;
  met: boolean | null;
  gap: number | null;
  status: string;
  notes: string | null;
  evaluator_name: string | null;
  evaluated_at: string | null;
}

type FilterTab = "all" | "met" | "gap";

export default function MyCompetencies() {
  const [competencies, setCompetencies] = useState<CompetencyItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [searchTerm, setSearchTerm] = useState("");
  const [filterTab, setFilterTab] = useState<FilterTab>("all");
  const [toast, setToast] = useState<{ message: string; type: "success" | "error" | "info" } | null>(null);
  const [selectedCompetency, setSelectedCompetency] = useState<string | null>(null);
  const [requestNotes, setRequestNotes] = useState("");

  const loadCompetencies = async () => {
    setLoading(true);
    setError("");
    try {
      const list = await getEssList("employee/competencies");
      setCompetencies(Array.isArray(list) ? list : []);
    } catch (err) {
      console.error("My Competencies load error:", err);
      setError("Unable to load your competencies. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadCompetencies();
  }, []);

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 3200);
    return () => clearTimeout(t);
  }, [toast]);

  const stats = useMemo(() => {
    const total = competencies.length;
    if (total === 0) return { total: 0, met: 0, gaps: 0, matchRate: 0 };
    const met = competencies.filter((c) => c.met === true).length;
    const gaps = total - met;
    const matchRate = total > 0 ? Math.round((met / total) * 100) : 0;
    return { total, met, gaps, matchRate };
  }, [competencies]);

  const filtered = useMemo(() => {
    return competencies.filter((item) => {
      const matchesSearch =
        !searchTerm ||
        item.competency_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (item.category ?? "").toLowerCase().includes(searchTerm.toLowerCase());
      if (!matchesSearch) return false;
      if (filterTab === "met") return item.met === true;
      if (filterTab === "gap") return item.met !== true;
      return true;
    });
  }, [competencies, searchTerm, filterTab]);

  const handleRequestSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedCompetency) return;
    setToast({
      message: `Re-evaluation requests are not yet connected. Please contact HR directly to review "${selectedCompetency}".`,
      type: "info",
    });
    setSelectedCompetency(null);
    setRequestNotes("");
  };

  const toastEl = toast ? (
    <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />
  ) : null;

  return (
    <div className="space-y-6">
      {toastEl}

      <PageHeader
        title="My Competencies"
        subtitle="Your competency profile and evaluation tracking"
      />

      {/* Summary KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: "Overall Match", value: `${stats.matchRate}%`, icon: Award, bg: "#F3E8FF", color: V },
          { label: "Total Assigned", value: stats.total, icon: Layers, bg: "#EDE9FE", color: V },
          { label: "Requirements Met", value: stats.met, icon: CheckCircle2, bg: "#F0FDF4", color: "#05CD99" },
          { label: "Action Needed", value: stats.gaps, icon: AlertTriangle, bg: "#FFF7ED", color: "#FFB547" },
        ].map((s) => (
          <Card key={s.label}>
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style={{ background: s.bg }}>
                <s.icon size={18} style={{ color: s.color }} />
              </div>
              <div>
                <div className="text-xl font-bold" style={{ color: TX }}>
                  {s.value}
                </div>
                <div className="text-xs" style={{ color: TX2 }}>{s.label}</div>
              </div>
            </div>
          </Card>
        ))}
      </div>

      <Card p={false}>
        <div className="p-6 border-b flex flex-col md:flex-row md:items-center justify-between gap-4" style={{ borderColor: BD }}>
          <div>
            <SectionHead title="Competency Breakdown" subtitle="Target levels required for your position" />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
              <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: TX2 }} />
              <input
                type="text"
                placeholder="Search competency or category..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="pl-9 pr-3 py-2 text-xs rounded-xl bg-white border focus:outline-none transition-colors w-56 shadow-sm"
                style={{ borderColor: BD, color: TX }}
              />
            </div>
            <div className="flex bg-slate-100 p-1 rounded-xl border" style={{ borderColor: BD }}>
              <button
                onClick={() => setFilterTab("all")}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all ${
                  filterTab === "all" ? "bg-white text-slate-900 shadow-sm" : "text-slate-600 hover:text-slate-900"
                }`}
              >
                All ({stats.total})
              </button>
              <button
                onClick={() => setFilterTab("met")}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all ${
                  filterTab === "met" ? "bg-white text-emerald-700 shadow-sm" : "text-slate-600 hover:text-slate-900"
                }`}
              >
                Met ({stats.met})
              </button>
              <button
                onClick={() => setFilterTab("gap")}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all ${
                  filterTab === "gap" ? "bg-white text-amber-700 shadow-sm" : "text-slate-600 hover:text-slate-900"
                }`}
              >
                Action Needed ({stats.gaps})
              </button>
            </div>
          </div>
        </div>

        {loading ? (
          <div className="p-12">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-violet-600 mx-auto"></div>
          </div>
        ) : error ? (
          <div className="p-8">
            <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
              <AlertTriangle size={18} className="mt-0.5 shrink-0" style={{ color: "#EE5D50" }} />
              <div className="flex-1">
                <p className="text-sm font-semibold" style={{ color: TX }}>Something went wrong</p>
                <p className="text-sm" style={{ color: TX2 }}>{error}</p>
                <div className="mt-3">
                  <button
                    onClick={loadCompetencies}
                    className="px-4 py-2 text-xs font-semibold rounded-xl border bg-white hover:bg-slate-50 transition-colors"
                    style={{ borderColor: BD, color: TX2 }}
                  >
                    Retry
                  </button>
                </div>
              </div>
            </div>
          </div>
        ) : competencies.length === 0 ? (
          <div className="p-10">
            <div className="text-center py-8 px-4">
              <div
                className="mx-auto mb-3 flex items-center justify-center rounded-full"
                style={{ width: 48, height: 48, background: BD }}
              >
                <Layers size={22} style={{ color: TX2 }} />
              </div>
              <p className="text-sm font-medium mb-1" style={{ color: TX }}>No competency assessments found</p>
              <p className="text-xs max-w-sm mx-auto" style={{ color: TX2 }}>
                Your competencies will appear here once HR has assigned them.
              </p>
            </div>
          </div>
        ) : (
          <div className="p-6 space-y-4">
            {filtered.length === 0 ? (
              <div className="text-center py-12">
                <Layers size={32} className="mx-auto mb-3 opacity-40" style={{ color: TX2 }} />
                <p className="text-sm font-medium" style={{ color: TX }}>No competency assessments found.</p>
                <p className="text-xs mt-1" style={{ color: TX2 }}>Try adjusting your search or filter.</p>
              </div>
            ) : (
              filtered.map((a) => {
                const isMet = a.met === true;

                return (
                  <div
                    key={a.id}
                    className="p-4 rounded-xl border transition-all hover:border-purple-300 bg-slate-50/50"
                    style={{ borderColor: BD }}
                  >
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
                      <div className="flex items-center gap-2.5 flex-wrap">
                        <span className="text-sm font-bold" style={{ color: TX }}>{a.competency_name}</span>
                        {a.category ? (
                          <span className="inline-flex text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                            {a.category}
                          </span>
                        ) : null}
                        {a.evaluated ? (
                          isMet ? (
                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                              <CheckCircle2 size={12} /> Target Met
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                              <AlertTriangle size={12} /> Gap: -{a.gap ?? 0} Level
                            </span>
                          )
                        ) : (
                          <span className="inline-flex text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                            Pending Evaluation
                          </span>
                        )}
                      </div>

                      <div className="flex items-center gap-3">
                        <span className="text-xs font-medium" style={{ color: TX2 }}>
                          Current: <strong style={{ color: TX }}>{a.current_level ?? "—"}</strong> / Target: <strong style={{ color: TX }}>{a.required_level}</strong>
                        </span>
                        {a.required_level_label ? (
                          <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-purple-50 text-slate-700 border border-purple-200">
                            {a.required_level_label}
                          </span>
                        ) : null}
                        {!isMet ? (
                          <button
                            onClick={() => setSelectedCompetency(a.competency_name)}
                            className="text-xs px-3 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-slate-700 font-semibold border border-purple-200 transition-all flex items-center gap-1.5 shadow-sm"
                          >
                            <Send size={12} /> Request Review
                          </button>
                        ) : null}
                      </div>
                    </div>

                    <GapBar current={a.current_level ?? 0} required={a.required_level} />

                    {a.evaluated_at ? (
                      <p className="mt-2 text-[11px]" style={{ color: TX2 }}>
                        Last evaluated:{" "}
                        {new Date(a.evaluated_at).toLocaleDateString("en-US", {
                          year: "numeric",
                          month: "short",
                          day: "numeric",
                        })}
                        {a.evaluator_name ? ` by ${a.evaluator_name}` : ""}
                      </p>
                    ) : null}

                    {a.notes ? (
                      <div className="mt-2 p-3 rounded-lg bg-white border text-xs" style={{ borderColor: BD, color: TX2 }}>
                        <span className="font-semibold" style={{ color: TX }}>Evaluator note: </span>
                        {a.notes}
                      </div>
                    ) : null}
                  </div>
                );
              })
            )}
          </div>
        )}
      </Card>

      <Modal open={Boolean(selectedCompetency)} onClose={() => setSelectedCompetency(null)} title="Request Re-evaluation">
        {selectedCompetency && (
          <form onSubmit={handleRequestSubmit} className="space-y-4">
            <div>
              <p className="text-xs font-semibold" style={{ color: TX2 }}>Competency</p>
              <h4 className="text-sm font-bold mt-0.5" style={{ color: TX }}>{selectedCompetency}</h4>
            </div>
            <div>
              <label className="block text-xs font-semibold mb-1.5" style={{ color: TX2 }}>
                Reason / Recent Achievements or Certifications
              </label>
              <textarea
                required
                rows={3}
                value={requestNotes}
                onChange={(e) => setRequestNotes(e.target.value)}
                placeholder="Mention recent training completed, projects, or achievements that show your growth in this skill..."
                className="w-full text-xs p-3 rounded-xl border bg-white focus:outline-none focus:border-purple-500 transition-colors shadow-sm"
                style={{ borderColor: BD, color: TX }}
              />
            </div>
            <div className="flex items-center justify-end gap-2 pt-4 border-t" style={{ borderColor: BD }}>
              <button
                type="button"
                onClick={() => setSelectedCompetency(null)}
                className="px-4 py-2 text-xs font-semibold rounded-xl border bg-white hover:bg-slate-50 transition-colors"
                style={{ borderColor: BD, color: TX2 }}
              >
                Cancel
              </button>
              <button
                type="submit"
                className="px-4 py-2 text-xs font-semibold rounded-xl text-white transition-all hover:opacity-95 flex items-center gap-1.5 shadow-sm"
                style={{ backgroundColor: V }}
              >
                <Send size={12} /> Submit to HR
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}