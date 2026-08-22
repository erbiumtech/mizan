<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use App\Modules\Core\Models\User;

/**
 * Who records a toolbox talk — §17.5.
 *
 * **The same grant as the induction register**, and deliberately not its own: both are the same seven-in-the-morning
 * job done by the same person at the same gate, and a second permission would only mean one of the two got filled in.
 *
 * There is no separate verification here, unlike §17.4's actions. A toolbox talk is not a claim about work being right —
 * it is a record that something was said to a list of people, and the attendance sheet is the evidence.
 */
class ToolboxTalkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPersonnelView');
    }

    public function view(User $user, ToolboxTalk $talk): bool
    {
        return $user->can('ConstructionPersonnelView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPersonnelUpdate');
    }

    public function update(User $user, ToolboxTalk $talk): bool
    {
        return $user->can('ConstructionPersonnelUpdate');
    }

    /**
     * Deletable only while nobody is recorded as having attended.
     *
     * An attendance sheet is somebody's evidence that they were told something, and §17.6 counts talks *and*
     * attendances — a register that could delete either would make both figures editable after the fact.
     */
    public function delete(User $user, ToolboxTalk $talk): bool
    {
        return $user->can('ConstructionPersonnelUpdate') && $talk->attendees()->count() === 0;
    }
}
