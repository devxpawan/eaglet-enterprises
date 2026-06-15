/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */

// Enterprise Dashboard — Sidebar & Navigation Controller

window.addEventListener('DOMContentLoaded', event => {

    const sidebarToggle = document.body.querySelector('#sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;

    // Determine if we're on mobile
    function isMobile() {
        return window.innerWidth < 992;
    }

    if (sidebarToggle) {
        // Restore desktop sidebar state from localStorage
        if (!isMobile() && localStorage.getItem('sb|sidebar-toggle') === 'true') {
            body.classList.add('sb-sidenav-toggled');
        }

        sidebarToggle.addEventListener('click', event => {
            event.preventDefault();

            if (isMobile()) {
                // Mobile: toggle the drawer open/close
                body.classList.toggle('sb-sidenav-toggled');
            } else {
                // Desktop: toggle collapse
                body.classList.toggle('sb-sidenav-toggled');
                localStorage.setItem('sb|sidebar-toggle', body.classList.contains('sb-sidenav-toggled'));
            }
        });
    }

    // Close sidebar on overlay click (mobile)
    if (overlay) {
        overlay.addEventListener('click', () => {
            body.classList.remove('sb-sidenav-toggled');
        });
    }

    // Close sidebar when a nav link is clicked on mobile
    document.querySelectorAll('.sb-sidenav-menu .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (isMobile() && link.getAttribute('href') && link.getAttribute('href') !== '#' && link.getAttribute('href') !== 'javascript:void(0);') {
                body.classList.remove('sb-sidenav-toggled');
            }
        });
    });

    // Handle resize: cleanup mobile state when switching to desktop
    let prevMobile = isMobile();
    window.addEventListener('resize', () => {
        const nowMobile = isMobile();
        if (prevMobile && !nowMobile) {
            // Switched to desktop: remove mobile-toggled state, restore desktop state
            body.classList.remove('sb-sidenav-toggled');
            if (localStorage.getItem('sb|sidebar-toggle') === 'true') {
                body.classList.add('sb-sidenav-toggled');
            }
        } else if (!prevMobile && nowMobile) {
            // Switched to mobile: ensure sidebar is hidden
            body.classList.remove('sb-sidenav-toggled');
        }
        prevMobile = nowMobile;
    });

    // Close dropdowns when clicking outside (navbar)
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                const dropdown = bootstrap.Dropdown.getInstance(menu.previousElementSibling);
                if (dropdown) dropdown.hide();
            });
        }
    });

    // Prevent CSS hover states from sticking on mobile after tap
    document.addEventListener('touchstart', () => {
        document.body.classList.add('touch-device');
    }, { passive: true });
});
