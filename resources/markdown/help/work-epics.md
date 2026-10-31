## What this is

An epic is a large piece of work with stories, tasks and bugs underneath it. On this screen each
epic on the project is a card: a progress bar, the figures behind it, and its children split into
what is open and what is done.

There is nothing to set up. An epic is a work item whose type is **Epic**, and a story is under it
when its *Parent* field names the epic. One level each way — an epic has no parent, and a subtask
has no children of its own.

## Progress

The bar is done children over all children, **by count**. Points are shown beside it when the
children carry any, but the bar does not use them: the children of an epic nobody has estimated
still get finished one at a time, and the bar should move when they do.

Progress is worked out every time the page is opened, never stored. A child reopened after the
fact takes the bar back down, which is the truth.

## When is an epic done?

When somebody says so. The bar reaching the end is the moment to ask, not the answer: the last
story being merged and the feature being finished are not always the same day, and a tool that
closed the epic for you would be deciding that they are.

Finished epics are hidden by default. **Show finished** brings them back.

## Roles and permissions

Everybody who may read work items may read this. Changing an epic — its title, its status, who
has it — is done on the item itself and needs **WorkItemUpdate**, which every Employee holds.
