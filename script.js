// script.js - BuildMaster Constructions

document.addEventListener('DOMContentLoaded', () => {
    
    // 1. Loading Spinner
    const spinner = document.getElementById('spinner');
    if(spinner) {
        window.addEventListener('load', () => {
            spinner.style.opacity = '0';
            setTimeout(() => {
                spinner.style.display = 'none';
            }, 500);
        });
    }
    
    // 2. Sticky Navbar & Active State
    const navbar = document.querySelector('.navbar');
    const navLinks = document.querySelectorAll('.nav-link');
    const sections = document.querySelectorAll('section');
    
    window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            navbar.classList.add('sticky');
        } else {
            navbar.classList.remove('sticky');
        }
        
        let current = '';
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.clientHeight;
            if (scrollY >= (sectionTop - 150)) {
                current = section.getAttribute('id');
            }
        });
        
        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href').includes(current) && current !== '') {
                link.classList.add('active');
            }
        });
        
        const backToTop = document.querySelector('.back-to-top');
        if(backToTop) {
            if (window.scrollY > 300) {
                backToTop.classList.add('show');
            } else {
                backToTop.classList.remove('show');
            }
        }
    });
    
    // 3. Smooth Scrolling
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href');
            if(targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if(targetElement) {
                e.preventDefault();
                window.scrollTo({
                    top: targetElement.offsetTop - 70,
                    behavior: 'smooth'
                });
                
                const navbarToggler = document.querySelector('.navbar-toggler');
                const navbarCollapse = document.querySelector('.navbar-collapse');
                if (navbarCollapse.classList.contains('show')) {
                    navbarToggler.click();
                }
            }
        });
    });
    
    // 3a. Mobile Menu Slide-in from Left
    const navbarCollapseEl = document.getElementById('navbarNav');
    if (navbarCollapseEl) {
        navbarCollapseEl.addEventListener('show.bs.collapse', () => {
            document.body.classList.add('menu-open');
        });
        navbarCollapseEl.addEventListener('hide.bs.collapse', () => {
            document.body.classList.remove('menu-open');
        });
        
        // Close menu when clicking outside or on backdrop
        document.addEventListener('click', (e) => {
            if (document.body.classList.contains('menu-open')) {
                const menu = document.getElementById('navbarNav');
                const toggler = document.querySelector('.navbar-toggler');
                if (menu && !menu.contains(e.target) && !toggler.contains(e.target)) {
                    e.preventDefault();
                    const bsCollapse = bootstrap.Collapse.getInstance(navbarCollapseEl);
                    if (bsCollapse) bsCollapse.hide();
                }
            }
        });
        
        // Close button inside menu
        const menuCloseBtn = document.querySelector('.menu-close-btn');
        if (menuCloseBtn) {
            menuCloseBtn.addEventListener('click', () => {
                const bsCollapse = bootstrap.Collapse.getInstance(navbarCollapseEl);
                if (bsCollapse) bsCollapse.hide();
            });
        }
    }
    
    // 4. Scroll Animations (Intersection Observer)
    const fadeElements = document.querySelectorAll('.animate-fade-up');
    
    const fadeObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, {
        root: null,
        threshold: 0.1,
        rootMargin: "0px 0px -50px 0px"
    });
    
    fadeElements.forEach(el => fadeObserver.observe(el));
    
    // 5. Animated Counters
    const counters = document.querySelectorAll('.counter-value');
    const speed = 200;
    
    const counterObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counter = entry.target;
                const updateCount = () => {
                    const target = +counter.getAttribute('data-target');
                    const count = +counter.innerText;
                    
                    const inc = target / speed;
                    
                    if (count < target) {
                        counter.innerText = Math.ceil(count + inc);
                        setTimeout(updateCount, 20);
                    } else {
                        counter.innerText = target;
                    }
                };
                updateCount();
                observer.unobserve(counter);
            }
        });
    }, {
        root: null,
        threshold: 0.5
    });
    
    counters.forEach(counter => counterObserver.observe(counter));
});