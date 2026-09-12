import React from 'react';
import { createRoot } from 'react-dom/client';
import { setCurrentUser } from './auth/userContext.js';
import OpenPositionsWidget from './components/OpenPositionsWidget.jsx';
import MyGoals from './pages/employee/MyGoals.tsx';
import MyPerformance from './pages/employee/MyPerformance.tsx';
import MyCompetencies from './pages/employee/MyCompetencies.tsx';
import MyTrainings from './pages/employee/MyTrainings.tsx';
import MyLearning from './pages/employee/MyLearning.tsx';
import MyDevelopment from './pages/employee/MyDevelopment.tsx';
import MyRecognition from './pages/employee/MyRecognition.tsx';

const widgets = {
    'open-positions': OpenPositionsWidget,
    'my-goals': MyGoals,
    'my-performance': MyPerformance,
    'my-competencies': MyCompetencies,
    'my-trainings': MyTrainings,
    'my-learning': MyLearning,
    'my-development': MyDevelopment,
    'my-recognition': MyRecognition
};

document.querySelectorAll('[data-react-widget]').forEach((mount) => {
    const name = mount.getAttribute('data-react-widget');
    const Component = widgets[name];
    if (!Component) {
        console.warn('[HR1 React] Unknown widget:', name);
        return;
    }

    // The PHP page passes the logged-in employee (session-scoped) as a JSON
    // string on the mount element. It is parsed once and shared with the
    // `useAuth` hook for all widgets mounted on this page.
    if (mount.dataset.hr1User) {
        try {
            setCurrentUser(JSON.parse(mount.dataset.hr1User));
        } catch (err) {
            console.error('[HR1 React] Invalid data-hr1-user payload:', err);
        }
    }

    createRoot(mount).render(<Component />);
});