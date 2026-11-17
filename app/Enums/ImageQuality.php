<?php

namespace App\Enums;

/** Qualities accepted by POST /v1/generations. */
enum ImageQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
