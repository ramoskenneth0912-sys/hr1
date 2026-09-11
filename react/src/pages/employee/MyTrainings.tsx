// src/app/pages/employee/MyTrainings.tsx

import { useEffect, useState } from "react";
import {
  BookOpen,
  Calendar,
  ClipboardList,
  Clock,
  Eye,
  Play,
  Trophy,
  Search,
  X,
} from "lucide-react";

import {
  Card,
  Badge,
  ProgressBar,
  KpiCard,
  Modal,
  StatusBadge,
  GhostBtn,
  Pagination,
  TableBase,
  TableRow,
  Td,
  TdSub,
  PageHeader,
} from "../../components/shared-ui";

import { V, TX, TX2, BD, BASE_URL } from "../../lib/constants";
import { getEssList, essAction } from "../../lib/essApi";

const SUCCESS = "#10b981";

/* =========================================================
   TYPES
========================================================= */

type TrainingStatus =
  | "PENDING"
  | "IN_PROGRESS"
  | "COMPLETED"
  | "OVERDUE";

interface TrainingAssignment {
  assignment_id: string;
  training_id: string;
  training_title: string;
  training_type: string;
  description: string;
  provider: string;
  duration: string;
  assigned_by: string;
  assigned_date: string;
  due_date: string;
  completion_date?: string;
  progress: number;
  status: TrainingStatus;
  next_session?: string;
}

/* =========================================================
   MAIN COMPONENT
========================================================= */

export default function MyTrainings() {
  const [selectedTraining, setSelectedTraining] =
    useState<TrainingAssignment | null>(null);

  const [viewOpen, setViewOpen] = useState(false);

  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] =
  useState<"ALL" | TrainingStatus>("ALL");

  const [trainings, setTrainings] = useState<TrainingAssignment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
  const fetchMyTrainings = async () => {
    try {
      setLoading(true);
      setError("");

      const data = await getEssList("employee/trainings");
      setTrainings(data);

    } catch (err) {
      console.error("My trainings error:", err);

      setError(
        "Unable to load your training assignments. Please try again."
      );

    } finally {
      setLoading(false);
    }
  };

  fetchMyTrainings();
}, []);

  /* =========================================================
     SEARCH + STATUS FILTER
  ========================================================= */

  const filteredTrainings = trainings.filter((training) => {
    const query = searchQuery.toLowerCase().trim();

    const matchesSearch =
  !query ||
  (training.training_title || "").toLowerCase().includes(query) ||
  (training.description || "").toLowerCase().includes(query) ||
  (training.assigned_by || "").toLowerCase().includes(query);

    const matchesStatus =
      statusFilter === "ALL" ||
      training.status === statusFilter;

    return matchesSearch && matchesStatus;
  });


  /* =========================================================
     STATUS COUNTS
  ========================================================= */

  const assigned = trainings;

  const inProgress = trainings.filter(
    (t) => t.status === "IN_PROGRESS"
  );

  const completed = trainings.filter(
    (t) => t.status === "COMPLETED"
  );

  const overdue = trainings.filter(
    (t) => t.status === "OVERDUE"
  );

  /* =========================================================
     STATUS HELPERS
  ========================================================= */

  const getStatusVariant = (
    status: TrainingStatus
  ):
    | "success"
    | "warning"
    | "danger"
    | "info"
    | "muted"
    | "purple" => {
    switch (status) {
      case "COMPLETED":
        return "success";

      case "IN_PROGRESS":
        return "info";

      case "OVERDUE":
        return "danger";

      case "PENDING":
  return "warning";

      default:
        return "muted";
    }
  };

  const getStatusLabel = (status: TrainingStatus) => {
  switch (status) {
    case "COMPLETED":
      return "Completed";

    case "IN_PROGRESS":
      return "In Progress";

    case "OVERDUE":
      return "Overdue";

    case "PENDING":
  return "Assigned";

    default:
      return status;
  }
};

  /* =========================================================
     VIEW TRAINING
  ========================================================= */

  const handleViewTraining = (
    training: TrainingAssignment
  ) => {
    setSelectedTraining(training);
    setViewOpen(true);
  };

  /* =========================================================
     TRAINING ACTIONS
  ========================================================= */

  const handleStartTraining = async (
  training: TrainingAssignment
) => {
  try {
    const result = await essAction("employee/trainings/start", {
      assignment_id: training.assignment_id,
    });

    if (!result.success) {
      throw new Error(
        result.message || "Unable to start training."
      );
    }

    setTrainings(await getEssList("employee/trainings"));

    setViewOpen(false);
    setSelectedTraining(null);

  } catch (err) {
    console.error("Start training error:", err);

    alert(
      "Unable to start training. Please try again."
    );
  }
};

  const handleContinueTraining = (
  training: TrainingAssignment
) => {
  window.location.href = `${BASE_URL}/modules/employee/learning.php?assignment_id=${training.assignment_id}`;
};

  /* =========================================================
     RENDER
  ========================================================= */

  return (
    <div className="space-y-6">

      {/* =====================================================
          PAGE HEADER
      ====================================================== */}

      <PageHeader
        title="My Trainings"
        subtitle="View your assigned trainings and track your learning progress"
      />

      {/* =====================================================
          KPI CARDS
      ====================================================== */}

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">

        <KpiCard
          label="Assigned"
          value={assigned.length}
          sub="training assignments"
          icon={<BookOpen size={15} />}
          colorClass="text-violet-600"
          bgClass="bg-violet-50"
        />

        <KpiCard
          label="In Progress"
          value={inProgress.length}
          sub="currently learning"
          icon={<Play size={15} />}
          colorClass="text-blue-600"
          bgClass="bg-blue-50"
        />

        <KpiCard
          label="Completed"
          value={completed.length}
          sub="trainings completed"
          icon={<Trophy size={15} />}
          colorClass="text-emerald-600"
          bgClass="bg-emerald-50"
        />

        <KpiCard
          label="Overdue"
          value={overdue.length}
          sub="past due date"
          icon={<Clock size={15} />}
          colorClass="text-red-600"
          bgClass="bg-red-50"
        />

      </div>

          {/* Training Assignments */}
<Card p={false}>

  {/* Header and Filters */}
  <div
    className="p-4 sm:p-5 border-b flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3"
    style={{ borderColor: BD }}
  >

    <div className="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">

      {/* Search */}
      <div
        className="flex items-center gap-2 border bg-white rounded-xl px-3 py-2 w-full sm:w-72"
        style={{ borderColor: BD }}
      >
        <Search
          size={14}
          style={{ color: TX2 }}
        />

        <input
          type="text"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          placeholder="Search training..."
          className="bg-transparent text-sm focus:outline-none flex-1 min-w-0"
          style={{ color: TX }}
        />

        {searchQuery && (
          <button
            type="button"
            onClick={() => setSearchQuery("")}
            className="text-slate-400 hover:text-slate-600"
          >
            <X size={14} />
          </button>
        )}
      </div>


      {/* Status Filter */}
      <select
        value={statusFilter}
        onChange={(e) =>
          setStatusFilter(
            e.target.value as "ALL" | TrainingStatus
          )
        }
        className="border rounded-xl px-3 py-2 text-xs focus:outline-none bg-white"
        style={{
          borderColor: BD,
          color: TX,
        }}
      >
        <option value="ALL">All Status</option>
        <option value="PENDING">Not Started</option>
        <option value="IN_PROGRESS">In Progress</option>
        <option value="COMPLETED">Completed</option>
        <option value="OVERDUE">Overdue</option>
      </select>

    </div>
  </div>


  {/* Empty State */}
  {loading ? (
  <div className="p-10 text-center">
    <p
      className="text-sm"
      style={{ color: TX2 }}
    >
      Loading your trainings...
    </p>
  </div>
) : error ? (
  <div className="p-10 text-center">
    <p
      className="text-sm font-medium text-red-600"
    >
      {error}
    </p>
  </div>
) : trainings.length === 0 ? (

    <div className="p-10 text-center">

      <ClipboardList
        size={22}
        className="mx-auto mb-2"
        style={{ color: TX2 }}
      />

      <p
        className="text-sm font-medium"
        style={{ color: TX }}
      >
        No training assignments found
      </p>

      <p
        className="text-xs mt-1"
        style={{ color: TX2 }}
      >
        You currently have no assigned training programs.
      </p>

    </div>

  ) : filteredTrainings.length === 0 ? (

    /* No Search/Filter Results */

    <div className="p-10 text-center">

      <Search
        size={22}
        className="mx-auto mb-2"
        style={{ color: TX2 }}
      />

      <p
        className="text-sm font-medium"
        style={{ color: TX }}
      >
        No matching trainings found
      </p>

      <p
        className="text-xs mt-1"
        style={{ color: TX2 }}
      >
        Try adjusting your search or status filter.
      </p>

      <button
        type="button"
        onClick={() => {
          setSearchQuery("");
          setStatusFilter("ALL");
        }}
        className="mt-3 text-xs font-medium text-violet-600 hover:text-violet-700"
      >
        Clear filters
      </button>

    </div>

  ) : (

    <>

            {/* =================================================
                DESKTOP TABLE
            ================================================== */}

            <div className="hidden md:block overflow-x-auto">

              <TableBase
                headers={[
                  "Training",
                  "Assigned By",
                  "Due Date",
                  "Status",
                  "Progress",
                  "Actions",
                ]}
              >

                {filteredTrainings.map((training, i) => (

                  <TableRow
                    key={training.assignment_id}
                    last={i === filteredTrainings.length - 1}
                  >

                    {/* Training */}

                    <td className="px-5 py-3.5">

                      <div className="flex items-center gap-3">

                        <div
                          className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                          style={{
                            background:
                              training.status ===
                              "COMPLETED"
                                ? "#ecfdf5"
                                : training.status ===
                                  "IN_PROGRESS"
                                ? `${V}15`
                                : training.status ===
                                  "OVERDUE"
                                ? "#fef2f2"
                                : "#f1f5f9",

                            color:
                              training.status ===
                              "COMPLETED"
                                ? SUCCESS
                                : training.status ===
                                  "IN_PROGRESS"
                                ? V
                                : training.status ===
                                  "OVERDUE"
                                ? "#ef4444"
                                : TX2,
                          }}
                        >

                          {training.status ===
                          "COMPLETED" ? (
                            <Trophy size={16} />
                          ) : training.status ===
                            "IN_PROGRESS" ? (
                            <Play size={16} />
                          ) : (
                            <BookOpen size={16} />
                          )}

                        </div>

                        <div className="min-w-0">

                          <div
                            className="text-sm font-semibold"
                            style={{ color: TX }}
                          >
                            {training.training_title ||
                              "-"}
                          </div>

                          <div
                            className="text-xs mt-0.5 max-w-[260px] truncate"
                            style={{ color: TX2 }}
                          >
                            {training.description ||
                              "-"}
                          </div>

                        </div>

                      </div>

                    </td>

                    {/* Assigned By */}

                    <TdSub>
                      {training.assigned_by || "-"}
                    </TdSub>

                    {/* Due Date */}

                    <td className="px-5 py-3.5">

                      <span
                        className="text-xs"
                        style={{
                          color:
                            training.status ===
                            "OVERDUE"
                              ? "#ef4444"
                              : TX2,

                          fontWeight:
                            training.status ===
                            "OVERDUE"
                              ? 600
                              : 400,
                        }}
                      >
                        {training.due_date || "-"}
                      </span>

                    </td>

                    {/* Status */}

                    <Td>
                      <StatusBadge
                        label={getStatusLabel(
                          training.status
                        )}
                      />
                    </Td>

                    {/* Progress */}

                    <td className="px-5 py-3.5">

                      <div className="flex items-center gap-2 min-w-[130px]">

                        <div
                          className="w-24 h-1.5 rounded-full"
                          style={{
                            background: BD,
                          }}
                        >

                          <div
                            className="h-full rounded-full"
                            style={{
                              width: `${Math.min(
                                Math.max(
                                  Number(
                                    training.progress
                                  ) || 0,
                                  0
                                ),
                                100
                              )}%`,

                              background:
                                Number(
                                  training.progress
                                ) === 100
                                  ? SUCCESS
                                  : training.status ===
                                    "OVERDUE"
                                  ? "#ef4444"
                                  : V,
                            }}
                          />

                        </div>

                        <span
                          className="text-xs font-mono"
                          style={{ color: TX2 }}
                        >
                          {Number(
                            training.progress
                          ) || 0}
                          %
                        </span>

                      </div>

                    </td>

                    {/* Actions */}

                    <td className="px-5 py-3.5">

                      <div className="flex items-center gap-1">

                        <GhostBtn
                          icon={Eye}
                          onClick={() =>
                            handleViewTraining(
                              training
                            )
                          }
                        >
                          View
                        </GhostBtn>

                        {training.status ===
                          "IN_PROGRESS" && (
                          <GhostBtn
                            icon={Play}
                            onClick={() =>
                              handleContinueTraining(
                                training
                              )
                            }
                          >
                            Continue
                          </GhostBtn>
                        )}

                        {training.status === "PENDING" && (
  <GhostBtn
    icon={Play}
    onClick={() =>
      handleStartTraining(training)
    }
  >
    Start
  </GhostBtn>
)}

                        {training.status ===
                          "OVERDUE" && (
                          <GhostBtn
                            icon={Play}
                            onClick={() =>
                              handleContinueTraining(
                                training
                              )
                            }
                          >
                            Continue
                          </GhostBtn>
                        )}

                      </div>

                    </td>

                  </TableRow>

                ))}

              </TableBase>

              <Pagination
  total={filteredTrainings.length}
/>

            </div>

            {/* =================================================
                MOBILE CARDS
            ================================================== */}

            <div
              className="md:hidden divide-y"
              style={{ borderColor: BD }}
            >

              {filteredTrainings.map((training) => (

                <div
                  key={training.assignment_id}
                  className="p-4"
                >

                  {/* Training + Status */}

                  <div className="flex items-start justify-between gap-3">

                    <div className="flex items-start gap-3 min-w-0">

                      <div
                        className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                        style={{
                          background:
                            training.status ===
                            "COMPLETED"
                              ? "#ecfdf5"
                              : training.status ===
                                "IN_PROGRESS"
                              ? `${V}15`
                              : training.status ===
                                "OVERDUE"
                              ? "#fef2f2"
                              : "#f1f5f9",

                          color:
                            training.status ===
                            "COMPLETED"
                              ? SUCCESS
                              : training.status ===
                                "IN_PROGRESS"
                              ? V
                              : training.status ===
                                "OVERDUE"
                              ? "#ef4444"
                              : TX2,
                        }}
                      >

                        {training.status ===
                        "COMPLETED" ? (
                          <Trophy size={16} />
                        ) : training.status ===
                          "IN_PROGRESS" ? (
                          <Play size={16} />
                        ) : (
                          <BookOpen size={16} />
                        )}

                      </div>

                      <div className="min-w-0">

                        <div
                          className="text-sm font-semibold truncate"
                          style={{ color: TX }}
                        >
                          {training.training_title ||
                            "-"}
                        </div>

                        <div
                          className="text-xs mt-0.5 truncate"
                          style={{ color: TX2 }}
                        >
                          {training.description ||
                            "-"}
                        </div>

                      </div>

                    </div>

                    <StatusBadge
                      label={getStatusLabel(
                        training.status
                      )}
                    />

                  </div>

                  {/* Details */}

                  <div className="grid grid-cols-2 gap-3 mt-4">

                    <div>

                      <div
                        className="text-[10px]"
                        style={{ color: TX2 }}
                      >
                        Assigned By
                      </div>

                      <div
                        className="text-xs font-medium mt-0.5"
                        style={{ color: TX }}
                      >
                        {training.assigned_by ||
                          "-"}
                      </div>

                    </div>

                    <div>

                      <div
                        className="text-[10px]"
                        style={{ color: TX2 }}
                      >
                        Assigned Date
                      </div>

                      <div
                        className="text-xs font-medium mt-0.5"
                        style={{ color: TX }}
                      >
                        {training.assigned_date ||
                          "-"}
                      </div>

                    </div>

                    <div>

                      <div
                        className="text-[10px]"
                        style={{ color: TX2 }}
                      >
                        Due Date
                      </div>

                      <div
                        className="text-xs font-medium mt-0.5"
                        style={{
                          color:
                            training.status ===
                            "OVERDUE"
                              ? "#ef4444"
                              : TX,
                        }}
                      >
                        {training.due_date || "-"}
                      </div>

                    </div>

                    <div>

                      <div
                        className="text-[10px]"
                        style={{ color: TX2 }}
                      >
                        Completion Date
                      </div>

                      <div
                        className="text-xs font-medium mt-0.5"
                        style={{ color: TX }}
                      >
                        {training.completion_date ||
                          "-"}
                      </div>

                    </div>

                  </div>

                  {/* Progress */}

                  <div className="flex items-center gap-2 mt-4">

                    <div
                      className="flex-1 h-1.5 rounded-full"
                      style={{ background: BD }}
                    >

                      <div
                        className="h-full rounded-full"
                        style={{
                          width: `${Math.min(
                            Math.max(
                              Number(
                                training.progress
                              ) || 0,
                              0
                            ),
                            100
                          )}%`,

                          background:
                            Number(
                              training.progress
                            ) === 100
                              ? SUCCESS
                              : training.status ===
                                "OVERDUE"
                              ? "#ef4444"
                              : V,
                        }}
                      />

                    </div>

                    <span
                      className="text-xs font-mono"
                      style={{ color: TX2 }}
                    >
                      {Number(
                        training.progress
                      ) || 0}
                      %
                    </span>

                  </div>

                  {/* Actions */}

                  <div className="flex items-center gap-2 mt-4 flex-wrap">

                    <GhostBtn
                      icon={Eye}
                      onClick={() =>
                        handleViewTraining(
                          training
                        )
                      }
                    >
                      View
                    </GhostBtn>

                    {training.status ===
                      "IN_PROGRESS" && (
                      <GhostBtn
                        icon={Play}
                        onClick={() =>
                          handleContinueTraining(
                            training
                          )
                        }
                      >
                        Continue
                      </GhostBtn>
                    )}

                    {training.status === "PENDING" && (
  <GhostBtn
    icon={Play}
    onClick={() =>
      handleStartTraining(training)
    }
  >
    Start
  </GhostBtn>
)}

                    {training.status ===
                      "OVERDUE" && (
                      <GhostBtn
                        icon={Play}
                        onClick={() =>
                          handleContinueTraining(
                            training
                          )
                        }
                      >
                        Continue
                      </GhostBtn>
                    )}

                  </div>

                </div>

              ))}

              <div className="p-4">

                <Pagination
  total={filteredTrainings.length}
/>

              </div>

            </div>

          </>

        )}

      </Card>

      {/* =====================================================
          TRAINING DETAILS MODAL
      ====================================================== */}

      <Modal
        open={viewOpen}
        onClose={() => {
          setViewOpen(false);
          setSelectedTraining(null);
        }}
        title="Training Details"
      >

        {selectedTraining && (

          <div className="space-y-5">

            {/* Header */}

            <div className="p-5 rounded-xl bg-violet-50 border border-violet-100">

              <div className="flex items-start gap-4">

                <div className="w-11 h-11 rounded-xl bg-violet-100 flex items-center justify-center shrink-0">

                  <BookOpen
                    size={20}
                    className="text-violet-600"
                  />

                </div>

                <div className="flex-1">

                  <div className="flex items-center gap-2 flex-wrap">

                    <h3 className="text-base font-semibold text-slate-800">
                      {selectedTraining.training_title}
                    </h3>

                    <Badge
                      label={
                        selectedTraining.training_type
                      }
                      variant="purple"
                    />

                    <Badge
                      label={getStatusLabel(
                        selectedTraining.status
                      )}
                      variant={getStatusVariant(
                        selectedTraining.status
                      )}
                    />

                  </div>

                  <p className="text-xs text-slate-500 mt-1">
                    {selectedTraining.description}
                  </p>

                </div>

              </div>

            </div>

            {/* Information */}

            <div className="grid grid-cols-2 gap-4">

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">

                <p className="text-[11px] text-slate-400 mb-1">
                  Provider
                </p>

                <p className="text-sm font-medium text-slate-700">
                  {selectedTraining.provider}
                </p>

              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">

                <p className="text-[11px] text-slate-400 mb-1">
                  Duration
                </p>

                <p className="text-sm font-medium text-slate-700">
                  {selectedTraining.duration}
                </p>

              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">

                <p className="text-[11px] text-slate-400 mb-1">
                  Assigned By
                </p>

                <p className="text-sm font-medium text-slate-700">
                  {selectedTraining.assigned_by}
                </p>

              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">

                <p className="text-[11px] text-slate-400 mb-1">
                  Assigned Date
                </p>

                <p className="text-sm font-medium text-slate-700">
                  {selectedTraining.assigned_date}
                </p>

              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">

                <p className="text-[11px] text-slate-400 mb-1">
                  Due Date
                </p>

                <p
                  className={`text-sm font-medium ${
                    selectedTraining.status ===
                    "OVERDUE"
                      ? "text-red-600"
                      : "text-slate-700"
                  }`}
                >
                  {selectedTraining.due_date}
                </p>

              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">

                <p className="text-[11px] text-slate-400 mb-1">
                  Completion Date
                </p>

                <p className="text-sm font-medium text-slate-700">
  {selectedTraining.completion_date &&
  !String(selectedTraining.completion_date).startsWith("0000-00-00")
    ? selectedTraining.completion_date
    : "-"}
</p>

              </div>

            </div>

            {/* Progress */}

            <div className="p-4 rounded-xl border border-slate-100">

              <div className="flex justify-between items-center mb-2">

                <span className="text-xs font-medium text-slate-600">
                  Training Progress
                </span>

                <span className="text-sm font-semibold text-slate-700">
                  {selectedTraining.progress}%
                </span>

              </div>

              <ProgressBar
                value={selectedTraining.progress}
                color={
                  selectedTraining.status ===
                  "COMPLETED"
                    ? SUCCESS
                    : selectedTraining.status ===
                      "OVERDUE"
                    ? "#ef4444"
                    : V
                }
                showLabel={false}
              />

            </div>

            {/* Next Session */}

            {selectedTraining.next_session && (

              <div className="flex items-center gap-2 text-xs text-slate-500">

                <Calendar size={14} />

                <span>
                  Next session:{" "}

                  <span className="font-medium text-slate-700">
                    {selectedTraining.next_session}
                  </span>
                </span>

              </div>

            )}

            {/* Actions */}

            <div className="flex justify-end gap-2 pt-3 border-t border-slate-100">

              <button
                type="button"
                onClick={() => {
                  setViewOpen(false);
                  setSelectedTraining(null);
                }}
                className="px-4 py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
              >
                Close
              </button>

              {selectedTraining.status ===
  "PENDING" && (

                <button
                  type="button"
                  onClick={() =>
                    handleStartTraining(
                      selectedTraining
                    )
                  }
                  className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 transition-colors"
                >
                  <Play size={14} />
                  Start Training
                </button>

              )}

              {(selectedTraining.status ===
                "IN_PROGRESS" ||
                selectedTraining.status ===
                  "OVERDUE") && (

                <button
                  type="button"
                  onClick={() =>
                    handleContinueTraining(
                      selectedTraining
                    )
                  }
                  className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 transition-colors"
                >
                  <Play size={14} />
                  Continue Training
                </button>

              )}

            </div>

          </div>

        )}

      </Modal>

    </div>
  );
}