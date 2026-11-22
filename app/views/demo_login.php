<div class="wrap page narrow">
  <header class="page-head"><h1>Vstup do ukázky</h1><p class="lead">Tohle je ukázkový web smyšleného společenství. Není potřeba účet ani heslo – vyberte, jakýma očima se chcete podívat.</p></header>
  <div class="demo-roles">
    <form method="post" action="<?= url('/demo-prihlaseni') ?>" class="card"><?= csrf_field() ?><input type="hidden" name="jako" value="resident">
      <h2>Jako obyvatel</h2><p class="muted">Co vidí vlastník nebo nájemník po přihlášení: hlasování, závady, platby, termíny, dokumenty pro obyvatele.</p>
      <button class="btn btn-big" type="submit">Vstoupit jako obyvatel</button></form>
    <form method="post" action="<?= url('/demo-prihlaseni') ?>" class="card"><?= csrf_field() ?><input type="hidden" name="jako" value="admin">
      <h2>Jako správce (výbor)</h2><p class="muted">Celá administrace: obsah webu, uživatelé, e-maily, hlasování, nastavení vzhledu.</p>
      <button class="btn btn-big" type="submit">Vstoupit jako správce</button></form>
  </div>
  <p class="hint">V ukázce se změny neukládají. Vlastní web si založíte zdarma: <a href="<?= e(platform_url('/registrace')) ?>">Založit web</a>.</p>
</div>
