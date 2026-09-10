<?php
/**
 * Swapify — 404 Not Found
 * Frontend only. Search box redirects to marketplace.php?q=... just like
 * the navbar/hero search (no separate JS needed).
 */
require_once __DIR__ . '/includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page not found — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="error-page">
        <div class="container">
            <div class="error-illustration">
                <div class="error-icon-a"><i class="bi bi-camera"></i></div>
                <div class="error-badge"><i class="bi bi-question-lg"></i></div>
                <div class="error-icon-b"><i class="bi bi-controller"></i></div>
            </div>

            <div class="error-code mb-2">404</div>
            <h4 class="mb-2">This page didn't make the trade</h4>
            <p class="text-muted-swap mb-4" style="max-width:26rem; margin-inline:auto;">
                The page you're looking for might have been moved, renamed, or never existed. Let's get you back on track.
            </p>

            <form id="errorSearchForm" class="hero-search d-flex align-items-center mx-auto mb-4" style="max-width:480px;">
                <i class="bi bi-search text-muted-swap ms-3"></i>
                <input type="search" id="errorSearchInput" class="form-control py-2 border-0" placeholder="Search for items...">
                <button type="submit" class="btn btn-primary px-4">Search</button>
            </form>

            <a href="/index.php" class="btn btn-light-swap"><i class="bi bi-house me-1"></i> Go back home</a>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts(); ?>
    <script>
        document.getElementById('errorSearchForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const q = document.getElementById('errorSearchInput').value.trim();
            window.location.href = '/marketplace.php' + (q ? `?q=${encodeURIComponent(q)}` : '');
        });
    </script>
</body>
</html>