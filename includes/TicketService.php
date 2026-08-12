<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class TicketService
{
    public function __construct(private ReservationRepository $repository) {}
    public function register(): void { add_action('template_redirect',[$this,'render']); }
    public function url(array $row): string { return add_query_arg(['dizzy_ticket'=>1,'reservation'=>(int)$row['id'],'signature'=>$this->signature($row)],home_url('/')); }
    public function qrUrl(string $url): string { return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='.rawurlencode($url); }

    public function render(): void
    {
        if(sanitize_key((string)($_GET['dizzy_ticket']??''))!=='1') return;
        $row=$this->repository->find(absint($_GET['reservation']??0));
        $signature=sanitize_text_field(wp_unslash((string)($_GET['signature']??'')));
        if($row===null||($row['status']??'')!=='confirmed'||!hash_equals($this->signature($row),$signature)){status_header(404);wp_die(esc_html__('Invalid ticket.','dizzy-reservations-manager'));}
        $result=''; $nonce=sanitize_text_field(wp_unslash((string)($_GET['checkin_nonce']??'')));
        if(current_user_can('manage_options')&&wp_verify_nonce($nonce,'dizzy_qr_checkin')) $result=$this->repository->checkIn((int)$row['id'],get_current_user_id());
        status_header(200);nocache_headers();
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width"><title><?php esc_html_e('Reservation Ticket','dizzy-reservations-manager'); ?></title></head><body style="font-family:sans-serif;max-width:640px;margin:48px auto;padding:24px"><h1><?php esc_html_e('Reservation Ticket','dizzy-reservations-manager'); ?></h1><h2><?php echo esc_html(get_the_title((int)$row['event_id'])); ?></h2><p><?php echo esc_html((string)$row['name']); ?></p><p><?php echo esc_html(sprintf(__('Guests: %d','dizzy-reservations-manager'),(int)$row['guests'])); ?></p><?php if($result!==''): ?><p><strong><?php echo esc_html($result==='checked_in'?__('Check-in completed.','dizzy-reservations-manager'):__('Already checked in or invalid.','dizzy-reservations-manager')); ?></strong></p><?php endif; ?><?php if($result!==''): ?><p><a href="<?php echo esc_url(admin_url('admin.php?page=dizzy-reservations-checkin')); ?>" style="display:inline-block;background:#2271b1;color:#fff;padding:10px 16px;text-decoration:none"><?php esc_html_e('Return to Check-in','dizzy-reservations-manager'); ?></a></p><?php endif; ?></body></html><?php exit;
    }

    private function signature(array $row): string { return hash_hmac('sha256',implode('|',[(string)$row['id'],(string)$row['email'],(string)$row['created_at']]),wp_salt('auth')); }
}
