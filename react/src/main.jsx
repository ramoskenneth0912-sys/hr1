import React from 'react';
import { createRoot } from 'react-dom/client';
import OpenPositionsWidget from './components/OpenPositionsWidget.jsx';

const widgets = {
    'open-positions': OpenPositionsWidget
};

document.querySelectorAll('[data-react-widget]').forEach((mount) => {
    const name = mount.getAttribute('data-react-widget');
    const Component = widgets[name];
    if (!Component) {
        console.warn('[HR1 React] Unknown widget:', name);
        return;
    }
    createRoot(mount).render(<Component />);
});
