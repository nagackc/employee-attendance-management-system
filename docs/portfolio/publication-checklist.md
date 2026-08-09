# Portfolio publication checklist

## Ownership and privacy

- [ ] Source ownership or written publication permission is confirmed.
- [ ] The repository contains no real employee, customer, client, or operational data.
- [ ] Local configuration, credentials, dumps, uploads, exports, and logs are ignored and untracked.
- [ ] Images contain fictional or approved content and have been checked for EXIF or other embedded metadata.
- [ ] Secret-pattern scans and a manual review of tracked files are clean.
- [ ] The intended license decision is explicit; no license is added by assumption.

## Product presentation

- [ ] The README begins with the problem and intended users rather than the technology stack.
- [ ] Screenshots show useful workflows, use fictional data, include alt text, and remain readable on mobile GitHub views.
- [ ] Features, limitations, technical decisions, and lessons learned accurately match the implementation.
- [ ] Repository description and topics are concise and searchable.
- [ ] There are no broken links, empty future-project cards, exaggerated metrics, or unfinished claims.

## Reproducibility and quality

- [ ] A clean clone can be configured and started using only the README.
- [ ] Installation succeeds without a built-in password or browser-accessible installer.
- [ ] Migrations succeed twice against an isolated database.
- [ ] Demo data can only affect an explicitly named disposable database.
- [ ] Static checks, helper tests, integration tests, and CI pass.
- [ ] Generated files and dependencies are not committed unless intentionally required.

## GitHub profile

- [ ] The profile headline stays stack-neutral and truthful.
- [ ] Only completed, substantial projects are featured or pinned.
- [ ] “Currently Learning” names work that has actually started.
- [ ] Public name, location, LinkedIn, and email contain only approved values.
- [ ] The project link, screenshot, CI badge, and repository visibility work for signed-out visitors.
