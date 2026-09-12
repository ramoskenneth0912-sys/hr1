// src/app/pages/employee/MyPerformance.tsx
import { useEffect, useState } from "react";
import {
  ClipboardCheck, CheckCircle2, Clock, Save, Send,
  Lock, ChevronLeft, Award, MessageSquareText, CalendarRange, CheckCheck,
  TriangleAlert, Eye,
} from "lucide-react";
import { V, TX, TX2, BD, SUCCESS, INFO, WARNING, DANGER } from "../../lib/constants";
import { getEssList, getEssItem, essAction, essUpdate } from "../../lib/essApi";
import {
  Card, PageHeader, SectionHead, TableBase, TableRow, Td, TdSub,
  StatusBadge, Counter, GhostBtn, PrimaryBtn, SecondaryBtn, Modal, Toast,
} from "../../components/shared-ui";

type ReviewStatus = "drafted" | "assigned" | "self_assessment" | "manager_review" | "finalized" | "acknowledged";
type BadgeVariant = "success" | "warning" | "danger" | "info" | "secondary" | "purple";

interface PeriodMeta {
  id: number;
  name: string;
  period_type: string;
  start_date: string | null;
  end_date: string | null;
}

interface GoalResult {
  id: number;
  goal_id: number;
  title: string;
  category: string | null;
  live_status: string | null;
  live_progress: number | null;
  goal_status_snapshot: string;
  goal_progress_snapshot: number;
  rating: number | null;
  result_notes: string | null;
}

interface SelfAssessment {
  strengths?: string;
  improvements?: string;
  comments?: string;
}

interface Review {
  id: number;
  status: ReviewStatus;
  period: PeriodMeta;
  self_submitted_at: string | null;
  acknowledged_at: string | null;
  manager_rating: number | null;
  final_rating: number | null;
  final_rating_label: string | null;
  can_submit_self: boolean;
  can_acknowledge: boolean;
  manager_feedback: string | null;
  self_assessment: SelfAssessment | null;
  acknowledge_note: string | null;
  goal_results: GoalResult[];
  ratings_hidden: boolean;
  updated_at: string;
}

const STATUS_LABEL: Record<ReviewStatus, string> = {
  drafted: "Draft",
  assigned: "Assigned",
  self_assessment: "Self Assessment",
  manager_review: "Manager Review",
  finalized: "Finalized",
  acknowledged: "Acknowledged",
};

const STATUS_VARIANT: Record<ReviewStatus, BadgeVariant> = {
  drafted: "secondary",
  assigned: "info",
  self_assessment: "warning",
  manager_review: "purple",
  finalized: "success",
  acknowledged: "success",
};

const PERIOD_TYPE_LABEL: Record<string, string> = {
  annual: "Annual",
  semi_annual: "Semi-Annual",
  quarterly: "Quarterly",
  probationary: "Probationary",
};

const ACTIVE_STATUSES: ReviewStatus[] = ["assigned", "self_assessment", "manager_review"];

const MAX_SELF = 2000;
const MAX_COMMENTS = 4000;

const fmtDate = (d: string | null) => {
  if (!d) return "—";
  const [y, m, day] = d.split("-");
  const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  return `${months[parseInt(m, 10) - 1]} ${parseInt(day, 10)}, ${y}`;
};

const periodLabel = (p: PeriodMeta) => p.name || `${PERIOD_TYPE_LABEL[p.period_type] || "Period"} Review`;

export default function MyPerformance() {
  const [reviews, setReviews] = useState<Review[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState<number | null>(null);
  const [detail, setDetail] = useState<Review | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [selfId, setSelfId] = useState<number | null>(null);
  const [ackId, setAckId] = useState<number | null>(null);
  const [strengths, setStrengths] = useState("");
  const [improvements, setImprovements] = useState("");
  const [comments, setComments] = useState("");
  const [ackNote, setAckNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ message: string; type: "success" | "error" } | null>(null);

  const loadReviews = async () => {
    setLoading(true);
    setError("");
    try {
      const list = await getEssList("employee/performance");
      setReviews(Array.isArray(list) ? list : []);
    } catch (err) {
      console.error("My Performance load error:", err);
      setError("Unable to load your performance reviews. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadReviews();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 3200);
    return () => clearTimeout(t);
  }, [toast]);

  const currentReview = (id: number) => detail?.id === id ? detail : (reviews.find((r) => r.id === id) ?? null);

  const openDetail = async (id: number) => {
    setSelected(id);
    setDetail(null);
    setDetailLoading(true);
    try {
      const item = await getEssItem(`employee/performance/${id}`);
      setDetail(item);
      if (item) {
        setStrengths(item.self_assessment?.strengths ?? "");
        setImprovements(item.self_assessment?.improvements ?? "");
        setComments(item.self_assessment?.comments ?? "");
        setAckNote(item.acknowledge_note ?? "");
      }
    } catch (err) {
      console.error("My Performance detail error:", err);
      setToast({ message: "Unable to load this review. Please try again.", type: "error" });
    } finally {
      setDetailLoading(false);
    }
  };

  const closeDetail = () => {
    setSelected(null);
    setDetail(null);
    loadReviews();
  };

  const refreshSelected = async (id: number) => {
    try {
      const item = await getEssItem(`employee/performance/${id}`);
      if (selected === id) setDetail(item);
    } catch (err) {
      console.error("My Performance refresh error:", err);
    }
    loadReviews();
  };

  const openSelfModal = (review: Review | null) => {
    if (!review) return;
    setStrengths(review.self_assessment?.strengths ?? "");
    setImprovements(review.self_assessment?.improvements ?? "");
    setComments(review.self_assessment?.comments ?? "");
    setSelfId(review.id);
  };

  const openAckModal = (review: Review | null) => {
    if (!review) return;
    setAckNote(review.acknowledge_note ?? "");
    setAckId(review.id);
  };

  const selfPayloadReady = () =>
    strengths.trim() !== "" || improvements.trim() !== "" || comments.trim() !== "";

  const selfPayload = () => ({
    strengths: strengths.trim() || undefined,
    improvements: improvements.trim() || undefined,
    comments: comments.trim() || undefined,
  });

  const saveSelfDraft = async () => {
    if (selfId === null) return;
    if (!selfPayloadReady()) {
      setToast({ message: "Please fill in at least one of strengths, improvements, or comments.", type: "error" });
      return;
    }
    setSaving(true);
    try {
      const res = await essUpdate(`employee/performance/${selfId}/self-assessment`, selfPayload());
      setToast({ message: res.message || "Draft saved.", type: "success" });
      await refreshSelected(selfId);
    } catch (err) {
      console.error("Self-assessment draft error:", err);
      setToast({ message: (err as Error).message || "Unable to save the draft.", type: "error" });
    } finally {
      setSaving(false);
    }
  };

  const submitSelfAssessment = async () => {
    if (selfId === null) return;
    if (!selfPayloadReady()) {
      setToast({ message: "Please fill in at least one of strengths, improvements, or comments.", type: "error" });
      return;
    }
    setSaving(true);
    try {
      const res = await essAction(`employee/performance/${selfId}/self-assessment`, selfPayload());
      setToast({ message: res && res.success ? res.message || "Self-assessment submitted." : "Self-assessment submitted.", type: "success" });
      setSelfId(null);
      await refreshSelected(selfId);
    } catch (err) {
      console.error("Self-assessment submit error:", err);
      setToast({ message: (err as Error).message || "Unable to submit your self-assessment.", type: "error" });
    } finally {
      setSaving(false);
    }
  };

  const acknowledgeReview = async () => {
    if (ackId === null) return;
    setSaving(true);
    try {
      const res = await essAction(`employee/performance/${ackId}/acknowledge`, {
        acknowledge_note: ackNote.trim() || undefined,
      });
      setToast({ message: res && res.success ? res.message || "Review acknowledged." : "Review acknowledged.", type: "success" });
      setAckId(null);
      await refreshSelected(ackId);
    } catch (err) {
      console.error("Acknowledge error:", err);
      setToast({ message: (err as Error).message || "Unable to acknowledge the review.", type: "error" });
    } finally {
      setSaving(false);
    }
  };

  const activeCount = reviews.filter((r) => ACTIVE_STATUSES.includes(r.status)).length;
  const doneCount = reviews.filter((r) => r.status === "finalized" || r.status === "acknowledged").length;
  const actionCount = reviews.filter((r) => r.can_submit_self || r.can_acknowledge).length;

  // ---- Shared modal/toast UI ------------------------------------------------
  const selfModal = (
    <Modal open={selfId !== null} onClose={() => setSelfId(null)} title="Self-Assessment">
      <div className="space-y-4">
        {[
          { label: "Strengths", key: "strengths" as const, value: strengths, set: setStrengths, max: MAX_SELF, ph: "What went well during this period?" },
          { label: "Areas for Improvement", key: "improvements" as const, value: improvements, set: setImprovements, max: MAX_SELF, ph: "What could you improve?" },
          { label: "Comments", key: "comments" as const, value: comments, set: setComments, max: MAX_COMMENTS, ph: "Any additional comments for your reviewer." },
        ].map((f) => (
          <div key={f.key}>
            <label className="block text-xs font-semibold uppercase tracking-wide mb-1.5" style={{ color: TX2 }}>{f.label}</label>
            <textarea
              rows={4}
              value={f.value}
              onChange={(e) => f.set(e.target.value)}
              maxLength={f.max}
              placeholder={f.ph}
              className="w-full rounded border px-3 py-2 text-sm"
              style={{ borderColor: BD, color: TX, resize: "vertical" }}
            />
            <div className="text-right text-[11px] mt-1" style={{ color: TX2 }}>{f.value.length}/{f.max}</div>
          </div>
        ))}
        <p className="text-xs" style={{ color: TX2 }}>
          Save a draft any time. Submitting sends your self-assessment to your reviewer and can no longer be changed.
        </p>
        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8 }}>
          <GhostBtn icon={Save} onClick={saveSelfDraft} disabled={saving}>Save Draft</GhostBtn>
          <PrimaryBtn icon={Send} onClick={submitSelfAssessment} disabled={saving}>Submit</PrimaryBtn>
        </div>
      </div>
    </Modal>
  );

  const ackTarget = ackId !== null ? currentReview(ackId) : null;

  const ackModal = (
    <Modal open={ackId !== null} onClose={() => setAckId(null)} title="Acknowledge Review">
      <div className="space-y-4">
        <div className="flex items-start gap-3 rounded-xl border p-4" style={{ borderColor: BD, background: "#FAFBFF" }}>
          <CheckCheck size={18} className="mt-0.5 shrink-0" style={{ color: SUCCESS }} />
          <div>
            <p className="text-sm font-semibold" style={{ color: TX }}>
              Final rating: {ackTarget?.final_rating ?? "—"} / 5
              {ackTarget?.final_rating_label ? <span style={{ color: SUCCESS }}> · {ackTarget.final_rating_label}</span> : null}
            </p>
            <p className="text-sm" style={{ color: TX2 }}>Acknowledging confirms you have reviewed the outcome of this review.</p>
          </div>
        </div>
        <div>
          <label className="block text-xs font-semibold uppercase tracking-wide mb-1.5" style={{ color: TX2 }}>Note (optional)</label>
          <textarea
            rows={3}
            value={ackNote}
            onChange={(e) => setAckNote(e.target.value)}
            placeholder="Add a note on your acknowledgement..."
            className="w-full rounded border px-3 py-2 text-sm"
            style={{ borderColor: BD, color: TX, resize: "vertical" }}
          />
        </div>
        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8 }}>
          <SecondaryBtn onClick={() => setAckId(null)}>Cancel</SecondaryBtn>
          <PrimaryBtn icon={CheckCheck} onClick={acknowledgeReview} disabled={saving}>Acknowledge</PrimaryBtn>
        </div>
      </div>
    </Modal>
  );

  const toastEl = toast ? <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} /> : null;

  // ---- Detail view ------------------------------------------------------------
  if (selected !== null) {
    const rev = detail;
    return (
      <div className="space-y-6">
        <PageHeader
          title="Review Detail"
          subtitle={rev ? `${periodLabel(rev.period)} · ${STATUS_LABEL[rev.status]}` : "Performance review"}
        >
          <SecondaryBtn icon={ChevronLeft} onClick={closeDetail}>Back to Reviews</SecondaryBtn>
        </PageHeader>

        {detailLoading ? (
          <Card>
            <div className="p-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-violet-600 mx-auto"></div>
            </div>
          </Card>
        ) : !rev ? (
          <Card>
            <div className="p-8 text-center">
              <TriangleAlert size={24} className="mx-auto mb-2" style={{ color: WARNING }} />
              <p className="text-sm" style={{ color: TX2 }}>This review is no longer available.</p>
              <div className="mt-3">
                <GhostBtn onClick={closeDetail}>Back to Reviews</GhostBtn>
              </div>
            </div>
          </Card>
        ) : (
          <>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <Card className="text-center">
                <div className="flex items-center justify-center mb-4">
                  <div className="relative w-28 h-28 sm:w-32 sm:h-32">
                    <svg viewBox="0 0 120 120" className="w-full h-full -rotate-90">
                      <circle cx="60" cy="60" r="50" fill="none" stroke={BD} strokeWidth="10" />
                      <circle
                        cx="60"
                        cy="60"
                        r="50"
                        fill="none"
                        stroke={rev.final_rating ? SUCCESS : V}
                        strokeWidth="10"
                        strokeLinecap="round"
                        strokeDasharray={`${rev.final_rating ? (rev.final_rating / 5) * 314 : 0} 314`}
                        className="transition-all duration-700"
                      />
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                      <span className="text-2xl sm:text-3xl font-bold" style={{ color: rev.final_rating ? SUCCESS : TX2 }}>
                        {rev.final_rating ?? "—"}
                      </span>
                      <span className="text-xs" style={{ color: TX2 }}>/ 5</span>
                    </div>
                  </div>
                </div>
                <h3 className="text-sm font-bold mb-1" style={{ color: TX }}>{periodLabel(rev.period)}</h3>
                <div className="flex justify-center"><StatusBadge label={STATUS_LABEL[rev.status]} variant={STATUS_VARIANT[rev.status]} /></div>
                {rev.final_rating_label ? (
                  <p className="mt-2 text-sm font-semibold" style={{ color: SUCCESS }}>{rev.final_rating_label}</p>
                ) : null}
              </Card>

              <Card className="lg:col-span-2">
                <SectionHead title="Review Details" subtitle="Period, dates and current stage" />
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 mt-4">
                  <div>
                    <div className="text-xs" style={{ color: TX2 }}>Period</div>
                    <div className="text-sm font-semibold mt-1" style={{ color: TX }}>{rev.period.name || "—"}</div>
                  </div>
                  <div>
                    <div className="text-xs" style={{ color: TX2 }}>Type</div>
                    <div className="text-sm font-semibold mt-1" style={{ color: TX }}>
                      {PERIOD_TYPE_LABEL[rev.period.period_type] || rev.period.period_type || "—"}
                    </div>
                  </div>
                  <div>
                    <div className="text-xs" style={{ color: TX2 }}>Period Dates</div>
                    <div className="text-sm font-semibold mt-1" style={{ color: TX }}>
                      {rev.period.start_date ? fmtDate(rev.period.start_date) : "—"} – {rev.period.end_date ? fmtDate(rev.period.end_date) : "—"}
                    </div>
                  </div>
                  <div>
                    <div className="text-xs" style={{ color: TX2 }}>Self-Assessment</div>
                    <div className="text-sm font-semibold mt-1" style={{ color: rev.self_submitted_at ? SUCCESS : TX2 }}>
                      {rev.self_submitted_at ? fmtDate(rev.self_submitted_at.split(" ")[0]) : "Not submitted"}
                    </div>
                  </div>
                  <div>
                    <div className="text-xs" style={{ color: TX2 }}>Acknowledged</div>
                    <div className="text-sm font-semibold mt-1" style={{ color: rev.acknowledged_at ? SUCCESS : TX2 }}>
                      {rev.acknowledged_at ? fmtDate(rev.acknowledged_at.split(" ")[0]) : "Not yet"}
                    </div>
                  </div>
                  <div>
                    <div className="text-xs" style={{ color: TX2 }}>Manager Rating</div>
                    <div className="text-sm font-semibold mt-1" style={{ color: rev.manager_rating ? SUCCESS : TX2 }}>
                      {rev.manager_rating ?? (rev.ratings_hidden ? "Hidden" : "—")}
                    </div>
                  </div>
                </div>

                <div style={{ display: "flex", gap: 8, marginTop: 16, flexWrap: "wrap" }}>
                  {rev.can_submit_self ? (
                    <PrimaryBtn icon={Send} onClick={() => openSelfModal(rev)}>Self-Assessment</PrimaryBtn>
                  ) : null}
                  {rev.can_acknowledge ? (
                    <SecondaryBtn icon={CheckCheck} onClick={() => openAckModal(rev)}>Acknowledge</SecondaryBtn>
                  ) : null}
                  {rev.status === "finalized" || rev.status === "acknowledged" ? (
                    <span className="inline-flex items-center gap-1 text-xs" style={{ color: TX2 }}>
                      <Lock size={12} /> Finalized review — read only
                    </span>
                  ) : null}
                </div>
              </Card>
            </div>

            <Card>
              <SectionHead title="Self-Assessment" subtitle="Your own reflection for this review period" />
              {rev.self_assessment ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                  {[
                    { label: "Strengths", value: rev.self_assessment.strengths },
                    { label: "Areas for Improvement", value: rev.self_assessment.improvements },
                    { label: "Comments", value: rev.self_assessment.comments },
                  ].map((f) => (
                    <div key={f.label} className={f.label === "Comments" ? "sm:col-span-2" : undefined}>
                      <div className="text-xs font-semibold uppercase tracking-wide" style={{ color: TX2 }}>{f.label}</div>
                      <p className="text-sm mt-1 break-words whitespace-pre-wrap" style={{ color: f.value ? TX : TX2 }}>
                        {f.value || "—"}
                      </p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm mt-3" style={{ color: TX2 }}>No self-assessment recorded yet.</p>
              )}
            </Card>

            <Card>
              <SectionHead title="Goal Results" subtitle="Linked goals and ratings scored by your reviewer" />
              {rev.goal_results.length === 0 ? (
                <p className="text-sm mt-3" style={{ color: TX2 }}>No goals are linked to this review.</p>
              ) : (
                <div className="mt-4">
                  <TableBase headers={["Goal", "Snapshot", "Rating", "Notes"]}>
                    {rev.goal_results.map((g, i) => (
                      <TableRow key={g.id} last={i === rev!.goal_results.length - 1}>
                        <td className="px-5 py-3.5">
                          <div className="text-sm font-semibold" style={{ color: TX }}>{g.title}</div>
                          {g.category ? <TdSub>{g.category}</TdSub> : null}
                        </td>
                        <TdSub>
                          <div className="capitalize">{g.live_status || g.goal_status_snapshot}</div>
                          <div style={{ marginTop: 2 }}>Progress {g.live_progress ?? g.goal_progress_snapshot}%</div>
                        </TdSub>
                        <Td>
                          {rev.ratings_hidden ? (
                            <span className="text-xs inline-flex items-center gap-1" style={{ color: TX2 }}>
                              <Lock size={12} /> Hidden
                            </span>
                          ) : g.rating !== null ? (
                            <StatusBadge label={`${g.rating} / 5`} variant={g.rating >= 4 ? "success" : g.rating >= 3 ? "info" : "warning"} />
                          ) : (
                            <span className="text-xs" style={{ color: TX2 }}>Not scored</span>
                          )}
                        </Td>
                        <TdSub>{rev.ratings_hidden ? "—" : (g.result_notes || "—")}</TdSub>
                      </TableRow>
                    ))}
                  </TableBase>
                </div>
              )}
            </Card>

            <Card>
              <SectionHead title="Manager Feedback" subtitle="Your reviewer's comments" />
              <div className="flex items-start gap-3 mt-4">
                <div className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0" style={{ background: "#F3E8FF" }}>
                  <MessageSquareText size={16} style={{ color: V }} />
                </div>
                <div className="flex-1">
                  {rev.ratings_hidden ? (
                    <p className="text-sm" style={{ color: TX2 }}>
                      Manager feedback and ratings will appear once your review has been finalized.
                    </p>
                  ) : rev.manager_feedback ? (
                    <p className="text-sm break-words whitespace-pre-wrap" style={{ color: TX }}>{rev.manager_feedback}</p>
                  ) : (
                    <p className="text-sm" style={{ color: TX2 }}>No manager feedback provided.</p>
                  )}
                </div>
              </div>
            </Card>

            {rev.acknowledge_note ? (
              <Card>
                <SectionHead title="Acknowledgment Note" subtitle="Your note recorded when acknowledging" />
                <p className="text-sm mt-3 break-words whitespace-pre-wrap" style={{ color: TX }}>{rev.acknowledge_note}</p>
              </Card>
            ) : null}
          </>
        )}

        {selfModal}
        {ackModal}
        {toastEl}
      </div>
    );
  }

  // ---- List view ---------------------------------------------------------------
  return (
    <div className="space-y-6">
      <PageHeader title="My Performance" subtitle="Your performance reviews and evaluation history" />

      <div className="grid grid-cols-3 gap-4">
        {[
          { label: "Total Reviews", value: reviews.length, icon: ClipboardCheck, iconBg: "#EDE9FE", iconColor: V },
          { label: "Awaiting Your Action", value: actionCount, icon: Clock, iconBg: "#FFF7ED", iconColor: WARNING },
          { label: "Finalized / Acknowledged", value: doneCount, icon: CheckCircle2, iconBg: "#F0FDF4", iconColor: SUCCESS },
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
          <SectionHead title="My Performance Reviews" subtitle="Select a review to view details and actions" />
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
                  <SecondaryBtn onClick={loadReviews}>Retry</SecondaryBtn>
                </div>
              </div>
            </div>
          </div>
        ) : reviews.length === 0 ? (
          <div className="p-10">
            <div className="text-center py-8 px-4">
              <div
                className="mx-auto mb-3 flex items-center justify-center rounded-full"
                style={{ width: 48, height: 48, background: BD }}
              >
                <ClipboardCheck size={22} style={{ color: TX2 }} />
              </div>
              <p className="text-sm font-medium mb-1" style={{ color: TX }}>No reviews yet</p>
              <p className="text-xs max-w-sm mx-auto" style={{ color: TX2 }}>
                Your performance review will appear here once HR has created one for you.
              </p>
            </div>
          </div>
        ) : (
          <TableBase headers={["Review / Period", "Period Dates", "Self-Assessment", "Rating", "Status", "Action"]}>
            {reviews.map((r, i) => (
              <TableRow key={r.id} last={i === reviews.length - 1}>
                <td className="px-5 py-3.5">
                  <div className="text-sm font-semibold" style={{ color: TX }}>{periodLabel(r.period)}</div>
                  <TdSub>{PERIOD_TYPE_LABEL[r.period.period_type] || r.period.period_type}</TdSub>
                </td>
                <TdSub>
                  <span className="inline-flex items-center gap-1">
                    <CalendarRange size={13} />
                    {r.period.start_date ? fmtDate(r.period.start_date) : "—"} – {r.period.end_date ? fmtDate(r.period.end_date) : "—"}
                  </span>
                </TdSub>
                <TdSub>
                  {r.self_submitted_at ? (
                    <span className="inline-flex items-center gap-1" style={{ color: SUCCESS }}>
                      <CheckCheck size={13} /> Submitted
                    </span>
                  ) : r.status === "self_assessment" ? (
                    <span className="inline-flex items-center gap-1" style={{ color: WARNING }}>
                      <Clock size={13} /> Pending
                    </span>
                  ) : (
                    "—"
                  )}
                </TdSub>
                <Td>
                  {r.ratings_hidden ? (
                    <span className="text-xs inline-flex items-center gap-1" style={{ color: TX2 }}>
                      <Lock size={12} /> Hidden
                    </span>
                  ) : r.final_rating !== null ? (
                    <span
                      className="inline-flex items-center gap-1.5 text-sm font-bold"
                      style={{ color: r.final_rating >= 4 ? SUCCESS : r.final_rating >= 3 ? INFO : WARNING }}
                    >
                      <Award size={14} /> {r.final_rating} / 5
                      {r.final_rating_label ? (
                        <span className="text-xs font-medium" style={{ color: TX2 }}>({r.final_rating_label})</span>
                      ) : null}
                    </span>
                  ) : (
                    <span className="text-xs" style={{ color: TX2 }}>—</span>
                  )}
                </Td>
                <Td><StatusBadge label={STATUS_LABEL[r.status]} variant={STATUS_VARIANT[r.status]} /></Td>
                <td className="px-5 py-3.5">
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    <GhostBtn icon={Eye} onClick={() => openDetail(r.id)}>View</GhostBtn>
                    {r.can_submit_self ? (
                      <PrimaryBtn icon={Send} onClick={() => openSelfModal(r)}>Self-Assessment</PrimaryBtn>
                    ) : null}
                    {r.can_acknowledge ? (
                      <SecondaryBtn icon={CheckCheck} onClick={() => openAckModal(r)}>Acknowledge</SecondaryBtn>
                    ) : null}
                  </div>
                </td>
              </TableRow>
            ))}
          </TableBase>
        )}
      </Card>

      {selfModal}
      {ackModal}
      {toastEl}
    </div>
  );
}