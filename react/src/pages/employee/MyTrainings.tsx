import {
  BookOpen,
  CheckCircle2,
  ClipboardList,
  Clock,
} from "lucide-react";

import {
  Card,
  KpiCard,
  PageHeader,
  SectionHead,
} from "../../components/shared-ui";
import { TX, TX2, BD } from "../../lib/constants";

export default function MyTrainings() {
  return (
    <div className="space-y-6">
      <PageHeader
        title="My Trainings"
        subtitle="Your assigned training programs and learning activities"
      />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiCard
          label="Assigned"
          value={0}
          sub="training assignments"
          icon={<BookOpen size={15} />}
          colorClass="text-violet-600"
          bgClass="bg-violet-50"
        />
        <KpiCard
          label="In Progress"
          value={0}
          sub="active assignments"
          icon={<Clock size={15} />}
          colorClass="text-blue-600"
          bgClass="bg-blue-50"
        />
        <KpiCard
          label="Completed"
          value={0}
          sub="completed assignments"
          icon={<CheckCircle2 size={15} />}
          colorClass="text-emerald-600"
          bgClass="bg-emerald-50"
        />
        <KpiCard
          label="Overdue"
          value={0}
          sub="past due assignments"
          icon={<Clock size={15} />}
          colorClass="text-red-600"
          bgClass="bg-red-50"
        />
      </div>

      <Card p={false}>
        <div className="border-b p-6" style={{ borderColor: BD }}>
          <SectionHead
            title="Assigned Trainings"
            subtitle="Training assignments available to you"
          />
        </div>

        <div className="p-12 text-center">
          <ClipboardList
            size={24}
            className="mx-auto mb-3"
            style={{ color: TX2 }}
          />
          <p className="text-sm font-medium" style={{ color: TX }}>
            No training assignments found
          </p>
          <p className="mx-auto mt-2 max-w-lg text-xs leading-5" style={{ color: TX2 }}>
            You currently have no assigned training programs.
          </p>
        </div>
      </Card>
    </div>
  );
}
