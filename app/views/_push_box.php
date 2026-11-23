<?php /* Zapnutí upozornění do telefonu na tomto zařízení (Můj účet, uvítání po přijetí pozvánky). */ ?>
        <div data-push data-key="<?= e(push_public_key()) ?>" data-sw="<?= e(url('/sw.js')) ?>" data-scope="<?= e(url('/')) ?>" data-sub="<?= e(url('/push/prihlasit')) ?>" data-unsub="<?= e(url('/push/odhlasit')) ?>" data-csrf="<?= e(csrf_token()) ?>">
          <p class="push-state" aria-live="polite"></p>
          <div class="actions"><button class="btn" type="button" data-push-on hidden>Zapnout upozornění na tomto zařízení</button><button class="btn btn-ghost" type="button" data-push-off hidden>Vypnout na tomto zařízení</button></div>
          <p class="hint push-ios" hidden>Na iPhonu a iPadu fungují upozornění jen u webu přidaného na plochu: v Safari klepněte na <strong>Sdílet → Přidat na plochu</strong>, pak web otevřete z ikony na ploše, přihlaste se a upozornění zapněte v části Můj účet.</p>
        </div>
