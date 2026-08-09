<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Laravel derives the notification broadcast channel name from the notifiable's
// FQCN (str_replace('\\', '.', ...)), and Filament's notification bell does the
// same — so this must match App\Modules\Core\Models\User, not the classic
// App\Models\User default.
Broadcast::channel('App.Modules.Core.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
