import 'bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

// ── Sidebar toggle ──────────────────────────────────────
const sidebar   = document.getElementById('sidebar');
const mainContent = document.getElementById('main-content');
const overlay   = document.getElementById('sidebar-overlay');
const toggleBtns = document.querySelectorAll('.toggle-btn');

const COLLAPSED_KEY = 'erp_sidebar_collapsed';
const isMobile = () => window.innerWidth < 992;

function applyState() {
    if (!sidebar) return;
    if (isMobile()) {
        sidebar.classList.remove('collapsed');
        mainContent?.classList.remove('sidebar-collapsed');
    } else {
        const collapsed = localStorage.getItem(COLLAPSED_KEY) === '1';
        sidebar.classList.toggle('collapsed', collapsed);
        mainContent?.classList.toggle('sidebar-collapsed', collapsed);
    }
}

applyState();
window.addEventListener('resize', applyState);

toggleBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        if (isMobile()) {
            sidebar.classList.toggle('mobile-open');
            overlay?.classList.toggle('active');
        } else {
            const next = !sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed', next);
            mainContent?.classList.toggle('sidebar-collapsed', next);
            localStorage.setItem(COLLAPSED_KEY, next ? '1' : '0');
        }
    });
});

overlay?.addEventListener('click', () => {
    sidebar?.classList.remove('mobile-open');
    overlay?.classList.remove('active');
});
