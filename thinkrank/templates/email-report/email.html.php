<?php
/**
 * Email Report Layout
 *
 * Receives a $payload array assembled by Email_Report_Renderer.
 *
 * A light canvas with one centred 600px column: the logo row, an optional
 * test-send banner, an optional intro, the section cards (each already a
 * <tr> from Email_Report_Renderer::card()), the dashboard call to action
 * and a quiet footer (#742).
 *
 * Email-client safety notes:
 *  - Inline styles only — Gmail / Outlook / Apple Mail strip / partially
 *    support <style> blocks. Anything that must render reliably is inline.
 *  - Tables for outer layout — most reliable across clients (especially
 *    older Outlook versions).
 *  - The header logo, logo link, header background, intro, footer, extra CSS
 *    and dashboard link are slots filled through the
 *    `thinkrank_email_report_payload` filter. A header background turns the
 *    logo row into a coloured band. Extra CSS goes into a <style> tag; CSS
 *    support varies across email clients.
 *
 * @var array $payload
 *
 * @package ThinkRank
 */

declare(strict_types=1);

use ThinkRank\SEO\Email_Report_Renderer;
use ThinkRank\SEO\Email_Report_Sections\Email_Report_Html;

// This file is included from Email_Report_Renderer, so its variables live in
// that method's scope, not the global one — the prefix sniff assumes file
// scope means global scope. The unescaped echoes are the logo anchor, the
// section HTML and the cards, all assembled from esc_url()/esc_html() output
// above and in the section renderers; escaping them again would print
// entities in the email body.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.EscapeOutput.OutputNotEscaped

if (!defined('ABSPATH')) {
    exit;
}

$payload         = isset($payload) && is_array($payload) ? $payload : [];
$site_title      = (string) ($payload['site_title'] ?? '');
$site_url        = (string) ($payload['site_url'] ?? '');
$header_logo     = (string) ($payload['header_logo'] ?? '');
$header_bg       = (string) ($payload['header_bg'] ?? '');
$logo_link       = (string) ($payload['logo_link'] ?? '');
$intro_text      = (string) ($payload['intro_text'] ?? '');
$sections_html   = (string) ($payload['sections_html'] ?? '');
$footer_text     = (string) ($payload['footer_text'] ?? '');
$additional_css  = (string) ($payload['additional_css'] ?? '');
$cta_url         = (string) ($payload['cta_url'] ?? '');
$settings_url    = (string) ($payload['settings_url'] ?? '');
$frequency_label = (string) ($payload['frequency_label'] ?? '');
$context         = (array)  ($payload['context'] ?? []);
$period_label    = (string) ($context['period_label'] ?? '');
$is_test         = !empty($context['is_test']);
$not_connected   = !empty($context['not_connected']);

$font   = Email_Report_Html::FONT;
$ink    = Email_Report_Html::INK;
$muted  = Email_Report_Html::MUTED;
$canvas = Email_Report_Html::CANVAS;
$brand  = Email_Report_Html::PRIMARY;

// Logo row. With a Pro header background it becomes a coloured band and the
// right-hand label goes white; otherwise it sits straight on the canvas.
$band          = $header_bg !== '';
$header_style  = $band
    ? 'padding:18px 24px;background:' . esc_attr($header_bg) . ';border-radius:14px;'
    : 'padding:0 4px;';
$label_color   = $band ? '#ffffff' : $muted;

$logo_img = $header_logo !== ''
    ? '<img src="' . esc_url($header_logo) . '" alt="' . esc_attr($site_title !== '' ? $site_title : 'ThinkRank') . '" height="32" style="display:block;border:0;max-height:32px;width:auto;" />'
    : '<span style="' . $font . 'font-size:20px;font-weight:800;letter-spacing:-0.02em;color:' . ($band ? '#ffffff' : $ink) . ';">'
        . esc_html($site_title !== '' ? $site_title : 'ThinkRank')
        . '</span>';

if ($logo_link !== '') {
    $logo_img = '<a href="' . esc_url($logo_link) . '" style="text-decoration:none;">' . $logo_img . '</a>';
}

$preheader = $not_connected
    ? sprintf(
        /* translators: %s: site title. */
        __('Connect Google Search Console to start receiving SEO reports for %s.', 'thinkrank'),
        $site_title
    )
    : sprintf(
        /* translators: 1: site title, 2: period label. */
        __('%1$s: your search performance for %2$s.', 'thinkrank'),
        $site_title,
        $period_label
    );
?>
<!doctype html>
<html lang="<?php echo esc_attr(str_replace('_', '-', get_locale())); ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <meta name="color-scheme" content="light" />
    <title><?php echo esc_html($site_title); ?></title>
    <?php if ($additional_css !== '') : ?>
        <style type="text/css">
            <?php echo esc_html($additional_css); ?>
        </style>
    <?php endif; ?>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr($canvas); ?>;-webkit-font-smoothing:antialiased;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;"><?php echo esc_html($preheader); ?></div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr($canvas); ?>;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

                    <tr>
                        <td style="padding:0 0 20px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="<?php echo $header_style; ?>">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                    <td valign="middle"><?php echo $logo_img; ?></td>
                                    <?php if ($frequency_label !== '') : ?>
                                        <td align="right" valign="middle" style="<?php echo $font; ?>font-size:12px;color:<?php echo esc_attr($label_color); ?>;white-space:nowrap;"><?php echo esc_html($frequency_label); ?></td>
                                    <?php endif; ?>
                                </tr></table>
                            </td></tr></table>
                        </td>
                    </tr>

                    <?php if ($is_test) : ?>
                        <tr>
                            <td style="padding:0 0 16px 0;">
                                <div style="<?php echo $font; ?>font-size:13px;color:#7a4b00;background:#fff4d6;border:1px solid #f5d98a;border-radius:10px;padding:10px 14px;">
                                    <?php
                                    // The connect state is not what a scheduled run sends: while
                                    // Search Console is disconnected nothing goes out at all, so
                                    // promising "the same layout" here contradicts the card below.
                                    echo esc_html(
                                        $not_connected
                                            ? __('This is a test email sent from the ThinkRank dashboard. Scheduled reports stay paused until Search Console is connected.', 'thinkrank')
                                            : __('This is a test email sent from the ThinkRank dashboard. Scheduled reports use the same layout.', 'thinkrank')
                                    );
                                    ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($intro_text !== '') : ?>
                        <?php echo Email_Report_Renderer::card('<div style="' . $font . 'font-size:15px;line-height:1.6;color:' . $ink . ';">' . wp_kses_post(wpautop($intro_text)) . '</div>', 'tr-email-intro'); ?>
                    <?php endif; ?>

                    <?php echo $sections_html; ?>

                    <?php if ($cta_url !== '' && !$not_connected) : ?>
                        <?php
                        echo Email_Report_Renderer::card(
                            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:6px 0 2px 0;">'
                            . '<a href="' . esc_url($cta_url) . '" style="display:inline-block;background:' . $brand . ';color:#ffffff;' . $font . 'font-size:15px;font-weight:700;text-decoration:none;padding:14px 28px;border-radius:10px;">'
                            . esc_html__('Open the full report in ThinkRank', 'thinkrank') . '</a>'
                            . '<div style="' . $font . 'font-size:12px;color:' . $muted . ';margin-top:12px;">'
                            . esc_html__('Page-by-page metrics, keyword history and AI suggestions live in your dashboard.', 'thinkrank')
                            . '</div></td></tr></table>',
                            'tr-email-cta',
                            '22px 28px'
                        );
                        ?>
                    <?php endif; ?>

                    <tr>
                        <td style="padding:8px 4px 0 4px;<?php echo $font; ?>font-size:12px;color:<?php echo esc_attr($muted); ?>;line-height:1.7;text-align:center;">
                            <?php echo wp_kses_post($footer_text); ?>
                            <?php if ($settings_url !== '' || $site_url !== '') : ?>
                                <br>
                                <?php if ($settings_url !== '') : ?>
                                    <a href="<?php echo esc_url($settings_url); ?>" style="color:<?php echo esc_attr($muted); ?>;"><?php echo esc_html__('Report settings', 'thinkrank'); ?></a>
                                <?php endif; ?>
                                <?php if ($settings_url !== '' && $site_url !== '') : ?>
                                    &nbsp;·&nbsp;
                                <?php endif; ?>
                                <?php if ($site_url !== '') : ?>
                                    <a href="<?php echo esc_url($site_url); ?>" style="color:<?php echo esc_attr($muted); ?>;"><?php echo esc_html(wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
