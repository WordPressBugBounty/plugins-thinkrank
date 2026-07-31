<?php
/**
 * Email Report Layout
 *
 * Receives a $payload array assembled by Email_Report_Renderer.
 *
 * Email-client safety notes:
 *  - Inline styles only — Gmail / Outlook / Apple Mail strip / partially
 *    support <style> blocks. Anything that must render reliably is inline.
 *  - Tables for outer layout — most reliable across clients (especially
 *    older Outlook versions).
 *  - The `additional_css` block (Pro-only) is injected as a <style> tag so
 *    advanced agencies can theme without us re-engineering inline rules
 *    every time. The PRD calls out that CSS support varies — that's their
 *    risk to take.
 *
 * @var array $payload
 *
 * @package ThinkRank
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$payload         = isset($payload) && is_array($payload) ? $payload : [];
$site_title      = (string) ($payload['site_title'] ?? '');
$header_logo     = (string) ($payload['header_logo'] ?? '');
$header_bg       = (string) ($payload['header_bg'] ?? '');
$logo_link       = (string) ($payload['logo_link'] ?? '');
$intro_text      = (string) ($payload['intro_text'] ?? '');
$sections_html   = (string) ($payload['sections_html'] ?? '');
$footer_text     = (string) ($payload['footer_text'] ?? '');
$additional_css  = (string) ($payload['additional_css'] ?? '');
$cta_url         = (string) ($payload['cta_url'] ?? '');
$context         = (array)  ($payload['context'] ?? []);
$period_label    = (string) ($context['period_label'] ?? '');
$is_test         = !empty($context['is_test']);

$header_style = 'padding:24px;text-align:center;';
if ($header_bg !== '') {
    $header_style .= 'background:' . $header_bg . ';';
} else {
    $header_style .= 'background:#0f172a;';
}

$logo_img = $header_logo !== ''
    ? '<img src="' . esc_url($header_logo) . '" alt="' . esc_attr($site_title) . '" style="max-height:48px;border:0;display:inline-block;" />'
    : '<span style="font:700 22px/1 -apple-system,Segoe UI,Roboto,sans-serif;color:#ffffff;">'
        . esc_html($site_title !== '' ? $site_title : 'ThinkRank')
        . '</span>';

if ($logo_link !== '') {
    $logo_img = '<a href="' . esc_url($logo_link) . '" style="text-decoration:none;color:#ffffff;">' . $logo_img . '</a>';
}
?>
<!doctype html>
<html lang="<?php echo esc_attr(str_replace('_', '-', get_locale())); ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo esc_html($site_title); ?></title>
    <?php if ($additional_css !== '') : ?>
        <style type="text/css">
            <?php echo esc_html($additional_css); ?>
        </style>
    <?php endif; ?>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;-webkit-font-smoothing:antialiased;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
        <tr>
            <td align="center" style="padding:32px 12px;">

                <?php if ($is_test) : ?>
                    <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;margin:0 0 12px 0;">
                        <tr>
                            <td style="background:#fef3c7;color:#78350f;padding:10px 16px;border-radius:6px;font:500 13px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">
                                <?php esc_html_e('Test email — this is a preview triggered from the ThinkRank settings.', 'thinkrank'); ?>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="<?php echo esc_attr($header_style); ?>">
                            <?php echo $logo_img; // already escaped above ?>
                            <?php if ($period_label !== '') : ?>
                                <div style="margin-top:8px;color:rgba(255,255,255,0.85);font:500 13px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">
                                    <?php echo esc_html($period_label); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($intro_text !== '') : ?>
                        <tr>
                            <td style="padding:24px 24px 0 24px;font:400 15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;color:#374151;">
                                <?php echo wp_kses_post($intro_text); ?>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <td style="padding:24px;font:400 14px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;color:#1f2937;">
                            <?php echo $sections_html; // sections render their own escaped HTML ?>
                        </td>
                    </tr>

                    <?php if ($cta_url !== '') : ?>
                        <tr>
                            <td align="center" style="padding:0 24px 28px 24px;">
                                <a href="<?php echo esc_url($cta_url); ?>" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font:600 14px/1 -apple-system,Segoe UI,Roboto,sans-serif;padding:12px 22px;border-radius:8px;">
                                    <?php esc_html_e('View Full Report', 'thinkrank'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <td style="padding:18px 24px;background:#f9fafb;color:#6b7280;font:400 12px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;text-align:center;border-top:1px solid #e5e7eb;">
                            <?php echo wp_kses_post($footer_text); ?>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
