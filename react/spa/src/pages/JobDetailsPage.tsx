import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Loader2, MapPin } from 'lucide-react';
import PublicHeader from '@/components/PublicHeader';
import { getJobById } from '@/services/jobs.service';
import type { Job } from '@/services/jobs.service';
import { formatEmploymentType, formatJobDate } from '@/utils/jobFormat';

type LoadState = 'loading' | 'loaded' | 'not-found' | 'error';

/**
 * Public job-details page (`/jobs/:id`) — reads `GET /api/v1/jobs/:id`
 * (public, OPEN jobs only). Public/unprotected: does not import
 * `useAuth`/`AuthContext`. Only renders sections the API actually returns —
 * no fabricated fields.
 */
export default function JobDetailsPage() {
  const { id } = useParams<{ id: string }>();
  const [job, setJob] = useState<Job | null>(null);
  const [state, setState] = useState<LoadState>('loading');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setState('loading');
      try {
        const result = await getJobById(id ?? '');
        if (cancelled) return;
        if (!result) {
          setState('not-found');
          return;
        }
        setJob(result);
        setState('loaded');
      } catch {
        if (cancelled) return;
        setState('error');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  return (
    <div className="public-shell">
      <PublicHeader />
      <main className="public-main">
        <Link to="/jobs" className="public-back-link">
          <ArrowLeft size={14} /> Back to Available Jobs
        </Link>

        {state === 'loading' ? (
          <div className="public-state">
            <Loader2 size={18} className="animate-spin" />
            Loading job details…
          </div>
        ) : state === 'not-found' ? (
          <div className="public-state">Job vacancy not found.</div>
        ) : state === 'error' ? (
          <div className="public-alert public-alert-danger">Unable to load this job vacancy.</div>
        ) : job ? (
          <div className="job-detail-grid">
            <article className="job-detail-main">
              <div className="job-detail-head">
                <h1 className="job-detail-title">{job.title}</h1>
                <span className="badge badge-success">Open</span>
              </div>
              <p className="job-card-code">{job.job_code}</p>

              {job.description ? (
                <section className="job-detail-section">
                  <h2>Job Description</h2>
                  <p>{job.description}</p>
                </section>
              ) : null}

              {job.requirements ? (
                <section className="job-detail-section">
                  <h2>Requirements</h2>
                  <p>{job.requirements}</p>
                </section>
              ) : null}

              {job.qualifications ? (
                <section className="job-detail-section">
                  <h2>Qualifications</h2>
                  <p>{job.qualifications}</p>
                </section>
              ) : null}

              {job.required_skills ? (
                <section className="job-detail-section">
                  <h2>Skills</h2>
                  <p>{job.required_skills}</p>
                </section>
              ) : null}

              {job.education_requirement ? (
                <section className="job-detail-section">
                  <h2>Education</h2>
                  <p>{job.education_requirement}</p>
                </section>
              ) : null}

              {job.experience_requirement ? (
                <section className="job-detail-section">
                  <h2>Experience</h2>
                  <p>{job.experience_requirement}</p>
                </section>
              ) : null}
            </article>

            <aside className="job-detail-side">
              <div className="panel job-detail-panel">
                <dl className="job-detail-facts">
                  <div>
                    <dt>Location</dt>
                    <dd>
                      <MapPin size={13} aria-hidden="true" /> {job.location}
                    </dd>
                  </div>
                  {job.department ? (
                    <div>
                      <dt>Department</dt>
                      <dd>{job.department}</dd>
                    </div>
                  ) : null}
                  {formatEmploymentType(job.employment_type) ? (
                    <div>
                      <dt>Employment Type</dt>
                      <dd>{formatEmploymentType(job.employment_type)}</dd>
                    </div>
                  ) : null}
                  <div>
                    <dt>Vacancies</dt>
                    <dd>{job.vacancies}</dd>
                  </div>
                  {formatJobDate(job.posted_date) ? (
                    <div>
                      <dt>Posted</dt>
                      <dd>{formatJobDate(job.posted_date)}</dd>
                    </div>
                  ) : null}
                  {formatJobDate(job.closing_date) ? (
                    <div>
                      <dt>Closing Date</dt>
                      <dd>{formatJobDate(job.closing_date)}</dd>
                    </div>
                  ) : null}
                </dl>
                <Link to={`/jobs/${job.id}/apply`} className="btn btn-primary job-apply-cta">
                  Apply Now
                </Link>
              </div>
            </aside>
          </div>
        ) : null}
      </main>
    </div>
  );
}
