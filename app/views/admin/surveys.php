<div class="wrap page">
  <header class="page-head row"><h1>Ankety</h1><a class="btn" href="<?= url('/admin/ankety/nova') ?>">+ Nová anketa</a></header>
  <p class="lead">Nezávazné zjištění názoru: každý přihlášený obyvatel odpoví jednou. Pro rozhodnutí podle podílů použijte Hlasování.</p>
  <?php if (!$surveys): ?><p class="empty">Zatím tu žádná anketa není.</p><?php else: ?>
  <table class="table"><thead><tr><th>Otázka</th><th>Do</th><th>Odpovědí</th><th>Vede</th><th>Stav</th><th></th></tr></thead><tbody>
    <?php foreach ($surveys as $s): $top = $s['options']; usort($top, fn($a, $b) => $b['votes'] <=> $a['votes']); ?>
    <tr<?= $s['published'] ? '' : ' class="is-off"' ?>>
      <td data-label="Otázka"><a href="<?= url('/admin/ankety/' . $s['id']) ?>"><strong><?= e($s['title']) ?></strong></a></td>
      <td data-label="Do"><?= $s['closes_at'] !== '' ? cz_date($s['closes_at']) : 'bez termínu' ?></td>
      <td data-label="Odpovědí"><?= (int) $s['total'] ?></td>
      <td data-label="Vede"><?= $s['total'] && $top ? e($top[0]['label']) . ' (' . (int) round($top[0]['votes'] / $s['total'] * 100) . ' %)' : '—' ?></td>
      <td data-label="Stav"><?= !$s['published'] ? 'skrytá' : ($s['open'] ? 'probíhá' : 'ukončena') ?></td>
      <td class="t-actions"><form method="post" action="<?= url('/admin/ankety/' . $s['id'] . '/smazat') ?>" data-confirm="Opravdu smazat anketu i s odpověďmi?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
    </tr>
    <?php endforeach ?>
  </tbody></table>
  <?php endif ?>
</div>
