import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './index.html',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                display: ['"Iowan Old Style"', 'Georgia', 'Cambria', 'Times New Roman', 'serif'],
            },
            colors: {
                ink: {
                    50: '#f4f6f8',
                    100: '#e6eaf0',
                    200: '#c6cfdc',
                    300: '#a4b3c7',
                    400: '#7f93ad',
                    500: '#5c7496',
                    600: '#455a7a',
                    700: '#354760',
                    800: '#27364a',
                    900: '#1b2635',
                    950: '#111a26',
                },
                parchment: {
                    50: '#fdfbf7',
                    100: '#faf6ee',
                    200: '#f3ead7',
                    300: '#e8d9b8',
                },
                terracotta: {
                    50: '#fdf3ee',
                    100: '#fbe4d8',
                    200: '#f6c7b0',
                    300: '#ef9f7b',
                    400: '#e6774a',
                    500: '#d95a2b',
                    600: '#c1461f',
                    700: '#9f371d',
                    800: '#802e1d',
                    900: '#67291d',
                },
            },
        },
    },
    plugins: [],
};
