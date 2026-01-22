/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './index.php',
    './**/*.php',
    './js/**/*.js',
  ],

  theme: {
    extend: {
      colors: {
        // Optional: keep branding consistent later
        kiosk: {
          bg: '#020617',   // slate-950
          panel: '#020617',
        },
      },
      borderRadius: {
        'xl': '0.75rem',
        '2xl': '1rem',
        '3xl': '1.5rem',
      },
      boxShadow: {
        kiosk: '0 20px 40px rgba(0,0,0,0.35)',
      },
    },
  },

  plugins: [],
};
