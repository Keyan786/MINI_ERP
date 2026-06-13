            </main><!-- /.main-content -->
        </div><!-- /.main-wrapper -->
    </div><!-- /.app-layout -->

    <!-- App JavaScript -->
    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>

    <!-- Live Clock -->
    <script>
        (function() {
            const clockEl = document.getElementById('live-clock');
            if (!clockEl) return;
            function updateClock() {
                const now = new Date();
                clockEl.textContent = now.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });
            }
            updateClock();
            setInterval(updateClock, 30000);
        })();
    </script>
</body>
</html>
