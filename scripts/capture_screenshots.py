#!/usr/bin/env python3
"""Capture portfolio screenshots from the guarded fictional demo dataset."""

from __future__ import annotations

import os
from pathlib import Path

from playwright.sync_api import sync_playwright


def required_environment(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before capturing screenshots.")
    return value


base_url = required_environment("EAMS_SCREENSHOT_BASE_URL").rstrip("/")
admin_email = required_environment("EAMS_SCREENSHOT_ADMIN_EMAIL")
employee_email = required_environment("EAMS_SCREENSHOT_EMPLOYEE_EMAIL")
password = required_environment("EAMS_SCREENSHOT_PASSWORD")
output_directory = Path(__file__).resolve().parent.parent / "docs" / "screenshots"
output_directory.mkdir(parents=True, exist_ok=True)


def sign_in(page, email: str) -> None:
    page.goto(f"{base_url}/pages/login.php", wait_until="networkidle")
    page.locator('input[name="email"]').fill(email)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(channel="chrome", headless=True)
    context = browser.new_context(
        viewport={"width": 1440, "height": 960},
        device_scale_factor=1,
        color_scheme="light",
        locale="en-US",
        timezone_id="America/New_York",
    )
    page = context.new_page()

    sign_in(page, employee_email)
    page.goto(f"{base_url}/employee/dashboard.php", wait_until="networkidle")
    acknowledge_button = page.locator("#ann-ack-btn")
    if acknowledge_button.is_visible():
        acknowledge_button.click()
        page.wait_for_timeout(400)
    page.screenshot(path=output_directory / "employee-dashboard.png")
    page.goto(f"{base_url}/employee/calendar.php", wait_until="networkidle")
    page.locator("#leave-calendar-wrap").wait_for(state="visible")
    page.screenshot(path=output_directory / "leave-calendar.png")

    page.goto(f"{base_url}/pages/logout.php", wait_until="networkidle")
    sign_in(page, admin_email)
    page.goto(f"{base_url}/admin/dashboard.php", wait_until="networkidle")
    page.screenshot(path=output_directory / "admin-dashboard.png")
    page.goto(f"{base_url}/admin/payroll_export.php", wait_until="networkidle")
    page.screenshot(path=output_directory / "payroll-review.png")

    context.close()
    browser.close()

print(f"Captured four fictional-data screenshots in {output_directory}.")
