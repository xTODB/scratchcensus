</main>
</div>
<script>
(function() {
    if (document.documentElement.getAttribute('data-img') !== 'on') return;
    var imgs = document.querySelectorAll('img.pic[data-src]');
    for (var i = 0; i < imgs.length; i++) {
        imgs[i].loading = 'lazy';
        imgs[i].onerror = function() { this.style.visibility = 'hidden'; };
        imgs[i].src = imgs[i].getAttribute('data-src');
    }
})();
</script>
<script>document.addEventListener('keydown', function(e) { if (e.key === 'Escape') document.body.classList.remove('nav-open'); });</script>
</body>
</html>
