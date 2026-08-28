<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

defined('ABSPATH') || exit;

final class CheckInWebApp
{
    private const QUERY_VAR = 'dizzy_ticket_checkin_app';

    public function register(): void
    {
        add_rewrite_rule('^ticket-check-in/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', [$this, 'render']);
        add_action('wp_head', [$this, 'manifestLink']);

        if (get_option('dizzy_tm_checkin_rewrite_version') !== DIZZY_TICKETS_VERSION) {
            flush_rewrite_rules(false);
            update_option('dizzy_tm_checkin_rewrite_version', DIZZY_TICKETS_VERSION, false);
        }
    }

    public function manifestLink(): void
    {
        if ((int) get_query_var(self::QUERY_VAR) !== 1) {
            return;
        }

        echo '<meta name="theme-color" content="#111111">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    }

    public function render(): void
    {
        if ((int) get_query_var(self::QUERY_VAR) !== 1) {
            return;
        }

        if (! is_user_logged_in()) {
            auth_redirect();
        }

        if (! current_user_can(ControllerRole::TICKETS_CAP)) {
            wp_die(esc_html__('You are not allowed to use ticket check-in.', 'dizzy-ticket-manager'), 403);
        }

        nocache_headers();
        status_header(200);
        $restBase = esc_url_raw(rest_url('dizzy-controller/v1'));
        $nonce = wp_create_nonce('wp_rest');
        $today = current_time('Y-m-d');
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111111">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?php echo esc_html__('Dizzy Ticket Check-in', 'dizzy-ticket-manager'); ?></title>
<style>
:root{color-scheme:dark;--bg:#080808;--card:#181818;--line:#303030;--text:#fff;--muted:#aaa;--blue:#3858f4;--green:#1d9b55;--red:#d83b3b;--orange:#d88324}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.app{width:min(760px,100%);min-height:100vh;margin:auto;padding:max(20px,env(safe-area-inset-top)) 16px max(28px,env(safe-area-inset-bottom))}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}h1{font-size:24px;margin:0}.date{color:var(--muted);font-size:14px}
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}.stat{background:var(--card);border:1px solid var(--line);padding:14px}.stat strong{display:block;font-size:25px}.stat span{color:var(--muted);font-size:13px}
.scanner{position:relative;overflow:hidden;aspect-ratio:4/3;background:#111;border:1px solid var(--line)}video{width:100%;height:100%;object-fit:cover}.guide{position:absolute;inset:18%;border:2px solid rgba(255,255,255,.85);box-shadow:0 0 0 999px rgba(0,0,0,.28);pointer-events:none}
.actions{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:12px}input,button{min-height:50px;border-radius:0;border:1px solid var(--line);font:inherit}input{width:100%;background:#111;color:#fff;padding:0 14px}button{cursor:pointer;background:#fff;color:#111;font-weight:700;padding:0 18px;text-transform:uppercase;letter-spacing:.07em}.secondary{background:transparent;color:#fff}.camera{width:100%;margin-top:10px;background:var(--blue);color:#fff;border:0}
.result{display:none;margin:14px 0;padding:20px;text-align:center;border:1px solid transparent}.result.show{display:block}.result.ok{background:var(--green)}.result.warn{background:var(--orange)}.result.error{background:var(--red)}.result strong{font-size:23px;display:block}.result small{display:block;margin-top:5px}
.list-head{display:flex;justify-content:space-between;align-items:center;margin-top:22px}.list-head h2{font-size:18px}.tickets{display:grid;gap:8px}.ticket{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--card);border:1px solid var(--line);padding:12px}.ticket p{margin:0}.ticket small{color:var(--muted)}.ticket button{min-height:40px;font-size:12px}.checked{opacity:.58}
.notice{color:var(--muted);text-align:center;padding:14px}@media(min-width:650px){.stats{grid-template-columns:repeat(4,1fr)}}
</style>
</head>
<body>
<main class="app">
<header><div><h1><?php echo esc_html__('Ticket Check-in', 'dizzy-ticket-manager'); ?></h1><div class="date"><?php echo esc_html(wp_date(get_option('date_format'))); ?></div></div><button id="logout" class="secondary" type="button"><?php echo esc_html__('Exit', 'dizzy-ticket-manager'); ?></button></header>
<section class="stats" aria-label="<?php echo esc_attr__('Today statistics', 'dizzy-ticket-manager'); ?>">
<div class="stat"><strong id="sold">0</strong><span><?php echo esc_html__('Sold tickets', 'dizzy-ticket-manager'); ?></span></div>
<div class="stat"><strong id="expected">0</strong><span><?php echo esc_html__('Expected', 'dizzy-ticket-manager'); ?></span></div>
<div class="stat"><strong id="checked">0</strong><span><?php echo esc_html__('Checked-in', 'dizzy-ticket-manager'); ?></span></div>
<div class="stat"><strong id="attended">0</strong><span><?php echo esc_html__('Attended', 'dizzy-ticket-manager'); ?></span></div>
</section>
<div id="result" class="result" role="status" aria-live="assertive"></div>
<section class="scanner"><video id="video" playsinline muted></video><div class="guide"></div></section>
<button id="camera" class="camera" type="button"><?php echo esc_html__('Start camera', 'dizzy-ticket-manager'); ?></button>
<div class="actions"><input id="ticketInput" type="text" inputmode="text" autocomplete="off" placeholder="<?php echo esc_attr__('Paste ticket URL or code', 'dizzy-ticket-manager'); ?>"><button id="submit" type="button"><?php echo esc_html__('Check in', 'dizzy-ticket-manager'); ?></button></div>
<div class="list-head"><h2><?php echo esc_html__("Today's tickets", 'dizzy-ticket-manager'); ?></h2><button id="refresh" class="secondary" type="button"><?php echo esc_html__('Refresh', 'dizzy-ticket-manager'); ?></button></div>
<div id="tickets" class="tickets"><div class="notice"><?php echo esc_html__('Loading…', 'dizzy-ticket-manager'); ?></div></div>
</main>
<script>
(() => {
const api=<?php echo wp_json_encode($restBase); ?>,nonce=<?php echo wp_json_encode($nonce); ?>,today=<?php echo wp_json_encode($today); ?>;
const el=id=>document.getElementById(id), result=el('result'), video=el('video');
let stream=null, scanning=false, lastValue='', lastTime=0;
const request=async(path,options={})=>{const r=await fetch(api+path,{credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},...options});const data=await r.json();if(!r.ok)throw Object.assign(new Error(data.message||'Request failed'),{data});return data};
const message=(kind,title,detail='')=>{result.className='result show '+kind;result.innerHTML='<strong>'+title+'</strong>'+(detail?'<small>'+detail+'</small>':'');if(navigator.vibrate)navigator.vibrate(kind==='ok'?[80]:[120,70,120]);setTimeout(()=>result.classList.remove('show'),4200)};
const load=async()=>{try{const [stats,tickets]=await Promise.all([request('/attendance?date='+today),request('/tickets?date='+today)]);['sold','expected','checked_in','attended'].forEach(k=>el(k==='checked_in'?'checked':k).textContent=stats[k]||0);el('tickets').innerHTML=tickets.length?tickets.map(t=>'<div class="ticket '+(t.checked_in_at?'checked':'')+'"><p><strong>'+escapeHtml(t.holder_name)+'</strong><br><small>'+escapeHtml(t.event)+' · '+escapeHtml(t.short_code)+'</small></p>'+(t.checked_in_at?'<small><?php echo esc_js(__('Checked-in', 'dizzy-ticket-manager')); ?></small>':'<button data-code="'+t.code+'"><?php echo esc_js(__('Check in', 'dizzy-ticket-manager')); ?></button>')+'</div>').join(''):'<div class="notice"><?php echo esc_js(__('No tickets for today.', 'dizzy-ticket-manager')); ?></div>'}catch(e){message('error','<?php echo esc_js(__('Could not load tickets.', 'dizzy-ticket-manager')); ?>',e.message)}};
const escapeHtml=s=>String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const check=async value=>{value=String(value||'').trim();if(!value)return;const now=Date.now();if(value===lastValue&&now-lastTime<3000)return;lastValue=value;lastTime=now;try{const data=await request('/check-in',{method:'POST',body:JSON.stringify({ticket:value,date:today})});if(data.result==='checked_in')message('ok','<?php echo esc_js(__('Check-in completed', 'dizzy-ticket-manager')); ?>',data.holder_name||'');else if(data.result==='already_checked_in')message('warn','<?php echo esc_js(__('Ticket already checked in', 'dizzy-ticket-manager')); ?>',data.holder_name||'');else if(data.result==='wrong_day')message('error','<?php echo esc_js(__('Ticket is not for today', 'dizzy-ticket-manager')); ?>',data.event_date||'');else message('error','<?php echo esc_js(__('Invalid ticket', 'dizzy-ticket-manager')); ?>');el('ticketInput').value='';await load()}catch(e){message('error','<?php echo esc_js(__('Invalid ticket', 'dizzy-ticket-manager')); ?>',e.data&&e.data.event_date?e.data.event_date:e.message)}};
el('submit').onclick=()=>check(el('ticketInput').value);el('ticketInput').addEventListener('keydown',e=>{if(e.key==='Enter')check(e.target.value)});el('refresh').onclick=load;
el('tickets').onclick=e=>{const b=e.target.closest('button[data-code]');if(b)check(b.dataset.code)};
el('logout').onclick=()=>location.href=<?php echo wp_json_encode(wp_logout_url(home_url('/'))); ?>;
el('camera').onclick=async()=>{if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;scanning=false;video.srcObject=null;el('camera').textContent='<?php echo esc_js(__('Start camera', 'dizzy-ticket-manager')); ?>';return}if(!('BarcodeDetector'in window)){message('warn','<?php echo esc_js(__('Camera QR scanning is not supported.', 'dizzy-ticket-manager')); ?>','<?php echo esc_js(__('Use the manual field below.', 'dizzy-ticket-manager')); ?>');return}try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});video.srcObject=stream;await video.play();scanning=true;el('camera').textContent='<?php echo esc_js(__('Stop camera', 'dizzy-ticket-manager')); ?>';const detector=new BarcodeDetector({formats:['qr_code']});const scan=async()=>{if(!scanning)return;try{const codes=await detector.detect(video);if(codes[0]&&codes[0].rawValue)await check(codes[0].rawValue)}catch(e){}requestAnimationFrame(scan)};scan()}catch(e){message('error','<?php echo esc_js(__('Camera could not be opened.', 'dizzy-ticket-manager')); ?>',e.message)}};
load();
})();
</script>
</body>
</html><?php
        exit;
    }
}
