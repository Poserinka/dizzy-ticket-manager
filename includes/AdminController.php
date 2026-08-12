<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class AdminController
{
    private const MENU='dizzy-reservations';
    private const STATUSES=['pending','confirmed','waitlisted','cancelled'];

    public function __construct(private ReservationRepository $repository,private ReservationService $service,private TicketService $tickets,private EventGateway $events) {}

    public function register(): void
    {
        add_action('admin_menu',[$this,'menu']);
        add_action('admin_post_dizzy_reservation_status',[$this,'status']);
        add_action('admin_post_dizzy_reservation_checkin',[$this,'checkin']);
        add_action('admin_post_dizzy_reservation_undo_checkin',[$this,'undo']);
        add_action('admin_post_dizzy_reservation_report_csv',[$this,'exportCsv']);
    }

    public function menu(): void
    {
        add_menu_page(__('Reservations Manager','dizzy-reservations-manager'),__('Reservations','dizzy-reservations-manager'),'manage_options',self::MENU,[$this,'reservations'],'dashicons-tickets-alt',26);
        add_submenu_page(self::MENU,__('Reservations','dizzy-reservations-manager'),__('Reservations','dizzy-reservations-manager'),'manage_options',self::MENU,[$this,'reservations']);
        add_submenu_page(self::MENU,__('Check-in','dizzy-reservations-manager'),__('Check-in','dizzy-reservations-manager'),'manage_options','dizzy-reservations-checkin',[$this,'checkinPage']);
        add_submenu_page(self::MENU,__('Reports','dizzy-reservations-manager'),__('Reports','dizzy-reservations-manager'),'manage_options','dizzy-reservations-reports',[$this,'reports']);
    }

    public function reservations(): void
    {
        $this->guard();echo '<div class="wrap"><h1>'.esc_html__('Reservations','dizzy-reservations-manager').'</h1><table class="widefat striped"><thead><tr><th>Name</th><th>Event</th><th>Guests</th><th>Status</th><th>Ticket</th></tr></thead><tbody>';
        foreach($this->repository->all() as $row){$id=(int)$row['id'];echo '<tr><td>'.esc_html((string)$row['name']).'<br>'.esc_html((string)$row['email']).'</td><td>'.esc_html(get_the_title((int)$row['event_id'])).'</td><td>'.esc_html((string)$row['guests']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dizzy_reservation_status"><input type="hidden" name="reservation_id" value="'.$id.'">';wp_nonce_field('dizzy_reservation_'.$id);echo '<select name="status">';foreach(self::STATUSES as $status)echo '<option value="'.esc_attr($status).'" '.selected($row['status'],$status,false).'>'.esc_html(ucfirst($status)).'</option>';echo '</select> <button class="button">Save</button></form></td><td>';
            if($row['status']==='confirmed'){$url=$this->tickets->url($row);echo '<a class="button" href="'.esc_url($url).'">Open ticket</a><br><img src="'.esc_url($this->tickets->qrUrl($url)).'" width="90" height="90" alt="QR">';}echo '</td></tr>';}
        echo '</tbody></table></div>';
    }

    public function checkinPage(): void
    {
        $this->guard();$totals=$this->repository->attendanceTotals();echo '<style>#wpbody-content>.notice,#wpbody-content>.update-nag,#wpbody-content>.wrap>.notice{display:none!important}</style><div class="wrap"><h1>'.esc_html__('Check-in & Attendance','dizzy-reservations-manager').'</h1>'; ?>
        <h2><?php esc_html_e('QR Scanner','dizzy-reservations-manager'); ?></h2><p><?php esc_html_e('Allow camera access and point it at a reservation QR code.','dizzy-reservations-manager'); ?></p><video id="dizzy-qr-video" style="width:100%;max-width:480px;background:#111" playsinline></video><p id="dizzy-qr-message"></p><p><input id="dizzy-qr-url" type="url" class="regular-text" placeholder="Paste ticket URL"> <button id="dizzy-open-ticket" class="button">Open ticket</button></p>
        <?php echo $this->cards(['Confirmed reservations'=>$totals['confirmed_reservations'],'Expected guests'=>$totals['confirmed_guests'],'Checked-in reservations'=>$totals['checked_in_reservations'],'Guests attended'=>$totals['checked_in_guests']]); ?>
        <h2><?php esc_html_e('Manual Check-in','dizzy-reservations-manager'); ?></h2><table class="widefat striped"><thead><tr><th>Name</th><th>Event</th><th>Guests</th><th>Checked in</th><th>Action</th></tr></thead><tbody><?php foreach($this->repository->all() as $row): if($row['status']!=='confirmed')continue;$id=(int)$row['id']; ?><tr><td><?php echo esc_html((string)$row['name']); ?></td><td><?php echo esc_html(get_the_title((int)$row['event_id'])); ?></td><td><?php echo esc_html((string)$row['guests']); ?></td><td><?php echo esc_html((string)($row['checked_in_at']?:'-')); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo !empty($row['checked_in_at'])?'dizzy_reservation_undo_checkin':'dizzy_reservation_checkin'; ?>"><input type="hidden" name="reservation_id" value="<?php echo esc_attr((string)$id); ?>"><?php wp_nonce_field('dizzy_checkin_'.$id); ?><button class="button"><?php echo esc_html(!empty($row['checked_in_at'])?'Undo':'Check in'); ?></button></form></td></tr><?php endforeach; ?></tbody></table>
        <script>(()=>{const v=document.getElementById('dizzy-qr-video'),m=document.getElementById('dizzy-qr-message'),i=document.getElementById('dizzy-qr-url'),nonce=<?php echo wp_json_encode(wp_create_nonce('dizzy_qr_checkin')); ?>;const open=x=>{try{const u=new URL(x,location.origin);if(u.origin!==location.origin||(u.searchParams.get('dizzy_ticket')!=='1'&&!u.searchParams.has('dizzy_paid_ticket')))throw 0;u.searchParams.set('checkin_nonce',nonce);location.assign(u.href);return true}catch(e){m.textContent='Invalid ticket URL.';return false}};document.getElementById('dizzy-open-ticket').onclick=e=>{e.preventDefault();open(i.value)};if(!('BarcodeDetector'in window)||!navigator.mediaDevices?.getUserMedia){m.textContent='Camera QR scanning is not supported. Paste the ticket URL instead.';return}const d=new BarcodeDetector({formats:['qr_code']});navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}}).then(s=>{v.srcObject=s;v.play();const scan=async()=>{try{const c=await d.detect(v);if(c[0]?.rawValue&&open(c[0].rawValue)){s.getTracks().forEach(t=>t.stop());return}}catch(e){}requestAnimationFrame(scan)};scan()}).catch(()=>m.textContent='Camera access was denied.');})();</script></div><?php
    }

    public function reports(): void
    {
        $this->guard();$s=$this->repository->reportSummary();$url=wp_nonce_url(admin_url('admin-post.php?action=dizzy_reservation_report_csv'),'dizzy_reservation_report_csv');echo '<div class="wrap"><h1>'.esc_html__('Reservation Reports','dizzy-reservations-manager').'</h1>'.$this->cards(['Reservations'=>$s['reservations'],'Confirmed guests'=>$s['confirmed_guests'],'Attended guests'=>$s['attended_guests'],'No-show guests'=>$s['no_show_guests'],'Waitlisted'=>$s['waitlisted']]).'<p><a class="button" href="'.esc_url($url).'">Export CSV</a></p><table class="widefat striped"><thead><tr><th>Event</th><th>Date</th><th>Reservations</th><th>Guests</th><th>Attended</th><th>Capacity</th><th>Usage</th></tr></thead><tbody>';
        foreach($this->repository->reportRows() as $r){$cap=(int)($r['capacity']??0);$guests=(int)$r['guests'];echo '<tr><td>'.esc_html((string)$r['post_title']).'</td><td>'.esc_html((string)$r['start_datetime']).'</td><td>'.esc_html((string)$r['reservations']).'</td><td>'.$guests.'</td><td>'.esc_html((string)$r['attended']).'</td><td>'.esc_html($cap>0?(string)$cap:'Unlimited').'</td><td>'.esc_html($cap>0?round($guests/$cap*100,1).'%':'—').'</td></tr>';}
        echo '</tbody></table></div>';
    }

    public function capacity(): void
    {
        $this->guard();echo '<div class="wrap"><h1>'.esc_html__('Capacity Management','dizzy-reservations-manager').'</h1><p>Use 0 for unlimited capacity.</p><table class="widefat striped"><thead><tr><th>Event</th><th>Date</th><th>Capacity</th></tr></thead><tbody>';
        foreach($this->events->allUpcoming() as $r){$id=(int)$r['id'];echo '<tr><td>'.esc_html((string)$r['post_title']).'</td><td>'.esc_html((string)$r['start_datetime']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dizzy_reservation_capacity"><input type="hidden" name="occurrence_id" value="'.$id.'">';wp_nonce_field('dizzy_capacity_'.$id);echo '<input type="number" min="0" name="capacity" value="'.esc_attr((string)$this->repository->capacity($id)).'"> <button class="button">Save</button></form></td></tr>';}
        echo '</tbody></table></div>';
    }

    private function cards(array $items): string { $html='<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';foreach($items as $label=>$value)$html.='<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;min-width:150px"><strong style="font-size:22px;display:block">'.esc_html((string)$value).'</strong>'.esc_html($label).'</div>';return $html.'</div>'; }
    private function guard(): void { if(!current_user_can('manage_options'))wp_die(esc_html__('Unauthorized','dizzy-reservations-manager')); }
    private function authorizedId(): int { $this->guard();return absint($_POST['reservation_id']??0); }
    private function redirect(string $page=self::MENU): never { wp_safe_redirect(admin_url('admin.php?page='.$page));exit; }
    public function status(): void { $id=$this->authorizedId();check_admin_referer('dizzy_reservation_'.$id);$status=sanitize_key((string)($_POST['status']??''));if(in_array($status,self::STATUSES,true))$this->service->changeStatus($id,$status);$this->redirect(); }
    public function checkin(): void { $id=$this->authorizedId();check_admin_referer('dizzy_checkin_'.$id);$this->repository->checkIn($id,get_current_user_id());$this->redirect('dizzy-reservations-checkin'); }
    public function undo(): void { $id=$this->authorizedId();check_admin_referer('dizzy_checkin_'.$id);$this->repository->undoCheckIn($id);$this->redirect('dizzy-reservations-checkin'); }
    public function saveCapacity(): void { $this->guard();$id=absint($_POST['occurrence_id']??0);check_admin_referer('dizzy_capacity_'.$id);if($this->events->occurrence((int)($this->findEventId($id)), $id)!==null)$this->repository->setCapacity($id,absint($_POST['capacity']??0));$this->redirect('dizzy-reservations-capacity'); }
    private function findEventId(int $occurrenceId): int { foreach($this->events->allUpcoming() as $r)if((int)$r['id']===$occurrenceId)return (int)$r['event_id'];return 0; }
    public function exportCsv(): void { $this->guard();check_admin_referer('dizzy_reservation_report_csv');nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename=dizzy-reservation-report.csv');$out=fopen('php://output','wb');fputcsv($out,['Event','Date','Reservations','Guests','Attended','Capacity']);foreach($this->repository->reportRows() as $r)fputcsv($out,[$r['post_title'],$r['start_datetime'],$r['reservations'],$r['guests'],$r['attended'],$r['capacity']]);fclose($out);exit; }
}
