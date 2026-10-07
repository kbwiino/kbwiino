<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
  <title><?= e($title ?? 'ResearchMentor AI') ?> · ResearchMentor AI</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-light">
  <a class="visually-hidden-focusable" href="#main">Skip to content</a>
  <main id="main" class="container py-5"><?= $content ?></main>
  <script src="/assets/vendor/bootstrap.bundle.min.js"></script>
  <script src="/assets/js/app.js"></script>
  <?php if (!empty($script)): ?><script src="<?= e($script) ?>"></script><?php endif; ?>
</body>
</html>
