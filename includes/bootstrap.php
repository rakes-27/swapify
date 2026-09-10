<?php
/**
 * Swapify Bootstrap Helper
 */

define('BASE_URL', '/');

/**
 * Returns the correct URL for any asset.
 */
function asset($path)
{
    return BASE_URL . ltrim($path, '/');
}
/**
 * Returns the correct URL for application pages.
 */
function url($path)
{
    return BASE_URL . ltrim($path, '/');
}

/**
 * Load Bootstrap + Fonts + CSS
 */
function loadBootstrap()
{
?>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Swapify CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<?php
}

/**
 * Load JavaScript
 */
function loadScripts($pageScript = null)
{
?>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Global JS -->
    <script src="<?= asset('assets/js/main.js') ?>"></script>

<?php
    if ($pageScript) {
?>
        <script src="<?= asset("assets/js/{$pageScript}.js") ?>"></script>
<?php
    }
}

/**
 * Include Components
 */
function component($name)
{
    $file = __DIR__ . "/../components/{$name}.php";

    if (!file_exists($file)) {
        die("Component '{$name}' not found.<br>Expected:<br>{$file}");
    }

    include $file;
}