<?php

namespace App\Notifications\Tax;

use App\Services\Tax\PaymentRegistry;
use App\Support\Tax\F24Code;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentDueReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $type, public Model $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject(__('Payment due on :date: :what', ['date' => $data['due_date_formatted'], 'what' => $data['description']]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->first_name]))
            ->line(__(':what for :customer is due on :date.', ['what' => $data['description'], 'customer' => $data['customer_name'], 'date' => $data['due_date_formatted']]))
            ->line(__('Amount outstanding: €:amount', ['amount' => number_format($data['outstanding'], 2, ',', '.')]))
            ->when($data['f24_code'], fn (MailMessage $m) => $m->line(__('Pay with an F24 form, code :code (:section), tax year :year.', ['code' => $data['f24_code'], 'section' => $data['f24_section'], 'year' => $data['tax_year']])))
            ->action(__('View your tax dashboard'), url('/app/tax'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'payment_type' => $this->type,
            'payment_id' => $this->payment->id,
            'customer_id' => $this->payment->customer_id,
            'customer_name' => $this->payment->customer?->company ?: $this->payment->customer?->name,
            'description' => PaymentRegistry::describe($this->type, $this->payment),
            'due_date' => $this->payment->due_date?->toDateString(),
            'due_date_formatted' => $this->payment->due_date?->format('d/m/Y'),
            'outstanding' => round((float) $this->payment->amount - (float) $this->payment->paid_amount, 2),
            'f24_code' => F24Code::for($this->type, $this->payment),
            'f24_section' => F24Code::section($this->type),
            'tax_year' => $this->payment->tax_year ?? $this->payment->payment_year,
        ];
    }
}
