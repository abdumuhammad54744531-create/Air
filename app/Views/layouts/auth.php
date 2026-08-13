<?php $flash=pull_flash(); ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title??'Masuk')?> — SIMMA</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?=url('assets/css/app.css')?>" rel="stylesheet"></head>
<body class="auth-body"><?php if($flash):?><div class="position-fixed top-0 start-50 translate-middle-x mt-4 alert alert-<?=e($flash['type'])?> shadow"><?=e($flash['message'])?></div><?php endif?><?=$content?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?=url('assets/js/app.js')?>"></script></body></html>

