<!doctype html>
<html lang="cs" data-look="<?= e(isset(LOOKS[site('look')]) ? site('look') : 'les') ?>" data-style="<?= e(isset(STYLES[site('style')]) ? site('style') : 'klasik') ?>"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') . ' · ' . site('name')) ?></title>
<?= $GLOBALS['HEAD_EXTRA'] ?? '' ?>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="bare"><?= $content ?></body>
</html>
