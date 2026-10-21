<?php
// includes/footer.php

declare(strict_types=1);
?>
</div>
</main>
<!-- Hauptbereich Ende -->

<!-- Footer -->
<footer class="bg-white border-top py-3 mt-auto text-center text-muted small">
    <div class="container">
        <span>&copy; <?= date('Y') ?> Lernzeit-Manager &ndash; ISEF01 Software Engineering</span>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle via CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<?php if (!empty($extraScripts)): ?>
    <?= $extraScripts ?>
<?php endif; ?>
</body>

</html>
