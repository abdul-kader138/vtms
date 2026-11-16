<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\ForeignServicePurchase;
use App\Models\Setting;

/**
 * Reverse-charge IVA on digital services bought from a foreign supplier
 * (Facebook/Google/Canva ads, SaaS subscriptions, etc.). Under Regime
 * Forfettario the customer has no output IVA to net it against, so the
 * self-assessed IVA is a real, non-refundable cost — unlike the reverse
 * charge for a normal VAT-registered business, where it nets to zero.
 */
class ForeignServiceIvaService
{
    public const DEFAULT_RATE = 22.00;

    public function rate(): float
    {
        return (float) Setting::get('foreign_services_iva_rate', self::DEFAULT_RATE);
    }

    public function totalPurchases(Customer $customer, int $year): float
    {
        return round((float) ForeignServicePurchase::where('customer_id', $customer->id)->forYear($year)->sum('amount'), 2);
    }

    public function ivaOwed(Customer $customer, int $year): float
    {
        return round($this->totalPurchases($customer, $year) * $this->rate() / 100, 2);
    }
}
