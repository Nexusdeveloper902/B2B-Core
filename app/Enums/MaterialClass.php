<?php

namespace App\Enums;

enum MaterialClass: string
{
    case Plastic = 'plastic';
    case Paper = 'paper';
    case Metal = 'metal';
    case Glass = 'glass';
    case Other = 'other';

    /**
     * Points awarded per material class (single source: config/recycling.php).
     */
    public function points(): int
    {
        return (int) config("recycling.points.{$this->value}", 0);
    }
}
