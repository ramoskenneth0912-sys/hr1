import { Link } from 'react-router-dom';
import {
  Award, BookOpen, Compass, GraduationCap, LayoutDashboard, Target, Trophy,
  ArrowRight, Sparkles,
} from 'lucide-react';
import { Card, PageHeader } from '@hr1/components/shared-ui';
import { BD, TX, TX2, V } from '@hr1/lib/constants';
import { useAuth } from '@/auth/AuthContext';

const MODULES = [
  { to: '/ess/goals', title: 'My Goals', desc: 'Track progress on assigned goals', icon: Target, bg: '#EDE9FE', color: V, iconColor: V },
  { to: '/ess/performance', title: 'My Performance', desc: 'Reviews, self-assessments & feedback', icon: Compass, bg: '#EFF6FF', color: '#1D4ED8', iconColor: '#3B82F6' },
  { to: '/ess/competencies', title: 'My Competencies', desc: 'Skills and competency levels', icon: Award, bg: '#F0FDF4', color: '#047857', iconColor: '#10B981' },
  { to: '/ess/development', title: 'Development Plans', desc: 'Create and track your plan', icon: GraduationCap, bg: '#FFF7ED', color: '#C2410C', iconColor: '#F97316' },
  { to: '/ess/recognition', title: 'Recognition', desc: 'Achievements awarded to you', icon: Trophy, bg: '#FDF2F8', color: '#BE185D', iconColor: '#EC4899' },
  { to: '/ess/trainings', title: 'My Trainings', desc: 'Training programs assigned to you', icon: BookOpen, bg: '#F8FAFC', color: '#334155', iconColor: '#64748B' },
  { to: '/ess/learning', title: 'My Learning', desc: 'Approved learning resources', icon: Sparkles, bg: '#F5F3FF', color: '#5B21B6', iconColor: '#7B2CBF' },
];

export default function Dashboard() {
  const { user } = useAuth();
  const name = user?.username ?? (user?.email?.split('@')[0] ?? '');

  const firstName = name ? name.charAt(0).toUpperCase() + name.slice(1) : 'there';

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Welcome back, ${firstName}`}
        subtitle="Your workspace and self-service modules"
      />

      <Card>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: '.75rem' }}>
          <div
            style={{
              width: 40,
              height: 40,
              borderRadius: 'var(--radius-sm)',
              background: 'var(--purple-bg)',
              color: V,
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexShrink: 0,
            }}
          >
            <LayoutDashboard size={19} />
          </div>
          <div>
            <h2 style={{ fontSize: '1.05rem', fontWeight: 700, color: TX, margin: 0, letterSpacing: '-0.01em' }}>
              Employee self-service
            </h2>
            <p style={{ fontSize: '.84rem', color: TX2, marginTop: '.25rem', marginBottom: 0 }}>
              Goal tracking, performance reviews, competencies, development plans and more —
              all powered by the existing HR1 API.
            </p>
          </div>
        </div>
      </Card>

      <div className="module-cards">
        {MODULES.map((m) => (
          <Link key={m.to} to={m.to} className="module-card">
            <span className="module-num" style={{ background: m.color }}>
              <m.icon size={16} />
            </span>
            <h3>{m.title}</h3>
            <p>{m.desc}</p>
            <span
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                marginTop: '.75rem',
                fontSize: '.75rem',
                fontWeight: 600,
                color: TX,
              }}
            >
              Open <ArrowRight size={13} style={{ color: TX2 }} />
            </span>
          </Link>
        ))}
      </div>

      <div style={{ borderTop: `1px solid ${BD}`, paddingTop: '1rem' }}>
        <p style={{ fontSize: '.75rem', color: TX2, margin: 0 }}>
          HR1 standalone SPA — React • TypeScript • Tailwind CSS • Vite.
        </p>
      </div>
    </div>
  );
}