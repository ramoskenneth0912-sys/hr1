import React from 'react';
import ReactDOM from 'react-dom/client';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';

import './index.css';

import { AuthProvider } from './auth/AuthContext';
import Layout from './components/Layout';
import ProtectedRoute from './components/ProtectedRoute';
import Dashboard from './pages/Dashboard';
import LoginPage from './pages/LoginPage';
import NotFoundPage from './pages/NotFoundPage';
// Public applicant experience — jobs board, job details, and start-application
// pages (Step 3 Node.js/Express + Auth.js foundation, Step 4 UI). Deliberately
// public/unprotected: applicants must be able to browse jobs and start an
// application without being treated as authenticated portal users.
import JobsPage from './pages/JobsPage';
import JobDetailsPage from './pages/JobDetailsPage';
import JobApplyPage from './pages/JobApplyPage';

// Node.js/Express + Auth.js migration-preview routes (Step 4). Deliberately
// isolated from the production `/login` and dashboard routes above — these
// prove the new session works independently before anything is cut over.
import NodeLoginPage from './pages/NodeLoginPage';
import NodeDashboardPage from './pages/NodeDashboardPage';
import NodeProtectedRoute from './components/NodeProtectedRoute';

// Preserved HR1 ESS modules (react/src/pages/employee) — reused unchanged.
import MyGoals from '@hr1/pages/employee/MyGoals';
import MyPerformance from '@hr1/pages/employee/MyPerformance';
import MyCompetencies from '@hr1/pages/employee/MyCompetencies';
import MyDevelopment from '@hr1/pages/employee/MyDevelopment';
import MyRecognition from '@hr1/pages/employee/MyRecognition';
import MyTrainings from '@hr1/pages/employee/MyTrainings';
import MyLearning from '@hr1/pages/employee/MyLearning';

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/jobs" element={<JobsPage />} />
      <Route path="/jobs/:id" element={<JobDetailsPage />} />
      <Route path="/jobs/:id/apply" element={<JobApplyPage />} />
      <Route path="/node-login" element={<NodeLoginPage />} />
      <Route
        path="/node-dashboard"
        element={
          <NodeProtectedRoute>
            <NodeDashboardPage />
          </NodeProtectedRoute>
        }
      />
      <Route
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        <Route path="/" element={<Dashboard />} />
        <Route path="/ess/goals" element={<MyGoals />} />
        <Route path="/ess/performance" element={<MyPerformance />} />
        <Route path="/ess/competencies" element={<MyCompetencies />} />
        <Route path="/ess/development" element={<MyDevelopment />} />
        <Route path="/ess/recognition" element={<MyRecognition />} />
        <Route path="/ess/trainings" element={<MyTrainings />} />
        <Route path="/ess/learning" element={<MyLearning />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
  <React.StrictMode>
    <HashRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </HashRouter>
  </React.StrictMode>,
);