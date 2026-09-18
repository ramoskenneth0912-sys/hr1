import { useEffect, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Loader2, UploadCloud } from 'lucide-react';
import PublicHeader from '@/components/PublicHeader';
import { getJobById } from '@/services/jobs.service';
import type { Job } from '@/services/jobs.service';

type LoadState = 'loading' | 'loaded' | 'not-found' | 'error';

interface FormValues {
  fullName: string;
  email: string;
  contactNumber: string;
  address: string;
}

const EMPTY_FORM: FormValues = { fullName: '', email: '', contactNumber: '', address: '' };

/**
 * Public "start application" page (`/jobs/:id/apply`).
 *
 * Step 4 scope is UI-only: the actual applicant-submission backend (file
 * upload + `applicants` row insert) is reserved for Step 5. This page
 * validates the form client-side and clearly reports that submission is not
 * yet wired up — it never fakes a successful submission or writes any data.
 */
export default function JobApplyPage() {
  const { id } = useParams<{ id: string }>();
  const [job, setJob] = useState<Job | null>(null);
  const [state, setState] = useState<LoadState>('loading');

  const [values, setValues] = useState<FormValues>(EMPTY_FORM);
  const [resumeName, setResumeName] = useState<string | null>(null);
  const [errors, setErrors] = useState<Partial<Record<keyof FormValues | 'resume', string>>>({});
  const [submitAttempted, setSubmitAttempted] = useState(false);

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

  const setField = (field: keyof FormValues) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setValues((prev) => ({ ...prev, [field]: e.target.value }));
  };

  const validate = (): boolean => {
    const next: Partial<Record<keyof FormValues | 'resume', string>> = {};
    if (!values.fullName.trim()) next.fullName = 'Full name is required.';
    if (!values.email.trim()) {
      next.email = 'Email address is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) {
      next.email = 'Enter a valid email address.';
    }
    if (!values.contactNumber.trim()) next.contactNumber = 'Contact number is required.';
    if (!resumeName) next.resume = 'Resume/CV is required.';
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    setSubmitAttempted(true);
    validate();
    // Intentionally does not call any API: the applicant-submission backend
    // is Step 5 scope. Nothing is inserted into the database from here.
  };

  return (
    <div className="public-shell">
      <PublicHeader />
      <main className="public-main">
        <Link to={id ? `/jobs/${id}` : '/jobs'} className="public-back-link">
          <ArrowLeft size={14} /> Back to Job Details
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
          <div className="apply-layout">
            <div className="panel apply-job-summary">
              <span className="apply-job-summary-label">Applying for</span>
              <h1 className="job-detail-title apply-job-summary-title">{job.title}</h1>
              <p className="job-card-code">{job.job_code}</p>
              <p className="job-card-meta-inline">{job.location}</p>
            </div>

            <form className="panel apply-form" onSubmit={handleSubmit} noValidate>
              <h2 className="apply-form-heading">Applicant Information</h2>

              <div className="apply-form-grid">
                <div className="apply-field">
                  <label htmlFor="fullName">Full Name</label>
                  <input id="fullName" type="text" value={values.fullName} onChange={setField('fullName')} autoComplete="name" />
                  {errors.fullName ? <span className="apply-field-error">{errors.fullName}</span> : null}
                </div>

                <div className="apply-field">
                  <label htmlFor="email">Email Address</label>
                  <input id="email" type="email" value={values.email} onChange={setField('email')} autoComplete="email" />
                  {errors.email ? <span className="apply-field-error">{errors.email}</span> : null}
                </div>

                <div className="apply-field">
                  <label htmlFor="contactNumber">Contact Number</label>
                  <input
                    id="contactNumber"
                    type="tel"
                    value={values.contactNumber}
                    onChange={setField('contactNumber')}
                    autoComplete="tel"
                  />
                  {errors.contactNumber ? <span className="apply-field-error">{errors.contactNumber}</span> : null}
                </div>

                <div className="apply-field apply-field-wide">
                  <label htmlFor="address">Address (optional)</label>
                  <input id="address" type="text" value={values.address} onChange={setField('address')} autoComplete="street-address" />
                </div>
              </div>

              <h2 className="apply-form-heading">Resume / CV</h2>
              <label className="apply-file-drop" htmlFor="resume">
                <UploadCloud size={20} aria-hidden="true" />
                <span>{resumeName ?? 'Upload Resume/CV (PDF or Word document)'}</span>
                <input
                  id="resume"
                  type="file"
                  accept=".pdf,.doc,.docx"
                  className="apply-file-input"
                  onChange={(e) => setResumeName(e.target.files?.[0]?.name ?? null)}
                />
              </label>
              {errors.resume ? <span className="apply-field-error">{errors.resume}</span> : null}

              {submitAttempted ? (
                <div className="public-alert public-alert-info apply-notice">
                  Online application submission is not available yet. This form validates your details, but
                  nothing has been sent or saved — the applicant-submission backend is being built in the next
                  phase. Please check back soon, or contact HR directly to apply for this position.
                </div>
              ) : null}

              <button type="submit" className="btn btn-primary apply-submit-btn">
                Submit Application
              </button>
            </form>
          </div>
        ) : null}
      </main>
    </div>
  );
}
