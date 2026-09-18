import { useMemo } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import {
  Award, BookOpen, Compass, GraduationCap, LayoutDashboard, LogOut,
  Menu, Target, Trophy, UserRound, X,
} from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/auth/AuthContext';

interface NavItem {
  to: string;
  label: string;
  icon: typeof Target;
  end?: boolean;
}

const NAV_SECTIONS: { label: string; items: NavItem[] }[] = [
  {
    label: 'Overview',
    items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true }],
  },
  {
    label: 'Performance',
    items: [
      { to: '/ess/goals', label: 'My Goals', icon: Target },
      { to: '/ess/performance', label: 'My Performance', icon: Compass },
      { to: '/ess/competencies', label: 'My Competencies', icon: Award },
      { to: '/ess/development', label: 'Development', icon: GraduationCap },
    ],
  },
  {
    label: 'Growth',
    items: [
      { to: '/ess/recognition', label: 'Recognition', icon: Trophy },
      { to: '/ess/trainings', label: 'My Trainings', icon: BookOpen },
      { to: '/ess/learning', label: 'My Learning', icon: GraduationCap },
    ],
  },
];

const TITLES: Record<string, string> = {
  '/': 'Dashboard',
  '/ess/goals': 'My Goals',
  '/ess/performance': 'My Performance',
  '/ess/competencies': 'My Competencies',
  '/ess/development': 'Development Plans',
  '/ess/recognition': 'Recognition',
  '/ess/trainings': 'My Trainings',
  '/ess/learning': 'My Learning',
};

const ROLE_LABEL: Record<string, string> = {
  hr: 'HR',
  manager: 'Manager',
  employee: 'Employee',
  applicant: 'Applicant',
};

function initials(name: string | null | undefined): string {
  if (!name) return '?';
  const parts = (name || '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

export default function Layout() {
  const { user, logout } = useAuth();
  const location = useLocation();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const pageTitle = useMemo(() => TITLES[location.pathname] ?? 'HR1 Employee Portal', [location.pathname]);
  const displayName = user?.username ?? (user?.email?.split('@')[0] ?? '');

  return (
    <div className="app-layout">
      {/* ------------------------------------------------------------------ */}
      {/* Sidebar                                                             */}
      {/* ------------------------------------------------------------------ */}
      <aside id="hr1-sidebar" className={`sidebar ${sidebarOpen ? 'open' : ''}`}>
        <div className="sidebar-brand">
          <a href="#/" onClick={() => setSidebarOpen(false)}>
            <span className="brand-logo">{(user ? initials(displayName) : 'H').slice(0, 1)}</span>
            <span className="brand-names">
              <span className="brand-name">HR1</span>
              <span className="brand-sub">Employee Portal</span>
            </span>
          </a>
          <button
            type="button"
            className="lg:hidden"
            onClick={() => setSidebarOpen(false)}
            aria-label="Close navigation"
            style={{ background: 'transparent', border: 'none', color: '#A3AED0', cursor: 'pointer' }}
          >
            <X size={20} />
          </button>
        </div>

        <nav className="sidebar-nav" aria-label="Primary">
          {NAV_SECTIONS.map((section) => (
            <div key={section.label} className="nav-section">
              <span className="nav-section-label">{section.label}</span>
              {section.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.end}
                  className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
                  onClick={() => setSidebarOpen(false)}
                >
                  <item.icon size={16} style={{ flexShrink: 0 }} />
                  {item.label}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="sidebar-footer">
          <button
            type="button"
            className="nav-link"
            onClick={() => void logout()}
            style={{ width: '100%', background: 'transparent', border: 'none', cursor: 'pointer' }}
          >
            <LogOut size={16} />
            Sign out
          </button>
          <span className="sidebar-version">HR1 SPA v0.1</span>
        </div>
      </aside>

      {/* ------------------------------------------------------------------ */}
      {/* Main column                                                         */}
      {/* ------------------------------------------------------------------ */}
      <div className="main-wrapper">
        <header className="topbar">
          <div style={{ display: 'flex', alignItems: 'center', gap: '.75rem' }}>
            <button
              type="button"
              className="lg:hidden"
              onClick={() => setSidebarOpen(true)}
              aria-label="Open navigation"
              style={{ background: 'transparent', border: 'none', color: 'var(--text-dark)', cursor: 'pointer', display: 'inline-flex' }}
            >
              <Menu size={20} />
            </button>
            <span style={{ fontSize: '.7rem', fontWeight: 600, letterSpacing: '.08em', textTransform: 'uppercase', color: 'var(--muted)' }}>
              Employee Portal
            </span>
            <span style={{ color: 'var(--border-strong)' }}>/</span>
            <h1 style={{ fontSize: '.95rem', fontWeight: 700, color: 'var(--text-dark)', margin: 0, letterSpacing: '-0.01em' }}>
              {pageTitle}
            </h1>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '.75rem' }}>
            {user ? (
              <>
                <div style={{ textAlign: 'right', lineHeight: 1.3 }}>
                  <div style={{ fontSize: '.8125rem', fontWeight: 600, color: 'var(--text-dark)' }}>{displayName}</div>
                  <div style={{ fontSize: '.6875rem', color: 'var(--muted)' }}>
                    {user.role ? (ROLE_LABEL[user.role] ?? user.role) : 'Teammate'}
                    {user.employee_no ? ` • ${user.employee_no}` : ''}
                  </div>
                </div>
                <span
                  style={{
                    width: 34,
                    height: 34,
                    borderRadius: '50%',
                    background: 'var(--purple)',
                    color: '#fff',
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: 700,
                    fontSize: '.8125rem',
                  }}
                >
                  {initials(displayName)}
                </span>
              </>
            ) : (
              <UserRound size={18} style={{ color: 'var(--muted)' }} />
            )}
          </div>
        </header>

        <main className="main-content fade-in-up">
          <Outlet />
        </main>
      </div>
    </div>
  );
}