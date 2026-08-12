<?php
/**
 * Ticket confirmation email theme — copy this content to ticket-confirmed.php.
 *
 * Available variables:
 * $site_name, $site_url, $order_id, $event_name, $event_date,
 * $event_time, $customer_name, $customer_email, $customer_phone,
 * $ticket_count, $total_amount, $currency and $tickets.
 *
 * Each item in $tickets contains: code, type, url and label.
 */
defined('ABSPATH') || exit;
?>
<!doctype html>
<html>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Your event tickets', 'dizzy-ticket-manager'); ?></title>
</head>
<body style="margin:0;padding:0;background:#0b0b0b;color:#ffffff;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#0b0b0b;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#191919;">
                <tr>
                    <td style="padding:34px 38px;border-bottom:1px solid #333333;">
                        <div style="font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#bdbdbd;"><?php echo esc_html((string) $site_name); ?></div>
                        <h1 style="margin:12px 0 0;color:#ffffff;font-size:28px;line-height:1.25;"><?php esc_html_e('Your tickets are ready', 'dizzy-ticket-manager'); ?></h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:34px 38px;color:#ffffff;">
                        <p style="margin:0 0 24px;font-size:16px;line-height:1.65;">
                            <?php echo esc_html(sprintf(__('Hello %s, your payment was received successfully.', 'dizzy-ticket-manager'), (string) $customer_name)); ?>
                        </p>
                        <h2 style="margin:0 0 18px;color:#ffffff;font-size:22px;"><?php echo esc_html((string) $event_name); ?></h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                            <tr><td style="padding:11px 0;border-bottom:1px solid #333333;color:#a7a7a7;"><?php esc_html_e('Date', 'dizzy-ticket-manager'); ?></td><td align="right" style="padding:11px 0;border-bottom:1px solid #333333;color:#ffffff;"><?php echo esc_html((string) $event_date); ?></td></tr>
                            <tr><td style="padding:11px 0;border-bottom:1px solid #333333;color:#a7a7a7;"><?php esc_html_e('Time', 'dizzy-ticket-manager'); ?></td><td align="right" style="padding:11px 0;border-bottom:1px solid #333333;color:#ffffff;"><?php echo esc_html((string) $event_time); ?></td></tr>
                            <tr><td style="padding:11px 0;border-bottom:1px solid #333333;color:#a7a7a7;"><?php esc_html_e('Tickets', 'dizzy-ticket-manager'); ?></td><td align="right" style="padding:11px 0;border-bottom:1px solid #333333;color:#ffffff;"><?php echo esc_html((string) $ticket_count); ?></td></tr>
                            <tr><td style="padding:11px 0;border-bottom:1px solid #333333;color:#a7a7a7;"><?php esc_html_e('Total', 'dizzy-ticket-manager'); ?></td><td align="right" style="padding:11px 0;border-bottom:1px solid #333333;color:#ffffff;"><?php echo esc_html((string) $currency . ' ' . (string) $total_amount); ?></td></tr>
                        </table>
                        <div style="margin-top:28px;">
                            <?php foreach ((array) $tickets as $index => $ticket) : ?>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 12px;background:#222222;">
                                    <tr>
                                        <td style="padding:18px;color:#ffffff;">
                                            <strong><?php echo esc_html((string) ($ticket['type'] ?? __('Event Ticket', 'dizzy-ticket-manager'))); ?></strong><br>
                                            <span style="color:#a7a7a7;font-size:12px;"><?php echo esc_html((string) ($ticket['label'] ?? '')); ?></span>
                                        </td>
                                        <td align="right" style="padding:18px;">
                                            <a href="<?php echo esc_url((string) ($ticket['url'] ?? '')); ?>" style="display:inline-block;background:#ffffff;color:#050505;font-size:12px;font-weight:bold;letter-spacing:1.3px;padding:14px 20px;text-decoration:none;text-transform:uppercase;"><?php esc_html_e('Open ticket', 'dizzy-ticket-manager'); ?></a>
                                        </td>
                                    </tr>
                                </table>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin:24px 0 0;color:#8f8f8f;font-size:12px;"><?php echo esc_html(sprintf(__('Order number: %d', 'dizzy-ticket-manager'), (int) $order_id)); ?></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
