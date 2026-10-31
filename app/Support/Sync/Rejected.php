<?php

namespace App\Support\Sync;

use RuntimeException;

/**
 * Carries a workflow's refusal out of the inbox's transaction, so the refusal rolls the workflow's
 * writes back and is then recorded on its own — see SyncInbox. Internal to the engine.
 */
final class Rejected extends RuntimeException {}
