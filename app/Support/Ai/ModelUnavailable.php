<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * The model could not be reached, or replied with something unusable.
 *
 * Its own class so callers can tell "the bot is down" from "the bot understood
 * you and the answer was no" — the first is a retry and an apology, the second
 * is a question to the user. Collapsing them into one failure is how a rate
 * limit comes to be reported as "I didn't understand that".
 */
class ModelUnavailable extends RuntimeException {}
