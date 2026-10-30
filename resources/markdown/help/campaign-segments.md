## What this is

Who a campaign is for. Every campaign's **Send to** picks one of these.

## A rule, not a list <!-- requires: CampaignView -->

A segment says *which* people — open leads, active customers, leads from one source, people in
one city — and is worked out **at the moment a campaign is sent**. A campaign therefore reaches
everybody who matches then, not whoever matched when the segment was made.

**Matches now** shows how many it finds today. That is not how many will be sent to: consent is
checked separately for each person on the campaign's channel, and somebody with no consent on
record is skipped.

## Making one <!-- requires: CampaignCreate -->

Name it for who it reaches. Switch on open leads, active customers or both, then narrow by lead
source or city if you need to.

## Deleting <!-- requires: CampaignDelete -->

A segment a campaign was sent to cannot be deleted — the campaign would no longer say who it was
for. Switch **Active** off instead.

## Roles and permissions

The campaigns' own: **View** `CampaignView`, **Create** `CampaignCreate`, **Update**
`CampaignUpdate`, **Delete** `CampaignDelete`.
