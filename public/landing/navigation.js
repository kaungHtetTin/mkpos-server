(() => {
    const links = [...document.querySelectorAll('.desktop-nav a, .mobile-nav a')];
    const header = document.querySelector('.site-header');
    const normalize = path => path.replace(/\/$/, '') || '/';
    const currentPath = normalize(location.pathname);
    const entries = links.map(link => ({ link, url: new URL(link.href) }));
    const sections = [...new Set(entries.filter(({ url }) => normalize(url.pathname) === currentPath && url.hash)
        .map(({ url }) => document.getElementById(url.hash.slice(1))).filter(Boolean))];
    const update = () => {
        const top = header.getBoundingClientRect().bottom + 24;
        const section = sections.find(element => {
            const rect = element.getBoundingClientRect();
            return rect.top <= top && rect.bottom > top;
        });
        for (const { link, url } of entries) {
            const path = normalize(url.pathname);
            const active = url.hash
                ? path === currentPath && section?.id === url.hash.slice(1)
                : currentPath === path || currentPath.startsWith(path + '/');
            if (active) link.setAttribute('aria-current', url.hash ? 'location' : 'page');
            else link.removeAttribute('aria-current');
        }
    };
    let queued = false;
    const schedule = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; update(); });
    };
    addEventListener('scroll', schedule, { passive: true });
    addEventListener('resize', schedule);
    addEventListener('hashchange', schedule);
    addEventListener('pageshow', schedule);
    links.forEach(link => link.addEventListener('click', () => {
        const menu = link.closest('.mobile-nav');
        if (menu) menu.open = false;
    }));
    update();
})();
