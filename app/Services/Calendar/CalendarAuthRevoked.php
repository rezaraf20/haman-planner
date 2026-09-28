<?php
declare(strict_types=1);

namespace App\Services\Calendar;

/** The user revoked access (or the refresh token expired): they need to connect again. */
final class CalendarAuthRevoked extends CalendarException {}
