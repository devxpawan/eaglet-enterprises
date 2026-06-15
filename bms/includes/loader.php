<style>
    :root {
        --m3-primary: #0b3354;
        --m3-surface: #ffffff;
        --m3-surface-variant: #f1f5f9;
    }

    .m3-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: var(--m3-surface);
        z-index: 999999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition: opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), 
                    visibility 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .m3-spinner {
        animation: m3Rotate 2s linear infinite;
        width: 48px;
        height: 48px;
    }

    .m3-spinner .path {
        stroke: var(--m3-primary);
        stroke-linecap: round;
        animation: m3Dash 1.5s ease-in-out infinite;
        stroke-width: 4;
    }

    @keyframes m3Rotate {
        100% { transform: rotate(360deg); }
    }

    @keyframes m3Dash {
        0% { stroke-dasharray: 1, 150; stroke-dashoffset: 0; }
        50% { stroke-dasharray: 90, 150; stroke-dashoffset: -35; }
        100% { stroke-dasharray: 90, 150; stroke-dashoffset: -124; }
    }

    .m3-loader-text {
        margin-top: 16px;
        color: var(--m3-primary);
        font-family: system-ui, -apple-system, sans-serif;
        font-weight: 500;
        font-size: 14px;
        letter-spacing: 0.5px;
        opacity: 0.8;
    }

    body.loading { overflow: hidden; }
</style>

<div class="m3-loader" id="site-loader">
    <svg class="m3-spinner" viewBox="0 0 50 50">
        <circle class="path" cx="25" cy="25" r="20" fill="none"></circle>
    </svg>
    <div class="m3-loader-text">Loading...</div>
</div>

<script>
    (function() {
        const loader = document.getElementById('site-loader');
        
        function hideLoader() {
            if (!loader) return;
            loader.style.opacity = '0';
            loader.style.visibility = 'hidden';
            document.body.classList.remove('loading');
            setTimeout(() => {
                if (loader.parentNode) loader.remove();
            }, 400);
        }

        document.body.classList.add('loading');

        // Initial check
        if (document.readyState === 'complete') {
            hideLoader();
        }

        // Listen for load events
        window.addEventListener('load', hideLoader);
        
        // Fallback for long-loading resources
        setTimeout(hideLoader, 3000);
    })();
</script>