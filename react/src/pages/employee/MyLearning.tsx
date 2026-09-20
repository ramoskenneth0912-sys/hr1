// @ts-nocheck - legacy ESS module (was never type-checked before TS arrived).
import { BookOpen, Clock, GraduationCap } from "lucide-react";

import { PageHeader } from "../../components/shared-ui";

function FoundationCard({
  icon: Icon,
  title,
  children,
}: {
  icon: typeof BookOpen;
  title: string;
  children: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
        <Icon size={19} />
      </div>
      <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-500">{children}</p>
    </div>
  );
}

export default function MyLearning() {
  return (
    <div className="space-y-6">
      <PageHeader
        title="My Learning"
        subtitle="Learning resources and activities will appear here when available"
      />

      <section className="rounded-2xl border border-violet-100 bg-violet-50/70 p-6">
        <div className="flex items-start gap-4">
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white text-violet-600 shadow-sm">
            <GraduationCap size={23} />
          </div>
          <div>
            <h2 className="text-base font-semibold text-violet-950">
              No learning resources are currently available
            </h2>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-violet-800">
              Approved learning resources will appear here when a supported
              learning source becomes available.
            </p>
          </div>
        </div>
      </section>

      <div className="grid gap-4 md:grid-cols-2">
        <FoundationCard icon={BookOpen} title="What you can expect">
          Approved learning resources will appear here when they are available
          through HR1.
        </FoundationCard>
        <FoundationCard icon={Clock} title="In the meantime">
          Use My Trainings for formal training assignments. This page remains
          a clear home for future learning resources.
        </FoundationCard>
      </div>
    </div>
  );
}
