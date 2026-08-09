# Screenshot source notes

Portfolio screenshots in this directory must be captured from the guarded `scripts/seed_demo.php` dataset only.

Expected files:

- `employee-dashboard.png`
- `leave-calendar.png`
- `admin-dashboard.png`
- `payroll-review.png`

Before committing a screenshot, verify that it contains only fictional `example.test` identities, does not expose local paths or credentials, has no unnecessary metadata, and remains readable in the two-column README layout.

With the isolated demo application running, capture all four views with:

```bash
export EAMS_SCREENSHOT_BASE_URL=http://127.0.0.1:8080
export EAMS_SCREENSHOT_ADMIN_EMAIL=admin@example.test
export EAMS_SCREENSHOT_EMPLOYEE_EMAIL=maya.chen@example.test
export EAMS_SCREENSHOT_PASSWORD='the-value-used-for-EAMS_DEMO_PASSWORD'
python3 scripts/capture_screenshots.py
```
