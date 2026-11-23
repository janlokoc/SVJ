<!doctype html>
<html lang="cs" data-look="<?= e(site_look_used()) ?>" data-style="<?= e(site_style_used()) ?>"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') . ' · ' . site('name')) ?></title>
<?= $GLOBALS['HEAD_EXTRA'] ?? '' ?>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="bare">
<?= staging_bar() ?><?= $content ?></body>
</html>
