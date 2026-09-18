import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Loader2, MapPin, Search } from 'lucide-react';
import PublicHeader from '@/components/PublicHeader';
import { getJobs } from '@/services/jobs.service';
import type { Job } from '@/services/jobs.service';
import { NodeApiError } from '@/services/api';
import { formatEmploymentType, formatJobDate } from '@/utils/jobFormat';

/**
 * Public job board — lists OPEN vacancies from the Node.js/Express REST API
 * (`GET /api/v1/jobs`). Deliberately public/unprotected and does NOT import
 * `useAuth`/`AuthContext`: applicants must be able to browse jobs without
 * being treated as authenticated dashboard users (existing HR1 rule).
 */
export default function JobsPage() {
  const [jobs, setJobs] = useState<Job[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        // A generous limit is fetched once so the search box below can filter
        // instantly across fields (job code, location, department) that the
        // Node API's own `search` filter does not combine into one param.
        const res = await getJobs({ limit: 100 });
        if (cancelled) return;
        setJobs(res.data);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof NodeApiError ? err.message : 'Unable to load job vacancies.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const filteredJobs = useMemo(() => {
    if (!jobs) return null;
    const term = search.trim().toLowerCase();
    if (!term) return jobs;
    return jobs.filter((job) => {
      const haystack = [job.title, job.job_code, job.location, job.department ?? '']
        .join(' ')
        .toLowerCase();
      return haystack.includes(term);
    });
  }, [jobs, search]);

  return (
    <div className="public-shell">
      <PublicHeader />
      <main className="public-main">
        <div className="public-hero">
          <h1 className="public-hero-title">Available Job Opportunities</h1>
          <p className="public-hero-subtitle">
            Explore current openings at HR1 and start your application — no account required to browse.
          </p>
        </div>

        <div className="public-search">
          <Search size={16} className="public-search-icon" aria-hidden="true" />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by job title, job code, location, or department…"
            aria-label="Search jobs"
          />
        </div>

        {error ? (
          <div className="public-alert public-alert-danger">{error}</div>
        ) : loading ? (
          <div className="public-state">
            <Loader2 size={18} className="animate-spin" />
            Loading available jobs…
          </div>
        ) : filteredJobs && filteredJobs.length > 0 ? (
          <div className="job-grid">
            {filteredJobs.map((job) => {
              const employmentType = formatEmploymentType(job.employment_type);
              const posted = formatJobDate(job.posted_date);
              const closing = formatJobDate(job.closing_date);
              return (
                <Link key={job.id} to={`/jobs/${job.id}`} className="job-card">
                  <div className="job-card-head">
                    <h2 className="job-card-title">{job.title}</h2>
                    <span className="badge badge-success">Open</span>
                  </div>
                  <p className="job-card-code">{job.job_code}</p>
                  <ul className="job-card-meta">
                    <li>
                      <MapPin size={13} aria-hidden="true" /> {job.location}
                    </li>
                    {job.department ? <li>{job.department}</li> : null}
                    {employmentType ? <li>{employmentType}</li> : null}
                    <li>
                      {job.vacancies} {job.vacancies === 1 ? 'vacancy' : 'vacancies'}
                    </li>
                  </ul>
                  <div className="job-card-foot">
                    <span>{posted ? `Posted ${posted}` : 'Posted date not set'}</span>
                    <span>{closing ? `Closes ${closing}` : 'No closing date'}</span>
                  </div>
                  <span className="btn btn-outline btn-sm job-card-cta">View Details</span>
                </Link>
              );
            })}
          </div>
        ) : (
          <div className="public-state">No job vacancies found.</div>
        )}
      </main>
    </div>
  );
}
