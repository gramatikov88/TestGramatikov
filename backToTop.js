(() => {
    const btn = document.getElementById('backToTopBtn');
    if (!btn) {
        return;
    }

    const toggleBtn = () => {
        const shouldShow = window.scrollY > 250;
        btn.classList.toggle('show', shouldShow);
    };

    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    document.addEventListener('scroll', toggleBtn, { passive: true });
    toggleBtn();
})();
