## What this is

A template for a package: which components a person gets, each as a share of
gross, a fixed amount, or whatever gross has left. "Factory floor" might be
basic 60%, house rent 30%, medical the balance; "Office staff" something else.

## Using one <!-- requires: SalaryStructureView -->

On an employee's salary package, **Fill from structure** takes a structure and
one monthly gross figure and sets each named component. The itemised package
is still what payroll reads, and every line can be edited afterwards; the
structure only saves the typing.

## Writing one <!-- requires: SalaryStructureUpdate -->

Click **New**, name it, and add a line per component. Shares and fixed
amounts are taken first; one component may take the balance. The sum need not
reach gross: anything unnamed is simply not set.
