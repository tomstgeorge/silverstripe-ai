<?php

declare(strict_types=1);

namespace DiveShop365\AI\Enum;

/**
 * The audience channels a knowledge article can be published to.
 *
 * Using a backed enum means the compiler rejects invalid audience values —
 * you cannot construct a FlowiseQueryService without declaring a valid channel.
 */
enum ChatAudience: string
{
    case Customer = 'customer';  // public website chatbot — unauthenticated visitors
    case Staff    = 'staff';     // Flutter staff app — JWT authenticated
    case Cms      = 'cms';       // CMS admin chatbot — SS session authenticated
}
