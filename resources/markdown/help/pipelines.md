## What this is

Your sales process: the stages a deal passes through.

## Stages are yours to name <!-- requires: PipelineView -->

They are rows, not a fixed list we ship. Rename them, reorder them, add one — no deploy, no
waiting for us.

**Likely to close (%)** on a stage weights the forecast, so nobody has to guess twice: a deal
in Proposal is worth 50% of its value in the forecast without a salesperson putting a second
number on it.

**This means won / this means lost** marks the terminal stages. That flag is what reports
read, so they never break when you rename "Won" to "Closed — signed".

**Chase after (days)** is how long a deal may sit in the stage before it shows up as stalled.
A long qualification stage and a short negotiation stage have different patience, which is
why this is per stage rather than one company-wide number. Leave it blank for a stage that
never counts as stalled; terminal stages never do.

## More than one pipeline <!-- requires: PipelineCreate -->

A company selling two different things runs two processes, and their stages mean different
things. **A deal cannot move between pipelines** for that reason — if a deal has genuinely
changed shape, open a new one.

One pipeline is the default, and new deals start in its first non-terminal stage.

## Deleting <!-- requires: PipelineDelete -->

A pipeline or stage with deals against it cannot be deleted — the history would go with it.
Switch the pipeline inactive instead.

## Roles and permissions

**View**: `PipelineView` — everybody, since the board and every deal form name the stages.
**Create / update / delete**: `PipelineCreate`, `PipelineUpdate`, `PipelineDelete`. The same
group covers stages and lost reasons: they are one piece of setup.
