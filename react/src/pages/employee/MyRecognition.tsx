// @ts-nocheck - legacy ESS module (was never type-checked before TS arrived).
import { useEffect, useState } from "react";
import { Award, CalendarDays, TriangleAlert } from "lucide-react";
import { getEssList } from "../../lib/essApi";
import { Card, PageHeader, SectionHead, StatusBadge } from "../../components/shared-ui";
import { TX, TX2 } from "../../lib/constants";

interface Recognition {
  id: number;
  category: string;
  title: string;
  message: string;
  recognition_date: string;
  given_by: string | null;
  status: "published";
}

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}

export default function MyRecognition() {
  const [recognitions, setRecognitions] = useState<Recognition[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    getEssList("/employee/recognition")
      .then((items) => {
        if (active) setRecognitions(Array.isArray(items) ? items as Recognition[] : []);
      })
      .catch((err) => {
        console.error("My Recognition load error:", err);
        if (active) setError("Unable to load your recognition records. Please try again.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, []);

  return (
    <div className="space-y-6">
      <PageHeader
        title="My Recognition"
        subtitle="Recognition records issued to you by HR or your manager"
      />

      {loading && (
        <Card>
          <p className="text-sm" style={{ color: TX2 }}>Loading your recognition records…</p>
        </Card>
      )}

      {error && (
        <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
          <TriangleAlert size={18} className="mt-0.5 shrink-0" />
          <p className="text-sm text-red-800">{error}</p>
        </div>
      )}

      {!loading && !error && recognitions.length === 0 && (
        <Card>
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <Award size={28} style={{ color: TX2 }} />
            <p className="mt-3 text-sm font-medium" style={{ color: TX }}>No recognition records yet</p>
            <p className="mt-1 max-w-md text-sm" style={{ color: TX2 }}>
              Recognition issued by HR or your manager will appear here.
            </p>
          </div>
        </Card>
      )}

      {!loading && !error && recognitions.length > 0 && (
        <Card>
          <SectionHead
            title="Recognition history"
            subtitle="Published recognition records associated with your employee profile"
          />
          <div className="mt-4 space-y-3">
            {recognitions.map((recognition) => (
              <article key={recognition.id} className="rounded-xl border p-4" style={{ borderColor: "#E2E8F0" }}>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="text-sm font-semibold" style={{ color: TX }}>{recognition.title}</h3>
                      <StatusBadge label={recognition.category} variant="secondary" />
                    </div>
                    <p className="mt-2 whitespace-pre-wrap text-sm leading-6" style={{ color: TX2 }}>
                      {recognition.message}
                    </p>
                  </div>
                  <div className="shrink-0 text-left text-xs sm:text-right" style={{ color: TX2 }}>
                    <div className="flex items-center gap-1 sm:justify-end">
                      <CalendarDays size={14} />
                      <span>{formatDate(recognition.recognition_date)}</span>
                    </div>
                    {recognition.given_by && <p className="mt-1">Given by {recognition.given_by}</p>}
                  </div>
                </div>
              </article>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
