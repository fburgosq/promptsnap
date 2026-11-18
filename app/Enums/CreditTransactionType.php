<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    case GenerationCharge = 'generation_charge';
}
