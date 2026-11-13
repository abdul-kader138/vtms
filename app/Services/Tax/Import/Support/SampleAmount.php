<?php

namespace App\Services\Tax\Import\Support;

/** An amount cell in a sample file: Italian text in CSV, a real number in Excel. */
final class SampleAmount
{
    public function __construct(public readonly float $value) {}
}
