# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-27

First tagged release. The schema is not yet stable; nothing before this release
is documented here. Highlights of what the release contains:

- Multi-tenant (database-per-tenant) accounting: double-entry ledger, invoicing
  with customer withholding, banking exports, fixed assets, budgets, reports.
- Payroll and HR: payslips with component tables, leave with carry-forward
  expiry, attendance, employee hierarchy access, tax slabs per fiscal year.
- CRM, quotations, support, campaigns, construction management, projects with
  environment monitoring and a public status page.
- Filament 5 admin panel with saved table views, custom fields (conditional
  visibility, encryption at rest), command palette, and a leave/payslip API.
- FBR digital invoicing pipeline (driver interface, queued submission, QR on
  the invoice PDF) awaiting a live integrator driver.
- Git history rewritten 2026-09-26 to remove personal data that predated the
  open-source release; see docs/open-source-release-checklist.md §1.6.
