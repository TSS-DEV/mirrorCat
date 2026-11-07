<footer class="bg-dark text-white p-2 w-100 mt-auto">
  <div class="container-fluid d-flex flex-wrap justify-content-between gap-2 small">
    <span id="copyright">&copy; <b><?php echo h($app_name ?? 'mirrorCAT'); ?></b> 2025 - <?php echo date('Y'); ?></span>
    <span class="text-white-50">WorldCat data via the OCLC Metadata API</span>
  </div>
</footer>
<script src="./app.js?<?php echo filemtime(__DIR__ . '/app.js'); ?>"></script>
</body>

</html>
