<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

use RuntimeException;

defined('ABSPATH') || exit;

final class ReservationService
{
    public function __construct(private EventGateway $events,private ReservationRepository $repository,private Mailer $mailer,private TicketService $tickets) {}

    public function create(array $data): int
    {
        $eventId=absint($data['event_id']??0);$occurrenceId=absint($data['occurrence_id']??0);
        $name=sanitize_text_field((string)($data['name']??''));$email=sanitize_email((string)($data['email']??''));$guests=absint($data['guests']??0);
        if($this->events->occurrence($eventId,$occurrenceId)===null) throw new RuntimeException('Selected event date is unavailable.');
        if($name===''||!is_email($email)||$guests<1||$guests>100) throw new RuntimeException('Invalid reservation details.');
        $capacity=absint(get_post_meta($eventId,'_dizzy_capacity',true));$status=$capacity>0&&$this->repository->reservedGuests($occurrenceId)+$guests>$capacity?'waitlisted':'pending';
        $id=$this->repository->create(['event_id'=>$eventId,'occurrence_id'=>$occurrenceId,'name'=>$name,'email'=>$email,'phone'=>sanitize_text_field((string)($data['phone']??'')),'guests'=>$guests,'status'=>$status]);
        $this->mailer->send($email,$status==='waitlisted'?'Added to the waiting list':'Reservation received',$status==='waitlisted'?'The event is full. Your reservation is on the waiting list.':'Your reservation is awaiting approval.');
        return $id;
    }

    public function changeStatus(int $id,string $status): bool
    {
        $row=$this->repository->find($id);if($row===null) return false;
        if($status==='confirmed'){$capacity=absint(get_post_meta((int)$row['event_id'],'_dizzy_capacity',true));if($capacity>0&&$this->repository->reservedGuests((int)$row['occurrence_id'],$id)+(int)$row['guests']>$capacity) return false;}
        if(!$this->repository->updateStatus($id,$status)) return false;
        if(is_email((string)$row['email'])){$message=match($status){'confirmed'=>'Your reservation is confirmed.<br><a href="'.esc_url($this->tickets->url($row)).'">Open ticket</a>','cancelled'=>'Your reservation is cancelled.','waitlisted'=>'Your reservation is on the waiting list.',default=>'Your reservation is awaiting approval.'};$this->mailer->send((string)$row['email'],'Reservation '.ucfirst($status),$message);}
        return true;
    }
}
