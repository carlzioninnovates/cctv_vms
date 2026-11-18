import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/**/*.blade.php', './resources/**/*.js'],
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', ...defaultTheme.fontFamily.sans] },
            colors: {
                brand: { 50: '#eef8f6', 100: '#d5efe9', 500: '#1f9d86', 600: '#178572', 700: '#136b5c' },
            },
        },
    },
    plugins: [],
};
