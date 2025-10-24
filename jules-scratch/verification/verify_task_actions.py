
import asyncio
from playwright.async_api import async_playwright

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        page = await browser.new_page()

        try:
            # Navigate to the login page
            await page.goto("http://localhost:8080/EAGLE/login.php")

            # Wait for the email input to be visible
            await page.wait_for_selector('input[name="email"]')

            # Fill in the login form and submit
            await page.fill('input[name="email"]', "admin@example.com")
            await page.fill('input[name="password"]', "123")
            await page.click('button[type="submit"]')

            # Wait for navigation to the main page
            await page.wait_for_url("http://localhost:8080/EAGLE/index.php")

            # --- Test Reminder Creation ---
            # Click the "Recordatorio" button on the first task
            await page.locator('.task-card:has-text("Tarea: fin de mes tiendas rapidisomo") .text-gray-600').click()

            # Select a user from the dropdown
            await page.select_option('select[id^="reminder-user-"]', label="Jose Blanco")

            # Click the "Crear" button
            await page.click('button:has-text("Crear")')

            # --- Test Task Reassignment ---
            # Click the "Re-asignar" button on the first task
            await page.locator('.task-card:has-text("Tarea: fin de mes tiendas rapidisomo") .text-blue-600').click()

            # Select a user from the dropdown
            await page.select_option('select[id^="assign-user-"]', label="Juan silva (Checkinero)")

            # Click the "Confirmar" button
            await page.click('button:has-text("Confirmar")')

            # Take a screenshot
            await page.screenshot(path="jules-scratch/verification/verification.png")

        except Exception as e:
            print(f"An error occurred: {e}")
            await page.screenshot(path="jules-scratch/verification/error.png")

        finally:
            await browser.close()

asyncio.run(main())
