(() => {
    'use strict';

    const getStoredTheme = () => localStorage.getItem('theme');
    const setStoredTheme = theme => localStorage.setItem('theme', theme);

    const getPreferredTheme = () => {
        const stored = getStoredTheme();
        if (stored) return stored;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };

    const setTheme = theme => {
        if (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        } else {
            document.documentElement.setAttribute('data-bs-theme', theme);
        }
    };

    setTheme(getPreferredTheme());

    const showActiveTheme = theme => {
        const activeIcon = document.querySelector('.theme-icon-active use');
        const activeItem = document.querySelector(`[data-bs-theme-value="${theme}"]`);
        if (!activeIcon || !activeItem) return;

        const svgHref = activeItem.querySelector('svg use')?.getAttribute('href');

        document.querySelectorAll('[data-bs-theme-value]').forEach(el => el.classList.remove('active'));
        activeItem.classList.add('active');
        if (svgHref) activeIcon.setAttribute('href', svgHref);
    };

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        const stored = getStoredTheme();
        if (stored !== 'light' && stored !== 'dark') setTheme(getPreferredTheme());
    });

    window.addEventListener('DOMContentLoaded', () => {
        showActiveTheme(getPreferredTheme());

        document.querySelectorAll('[data-bs-theme-value]').forEach(toggle => {
            toggle.addEventListener('click', () => {
                const theme = toggle.getAttribute('data-bs-theme-value');
                setStoredTheme(theme);
                setTheme(theme);
                showActiveTheme(theme);
            });
        });
    });
})();
