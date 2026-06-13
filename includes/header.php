<?php
/**
 * Header Include - Mini ERP System
 * HTML head, navbar, and opening layout tags.
 * Set $pageTitle and $currentModule before including this file.
 */

$pageTitle = $pageTitle ?? 'Mini ERP';
$currentModule = $currentModule ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Mini ERP System — Integrated Manufacturing Business Management">
    <title><?= e($pageTitle) ?> — Mini ERP</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- App Stylesheet -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
    <?= render_flash_messages() ?>

    <div class="app-layout">
        <!-- Sidebar Overlay (Mobile) -->
        <div class="sidebar-overlay"></div>

        <?php include __DIR__ . '/sidebar.php'; ?>

        <div class="main-wrapper">
            <!-- Top Navbar -->
            <nav class="navbar" id="main-navbar">
                <div class="navbar-left">
                    <button class="navbar-btn menu-toggle" id="menu-toggle" aria-label="Toggle menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <h2 class="navbar-title"><?= e($pageTitle) ?></h2>
                </div>
                <div class="navbar-right">
                    <span style="font-size:0.8125rem; color:var(--text-muted); margin-right: 15px;">
                        <i class="fa-regular fa-clock" style="margin-right:4px;"></i>
                        <span id="live-clock"></span>
                    </span>
                    <a href="<?= BASE_URL ?>/auth/logout.php" class="btn-logout" onclick="return confirm('Are you sure you want to log out?')">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        Logout
                    </a>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
