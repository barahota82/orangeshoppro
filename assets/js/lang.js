document.addEventListener('DOMContentLoaded', () => {
    const lang = window.APP_LANG || 'en';
    try {
        localStorage.setItem('site_lang', lang);
    } catch (e) {}
});
