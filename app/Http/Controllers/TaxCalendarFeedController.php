<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Tax\PaymentCalendarService;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Personal deadlines feed (iCalendar) for a customer's phone or calendar
 * app. Calendar apps can't sign in, so the URL carries a long random secret
 * (users.calendar_token) that the customer can reset from the portal.
 * Only open payments are listed: from 60 days ago (still-overdue ones) on.
 */
class TaxCalendarFeedController
{
    public function __invoke(string $token, PaymentCalendarService $calendar): Response
    {
        abort_unless(strlen($token) >= 32, 404);

        $user = User::where('calendar_token', $token)->first();
        abort_if($user === null || $user->customer_id === null, 404);

        $entries = $calendar->entries(today()->subDays(60), today()->addYears(2), status: 'open', customerId: $user->customer_id);
        $app = config('app.name', 'VTMS');
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'vtms';
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $portal = route('customer.tax');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$app.'//Tax deadlines//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->text(__(':app — tax deadlines', ['app' => $app])),
            'X-PUBLISHED-TTL:PT12H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
        ];

        foreach ($entries as $e) {
            $due = Carbon::parse($e['due_date']);
            $amount = '€ '.number_format((float) $e['outstanding'], 2, ',', '.');
            $code = $e['f24'] ? ' · F24 '.$e['f24'] : '';

            array_push($lines,
                'BEGIN:VEVENT',
                'UID:'.$e['type'].'-'.$e['id'].'@'.$host,
                'DTSTAMP:'.$stamp,
                'DTSTART;VALUE=DATE:'.$due->format('Ymd'),
                'DTEND;VALUE=DATE:'.$due->copy()->addDay()->format('Ymd'),
                'SUMMARY:'.$this->text($e['description'].' — '.$amount),
                'DESCRIPTION:'.$this->text(__('Due :date: :amount', ['date' => $due->format('d/m/Y'), 'amount' => $amount]).$code."\n".$portal),
                'URL:'.$portal,
                'TRANSP:TRANSPARENT',
                'BEGIN:VALARM',
                'ACTION:DISPLAY',
                'DESCRIPTION:'.$this->text($e['description']),
                'TRIGGER:-P3D',
                'END:VALARM',
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="tax-deadlines.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    /** RFC 5545 text escaping. */
    private function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }

    /** RFC 5545 line folding at 75 octets. */
    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--; // never split a UTF-8 character
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
