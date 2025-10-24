
from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context()
    page = context.new_page()

    try:
        # Navigate to the login page
        page.goto("http://localhost:8000/login.php")

        # Fill in login credentials with corrected selectors
        page.get_by_label("Email").fill("admin@example.com")
        page.get_by_label("Contraseña").fill("password")
        page.get_by_role("button", name="Iniciar Sesión").click()

        # Wait for navigation to the main page and for tasks to be visible
        page.wait_for_url("http://localhost:8000/index.php")
        expect(page.get_by_role("heading", name="Alertas y Tareas Prioritarias")).to_be_visible()

        # Take a screenshot of the main content area
        main_content = page.locator("main")
        main_content.screenshot(path="jules-scratch/verification/task_order_verification.png")

        print("Screenshot taken successfully.")

    except Exception as e:
        print(f"An error occurred: {e}")
        page.screenshot(path="jules-scratch/verification/error.png")
    finally:
        browser.close()

with sync_playwright() as playwright:
    run(playwright)
