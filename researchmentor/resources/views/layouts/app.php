<?php
use App\Support\Roles;
/** @var string $content */
$roles = $user['roles'] ?? [];
$has = fn(string ...$r) => (bool) array_intersect($r, $roles);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
  <title><?= e($title ?? 'ResearchMentor AI') ?> · ResearchMentor AI</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
  <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body data-user-name="<?= e($user['name'] ?? '') ?>" data-roles="<?= e(implode(',', $roles)) ?>">
<a class="visually-hidden-focusable" href="#main">Skip to content</a>
<nav class="navbar navbar-expand-lg navbar-dark bg-rm" aria-label="Primary">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="/dashboard"><i class="bi bi-mortarboard-fill me-1"></i>ResearchMentor <span class="badge text-bg-light text-rm">AI</span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="/dashboard">Dashboard</a></li>
        <?php if ($has(Roles::STUDENT, Roles::SUPERVISOR, Roles::COORDINATOR, Roles::GRAD_ADMIN, Roles::EXAMINER, Roles::SYSADMIN)): ?>
        <li class="nav-item"><a class="nav-link" href="/projects">Projects</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="/knowledge">Guidelines</a></li>
        <?php if ($has(Roles::GRAD_ADMIN, Roles::SYSADMIN)): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/users">Users</a></li>
        <?php endif; ?>
        <?php if ($has(Roles::GRAD_ADMIN)): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/institution">Institution</a></li>
        <?php endif; ?>
        <?php if ($has(Roles::SYSADMIN)): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/system">System</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="/notifications" aria-label="Notifications"><i class="bi bi-bell"></i> <span id="notif-count" class="badge text-bg-warning d-none"></span></a></li>
        <li class="nav-item"><span class="navbar-text me-lg-3"><?= e($user['name'] ?? '') ?> <small class="opacity-75">(<?= e(implode(', ', array_map(fn($r) => Roles::LABELS[$r] ?? $r, $roles))) ?>)</small></span></li>
        <li class="nav-item"><button class="btn btn-sm btn-outline-light" id="logout-btn" type="button">Sign out</button></li>
      </ul>
    </div>
  </div>
</nav>
<main id="main" class="container-xl py-4">
  <div id="alerts" aria-live="polite"></div>
  <?= $content ?>
</main>
<footer class="container-xl pb-4 small text-muted">
  AI output is advisory only and does not replace your supervisor. · <a href="/privacy">Privacy notice</a>
</footer>
<script src="/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/chart.umd.min.js"></script>
<script src="/assets/js/app.js"></script>
<?php if (!empty($page)): ?><script src="/assets/js/pages/<?= e($page) ?>.js"></script><?php endif; ?>
</body>
</html>
