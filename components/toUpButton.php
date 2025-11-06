<!-- To Up Button Component -->
<div id="to-up-wrapper">

    <!-- Bootstrap Icons (самодостатъчен компонент) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <a href="#top" class="to-up-button d-flex align-items-center justify-content-center" aria-label="Scroll to top">
        <i class="bi bi-arrow-up-short"></i>
    </a>

    <style>
        .to-up-button {
            position: fixed;
            right: 20px;
            bottom: 20px;
            width: 45px;
            height: 45px;
            background: #000;
            color: #fff;
            border-radius: 50%;
            font-size: 28px;
            cursor: pointer;
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.25s ease, visibility 0.25s ease, transform 0.25s ease;
            transform: translateY(8px);
        }

        .to-up-button.is-visible {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translateY(0);
        }

        .to-up-button:hover {
            background: #333;
        }
    </style>

    <script>
        (function () {
            function toggleToUp() {
                var btn = document.querySelector(".to-up-button");
                if (!btn) return;
                if (window.scrollY > 100) {
                    btn.classList.add("is-visible");
                } else {
                    btn.classList.remove("is-visible");
                }
            }

            window.addEventListener("load", toggleToUp, { passive: true });
            window.addEventListener("scroll", toggleToUp, { passive: true });
        })();
    </script>

</div>
