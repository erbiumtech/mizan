## What this is

People who applied.

## Why applicants and applications are separate <!-- requires: ApplicantView -->

**Because one person applies twice.** A system that cannot see that has no memory, and
recognising a returning candidate is the most useful thing a hiring record does after the
first year.

So a person is one row, and each job they apply for is another. The list says **"has
applied before"** where that is true.

Search for an email or a phone number before adding somebody new.

## This is the most sensitive data here <!-- requires: ApplicantView -->

And it is held about people your company never hired.

**Applicant records are deleted automatically two years after a rejection, and the CV file
goes with the row.** The clock starts at the rejection, not at the application: somebody
still in a process, or somebody hired, is never pruned however long ago they applied.

**Your company is the data controller for this.** The retention period is a setting, and
shortening it is a decision you are entitled to make.

## Roles and permissions

**View / create / update / delete**: `ApplicantView`, `ApplicantCreate`,
`ApplicantUpdate`, `ApplicantDelete`. These cover applicants, their applications, their
interviews and their offers together — deliberately, because splitting them would allow a
role that can read CVs without being trusted with the rest. The Employee role holds none
of it.
