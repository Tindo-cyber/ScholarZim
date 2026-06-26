/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{ts,tsx}', './components/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#006b3f',
          dark: '#004d2e',
          light: '#e8f5ef',
          foreground: '#ffffff',
        },
        gold: '#f5b800',
      },
      fontFamily: {
        sans: ['DM Sans', 'system-ui', 'sans-serif'],
        display: ['Plus Jakarta Sans', 'DM Sans', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 3px rgba(15, 23, 42, 0.06), 0 4px 16px rgba(15, 23, 42, 0.06)',
        'card-hover': '0 8px 28px rgba(15, 23, 42, 0.12)',
      },
    },
  },
  plugins: [],
};
