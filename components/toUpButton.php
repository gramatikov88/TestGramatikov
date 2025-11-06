<!-- бутон за връщане нагоре -->
 <script>
    document.addEventListener("DOMContentLoaded", function () {
        const toUpButton = document.querySelector(".to-up-button");

        window.addEventListener("scroll", function () {
            if (window.scrollY > 300) {
                toUpButton.style.display = "flex";
            } else {
                toUpButton.style.display = "none";
            }
        });
    });
 </script>
<a href="#top" class="to-up-button d-flex align-items-center justify-content-center ">
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
        transition: 0.3s;
        cursor: pointer;
        z-index: 999;
    }

    .to-up-button:hover {
        background: #333;
    }
</style>