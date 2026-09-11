// src/app/pages/employee/MyDevelopment.tsx
import { useState, useEffect } from "react";
import {
  TrendingUp, Shield, Zap, Check, CheckCircle2,
  AlertTriangle, Calendar, User, Briefcase,
  Target, Rocket, Award, Brain, BarChart3,
  Clock, Star, Users, ArrowUpRight, Sparkles,
  Activity, PieChart, LineChart as LineChartIcon,
  Trophy, Loader2
} from "lucide-react";
import {
  Avatar, Badge, ProgressBar, ReadinessGauge, Toast,
  KpiCard
} from "../../components/shared-ui";
import { RadarChart, PolarGrid, PolarAngleAxis, Radar, ResponsiveContainer, LineChart, Line, XAxis, YAxis, Tooltip, CartesianGrid, BarChart, Bar, Legend } from "recharts";
import { useAuth } from "../../auth/useAuth";
import { apiService } from "../../services/apiService";

// ---------- Types ----------
interface CompetencyScore {
  name: string;
  score: number;
  required: number;
  gap: number;
}

interface SkillGap {
  competency: string;
  current: number;
  required: number;
  gap: number;
}

interface TrainingRecommendation {
  title: string;
  priority: "high" | "medium" | "low";
  reason: string;
}

interface ActionPlanStep {
  id: string;
  description: string;
  dueDate: string;
  status: "not-started" | "in-progress" | "completed";
}

interface SuccessionCandidate {
  id: string;
  name: string;
  initials: string;
  color: string;
  role: string;
  department: string;
  targetPosition: string;
  readiness: number;
  rank: number;
  timeline: "0–6 months" | "6–12 months" | "12+ months";
  strengths: string[];
  developmentAreas: string[];
  competencies: CompetencyScore[];
  skillGaps: SkillGap[];
  trainingRecommendations: TrainingRecommendation[];
  actionPlan: ActionPlanStep[];
  completedTrainings: number;
  lastEval: string;
  isReady: boolean;
  promotionProbability?: number;
}

interface EmployeeProfile {
  employee_id: string;
  full_name: string;
  current_role: string;
  department: string;
  tenure_years: number;
  overall_rating: number;
  avg_competency: number;
}

export default function MyDevelopment() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState<EmployeeProfile | null>(null);
  const [candidate, setCandidate] = useState<SuccessionCandidate | null>(null);
  const [toast, setToast] = useState<{ message: string; type: "success" | "error" | "info" } | null>(null);
  const [activeTab, setActiveTab] = useState<"overview" | "skills" | "roadmap" | "feedback">("overview");

  const showToast = (message: string, type: "success" | "error" | "info") => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3500);
  };

  // ---------- Data Fetching ----------
  useEffect(() => {
    const loadData = async () => {
      try {
        setLoading(true);

        // Fetch employee profile
        const profileData = await apiService.getMyProfile();
        if (profileData.success && profileData.data) {
          setProfile(profileData.data);
        }

        // Fetch succession data for this employee
        const successionData = await apiService.getMySuccession();
        if (successionData.success && successionData.data) {
          const c = successionData.data;
          setCandidate({
            id: c.employee_id || c.id,
            name: c.full_name || c.name || profileData.data?.full_name || user?.name || 'Unknown',
            initials: c.initials || (c.full_name || user?.name || 'U').split(' ').map((n: string) => n[0]).join(''),
            color: c.color || '#7C3AED',
            role: c.current_role || c.role || 'N/A',
            department: c.department || 'N/A',
            targetPosition: c.target_position || c.targetPosition || c.current_role || 'N/A',
            readiness: c.readiness || c.readiness_score || 0,
            rank: c.rank || 0,
            timeline: c.timeline || '6–12 months',
            strengths: c.strengths || [],
            developmentAreas: c.development_areas || c.developmentAreas || [],
            competencies: c.competencies || [],
            skillGaps: c.skill_gaps || c.skillGaps || [],
            trainingRecommendations: c.training_recommendations || c.trainingRecommendations || [],
            actionPlan: c.action_plan || c.actionPlan || [],
            completedTrainings: c.completed_trainings || c.completedTrainings || 0,
            lastEval: c.last_eval || c.lastEval || 'N/A',
            isReady: c.is_ready || c.isReady || false,
            promotionProbability: c.promotion_probability || c.promotionProbability,
          });
        }
      } catch (error) {
        console.error('Failed to load development data:', error);
        showToast('Failed to load your development data', 'error');
      } finally {
        setLoading(false);
      }
    };

    loadData();
  }, [user]);

  const getStatusVariant = (status: string): "success" | "warning" | "danger" | "info" | "muted" | "purple" => {
    const map: Record<string, any> = {
      "completed": "success", "approved": "success", "active": "success",
      "in-progress": "info", "not-started": "muted", "pending": "warning",
      "pending approval": "warning", "full": "danger", "critical": "danger"
    };
    return map[status.toLowerCase()] || "purple";
  };

  // Mock performance trend data (will be replaced with real data)
  const performanceData = [
    { month: "Jan", score: 72, target: 80 },
    { month: "Feb", score: 75, target: 80 },
    { month: "Mar", score: 78, target: 85 },
    { month: "Apr", score: 82, target: 85 },
    { month: "May", score: 85, target: 90 },
    { month: "Jun", score: 88, target: 90 },
  ];

  // Loading state
  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <Loader2 size={32} className="animate-spin text-violet-600" />
        <span className="ml-3 text-sm text-slate-500">Loading your development insights…</span>
      </div>
    );
  }

  // If user has no profile data (fallback)
  if (!profile && !candidate) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="My Development"
          subtitle="Your career growth and succession readiness"
        >
          <Badge label="AI Powered" variant="purple" />
        </PageHeader>

        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-6">
          <div className="flex items-center gap-4">
            <Avatar initials={user?.name?.charAt(0) ?? "?"} color="#7c3aed" size="lg" />
            <div className="flex-1">
              <h2 className="text-lg font-semibold text-slate-800">{user?.name ?? "Employee"}</h2>
              <p className="text-sm text-slate-500">No profile data found</p>
            </div>
          </div>
        </div>

        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-10 text-center">
          <div className="w-20 h-20 rounded-full bg-violet-50 flex items-center justify-center mx-auto mb-4">
            <TrendingUp size={40} className="text-violet-300" />
          </div>
          <h3 className="text-base font-semibold text-slate-700 mb-2">Profile Not Set Up</h3>
          <p className="text-sm text-slate-400 max-w-md mx-auto">
            Your HR Administrator needs to complete your employee profile. Please contact HR for assistance.
          </p>
        </div>
      </div>
    );
  }

  // If user is NOT in succession pipeline (candidate is null or has no readiness)
  if (!candidate || candidate.readiness === 0) {
    return (
      <div className="space-y-6">
        {/* Page Header */}
        <PageHeader
          title="My Development"
          subtitle="Your career growth and succession readiness"
        >
          <Badge label="AI Powered" variant="purple" />
        </PageHeader>

        {/* Profile Summary Card */}
        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-6">
          <div className="flex items-center gap-4">
            <Avatar initials={profile?.full_name?.charAt(0) ?? user?.name?.charAt(0) ?? "?"} color="#7c3aed" size="lg" />
            <div className="flex-1">
              <h2 className="text-lg font-semibold text-slate-800">{profile?.full_name ?? user?.name}</h2>
              <p className="text-sm text-slate-500">{profile?.current_role ?? "Employee"} · {profile?.department ?? "N/A"}</p>
              <Badge label="Not in Pipeline" variant="muted" />
            </div>
          </div>
        </div>

        {/* Not in Pipeline Message */}
        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-10 text-center">
          <div className="w-20 h-20 rounded-full bg-violet-50 flex items-center justify-center mx-auto mb-4">
            <TrendingUp size={40} className="text-violet-300" />
          </div>
          <h3 className="text-base font-semibold text-slate-700 mb-2">Not Yet in Succession Pipeline</h3>
          <p className="text-sm text-slate-400 max-w-md mx-auto">
            Your HR Supervisor or HR Admin will add you to the succession pipeline based on your performance reviews and career goals.
            Keep completing your training assignments!
          </p>
        </div>

        {/* How to get noticed */}
        <div className="bg-gradient-to-r from-violet-50 to-indigo-50 border border-violet-100 rounded-xl p-5">
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 rounded-lg bg-violet-100 flex items-center justify-center shrink-0">
              <Zap size={16} className="text-violet-600" />
            </div>
            <div>
              <div className="text-sm font-semibold text-violet-800 mb-2">How to get noticed</div>
              <div className="grid grid-cols-2 gap-2">
                <ul className="space-y-1.5 text-xs text-violet-700">
                  <li className="flex items-center gap-2">
                    <Check size={12} className="shrink-0 text-violet-600" /> Complete all training programs
                  </li>
                  <li className="flex items-center gap-2">
                    <Check size={12} className="shrink-0 text-violet-600" /> Score above 85% in evaluations
                  </li>
                </ul>
                <ul className="space-y-1.5 text-xs text-violet-700">
                  <li className="flex items-center gap-2">
                    <Check size={12} className="shrink-0 text-violet-600" /> Discuss career progression plan
                  </li>
                  <li className="flex items-center gap-2">
                    <Check size={12} className="shrink-0 text-violet-600" /> Lead cross-functional projects
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        {toast && (
          <Toast
            message={toast.message}
            type={toast.type}
            onClose={() => setToast(null)}
          />
        )}
      </div>
    );
  }

  // User IS in succession pipeline
  const skills = candidate.competencies.length > 0
    ? candidate.competencies.map(c => ({ subject: c.name, score: c.score }))
    : [
        { subject: "Merchandising", score: 85 },
        { subject: "Logistics", score: 75 },
        { subject: "Trading", score: 65 },
        { subject: "Sales", score: 90 },
        { subject: "Customer Service", score: 80 },
        { subject: "Leadership", score: 60 },
      ];

  const avgSkillScore = skills.reduce((sum, s) => sum + s.score, 0) / skills.length;
  const skillGap = Math.round(100 - avgSkillScore);
  const completedActions = candidate.actionPlan.filter(a => a.status === "completed").length;
  const totalActions = candidate.actionPlan.length;
  const roadmapProgress = totalActions > 0 ? Math.round((completedActions / totalActions) * 100) : 0;

  // Training data for bar chart
  const trainingData = candidate.competencies.length > 0
    ? candidate.competencies.map(c => ({
        name: c.name,
        completed: c.score,
        total: 100
      }))
    : [
        { name: "Merchandising", completed: 85, total: 100 },
        { name: "Logistics", completed: 70, total: 100 },
        { name: "Trading", completed: 60, total: 100 },
        { name: "Sales", completed: 90, total: 100 },
      ];

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <PageHeader
        title="My Development"
        subtitle="AI-powered career growth & succession readiness"
      >
        <div className="flex items-center gap-2">
          <Badge label="AI Analytics" variant="purple" />
          <Badge label="v2.0" variant="info" />
        </div>
      </PageHeader>

      {/* Hero Banner */}
      <div className="bg-gradient-to-r from-violet-600 to-indigo-600 rounded-2xl p-6 text-white">
        <div className="flex items-center gap-6 flex-wrap">
          <div className="shrink-0">
            <ReadinessGauge value={candidate.readiness} />
          </div>
          <div className="flex-1 min-w-[200px]">
            <div className="flex items-center gap-2 mb-1">
              <Sparkles size={16} className="text-yellow-300" />
              <span className="text-xs text-violet-200 uppercase tracking-widest font-medium">AI Succession Prediction</span>
            </div>
            <h2 className="text-2xl font-bold mb-1">
              Ready for <span className="text-yellow-300">{candidate.targetPosition}</span>
            </h2>
            <p className="text-sm text-violet-200 mb-3">
              Ranked <strong className="text-white">#{candidate.rank}</strong> in succession pipeline
            </p>
            <div className="flex gap-3 flex-wrap">
              <div className="bg-white/10 backdrop-blur-sm rounded-lg px-3 py-2">
                <div className="text-xs text-violet-200">Timeline</div>
                <div className="text-sm font-semibold">{candidate.timeline}</div>
              </div>
              <div className="bg-white/10 backdrop-blur-sm rounded-lg px-3 py-2">
                <div className="text-xs text-violet-200">Performance</div>
                <div className="text-sm font-semibold">{candidate.lastEval}</div>
              </div>
              <div className="bg-white/10 backdrop-blur-sm rounded-lg px-3 py-2">
                <div className="text-xs text-violet-200">Skills Gap</div>
                <div className="text-sm font-semibold">{skillGap}%</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Quick Stats Row */}
      <div className="grid grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500">Skills Proficiency</span>
            <div className="w-8 h-8 rounded-lg bg-violet-50 flex items-center justify-center">
              <Award size={16} className="text-violet-500" />
            </div>
          </div>
          <div className="text-2xl font-bold text-slate-800 mt-1">{Math.round(avgSkillScore)}%</div>
          <div className="w-full h-1.5 bg-slate-100 rounded-full mt-2 overflow-hidden">
            <div className="h-full bg-violet-500 rounded-full transition-all duration-1000" style={{ width: `${avgSkillScore}%` }} />
          </div>
        </div>
        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500">Roadmap Progress</span>
            <div className="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center">
              <Target size={16} className="text-emerald-500" />
            </div>
          </div>
          <div className="text-2xl font-bold text-slate-800 mt-1">{completedActions}/{totalActions}</div>
          <div className="w-full h-1.5 bg-slate-100 rounded-full mt-2 overflow-hidden">
            <div className="h-full bg-emerald-500 rounded-full transition-all duration-1000" style={{ width: `${roadmapProgress}%` }} />
          </div>
        </div>
        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500">Trainings Done</span>
            <div className="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
              <Brain size={16} className="text-blue-500" />
            </div>
          </div>
          <div className="text-2xl font-bold text-slate-800 mt-1">{candidate.completedTrainings}</div>
          <span className="text-xs text-emerald-600">+{candidate.completedTrainings} completed</span>
        </div>
        <div className="bg-white rounded-xl border border-slate-100 shadow-sm p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500">Career Ranking</span>
            <div className="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center">
              <Trophy size={16} className="text-amber-500" />
            </div>
          </div>
          <div className="text-2xl font-bold text-slate-800 mt-1">#{candidate.rank}</div>
          <span className="text-xs text-emerald-600">Top {Math.min(100, Math.round((candidate.rank / 50) * 100))}%</span>
        </div>
      </div>

      {/* Tabs for detailed views */}
      <div className="bg-white rounded-xl border border-slate-100 shadow-sm overflow-hidden">
        <div className="flex border-b border-slate-100 px-4 bg-slate-50/50">
          {(["overview", "skills", "roadmap", "feedback"] as const).map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`px-4 py-3 text-sm font-medium transition-colors border-b-2 capitalize ${
                activeTab === tab
                  ? "border-violet-500 text-violet-600 bg-white -mb-px"
                  : "border-transparent text-slate-500 hover:text-slate-700 hover:bg-white/50"
              }`}
            >
              {tab === "overview" && "Overview"}
              {tab === "skills" && "Skills & Competencies"}
              {tab === "roadmap" && "Development Roadmap"}
              {tab === "feedback" && "Performance & Feedback"}
            </button>
          ))}
        </div>

        <div className="p-6">
          {/* Overview Tab */}
          {activeTab === "overview" && (
            <div className="space-y-6">
              <div className="grid grid-cols-2 gap-6">
                {/* Skills Radar */}
                <div>
                  <h4 className="text-sm font-semibold text-slate-700 mb-4">Skills Overview</h4>
                  <ResponsiveContainer width="100%" height={250}>
                    <RadarChart data={skills}>
                      <PolarGrid stroke="#e2e8f0" />
                      <PolarAngleAxis dataKey="subject" tick={{ fontSize: 11, fill: "#94a3b8" }} />
                      <Radar dataKey="score" stroke="#7c3aed" fill="#7c3aed" fillOpacity={0.2} strokeWidth={2} />
                    </RadarChart>
                  </ResponsiveContainer>
                </div>

                {/* Training Progress */}
                <div>
                  <h4 className="text-sm font-semibold text-slate-700 mb-4">Training Progress</h4>
                  <ResponsiveContainer width="100%" height={250}>
                    <BarChart data={trainingData} layout="vertical">
                      <CartesianGrid stroke="#f1f5f9" horizontal={false} />
                      <XAxis type="number" domain={[0, 100]} tick={{ fontSize: 11, fill: "#94a3b8" }} />
                      <YAxis dataKey="name" type="category" tick={{ fontSize: 11, fill: "#94a3b8" }} width={80} />
                      <Tooltip />
                      <Bar dataKey="completed" fill="#7c3aed" radius={[0, 4, 4, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </div>

              {/* AI Insights */}
              <div className="bg-gradient-to-r from-indigo-50 to-violet-50 border border-indigo-100 rounded-xl p-4">
                <div className="flex items-start gap-3">
                  <div className="w-8 h-8 rounded-lg bg-violet-100 flex items-center justify-center shrink-0">
                    <Sparkles size={16} className="text-violet-600" />
                  </div>
                  <div>
                    <div className="text-sm font-semibold text-violet-800">AI Career Insights</div>
                    <p className="text-xs text-violet-600 mt-1 leading-relaxed">
                      Based on your current skills and performance trends, you are on track for promotion to
                      <strong className="text-violet-800"> {candidate.targetPosition}</strong> within the predicted timeline.
                      Focus on completing your development roadmap to accelerate your career growth.
                      {candidate.skillGaps.length > 0 && (
                        <span className="block mt-1 text-violet-700">
                          💡 Recommended: Improve {candidate.skillGaps[0]?.competency}
                          to close the skills gap.
                        </span>
                      )}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Skills & Competencies Tab */}
          {activeTab === "skills" && (
            <div>
              <div className="grid grid-cols-2 gap-6">
                {/* Radar Chart */}
                <div>
                  <div className="flex items-center justify-between mb-4">
                    <h4 className="text-sm font-semibold text-slate-700">Skills Assessment</h4>
                    <Badge label="AI Powered" variant="purple" />
                  </div>
                  <ResponsiveContainer width="100%" height={300}>
                    <RadarChart data={skills}>
                      <PolarGrid stroke="#e2e8f0" />
                      <PolarAngleAxis dataKey="subject" tick={{ fontSize: 12, fill: "#94a3b8", fontWeight: 500 }} />
                      <Radar dataKey="score" stroke="#7c3aed" fill="#7c3aed" fillOpacity={0.2} strokeWidth={2} />
                    </RadarChart>
                  </ResponsiveContainer>
                </div>

                {/* Skill Details */}
                <div>
                  <h4 className="text-sm font-semibold text-slate-700 mb-4">Skill Analysis</h4>
                  <div className="space-y-4">
                    {skills.map(sk => (
                      <div key={sk.subject} className="group">
                        <div className="flex justify-between text-xs mb-1">
                          <span className="text-slate-600 group-hover:text-slate-800 transition-colors">{sk.subject}</span>
                          <span className={`font-mono font-semibold ${
                            sk.score >= 80 ? "text-emerald-600" :
                            sk.score >= 65 ? "text-violet-600" :
                            "text-amber-600"
                          }`}>
                            {sk.score}/100
                          </span>
                        </div>
                        <ProgressBar
                          value={sk.score}
                          color={sk.score >= 80 ? "#10b981" : sk.score >= 65 ? "#7c3aed" : "#f59e0b"}
                          showLabel={false}
                        />
                      </div>
                    ))}

                    {/* Skill Gaps */}
                    {candidate.skillGaps.length > 0 && (
                      <div className="mt-4 p-4 bg-red-50 rounded-xl border border-red-100">
                        <div className="flex items-start gap-3">
                          <AlertTriangle size={16} className="text-red-600 mt-0.5 shrink-0" />
                          <div>
                            <div className="text-xs font-semibold text-red-700">Skill Gaps Identified</div>
                            <ul className="mt-1 space-y-1">
                              {candidate.skillGaps.slice(0, 3).map((gap, i) => (
                                <li key={i} className="text-xs text-red-600">
                                  {gap.competency}: {gap.current} / {gap.required} (gap: {gap.gap} points)
                                </li>
                              ))}
                            </ul>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* AI Recommendation */}
                    <div className="mt-4 p-4 bg-violet-50 rounded-xl border border-violet-100">
                      <div className="flex items-start gap-3">
                        <div className="w-8 h-8 rounded-lg bg-violet-100 flex items-center justify-center shrink-0">
                          <Sparkles size={16} className="text-violet-600" />
                        </div>
                        <div>
                          <div className="text-xs font-semibold text-violet-700">AI Skill Gap Analysis</div>
                          <div className="text-xs text-violet-600 mt-1 leading-relaxed">
                            Your overall skill proficiency is <strong>{Math.round(avgSkillScore)}%</strong> with a
                            <strong> {skillGap}%</strong> skill gap.
                            {candidate.skillGaps.length > 0 && (
                              <> Focus on improving <strong>{candidate.skillGaps[0]?.competency}</strong> to become fully ready for {candidate.targetPosition}.</>
                            )}
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Training Recommendations */}
              {candidate.trainingRecommendations.length > 0 && (
                <div className="mt-6">
                  <h4 className="text-sm font-semibold text-slate-700 mb-3">AI Training Recommendations</h4>
                  <div className="grid grid-cols-2 gap-3">
                    {candidate.trainingRecommendations.map((rec, i) => (
                      <div key={i} className="border border-slate-100 rounded-lg p-3 hover:border-violet-200 transition-colors">
                        <div className="flex items-start justify-between">
                          <div>
                            <div className="text-sm font-medium text-slate-700">{rec.title}</div>
                            <div className="text-xs text-slate-400 mt-0.5">{rec.reason}</div>
                          </div>
                          <Badge label={rec.priority} variant={rec.priority === 'high' ? 'danger' : rec.priority === 'medium' ? 'warning' : 'info'} />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Development Roadmap Tab */}
          {activeTab === "roadmap" && (
            <div>
              <div className="flex items-center justify-between mb-6">
                <div>
                  <h4 className="text-sm font-semibold text-slate-700">Action Plan</h4>
                  <p className="text-xs text-slate-500 mt-0.5">{completedActions} of {totalActions} tasks completed</p>
                </div>
                <Badge label={`${roadmapProgress}% Complete`} variant="purple" />
              </div>

              {candidate.actionPlan.length > 0 ? (
                <div className="space-y-3">
                  {candidate.actionPlan.map((step, i) => (
                    <div key={step.id || i} className="group flex items-center gap-4 p-4 rounded-xl border border-slate-100 hover:border-violet-200 hover:bg-violet-50/30 transition-all hover:shadow-sm">
                      <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 font-bold text-sm transition-colors ${
                        step.status === "completed"
                          ? "bg-emerald-100 text-emerald-700 group-hover:bg-emerald-200"
                          : step.status === "in-progress"
                            ? "bg-violet-100 text-violet-700 group-hover:bg-violet-200"
                            : "bg-slate-100 text-slate-400 group-hover:bg-slate-200"
                      }`}>
                        {step.status === "completed" ? <Check size={16} /> : i + 1}
                      </div>
                      <div className="flex-1">
                        <div className="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors">{step.description}</div>
                        <div className="text-xs text-slate-400 mt-0.5 flex items-center gap-1">
                          <Calendar size={10} /> Deadline: {step.dueDate}
                        </div>
                      </div>
                      <Badge label={step.status} variant={getStatusVariant(step.status)} />
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8 text-slate-400 text-sm">
                  No action plan defined yet. Your HR Admin will create one for you.
                </div>
              )}

              {/* Roadmap Progress */}
              {totalActions > 0 && (
                <div className="mt-6 p-4 bg-slate-50 rounded-xl border border-slate-100">
                  <div className="flex items-center justify-between text-sm mb-2">
                    <span className="text-slate-600">Overall Roadmap Progress</span>
                    <span className="font-semibold text-slate-800">{roadmapProgress}%</span>
                  </div>
                  <div className="w-full h-2 bg-slate-200 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all duration-1000"
                      style={{ width: `${roadmapProgress}%` }}
                    />
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Performance & Feedback Tab */}
          {activeTab === "feedback" && (
            <div>
              <div className="grid grid-cols-2 gap-6">
                {/* Performance Trend */}
                <div>
                  <h4 className="text-sm font-semibold text-slate-700 mb-4">Performance Trend</h4>
                  <ResponsiveContainer width="100%" height={280}>
                    <LineChart data={performanceData}>
                      <CartesianGrid stroke="#f1f5f9" strokeDasharray="3 3" />
                      <XAxis dataKey="month" tick={{ fontSize: 11, fill: "#94a3b8" }} />
                      <YAxis tick={{ fontSize: 11, fill: "#94a3b8" }} domain={[60, 100]} />
                      <Tooltip />
                      <Legend />
                      <Line type="monotone" dataKey="score" stroke="#7c3aed" strokeWidth={2} name="Your Score" />
                      <Line type="monotone" dataKey="target" stroke="#94a3b8" strokeWidth={1.5} strokeDasharray="4 4" name="Target" />
                    </LineChart>
                  </ResponsiveContainer>
                </div>

                {/* Strengths & Development Areas */}
                <div>
                  <h4 className="text-sm font-semibold text-slate-700 mb-4">Feedback Summary</h4>
                  <div className="space-y-4">
                    <div>
                      <div className="text-xs font-medium text-emerald-700 flex items-center gap-2 mb-2">
                        <div className="w-1 h-4 rounded-full bg-emerald-500" />
                        Strengths
                      </div>
                      {candidate.strengths.length > 0 ? (
                        candidate.strengths.map((s, i) => (
                          <div key={i} className="flex items-center gap-2 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2.5 mb-1.5 text-xs text-emerald-700 hover:bg-emerald-100 transition-colors">
                            <CheckCircle2 size={12} className="shrink-0 text-emerald-500" />
                            <span>{s}</span>
                          </div>
                        ))
                      ) : (
                        <div className="text-xs text-slate-400">No strengths recorded</div>
                      )}
                    </div>
                    <div>
                      <div className="text-xs font-medium text-amber-700 flex items-center gap-2 mb-2">
                        <div className="w-1 h-4 rounded-full bg-amber-500" />
                        Development Areas
                      </div>
                      {candidate.developmentAreas.length > 0 ? (
                        candidate.developmentAreas.map((d, i) => (
                          <div key={i} className="flex items-center gap-2 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2.5 mb-1.5 text-xs text-amber-700 hover:bg-amber-100 transition-colors">
                            <AlertTriangle size={12} className="shrink-0 text-amber-500" />
                            <span>{d}</span>
                          </div>
                        ))
                      ) : (
                        <div className="text-xs text-slate-400">No development areas identified</div>
                      )}
                    </div>
                  </div>

                  {/* Performance Summary */}
                  <div className="mt-4 p-4 bg-blue-50 rounded-xl border border-blue-100">
                    <div className="flex items-start gap-3">
                      <Activity size={16} className="text-blue-600 mt-0.5 shrink-0" />
                      <div>
                        <div className="text-xs font-semibold text-blue-700">Performance Summary</div>
                        <div className="text-xs text-blue-600 mt-1">
                          Your current performance rating is <strong>{candidate.lastEval}</strong>.
                          {candidate.isReady ? (
                            " You are ready for promotion!"
                          ) : (
                            " Continue developing your skills to reach the next level."
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {toast && (
        <Toast
          message={toast.message}
          type={toast.type}
          onClose={() => setToast(null)}
        />
      )}
    </div>
  );
}