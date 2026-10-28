<?php
declare(strict_types=1);


const DATA_DIR       = __DIR__ . '/survey_data';
const DATA_FILE      = DATA_DIR . '/responses.php';
const MAX_TEXT       = 2000;               // символов в текстовых ответах
const MAX_FILE_BYTES = 20 * 1024 * 1024;   // защита от забивания диска
const FILE_HEADER    = "<?php http_response_code(404); exit; ?>\n";

const WHO = [
    'bot'    => 'С ботом',
    'online' => 'В онлайне',
    'local'  => 'На одном устройстве',
];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text($s): string
{
    if (!is_string($s)) return '';
    $s = str_replace("\r", '', $s);
    $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '';   // убираем управляющие символы
    $s = trim($s);
    // обрезка по символам без зависимости от расширения mbstring
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($chars) > MAX_TEXT ? implode('', array_slice($chars, 0, MAX_TEXT)) : $s;
}

function yes_no($v): ?bool
{
    if ($v === 'yes') return true;
    if ($v === 'no') return false;
    return null;
}

function save_response(array $rec): bool
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) return false;

    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $ix = DATA_DIR . '/index.html';
    if (!is_file($ix)) @file_put_contents($ix, '');

    $fh = @fopen(DATA_FILE, 'c+');
    if (!$fh) return false;
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $size = (int)(fstat($fh)['size'] ?? 0);
        if ($size < MAX_FILE_BYTES) {
            if ($size === 0) fwrite($fh, FILE_HEADER);
            fseek($fh, 0, SEEK_END);
            $line = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ok = $line !== false && fwrite($fh, $line . "\n") !== false;
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

$done   = isset($_GET['ok']);
$errors = [];
$v = ['who' => '', 'rate' => '', 'hook' => '', 'prob' => '', 'prob_text' => '',
      'again' => '', 'idea' => '', 'idea_text' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ловушка для ботов: поле скрыто от людей. Если заполнено — молча «принимаем» и ничего не сохраняем.
    if (!empty($_POST['website'])) {
        header('Location: Survey.php?ok=1', true, 303);
        exit;
    }

    $in = fn(string $k) => $_POST[$k] ?? '';

    $v['who'] = is_string($in('who')) ? $in('who') : '';
    if (!isset(WHO[$v['who']])) $errors['who'] = 'Выберите, с кем вы играли.';

    $rate = filter_var($in('rate'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
    if ($rate === false) $errors['rate'] = 'Оцените концепт ползунком.';
    else $v['rate'] = (string)$rate;

    foreach (['hook' => 'Ответьте, зацепил ли вас процесс игры.',
              'prob' => 'Ответьте, возникали ли проблемы.',
              'again' => 'Ответьте, сыграли бы вы ещё.',
              'idea' => 'Ответьте, есть ли у вас замечания или идеи.'] as $k => $msg) {
        $raw = $in($k);
        $v[$k] = is_string($raw) ? $raw : '';
        if (yes_no($v[$k]) === null) $errors[$k] = $msg;
    }

    $v['prob_text'] = clean_text($in('prob_text'));
    $v['idea_text'] = clean_text($in('idea_text'));
    if ($v['prob'] === 'yes' && $v['prob_text'] === '') $errors['prob_text'] = 'Опишите, какие были проблемы.';
    if ($v['idea'] === 'yes' && $v['idea_text'] === '') $errors['idea_text'] = 'Напишите ваши замечания или идеи.';

    if (!$errors) {
        $rec = [
            'time'      => date('c'),
            'who'       => WHO[$v['who']],
            'rate'      => (int)$v['rate'],
            'hook'      => yes_no($v['hook']),
            'problems'  => yes_no($v['prob']),
            'problems_text' => $v['prob'] === 'yes' ? $v['prob_text'] : null,
            'play_again' => yes_no($v['again']),
            'ideas'     => yes_no($v['idea']),
            'ideas_text' => $v['idea'] === 'yes' ? $v['idea_text'] : null,
        ];
        if (save_response($rec)) {
            header('Location: Survey.php?ok=1', true, 303);
            exit;
        }
        error_log('Survey: не удалось записать ответ в ' . DATA_FILE);
        $errors['save'] = 'Не удалось сохранить ответы. Попробуйте ещё раз чуть позже.';
    }
}

function radio_yn(string $name, string $cur): string
{
    $o = '';
    foreach (['yes' => 'Да', 'no' => 'Нет'] as $val => $label) {
        $o .= '<label class="opt"><input type="radio" name="' . $name . '" value="' . $val . '"'
            . ($cur === $val ? ' checked' : '') . ' required><span>' . $label . '</span></label>';
    }
    return $o;
}

function err(array $e, string $k): string
{
    return isset($e[$k]) ? '<div class="err" role="alert">' . h($e[$k]) . '</div>' : '';
}
?>
<!doctype html>
<html lang="ru">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex">
  <title>Опрос — Лисьи норы</title>
  <style>
    :root {
      box-sizing: border-box;
      padding-top: env(safe-area-inset-top, 0px);
      padding-bottom: env(safe-area-inset-bottom, 0px);
      --bg: #f6efe0;
      --fg: #2a2019;
      --cd: #fffaf0;
      --bd: #b58f4a;
      --ac: #f2b84b;
      color-scheme: light
    }

    @media (prefers-color-scheme:dark) {
      :root:not([data-theme="light"]) {
        --bg: #2a2019;
        --fg: #f3e9d2;
        --cd: #372b22;
        --bd: #8a6a3a;
        color-scheme: dark
      }
    }

    :root[data-theme="dark"] {
      --bg: #2a2019;
      --fg: #f3e9d2;
      --cd: #372b22;
      --bd: #8a6a3a;
      color-scheme: dark
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--fg);
      font: 15px/1.4 system-ui, sans-serif
    }

    #app {
      max-width: 520px;
      margin: 0 auto;
      padding: 12px
    }

    h1 {
      font-size: 20px;
      margin: 4px 0 2px
    }

    a {
      color: inherit
    }

    button,
    input,
    textarea {
      font: inherit;
      color: inherit;
      background: var(--cd);
      border: 1px solid var(--bd);
      border-radius: 6px;
      padding: 5px 9px
    }

    button {
      cursor: pointer
    }

    [hidden] {
      display: none !important
    }

    .n {
      opacity: .7;
      font-size: 13px
    }

    fieldset.q {
      border: 1px solid var(--bd);
      border-radius: 10px;
      background: var(--cd);
      margin: 12px 0;
      padding: 12px 14px
    }

    fieldset.q legend {
      font-weight: 600;
      padding: 0 6px
    }

    .opts {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 4px
    }

    .opt {
      position: relative;
      cursor: pointer
    }

    .opt input {
      position: absolute;
      opacity: 0;
      inset: 0;
      margin: 0;
      cursor: pointer
    }

    .opt span {
      display: block;
      padding: 8px 14px;
      border: 1px solid var(--bd);
      border-radius: 8px;
      background: var(--bg)
    }

    .opt input:checked+span {
      background: var(--ac);
      color: #2a2019;
      font-weight: 600
    }

    .opt input:focus-visible+span {
      outline: 2px solid var(--fg);
      outline-offset: 2px
    }

    input[type=range] {
      width: 100%;
      padding: 0;
      border: 0;
      background: none;
      accent-color: var(--ac);
      margin: 10px 0 2px
    }

    .scale {
      display: flex;
      justify-content: space-between;
      font-size: 12px;
      opacity: .65
    }

    #rv {
      display: inline-block;
      min-width: 2.2em;
      text-align: center;
      font-weight: 700;
      font-size: 18px
    }

    textarea {
      width: 100%;
      box-sizing: border-box;
      margin-top: 10px;
      resize: vertical;
      min-height: 90px
    }

    .err {
      color: #c0392b;
      font-size: 13px;
      margin-top: 6px
    }

    .send {
      display: block;
      width: 100%;
      margin: 16px 0;
      padding: 12px;
      font-size: 16px;
      font-weight: 700;
      background: var(--ac);
      color: #2a2019;
      border: 2px solid var(--bd)
    }

    .hp {
      position: absolute;
      left: -9999px;
      width: 1px;
      height: 1px;
      overflow: hidden
    }

    .thanks {
      text-align: center;
      padding: 28px 12px
    }

    .thanks b {
      font-size: 20px
    }
  </style>
</head>

<body>
  <div id="app">
    <p class="n"><a href="index.html">← Вернуться к игре</a></p>

<?php if ($done): ?>
    <div class="thanks">
      <b>Спасибо за ответы! 🦊</b>
      <p>Они помогут сделать игру лучше.</p>
      <p><a href="index.html">Вернуться к игре</a></p>
    </div>
<?php else: ?>
    <h1>Опрос об игре</h1>
    <div class="n">Шесть коротких вопросов. Ответы анонимны.</div>

<?php if (isset($errors['save'])): ?>
    <?= err($errors, 'save') ?>
<?php elseif ($errors): ?>
    <div class="err" role="alert">Проверьте, пожалуйста, ответы: не на все вопросы есть ответ.</div>
<?php endif; ?>

    <form method="post" action="Survey.php" id="f" autocomplete="off" novalidate>
      <div class="hp" aria-hidden="true">
        <label>Не заполняйте это поле <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <fieldset class="q" id="q1">
        <legend>1. С кем вы играли?</legend>
        <div class="opts">
<?php foreach (WHO as $key => $label): ?>
          <label class="opt"><input type="radio" name="who" value="<?= h($key) ?>"<?= $v['who'] === $key ? ' checked' : '' ?> required><span><?= h($label) ?></span></label>
<?php endforeach; ?>
        </div>
        <?= err($errors, 'who') ?>
      </fieldset>

      <fieldset class="q" id="q2">
        <legend>2. Как вам концепт игры?</legend>
        <div class="n">Передвиньте ползунок (1 — совсем не понравился, 10 — отлично). Оценка: <span id="rv">—</span></div>
        <input type="range" id="rr" min="1" max="10" step="1" value="<?= $v['rate'] !== '' ? h($v['rate']) : '5' ?>" aria-label="Оценка концепта от 1 до 10">
        <div class="scale" aria-hidden="true"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span><span>6</span><span>7</span><span>8</span><span>9</span><span>10</span></div>
        <input type="hidden" name="rate" id="rate" value="<?= h($v['rate']) ?>">
        <?= err($errors, 'rate') ?>
      </fieldset>

      <fieldset class="q" id="q3">
        <legend>3. Зацепил ли вас процесс игры?</legend>
        <div class="opts"><?= radio_yn('hook', $v['hook']) ?></div>
        <?= err($errors, 'hook') ?>
      </fieldset>

      <fieldset class="q" id="q4">
        <legend>4. Возникли ли какие-то проблемы в процессе игры?</legend>
        <div class="opts"><?= radio_yn('prob', $v['prob']) ?></div>
        <textarea name="prob_text" id="prob_text" maxlength="<?= MAX_TEXT ?>" placeholder="Опишите, что именно пошло не так" <?= $v['prob'] === 'yes' ? '' : 'hidden' ?>><?= h($v['prob_text']) ?></textarea>
        <?= err($errors, 'prob') ?>
        <?= err($errors, 'prob_text') ?>
      </fieldset>

      <fieldset class="q" id="q5">
        <legend>5. Сыграли бы ещё?</legend>
        <div class="opts"><?= radio_yn('again', $v['again']) ?></div>
        <?= err($errors, 'again') ?>
      </fieldset>

      <fieldset class="q" id="q6">
        <legend>6. Есть ли у вас замечания или идеи по улучшению игры?</legend>
        <div class="opts"><?= radio_yn('idea', $v['idea']) ?></div>
        <textarea name="idea_text" id="idea_text" maxlength="<?= MAX_TEXT ?>" placeholder="Напишите ваши замечания или идеи" <?= $v['idea'] === 'yes' ? '' : 'hidden' ?>><?= h($v['idea_text']) ?></textarea>
        <?= err($errors, 'idea') ?>
        <?= err($errors, 'idea_text') ?>
      </fieldset>

      <button type="submit" class="send">Отправить</button>
    </form>

    <script>
      (function () {
        const $ = s => document.querySelector(s), f = $('#f');
        const rr = $('#rr'), rate = $('#rate'), rv = $('#rv');

        // Ползунок считается «отвеченным» только после того, как игрок к нему прикоснулся
        const setRate = () => { rate.value = rr.value; rv.textContent = rr.value; const e = $('#q2 .err'); if (e) e.remove() };
        ['input', 'change', 'pointerup', 'keydown', 'touchend'].forEach(ev => rr.addEventListener(ev, setRate));
        if (rate.value) rv.textContent = rate.value;

        // Текстовые поля появляются только при ответе «Да»
        function bind(name, box) {
          const t = $('#' + box);
          const upd = () => {
            const yes = f.querySelector('input[name=' + name + ']:checked')?.value === 'yes';
            t.hidden = !yes; t.required = yes;
          };
          f.querySelectorAll('input[name=' + name + ']').forEach(r => r.addEventListener('change', upd));
          upd();
        }
        bind('prob', 'prob_text'); bind('idea', 'idea_text');

        // Проверка перед отправкой (сервер всё равно проверяет повторно)
        f.addEventListener('submit', e => {
          f.querySelectorAll('.err.js').forEach(x => x.remove());
          let bad = null;
          const fail = (id, msg) => {
            const d = document.createElement('div'); d.className = 'err js'; d.textContent = msg;
            $('#' + id).appendChild(d); bad = bad || $('#' + id);
          };
          if (!f.querySelector('input[name=who]:checked')) fail('q1', 'Выберите, с кем вы играли.');
          if (!rate.value) fail('q2', 'Оцените концепт ползунком.');
          [['hook', 'q3'], ['prob', 'q4'], ['again', 'q5'], ['idea', 'q6']].forEach(([n, q]) => {
            if (!f.querySelector('input[name=' + n + ']:checked')) fail(q, 'Выберите ответ.');
          });
          if (f.querySelector('input[name=prob]:checked')?.value === 'yes' && !$('#prob_text').value.trim()) fail('q4', 'Опишите, какие были проблемы.');
          if (f.querySelector('input[name=idea]:checked')?.value === 'yes' && !$('#idea_text').value.trim()) fail('q6', 'Напишите ваши замечания или идеи.');
          if (bad) { e.preventDefault(); bad.scrollIntoView({ behavior: 'smooth', block: 'center' }) }
        });
      })();
    </script>
<?php endif; ?>
  </div>
</body>

</html>
