import { Link } from 'react-router-dom';

/**
 * Header for the public, unauthenticated applicant experience (jobs board,
 * job details, application form).
 *
 * Deliberately NOT the dashboard `Layout`/sidebar: applicants are not
 * authenticated portal users and must never see the Dashboard / My
 * Applications / My Profile / Logout navigation that signed-in employees
 * see. This is just a lightweight public nav bar (logo, "Available Jobs",
 * "Log in") — the `Log in` link only points at the existing PHP-backed
 * `/login` route and does not change how login works.
 */
export default function PublicHeader() {
  return (
    <header className="public-header">
      <div className="public-header-inner">
        <Link to="/jobs" className="public-header-brand">
          <span className="public-header-logo">H</span>
          <span className="public-header-name">HR1</span>
        </Link>
        <nav className="public-header-nav">
          <Link to="/jobs" className="public-header-link">
            Available Jobs
          </Link>
          <Link to="/login" className="btn btn-outline btn-sm">
            Log in
          </Link>
        </nav>
      </div>
    </header>
  );
}
