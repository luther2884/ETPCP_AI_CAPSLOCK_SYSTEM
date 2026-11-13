<?php
/* includes/header.example.php — what your header include needs.
   Merge these four things into your existing header; don't replace it
   wholesale unless it's trivial. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TechWorld Computer Store</title>

  <!-- 1. fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

  <!-- 2. your existing stylesheet first... -->
  <link rel="stylesheet" href="assets/css/style.css">
  <!-- 3. ...then this one LAST so its rules win -->
  <link rel="stylesheet" href="assets/css/techworld-anim.css">
</head>

<!-- 4. body class + background + rail + main wrapper -->
<body class="tw-page" id="top">
<?php include __DIR__ . '/animated-background.php'; ?>
<?php include __DIR__ . '/rail-and-fabs.php'; ?>
<main class="tw-main">
