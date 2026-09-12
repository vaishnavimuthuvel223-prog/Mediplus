document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mp-flash').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity 0.6s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 600);
        }, 5000);
    });
});
