## What this is

Why deals are lost: price, a competitor, no budget, no response. A deal's **Lost** action asks
which one, from this list.

## Why this is a list and not a text box <!-- requires: PipelineView -->

**Win/loss by reason** is the report this exists for. A free-text box gives you "price",
"Price" and "too expensive" as three reasons with three shares of your losses, none of them
right. A list of rows gives you one.

## Adding your own <!-- requires: PipelineCreate -->

Add the reasons your deals are actually lost for. Keep them few and distinct — a reason that
means the same as another splits its share in half.

## Deleting <!-- requires: PipelineDelete -->

A reason any deal was lost for **cannot be deleted**: the deal would lose its reason and the
report would lose the loss. Switch **Active** off instead — it leaves the Lost action's list and
every deal keeps it.

## Roles and permissions

The pipeline's own: **View** `PipelineView`, **Create** `PipelineCreate`, **Update**
`PipelineUpdate`, **Delete** `PipelineDelete`. A reason is part of setting up the pipeline.
