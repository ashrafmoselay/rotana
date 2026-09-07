<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

final class Audit
{
    public static function record(string $event, ?Model $subject = null, array $properties = []): void
    {
        $log = activity('rotana')->causedBy(auth()->user())->event($event)->withProperties($properties);
        if ($subject) {
            $log->performedOn($subject);
        }
        $log->log($event);
    }
}
