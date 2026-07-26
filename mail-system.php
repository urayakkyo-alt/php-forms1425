<?php
/* ==============================================================================
 *  mail-system.php
 *  お問い合わせフォーム メール送信システム
 * ------------------------------------------------------------------------------
 *  ・sample-form.html のような <form method="post" action="mail-system.php">
 *    から送信された内容を受け取り、指定のメールアドレスへ送信します。
 *  ・「1. 基本設定」を書き換えるだけで、送信先や本文の見た目を変更できます。
 *  ・フォームの項目(お名前・メールアドレス・チェックボックスなど)は
 *    このPHPファイルを一切変更しなくても、HTML側の <input name="◯◯"> の
 *    「name」を書き換えるだけで自由に追加・削除できます。
 *    (name属性の文字列が、そのままメール本文の【見出し】になります)
 *  ・完了画面・エラー画面のHTML/CSSは、このファイルの下の方にある
 *    「▼▼▼ ここからHTML ▼▼▼」という場所にあります。
 *    PHPの知識がなくても、HTMLとCSSがわかれば自由に編集できます。
 * ============================================================================ */

mb_language('Japanese');
mb_internal_encoding('UTF-8');

/* ============================================================================
 * 1. 基本設定 (ここを書き換えて使ってください)
 * ========================================================================== */
$config = [

    // ---- 送信先メールアドレス -------------------------------------------------
    // お問い合わせ内容が届くメールアドレスです。複数指定する場合は
    // 'to' => ['info@example.com', 'sales@example.com'], のようにカンマで区切って追加してください。
    'to' => [
        'info@example.com',
    ],

    // ---- 送信者として表示される情報 --------------------------------------------
    // サーバーによっては、実際に送信可能なドメインのアドレスでないと
    // 迷惑メール判定されたり、送信自体に失敗する場合があります。
    'from_email' => 'noreply@example.com',
    'from_name'  => 'ホームページ お問い合わせフォーム',

    // ---- メールの件名 ---------------------------------------------------------
    'subject' => '「ホームページのお問い合わせ」からメールが届きました',

    // ---- メール本文の一番上に入れる一言(不要な場合は空文字 '' でOK) -------------
    'intro_text' => '「ホームページのお問い合わせ」からメールが届きました',

    // ---- 自動返信メール --------------------------------------------------------
    // お問い合わせをしてくれた方に、自動でお礼メールを送りたい場合は true にしてください。
    'auto_reply' => false,
    'auto_reply_subject' => 'お問い合わせありがとうございます',
    'auto_reply_body' =>
        "この度はお問い合わせいただき、誠にありがとうございます。\n" .
        "内容を確認のうえ、担当者より改めてご連絡いたします。\n\n" .
        "※このメールは自動返信です。",
    // 自動返信を送る宛先として使う項目の name属性 (HTML側のメールアドレス欄のnameと合わせてください)
    'auto_reply_email_field' => 'メールアドレス',

    // ---- スパム対策 (reCAPTCHA / hCaptcha / Cloudflare Turnstile) --------------
    // 使いたいものの「シークレットキー」だけを入力してください。
    // 3つとも空のままなら、CAPTCHAでのチェックは行われません。
    // 2つ以上入力した場合は、上から順(reCAPTCHA→hCaptcha→Turnstile)に優先されます。
    'recaptcha_secret' => '',
    'hcaptcha_secret'  => '',
    'turnstile_secret' => '',

    // ---- ハニーポット(隠し項目)によるスパム対策 ---------------------------------
    // sample-form.html には人には見えない入力欄が1つ用意されています。
    // ここが埋まっていたら「ロボットによる送信」とみなして処理を中断します。
    'use_honeypot' => true,

    // ---- メール本文に含めない項目名 --------------------------------------------
    // システムで使う特別な項目なので、通常は編集不要です。
    // フォーム側で新しい隠し項目(name)を追加した場合、ここに追加すると本文から除外できます。
    'exclude_fields' => [
        '_required', '_subject', '_honeypot',
        'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response',
    ],
];
/* ============================================================================
 * 設定はここまでです。この下は基本的に変更不要です。
 * ========================================================================== */


/* ---- POST以外でのアクセスはフォームへ誘導 ---------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sample-form.html');
    exit;
}

/* ---- ハニーポットチェック(ボット対策) --------------------------------------- */
if ($config['use_honeypot'] && !empty($_POST['_honeypot'])) {
    // ボットからの送信とみなし、メールは送らずに完了画面だけ表示する
    // (ボットに「ブロックされた」と気づかせないための対応です)
    show_screen('complete');
    exit;
}

/* ---- 必須項目チェック -------------------------------------------------------
 * HTML側の <input type="hidden" name="_required" value="お名前,メールアドレス">
 * に書かれた項目名(name属性)が空の場合、エラー画面を表示します。
 * 必須項目を増やしたり減らしたりしたい場合は、HTML側の value を書き換えるだけでOKです。
 * ------------------------------------------------------------------------- */
$required_fields = [];
if (!empty($_POST['_required'])) {
    $required_fields = array_filter(array_map('trim', explode(',', $_POST['_required'])));
}
$missing_fields = [];
foreach ($required_fields as $field_name) {
    $val = $_POST[$field_name] ?? null;
    if (is_array($val)) {
        if (count(array_filter($val, fn($v) => trim((string)$v) !== '')) === 0) {
            $missing_fields[] = $field_name;
        }
    } else {
        if ($val === null || trim((string)$val) === '') {
            $missing_fields[] = $field_name;
        }
    }
}
if (!empty($missing_fields)) {
    show_screen('error', '未入力の項目があります: ' . implode('、', $missing_fields));
    exit;
}

/* ---- CAPTCHA(スパム防止)チェック -------------------------------------------- */
$captcha_ok = true;
if (!empty($config['recaptcha_secret'])) {
    $captcha_ok = verify_captcha(
        $config['recaptcha_secret'],
        $_POST['g-recaptcha-response'] ?? '',
        'https://www.google.com/recaptcha/api/siteverify'
    );
} elseif (!empty($config['hcaptcha_secret'])) {
    $captcha_ok = verify_captcha(
        $config['hcaptcha_secret'],
        $_POST['h-captcha-response'] ?? '',
        'https://hcaptcha.com/siteverify'
    );
} elseif (!empty($config['turnstile_secret'])) {
    $captcha_ok = verify_captcha(
        $config['turnstile_secret'],
        $_POST['cf-turnstile-response'] ?? '',
        'https://challenges.cloudflare.com/turnstile/v0/siteverify'
    );
}
if (!$captcha_ok) {
    show_screen('error', '画像認証(reCAPTCHA / hCaptcha / Turnstile)の確認に失敗しました。もう一度お試しください。');
    exit;
}

/* ---- メール本文の組み立て ----------------------------------------------------
 * $_POST に入っている項目を、上から順番にすべて本文へ変換します。
 * 「【name属性の値】 入力された内容」という形式になるので、
 * フォーム側で <input name="会社名"> のような項目を増やせば、
 * PHPを触らなくても自動でメール本文に反映されます。
 * ------------------------------------------------------------------------- */
$body = '';
if (!empty($config['intro_text'])) {
    $body .= $config['intro_text'] . "\n\n";
}

$reply_to = '';
foreach ($_POST as $key => $value) {
    if (in_array($key, $config['exclude_fields'], true)) {
        continue;
    }
    if (is_array($value)) {
        $value = implode('、', array_map('strval', $value));
    }
    $value = (string)$value;

    // 入力値の中にメールアドレスが含まれていたら、返信先(Reply-To)として自動採用
    if ($reply_to === '' && filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
        $reply_to = trim($value);
    }

    if (strpos($value, "\n") !== false) {
        // 複数行の内容(お問い合わせ内容など)は見出しの次の行に表示
        $body .= "【{$key}】\n{$value}\n\n";
    } else {
        // 1行の内容は見出しと同じ行に表示
        $body .= "【{$key}】 {$value}\n\n";
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '(取得できませんでした)';
$body .= '送信された日時:' . date('Y/m/d (D) H:i:s') . "\n";
$body .= "送信者のIPアドレス: {$ip}\n";

/* ---- 件名の組み立て ---------------------------------------------------------
 * HTML側に <input type="hidden" name="_subject" value="任意の文言"> を追加すると
 * その内容が件名の後ろに追加されます。1つのmail-system.phpを複数のフォームで
 * 使い分けたい場合などに便利です(不要なら何もしなくてOK)。
 * ------------------------------------------------------------------------- */
$subject = $config['subject'];
if (!empty($_POST['_subject'])) {
    $custom_subject = str_replace(["\r", "\n"], '', (string)$_POST['_subject']);
    $subject .= '：' . $custom_subject;
}

/* ---- メールヘッダーの組み立て ------------------------------------------------ */
$headers  = 'From: ' . mb_encode_mimeheader($config['from_name']) . " <{$config['from_email']}>\r\n";
if ($reply_to !== '') {
    $headers .= "Reply-To: {$reply_to}\r\n";
}
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

/* ---- 送信実行 ---------------------------------------------------------------- */
$to = implode(',', $config['to']);
$send_ok = mb_send_mail($to, $subject, $body, $headers);

if (!$send_ok) {
    show_screen('error', 'メールの送信に失敗しました。時間をおいて再度お試しいただくか、サイト管理者へご連絡ください。');
    exit;
}

/* ---- 自動返信メール ----------------------------------------------------------- */
if ($config['auto_reply'] && !empty($_POST[$config['auto_reply_email_field']])) {
    $to_addr = trim((string)$_POST[$config['auto_reply_email_field']]);
    if (filter_var($to_addr, FILTER_VALIDATE_EMAIL)) {
        $ar_headers  = 'From: ' . mb_encode_mimeheader($config['from_name']) . " <{$config['from_email']}>\r\n";
        $ar_headers .= "MIME-Version: 1.0\r\n";
        $ar_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        mb_send_mail($to_addr, $config['auto_reply_subject'], $config['auto_reply_body'], $ar_headers);
    }
}

/* ---- 完了画面を表示 ----------------------------------------------------------- */
show_screen('complete');
exit;


/* ============================================================================
 * 2. 内部で使う関数(通常は編集不要です)
 * ========================================================================== */

/**
 * reCAPTCHA / hCaptcha / Turnstile の判定結果をサーバー側で確認します。
 */
function verify_captcha(string $secret, string $response, string $verify_url): bool
{
    if ($response === '') {
        return false;
    }
    $post_data = http_build_query([
        'secret'   => $secret,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post_data,
            'timeout' => 10,
        ],
    ]);
    $result = @file_get_contents($verify_url, false, $context);
    if ($result === false) {
        return false;
    }
    $json = json_decode($result, true);
    return !empty($json['success']);
}

/**
 * 完了画面 / エラー画面を表示して終了します。
 * $type には 'complete' または 'error' を指定します。
 */
function show_screen(string $type, string $message = ''): void
{
    header('Content-Type: text/html; charset=UTF-8');
    if ($type === 'error') {
        render_error_screen($message);
    } else {
        render_complete_screen();
    }
}


/* ============================================================================
 * 3. 完了画面・エラー画面のHTML
 * ----------------------------------------------------------------------------
 *   ▼▼▼ ここから下は「見た目(HTML・CSS)」だけの部分です。 ▼▼▼
 *   PHPの知識がなくても、HTMLとCSSの書き方がわかれば自由に編集して構いません。
 *   関数の外側にある文字(<?php ... ?> の外)や、ヒアドキュメント(<<<HTML ... HTML)
 *   の中身は、すべて普通のHTMLとして扱われます。
 * ========================================================================== */

/**
 * ▼ 送信完了画面 ▼
 * ここのHTMLとCSSを書き換えれば、送信完了画面のデザインを自由に変更できます。
 */
function render_complete_screen(): void
{
    echo <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>送信完了しました</title>
<style>
  /* ここから完了画面用オリジナルCSS。自由に編集してください */
  body {
    margin: 0;
    padding: 0;
    background: #f5f6f8;
    font-family: "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif;
    color: #333;
  }
  .screen-wrap {
    max-width: 560px;
    margin: 60px auto;
    padding: 40px 30px;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    text-align: center;
  }
  .screen-icon {
    font-size: 48px;
    color: #3aa76d;
    line-height: 1;
    margin-bottom: 16px;
  }
  .screen-wrap h1 {
    font-size: 22px;
    margin: 0 0 16px;
    color: #222;
  }
  .screen-wrap p {
    font-size: 15px;
    line-height: 1.8;
    margin: 0 0 28px;
  }
  .screen-back-btn {
    display: inline-block;
    padding: 12px 32px;
    background: #3aa76d;
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
    font-size: 15px;
    transition: opacity .2s;
  }
  .screen-back-btn:hover {
    opacity: 0.85;
  }
  @media (max-width: 600px) {
    .screen-wrap {
      margin: 24px 16px;
      padding: 32px 20px;
    }
  }
  /* ここまで完了画面用オリジナルCSS */
</style>
</head>
<body>
  <div class="screen-wrap">
    <div class="screen-icon">✓</div>
    <h1>お問い合わせありがとうございました</h1>
    <p>
      お問い合わせを受け付けいたしました。<br>
      内容を確認のうえ、担当者より折り返しご連絡いたします。<br>
      今しばらくお待ちくださいますようお願いいたします。
    </p>
    <a class="screen-back-btn" href="sample-form.html">トップページへ戻る</a>
  </div>
</body>
</html>
HTML;
}

/**
 * ▼ エラー画面 ▼
 * ここのHTMLとCSSを書き換えれば、エラー画面のデザインを自由に変更できます。
 * $message には「未入力の項目があります」などのエラー内容が渡されます。
 */
function render_error_screen(string $message): void
{
    $safe_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>送信エラー</title>
<style>
  /* ここからエラー画面用オリジナルCSS。自由に編集してください */
  body {
    margin: 0;
    padding: 0;
    background: #f5f6f8;
    font-family: "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif;
    color: #333;
  }
  .screen-wrap {
    max-width: 560px;
    margin: 60px auto;
    padding: 40px 30px;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    text-align: center;
  }
  .screen-icon {
    font-size: 48px;
    color: #d9534f;
    line-height: 1;
    margin-bottom: 16px;
  }
  .screen-wrap h1 {
    font-size: 22px;
    margin: 0 0 16px;
    color: #222;
  }
  .screen-message {
    display: inline-block;
    background: #fdecea;
    color: #b3392f;
    border-radius: 4px;
    padding: 12px 18px;
    font-size: 14px;
    margin: 0 0 28px;
  }
  .screen-back-btn {
    display: inline-block;
    padding: 12px 32px;
    background: #555;
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
    font-size: 15px;
    transition: opacity .2s;
  }
  .screen-back-btn:hover {
    opacity: 0.85;
  }
  @media (max-width: 600px) {
    .screen-wrap {
      margin: 24px 16px;
      padding: 32px 20px;
    }
  }
  /* ここまでエラー画面用オリジナルCSS */
</style>
</head>
<body>
  <div class="screen-wrap">
    <div class="screen-icon">!</div>
    <h1>送信できませんでした</h1>
    <p class="screen-message">{$safe_message}</p>
    <p><a class="screen-back-btn" href="javascript:history.back()">フォームに戻ってやり直す</a></p>
  </div>
</body>
</html>
HTML;
}
