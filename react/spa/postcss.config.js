const path = require('path');

module.exports = {
  plugins: {
    // Uses the HR1 root tailwind.config.js (preflight + container disabled)
    // so the Tailwind utilities are emitted WITHOUT a global reset that would
    // clash with HR1's design system. Content globs in that config cover
    // ./react/** which includes this SPA plus the preserved island modules.
    tailwindcss: { config: path.resolve(__dirname, '../../tailwind.config.js') },
    autoprefixer: {},
  },
};