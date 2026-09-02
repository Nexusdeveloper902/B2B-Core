<?php

namespace App\Enums;

enum ReaderType: string
{
    case Classroom = 'classroom';
    case Pae = 'pae';
    case Recycling = 'recycling';
    case Entry = 'entry';
}
