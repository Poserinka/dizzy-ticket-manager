<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class TicketExperience
{
    public function __construct(private TicketSalesRepository $repository) {}

    public function register(): void
    {
        add_action('wp_footer', [$this, 'render']);
        add_action('wp_ajax_dizzy_ticket_details', [$this, 'details']);
        add_action('wp_ajax_nopriv_dizzy_ticket_details', [$this, 'details']);
    }

    public function details(): void
    {
        $code = sanitize_text_field(wp_unslash((string) ($_POST['ticket_code'] ?? '')));

        if (! preg_match('/^[a-f0-9]{64}$/', $code)) {
            wp_send_json_error(['message' => __('Invalid ticket.', 'dizzy-reservations-manager')], 400);
        }

        $ticket = $this->repository->ticketByCode($code);

        if ($ticket === null || ($ticket['status'] ?? '') !== 'valid') {
            wp_send_json_error(['message' => __('This ticket is not available.', 'dizzy-reservations-manager')], 404);
        }

        global $wpdb;
        $ticketName = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT i.ticket_name FROM {$wpdb->prefix}dizzy_ticket_order_items i INNER JOIN {$wpdb->prefix}dizzy_tickets t ON t.order_item_id=i.id WHERE t.ticket_code=%s LIMIT 1",
            $code
        ));
        $start = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT start_datetime FROM {$wpdb->prefix}dizzy_event_occurrences WHERE id=%d LIMIT 1",
            (int) $ticket['occurrence_id']
        ));
        $ticketUrl = add_query_arg('dizzy_paid_ticket', $code, home_url('/'));
        $qrResponse = wp_remote_get(
            'https://api.qrserver.com/v1/create-qr-code/?size=560x560&margin=24&data=' . rawurlencode($ticketUrl),
            ['timeout' => 15]
        );
        $qrData = '';

        if (! is_wp_error($qrResponse) && wp_remote_retrieve_response_code($qrResponse) === 200) {
            $qrData = 'data:image/png;base64,' . base64_encode(wp_remote_retrieve_body($qrResponse));
        }

        $timestamp = $start !== '' ? strtotime($start) : false;

        wp_send_json_success([
            'event' => get_the_title((int) $ticket['event_id']),
            'date' => $timestamp !== false ? wp_date(get_option('date_format') . ' – ' . get_option('time_format'), $timestamp, wp_timezone()) : $start,
            'holder' => (string) $ticket['holder_name'],
            'type' => $ticketName !== '' ? $ticketName : __('Event Ticket', 'dizzy-reservations-manager'),
            'code' => strtoupper(substr($code, 0, 12)),
            'qr' => $qrData,
            'url' => $ticketUrl,
            'filename' => sanitize_file_name(get_the_title((int) $ticket['event_id']) . '-' . substr($code, 0, 8) . '-ticket.jpg'),
        ]);
    }

    public function render(): void
    {
        $order = sanitize_text_field(wp_unslash((string) ($_GET['dizzy_order'] ?? '')));

        if (! preg_match('/^[a-f0-9]{64}$/', $order)) {
            return;
        }

        $orderRecord = $this->repository->orderByToken($order);

        if ($orderRecord === null) {
            return;
        }

        $paymentStatus = sanitize_key((string) ($orderRecord['status'] ?? 'pending'));
        $statusMessage = match ($paymentStatus) {
            'failed' => __('The payment failed. Please try again.', 'dizzy-reservations-manager'),
            'canceled', 'cancelled' => __('The payment was canceled.', 'dizzy-reservations-manager'),
            'expired' => __('The payment session expired. Please start a new payment.', 'dizzy-reservations-manager'),
            default => __('Your payment is being processed. Please wait a moment and refresh the page.', 'dizzy-reservations-manager'),
        };
        ?>
        <style>
            .dizzy-ticket-overlay{align-items:center;background:rgba(0,0,0,.82);display:none;inset:0;justify-content:center;padding:18px;position:fixed;z-index:999999}.dizzy-ticket-overlay.is-open{display:flex}.dizzy-ticket-modal{background:#191919;border:0px solid #292929;border-radius:0;box-shadow:0 24px 70px rgba(0,0,0,.55);box-sizing:border-box;color:#fff;max-height:92vh;max-width:720px;overflow:auto;padding:38px;position:relative;text-align:center;width:100%}.dizzy-ticket-modal h2,.dizzy-ticket-modal h3,.dizzy-ticket-modal p{color:inherit}.dizzy-ticket-close{background:transparent!important;border:0!important;color:#fff!important;font-size:28px!important;line-height:1!important;padding:8px!important;position:absolute;right:1px;top:1px}.dizzy-ticket-modal img{background:#fff;height:auto;max-width:280px;padding:12px;width:72%}.dizzy-ticket-modal .woocommerce-notices-wrapper{margin:0}.dizzy-ticket-modal .woocommerce-message{align-items:center;background:#1d1d1d;border:0;color:#fff;display:flex;gap:24px;justify-content:space-between;margin:0;padding:24px;text-align:left}.dizzy-ticket-modal .woocommerce-message::before{display:none}.dizzy-ticket-modal .woocommerce-message-text{line-height:1.6}.dizzy-ticket-modal .woocommerce-message .button.wc-forward{background:#fff;border:1px solid #fff;border-radius:0;color:#050505;flex:0 0 auto;font-size:12px;font-weight:700;letter-spacing:1.5px;padding:16px 28px;text-align:center;text-transform:uppercase}.dizzy-ticket-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:22px}.dizzy-ticket-actions button,.dizzy-ticket-actions a{border:1px solid #fff;border-radius:0;box-sizing:border-box;font-size:12px;font-weight:700;letter-spacing:1.2px;padding:14px 22px;text-decoration:none;text-transform:uppercase}.dizzy-ticket-primary{background:#fff!important;color:#050505!important}.dizzy-ticket-back{background:transparent!important;color:#fff!important}.dizzy-ticket-meta{line-height:1.7;margin:18px 0}.dizzy-ticket-loader{padding:45px 10px}@media(max-width:600px){.dizzy-ticket-modal{padding:38px 18px 24px}.dizzy-ticket-modal .woocommerce-message{align-items:stretch;flex-direction:column;text-align:center}.dizzy-ticket-modal .woocommerce-message .button.wc-forward,.dizzy-ticket-actions>*{width:100%}}
            .dizzy-ticket-close,.dizzy-ticket-close:hover,.dizzy-ticket-close:focus,.dizzy-ticket-close:focus-visible{-webkit-appearance:none!important;appearance:none!important;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;outline:0!important}
        </style>
        <?php if ($paymentStatus !== 'paid') : ?>
            <div class="dizzy-ticket-success dizzy-ticket-<?php echo esc_attr($paymentStatus); ?>"><p><strong><?php echo esc_html($statusMessage); ?></strong></p></div>
        <?php endif; ?>
        <div class="dizzy-ticket-overlay" id="dizzy-ticket-overlay" role="dialog" aria-modal="true" aria-labelledby="dizzy-ticket-heading">
            <div class="dizzy-ticket-modal"><button type="button" class="dizzy-ticket-close" aria-label="<?php esc_attr_e('Close', 'dizzy-reservations-manager'); ?>">&times;</button><div id="dizzy-ticket-modal-content"></div></div>
        </div>
        <script>
        (()=>{const success=document.querySelector('.dizzy-ticket-success');if(!success)return;const overlay=document.getElementById('dizzy-ticket-overlay'),content=document.getElementById('dizzy-ticket-modal-content'),close=overlay.querySelector('.dizzy-ticket-close'),ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;const initial=success.cloneNode(true);const open=()=>{overlay.classList.add('is-open');document.body.style.overflow='hidden';close.focus()};const shut=()=>{overlay.classList.remove('is-open');document.body.style.overflow='';};const showSuccess=()=>{content.innerHTML='';content.append(initial.cloneNode(true));content.querySelectorAll('a[href*="dizzy_paid_ticket"]').forEach(link=>{link.classList.add('dizzy-ticket-primary');link.addEventListener('click',e=>{e.preventDefault();load(link.href)})});open()};const load=async href=>{const code=new URL(href,location.href).searchParams.get('dizzy_paid_ticket');content.innerHTML='<div class="dizzy-ticket-loader"><?php echo esc_js(__('Loading ticket…', 'dizzy-reservations-manager')); ?></div>';open();try{const body=new URLSearchParams({action:'dizzy_ticket_details',ticket_code:code||''}),response=await fetch(ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}),json=await response.json();if(!json.success)throw new Error(json.data?.message||'Ticket unavailable');ticket(json.data)}catch(error){content.innerHTML='<h2><?php echo esc_js(__('Ticket could not be loaded.', 'dizzy-reservations-manager')); ?></h2><p>'+String(error.message)+'</p><div class="dizzy-ticket-actions"><button class="dizzy-ticket-back" type="button"><?php echo esc_js(__('Back', 'dizzy-reservations-manager')); ?></button></div>';content.querySelector('button').onclick=showSuccess}};const ticket=data=>{content.innerHTML='<h2 id="dizzy-ticket-heading"><?php echo esc_js(__('Your Ticket', 'dizzy-reservations-manager')); ?></h2><h3></h3><p class="dizzy-ticket-date"></p><img alt="<?php echo esc_js(__('Ticket QR code', 'dizzy-reservations-manager')); ?>"><div class="dizzy-ticket-meta"></div><p><?php echo esc_js(__('Present this QR code at the entrance.', 'dizzy-reservations-manager')); ?></p><div class="dizzy-ticket-actions"><button type="button" class="dizzy-ticket-primary dizzy-save-ticket"><?php echo esc_js(__('Save Ticket', 'dizzy-reservations-manager')); ?></button><button type="button" class="dizzy-ticket-back"><?php echo esc_js(__('Back', 'dizzy-reservations-manager')); ?></button></div>';content.querySelector('h3').textContent=data.event;content.querySelector('.dizzy-ticket-date').textContent=data.date;const img=content.querySelector('img');img.src=data.qr;content.querySelector('.dizzy-ticket-meta').textContent=data.type+' · '+data.holder+' · '+data.code;content.querySelector('.dizzy-ticket-back').onclick=showSuccess;content.querySelector('.dizzy-save-ticket').onclick=()=>save(data,img)};const save=(data,img)=>{if(!data.qr||!img.complete){location.assign(data.url);return}const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1600;const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,1080,1600);ctx.fillStyle='#111827';ctx.textAlign='center';ctx.font='700 54px sans-serif';wrap(ctx,data.event.toUpperCase(),540,150,900,68);ctx.font='36px sans-serif';ctx.fillText(data.date,540,300);ctx.drawImage(img,260,390,560,560);ctx.font='700 38px sans-serif';ctx.fillText(data.type,540,1040);ctx.font='32px sans-serif';ctx.fillText(data.holder,540,1110);ctx.font='28px monospace';ctx.fillText(data.code,540,1180);ctx.font='28px sans-serif';ctx.fillText('<?php echo esc_js(__('Present this QR code at the entrance.', 'dizzy-reservations-manager')); ?>',540,1335);const a=document.createElement('a');a.download=data.filename||'event-ticket.jpg';a.href=canvas.toDataURL('image/jpeg',.95);a.click()};const wrap=(ctx,text,x,y,max,line)=>{const words=String(text).split(/\s+/);let row='';for(const word of words){const test=row?row+' '+word:word;if(ctx.measureText(test).width>max&&row){ctx.fillText(row,x,y);row=word;y+=line}else row=test}if(row)ctx.fillText(row,x,y)};close.onclick=shut;overlay.addEventListener('click',e=>{if(e.target===overlay)shut()});document.addEventListener('keydown',e=>{if(e.key==='Escape')shut()});success.style.display='none';showSuccess()})();
        </script>
        <script>
        (()=>{const content=document.getElementById('dizzy-ticket-modal-content');if(!content)return;const styleNotice=()=>{const result=content.querySelector('.dizzy-ticket-success');if(!result)return;const wrapper=document.createElement('div'),message=document.createElement('div'),text=document.createElement('span'),links=[...result.querySelectorAll('a[href*="dizzy_paid_ticket"]')];wrapper.className='woocommerce-notices-wrapper';message.className='woocommerce-message';message.setAttribute('role','alert');message.tabIndex=-1;text.className='woocommerce-message-text';text.textContent=result.querySelector('strong')?.textContent||'Payment received. Your tickets are ready.';message.append(text);links.forEach((link,index)=>{link.className='button wc-forward';link.textContent=links.length>1?'OPEN TICKET '+(index+1):'OPEN TICKET';message.append(link)});wrapper.append(message);result.replaceWith(wrapper)};styleNotice();new MutationObserver(styleNotice).observe(content,{childList:true,subtree:true})})();
        </script>
        <?php
    }
}

