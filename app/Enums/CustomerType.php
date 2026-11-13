<?php

namespace App\Enums;

enum CustomerType: string
{
    case GestioneSeparata = 'gestione_separata';
    case Commercianti = 'commercianti';
    case Artigiani = 'artigiani';

    public function label(): string
    {
        return match ($this) {
            self::GestioneSeparata => 'Gestione Separata',
            self::Commercianti => 'Commercianti',
            self::Artigiani => 'Artigiani',
        };
    }
}
