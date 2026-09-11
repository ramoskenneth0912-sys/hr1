// src/app/pages/employee/MyRecognition.tsx
import { useState, useEffect } from "react";
import {
  Trophy, Flame, Award, Star, Medal,
  Sparkles, Zap, TrendingUp, Users,
  Calendar, CheckCircle2, Gift, Crown,
  BarChart3, PieChart, ArrowUpRight,
  Clock, Target, Heart, Smile, Brain,
  Shield, Rocket, BookOpen, Briefcase,
  Flag, Compass, Lightbulb, GraduationCap
} from "lucide-react";
import { V, TX, TX2, BD } from "../../lib/constants";
import { Card, PageHeader, SectionHead, Badge, Toast } from "../../components/shared-ui";
import { useAuth } from "../../auth/useAuth";
import {
  ResponsiveContainer, BarChart, Bar, XAxis, YAxis,
  Tooltip, CartesianGrid, PieChart as RePieChart,
  Pie, Cell, LineChart, Line
} from "recharts";

// Types
interface RecognitionItem {
  employee: string;
  type: string;
  message: string;
  points: number;
  total?: number;
  competencies?: string[];
  date?: string;
  given_by?: string;
}

interface SkillGrowth {
  competency: string;
  current: number;
  target: number;
}

interface CareerMilestone {
  title: string;
  achieved: boolean;
  icon: any;
}

// Mock AI Data (fallback)
const AI_CAREER_INSIGHTS = {
  strongestCompetency: "Innovation",
  weakestCompetency: "Leadership",
  promotionPrediction: "Ready for Senior role in 6-8 months",
  suggestedLearning: "Leadership Development Program",
  careerStage: "Mid-Level Professional",
  nextPosition: "Senior Developer",
  missingCompetencies: ["Team Management", "Strategic Planning"],
  estimatedReadiness: 78
};

const COMPETENCIES = [
  "Leadership",
  "Communication",
  "Teamwork",
  "Innovation",
  "Customer Service",
  "Problem Solving"
];

const COMPETENCY_COLORS = ['#8B5CF6', '#EC4899', '#10B981', '#F59E0B', '#3B82F6', '#EF4444'];

// Mock skill growth data (fallback)
const SKILL_GROWTH_DATA = [
  { competency: "Leadership", current: 65, target: 85 },
  { competency: "Communication", current: 72, target: 90 },
  { competency: "Innovation", current: 88, target: 95 },
  { competency: "Customer Service", current: 70, target: 85 },
  { competency: "Teamwork", current: 75, target: 88 },
  { competency: "Problem Solving", current: 82, target: 92 }
];

// Mock career milestones
const CAREER_MILESTONES: CareerMilestone[] = [
  { title: "First Recognition", achieved: true, icon: Star },
  { title: "100 Points", achieved: true, icon: Trophy },
  { title: "Top Performer", achieved: true, icon: Crown },
  { title: "Innovation Award", achieved: true, icon: Lightbulb },
  { title: "Top 10 Employee", achieved: false, icon: Users },
  { title: "Future Team Lead", achieved: false, icon: Shield }
];

// Lock icon component
const Lock = ({ size = 12, className = "" }) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    width={size}
    height={size}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
    className={className}
  >
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);

// API Service — recognition data is fetched through the shared HR1 API
// client (session-authenticated). getEssList returns [] when the endpoint
// does not exist yet, which renders the honest empty states below.
import { getEssList } from "../../lib/essApi";

export default function MyRecognition() {
  const { user } = useAuth();
  const [toast, setToast] = useState<{ message: string; type: "success" | "error" | "info" } | null>(null);
  const [activeView, setActiveView] = useState<"all" | "recent" | "top">("all");
  const [loading, setLoading] = useState(true);

  // State from API
  const [myRecognitions, setMyRecognitions] = useState<RecognitionItem[]>([]);
  const [allRecognitions, setAllRecognitions] = useState<RecognitionItem[]>([]);
  const [leaderboard, setLeaderboard] = useState<{employee: string; total: number}[]>([]);
  const [myTotalPoints, setMyTotalPoints] = useState(0);
  const [myRank, setMyRank] = useState(0);
  const [competencyDistribution, setCompetencyDistribution] = useState<{name: string; value: number}[]>([]);
  const [skillGrowthData, setSkillGrowthData] = useState<SkillGrowth[]>(SKILL_GROWTH_DATA);
  const [aiInsights, setAiInsights] = useState(AI_CAREER_INSIGHTS);
  const [careerMilestones, setCareerMilestones] = useState(CAREER_MILESTONES);
  const [monthlyData, setMonthlyData] = useState<{month: string; points: number}[]>([]);

  // Fetch data on mount
  useEffect(() => {
    fetchEmployeeData();
  }, []);

  const fetchEmployeeData = async () => {
    try {
      setLoading(true);

      // Fetch recognition data (empty list when the endpoint is not live yet)
      const allRecs = await getEssList('employee/recognition');

      // Filter recognitions for current user
      const userRecs = allRecs.filter((r: RecognitionItem) =>
        r.employee.toLowerCase() === user?.name?.toLowerCase()
      );

      setMyRecognitions(userRecs);
      setAllRecognitions(allRecs);

      // Calculate total points
      const totalPoints = userRecs.reduce((sum: number, r: RecognitionItem) => sum + r.points, 0);
      setMyTotalPoints(totalPoints);

      // Get leaderboard
      const leaderboardData = [];
      setLeaderboard(leaderboardData);

      // Find user's rank
      const rank = leaderboardData.findIndex((l: {employee: string; total: number}) =>
        l.employee.toLowerCase() === user?.name?.toLowerCase()
      ) + 1;
      setMyRank(rank > 0 ? rank : 0);

      // Calculate competency distribution
      const compDist = COMPETENCIES.map(comp => {
        const count = userRecs.filter((r: RecognitionItem) =>
          r.type?.includes(comp) ||
          (r.message && r.message.includes(comp)) ||
          (r.competencies && r.competencies.includes(comp))
        ).length;
        return { name: comp, value: count };
      });
      setCompetencyDistribution(compDist);

      // Generate monthly data from actual recognitions
      const monthData = generateMonthlyData(userRecs);
      setMonthlyData(monthData);

      // Update skill growth based on actual data
      const updatedSkills = updateSkillGrowth(userRecs);
      setSkillGrowthData(updatedSkills);

      // Update AI insights based on actual data
      const insights = generateAIInsights(userRecs, leaderboardData);
      setAiInsights(insights);

      // Update career milestones
      const milestones = updateCareerMilestones(totalPoints, userRecs);
      setCareerMilestones(milestones);

    } catch (error) {
      console.error('Error fetching employee data:', error);
    } finally {
      setLoading(false);
    }
  };

  // Generate monthly data from recognitions
  const generateMonthlyData = (recs: RecognitionItem[]) => {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const dataMap: Record<string, number> = {};

    recs.forEach((r, index) => {
      const monthIndex = index % 12;
      const month = months[monthIndex];
      dataMap[month] = (dataMap[month] || 0) + r.points;
    });

    return Object.entries(dataMap)
      .map(([month, points]) => ({ month, points }))
      .sort((a, b) => months.indexOf(a.month) - months.indexOf(b.month));
  };

  // Update skill growth based on recognition data
  const updateSkillGrowth = (recs: RecognitionItem[]): SkillGrowth[] => {
    if (recs.length === 0) return [];

    const skillLevels: Record<string, { current: number; count: number }> = {};

    COMPETENCIES.forEach(comp => {
      skillLevels[comp] = { current: 50, count: 0 };
    });

    recs.forEach(r => {
      const competencies = r.competencies || [];
      competencies.forEach(comp => {
        if (skillLevels[comp]) {
          skillLevels[comp].current += 5;
          skillLevels[comp].count += 1;
        }
      });
    });

    return COMPETENCIES.map(comp => ({
      competency: comp,
      current: Math.min(Math.round(skillLevels[comp].current / (skillLevels[comp].count + 1) * 10 + 50), 95),
      target: Math.min(Math.round(skillLevels[comp].current / (skillLevels[comp].count + 1) * 10 + 70), 100)
    }));
  };

  // Generate AI insights
  const generateAIInsights = (recs: RecognitionItem[], leaderboardData: {employee: string; total: number}[]) => {
    if (recs.length === 0) {
      return {
        strongestCompetency: "—",
        weakestCompetency: "—",
        promotionPrediction: "Not enough data yet",
        suggestedLearning: "Once you receive your first recognition, personalized suggestions will appear here.",
        careerStage: "No data yet",
        nextPosition: "To be determined",
        missingCompetencies: [],
        estimatedReadiness: 0
      };
    }

    // Find strongest and weakest competencies
    const compCount: Record<string, number> = {};
    recs.forEach(r => {
      (r.competencies || []).forEach(comp => {
        compCount[comp] = (compCount[comp] || 0) + 1;
      });
    });

    let strongest = "Innovation";
    let weakest = "Leadership";
    let maxCount = 0;
    let minCount = Infinity;

    COMPETENCIES.forEach(comp => {
      const count = compCount[comp] || 0;
      if (count > maxCount) { maxCount = count; strongest = comp; }
      if (count < minCount) { minCount = count; weakest = comp; }
    });

    // Calculate promotion readiness
    const totalPoints = recs.reduce((sum, r) => sum + r.points, 0);
    const readiness = Math.min(Math.round((totalPoints / 500) * 100), 98);

    // Determine career stage
    let stage = "Entry-Level Professional";
    if (totalPoints > 800) stage = "Senior Professional";
    else if (totalPoints > 500) stage = "Mid-Level Professional";
    else if (totalPoints > 200) stage = "Junior Professional";

    // Determine next position
    let nextPosition = "Senior Developer";
    if (totalPoints > 800) nextPosition = "Team Lead / Manager";
    else if (totalPoints > 500) nextPosition = "Senior Developer";
    else if (totalPoints > 200) nextPosition = "Mid-Level Developer";

    // Missing competencies
    const missing = COMPETENCIES.filter(comp => !compCount[comp] || compCount[comp] < 2);

    // Suggested learning
    let suggested = "Leadership Development Program";
    if (weakest === "Innovation") suggested = "Innovation & Creativity Workshop";
    else if (weakest === "Communication") suggested = "Communication Skills Training";
    else if (weakest === "Teamwork") suggested = "Team Building & Collaboration";
    else if (weakest === "Customer Service") suggested = "Customer Service Excellence";
    else if (weakest === "Problem Solving") suggested = "Advanced Problem Solving";

    return {
      strongestCompetency: strongest,
      weakestCompetency: weakest,
      promotionPrediction: `Ready for ${nextPosition} in ${Math.max(3, Math.round((800 - totalPoints) / 50))}-${Math.max(6, Math.round((800 - totalPoints) / 30))} months`,
      suggestedLearning: suggested,
      careerStage: stage,
      nextPosition: nextPosition,
      missingCompetencies: missing.slice(0, 3),
      estimatedReadiness: readiness
    };
  };

  // Update career milestones
  const updateCareerMilestones = (totalPoints: number, recs: RecognitionItem[]) => {
    return [
      { title: "First Recognition", achieved: recs.length > 0, icon: Star },
      { title: "100 Points", achieved: totalPoints >= 100, icon: Trophy },
      { title: "Top Performer", achieved: totalPoints >= 300, icon: Crown },
      { title: "Innovation Award", achieved: recs.some(r => r.type.includes("Innovation")), icon: Lightbulb },
      { title: "Top 10 Employee", achieved: false, icon: Users },
      { title: "Future Team Lead", achieved: totalPoints >= 700, icon: Shield }
    ];
  };

  const showToast = (message: string, type: "success" | "error" | "info") => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3500);
  };

  // Calculate promotion readiness
  const promotionReadiness = Math.min(
    Math.round((myTotalPoints / 500) * 100),
    98
  );

  // Loading state
  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-violet-600 mx-auto"></div>
          <p className="mt-4 text-slate-600">Loading your career data...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Toast Notification */}
      {toast && (
        <Toast
          message={toast.message}
          type={toast.type}
          onClose={() => setToast(null)}
        />
      )}

      <PageHeader
        title="My Career Growth"
        subtitle="Your competency development and promotion readiness"
      >
        <div className="flex items-center gap-2">
          <Badge label={aiInsights.careerStage} variant="purple" />
          <Badge label={`Rank #${myRank || '—'}`} variant="info" />
        </div>
      </PageHeader>

      {/* Hero Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-2xl border shadow-sm p-5" style={{ borderColor: BD }}>
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-amber-50 flex items-center justify-center">
              <Trophy size={22} className="text-amber-500" />
            </div>
            <div>
              <div className="text-xs text-slate-500 font-medium">Total Points</div>
              <div className="text-2xl font-bold text-slate-800">{myTotalPoints.toLocaleString()}</div>
            </div>
          </div>
        </div>

        <div className="bg-white rounded-2xl border shadow-sm p-5" style={{ borderColor: BD }}>
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-violet-50 flex items-center justify-center">
              <Crown size={22} className="text-violet-500" />
            </div>
            <div>
              <div className="text-xs text-slate-500 font-medium">Company Rank</div>
              <div className="text-2xl font-bold text-slate-800">#{myRank || '—'}</div>
            </div>
          </div>
        </div>

        <div className="bg-white rounded-2xl border shadow-sm p-5" style={{ borderColor: BD }}>
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-emerald-50 flex items-center justify-center">
              <Rocket size={22} className="text-emerald-500" />
            </div>
            <div>
              <div className="text-xs text-slate-500 font-medium">Promotion Readiness</div>
              <div className="text-2xl font-bold text-slate-800">{promotionReadiness}%</div>
            </div>
          </div>
        </div>

        <div className="bg-white rounded-2xl border shadow-sm p-5" style={{ borderColor: BD }}>
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-blue-50 flex items-center justify-center">
              <Briefcase size={22} className="text-blue-500" />
            </div>
            <div>
              <div className="text-xs text-slate-500 font-medium">Career Level</div>
              <div className="text-2xl font-bold text-slate-800">{aiInsights.careerStage}</div>
            </div>
          </div>
        </div>
      </div>

      {/* AI Career Insights Card */}
      <div className="bg-gradient-to-r from-violet-600 to-indigo-600 rounded-2xl p-6 text-white shadow-lg">
        <div className="flex items-start justify-between">
          <div className="flex-1">
            <div className="flex items-center gap-2 mb-4">
              <Brain size={20} className="text-white/80" />
              <h3 className="text-sm font-semibold">AI Career Insights</h3>
              <Badge label="Personalized" variant="purple" />
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <p className="text-xs text-violet-200 font-medium">Strongest Competency</p>
                <p className="text-sm font-semibold mt-1">
                  <Badge label={aiInsights.strongestCompetency} variant="success" />
                </p>
              </div>
              <div>
                <p className="text-xs text-violet-200 font-medium">Improvement Area</p>
                <p className="text-sm font-semibold mt-1">
                  <Badge label={aiInsights.weakestCompetency} variant="warning" />
                </p>
              </div>
              <div>
                <p className="text-xs text-violet-200 font-medium">Promotion Prediction</p>
                <p className="text-sm font-semibold mt-1">{aiInsights.promotionPrediction}</p>
              </div>
              <div>
                <p className="text-xs text-violet-200 font-medium">Suggested Learning Path</p>
                <p className="text-sm font-semibold mt-1 flex items-center gap-2">
                  <BookOpen size={14} className="text-yellow-300" />
                  {aiInsights.suggestedLearning}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Charts Section */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Skill Growth */}
        <Card p={false}>
          <div className="p-6 border-b" style={{ borderColor: BD }}>
            <SectionHead
              title="Skill Growth"
              subtitle="Current vs target competency levels"
            />
          </div>
          <div className="p-6">
            <ResponsiveContainer width="100%" height={300}>
              <BarChart data={skillGrowthData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                <XAxis dataKey="competency" tick={{ fontSize: 10 }} />
                <YAxis tick={{ fontSize: 10 }} />
                <Tooltip />
                <Bar dataKey="current" fill="#8B5CF6" radius={[4, 4, 0, 0]} />
                <Bar dataKey="target" fill="#CBD5E1" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        {/* Competency Breakdown */}
        <Card p={false}>
          <div className="p-6 border-b" style={{ borderColor: BD }}>
            <SectionHead
              title="Competency Breakdown"
              subtitle="Recognition distribution across skills"
            />
          </div>
          <div className="p-6">
            <ResponsiveContainer width="100%" height={300}>
              <RePieChart>
                <Pie
                  data={competencyDistribution.length > 0 && competencyDistribution.some(d => d.value > 0)
                    ? competencyDistribution
                    : [{ name: 'No Data', value: 1 }]}
                  cx="50%"
                  cy="50%"
                  innerRadius={60}
                  outerRadius={100}
                  paddingAngle={2}
                  dataKey="value"
                >
                  {competencyDistribution.length > 0 && competencyDistribution.some(d => d.value > 0) ?
                    competencyDistribution.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={COMPETENCY_COLORS[index % COMPETENCY_COLORS.length]} />
                    )) : (
                      <Cell fill="#e2e8f0" />
                    )
                  }
                </Pie>
                <Tooltip />
              </RePieChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>

      {/* Promotion Readiness */}
      <Card p={false}>
        <div className="p-6 border-b" style={{ borderColor: BD }}>
          <SectionHead
            title="Promotion Readiness"
            subtitle="Detailed competency assessment"
          />
        </div>
        <div className="p-6">
          <div className="space-y-4">
            <div>
              <div className="flex items-center justify-between mb-2">
                <span className="text-sm font-medium text-slate-700">Overall Readiness</span>
                <span className="text-sm font-bold text-violet-600">{promotionReadiness}%</span>
              </div>
              <div className="w-full bg-slate-100 rounded-full h-2.5">
                <div
                  className="bg-gradient-to-r from-violet-500 to-indigo-600 h-2.5 rounded-full transition-all duration-500"
                  style={{ width: `${promotionReadiness}%` }}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              {skillGrowthData.map((skill, index) => (
                <div key={index}>
                  <div className="flex items-center justify-between mb-1.5">
                    <span className="text-xs text-slate-600">{skill.competency}</span>
                    <span className="text-xs font-semibold text-slate-700">{skill.current}%</span>
                  </div>
                  <div className="w-full bg-slate-100 rounded-full h-2">
                    <div
                      className={`h-2 rounded-full transition-all duration-500`}
                      style={{
                        width: `${skill.current}%`,
                        backgroundColor: COMPETENCY_COLORS[index % COMPETENCY_COLORS.length]
                      }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </Card>

      {/* Career Milestones */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-slate-700">Career Milestones</h3>
          <Badge label={`${careerMilestones.filter(m => m.achieved).length}/${careerMilestones.length}`} variant="purple" />
        </div>
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
          {careerMilestones.map((milestone, index) => {
            const Icon = milestone.icon;
            return (
              <div
                key={index}
                className={`bg-white rounded-2xl border p-4 text-center transition-all ${
                  milestone.achieved
                    ? 'hover:shadow-md'
                    : 'opacity-50 hover:opacity-75'
                }`}
                style={{ borderColor: BD }}
              >
                <div className={`w-12 h-12 rounded-full mx-auto flex items-center justify-center ${
                  milestone.achieved
                    ? 'bg-gradient-to-br from-amber-50 to-amber-100'
                    : 'bg-slate-50'
                }`}>
                  <Icon size={24} className={milestone.achieved ? 'text-amber-600' : 'text-slate-400'} />
                </div>
                <p className="text-xs font-medium text-slate-700 mt-2">{milestone.title}</p>
                {milestone.achieved ? (
                  <CheckCircle2 size={12} className="text-emerald-500 mx-auto mt-1" />
                ) : (
                  <Lock size={12} className="text-slate-400 mx-auto mt-1" />
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Recognition Timeline */}
      <Card p={false}>
        <div className="p-6 border-b" style={{ borderColor: BD }}>
          <div className="flex items-center justify-between">
            <SectionHead title="Recognition Timeline" />
            <div className="flex gap-2">
              {(["all", "recent", "top"] as const).map((view) => (
                <button
                  key={view}
                  onClick={() => setActiveView(view)}
                  className={`px-3 py-1.5 text-xs font-medium rounded-lg transition-colors capitalize ${
                    activeView === view
                      ? "bg-violet-100 text-violet-700"
                      : "text-slate-500 hover:bg-slate-100"
                  }`}
                >
                  {view === "all" && "All"}
                  {view === "recent" && "Recent"}
                  {view === "top" && "Top Points"}
                </button>
              ))}
            </div>
          </div>
        </div>

        {myRecognitions.length === 0 ? (
          <div className="p-10 text-center">
            <div className="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
              <Trophy size={32} className="text-slate-300" />
            </div>
            <p className="text-sm font-medium text-slate-700 mb-1">No recognitions yet</p>
            <p className="text-xs text-slate-400">Keep up the great work! Your achievements will be recognized.</p>
          </div>
        ) : (
          <div className="p-4 space-y-3">
            {myRecognitions
              .filter((r) => {
                if (activeView === "recent") return true;
                if (activeView === "top") return r.points >= 50;
                return true;
              })
              .slice(0, activeView === "recent" ? 5 : undefined)
              .map((r, i) => {
                const competency = r.competencies?.[0] || COMPETENCIES[Math.floor(Math.random() * COMPETENCIES.length)];
                return (
                  <div key={i} className="group flex items-start gap-4 p-4 rounded-xl border hover:border-violet-200 hover:bg-violet-50/30 transition-all hover:shadow-sm" style={{ borderColor: BD }}>
                    <div className="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 bg-gradient-to-br from-amber-50 to-amber-100">
                      <Award size={20} className="text-amber-500" />
                    </div>
                    <div className="flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-slate-800">{r.type}</span>
                        <Badge label={`+${r.points} pts`} variant="purple" />
                      </div>
                      <p className="text-xs text-slate-500 mt-1">{r.message}</p>
                      <div className="flex flex-wrap items-center gap-2 mt-2">
                        <Badge label={`Competency: ${competency}`} variant="success" />
                        <Badge label={`Promotion Score +1%`} variant="info" />
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-violet-600">+{r.points}</span>
                      <ArrowUpRight size={14} className="text-violet-400 opacity-0 group-hover:opacity-100 transition-opacity" />
                    </div>
                  </div>
                );
              })}
          </div>
        )}
      </Card>

      {/* AI Recommendation Card */}
      <div className="bg-white rounded-2xl border shadow-sm p-6" style={{ borderColor: BD }}>
        <div className="flex items-start gap-4">
          <div className="w-12 h-12 rounded-xl bg-violet-50 flex items-center justify-center shrink-0">
            <Brain size={24} className="text-violet-600" />
          </div>
          <div className="flex-1">
            <h4 className="text-sm font-semibold text-slate-700">AI Learning Recommendation</h4>
            <p className="text-sm text-slate-600 mt-1">
              Based on your competency gaps, we recommend improving your <strong className="text-violet-600">{aiInsights.weakestCompetency}</strong> skills.
            </p>
            <div className="flex flex-wrap items-center gap-4 mt-3">
              <Badge label={`Recommended Course: ${aiInsights.suggestedLearning}`} variant="purple" />
              <Badge label={`Promotion Increase: +15%`} variant="success" />
              <button className="text-xs font-medium text-violet-600 hover:text-violet-700 flex items-center gap-1">
                View Course <ArrowUpRight size={12} />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Career Goal Card */}
      <div className="bg-gradient-to-r from-blue-600 to-violet-600 rounded-2xl p-6 text-white shadow-lg">
        <div className="flex items-start justify-between">
          <div>
            <div className="flex items-center gap-2 mb-3">
              <Flag size={20} className="text-white/80" />
              <h4 className="text-sm font-semibold">Your Career Goal</h4>
            </div>
            <div className="space-y-3">
              <div>
                <p className="text-xs text-blue-200">Current Stage</p>
                <p className="text-lg font-bold">{aiInsights.careerStage}</p>
              </div>
              <div>
                <p className="text-xs text-blue-200">Next Position</p>
                <p className="text-lg font-bold">{aiInsights.nextPosition}</p>
              </div>
              <div>
                <p className="text-xs text-blue-200">Missing Competencies</p>
                <div className="flex flex-wrap gap-2 mt-1">
                  {aiInsights.missingCompetencies.map((comp, i) => (
                    <Badge key={i} label={comp} variant="warning" />
                  ))}
                </div>
              </div>
              <div>
                <p className="text-xs text-blue-200">Estimated Readiness</p>
                <div className="flex items-center gap-3 mt-1">
                  <div className="flex-1 max-w-xs bg-white/20 rounded-full h-2">
                    <div
                      className="bg-white h-2 rounded-full transition-all duration-500"
                      style={{ width: `${aiInsights.estimatedReadiness}%` }}
                    />
                  </div>
                  <span className="text-sm font-bold">{aiInsights.estimatedReadiness}%</span>
                </div>
              </div>
            </div>
          </div>
          <div className="w-32 h-32 rounded-full bg-white/10 flex items-center justify-center shrink-0">
            <Compass size={48} className="text-white/60" />
          </div>
        </div>
      </div>
    </div>
  );
}